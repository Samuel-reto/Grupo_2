import json
import boto3
from datetime import datetime, timedelta

rds = boto3.client('rds')
ec2 = boto3.client('ec2')

def lambda_handler(event, context):
    
    EFS_ID = 'fs-00e030dbdc1110c4e'
    RDS_INSTANCE_ID = 'wordpress-db'
    EC2_INSTANCE_IDS = ['i-04eded50e7c62087d', 'i-0fc97a7380f992da0', 'i-0ece083e22830a039']
    RETENTION_DAYS = 7
    
    timestamp = datetime.now().strftime('%Y%m%d-%H%M%S')
    results = {'efs': {}, 'rds': {}, 'ec2': {}}
    
    print("INICIANDO BACKUP")
    
    try:
        efs_client = boto3.client('efs')
        response = efs_client.create_backup(FileSystemId=EFS_ID)
        results['efs'] = {'success': True, 'id': response['BackupId']}
        print(f"EFS OK: {response['BackupId']}")
    except Exception as e:
        results['efs'] = {'success': False, 'error': str(e)}
        print(f"EFS ERROR: {e}")
    
    try:
        snapshot_id = f"{RDS_INSTANCE_ID}-{timestamp}"
        rds.create_db_snapshot(DBSnapshotIdentifier=snapshot_id, DBInstanceIdentifier=RDS_INSTANCE_ID)
        results['rds'] = {'success': True, 'id': snapshot_id}
        print(f"RDS OK: {snapshot_id}")
    except Exception as e:
        results['rds'] = {'success': False, 'error': str(e)}
        print(f"RDS ERROR: {e}")
    
    ami_count = 0
    for instance_id in EC2_INSTANCE_IDS:
        try:
            ami_name = f"backup-{instance_id}-{timestamp}"
            response = ec2.create_image(InstanceId=instance_id, Name=ami_name, NoReboot=True)
            ami_count += 1
            print(f"EC2 OK: {response['ImageId']}")
        except Exception as e:
            print(f"EC2 ERROR {instance_id}: {e}")
    
    results['ec2'] = {'success': ami_count > 0, 'count': ami_count}
    
    print(f"\nRESUMEN: EFS={results['efs'].get('success')}, RDS={results['rds'].get('success')}, EC2={ami_count} AMIs")
    
    return {'statusCode': 200, 'body': json.dumps(results, default=str)}