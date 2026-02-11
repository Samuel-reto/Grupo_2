# ============================================
# SCRIPT AUTOMATIZADO PARA DESPLEGAR EFS
# Con detección de recursos existentes
# ============================================

Import-Module AWS.Tools.EC2
Import-Module AWS.Tools.ElasticFileSystem

Write-Host "============================================" -ForegroundColor Cyan
Write-Host "INICIANDO DESPLIEGUE AUTOMATIZADO DE EFS" -ForegroundColor Cyan
Write-Host "============================================`n" -ForegroundColor Cyan

try {
    # ============================================
    # OBTENER IDS AUTOMÁTICAMENTE
    # ============================================
    
    Write-Host "Obteniendo IDs de recursos AWS..." -ForegroundColor Yellow
    
    $vpc = Get-EC2Vpc -Filter @{Name="tag:Name"; Values="*WordPress*"} | Select-Object -First 1
    if (-not $vpc) {
        throw "No se encontró ninguna VPC con tag Name que contenga 'WordPress'"
    }
    $vpcId = $vpc.VpcId
    Write-Host "✓ VPC encontrada: $vpcId" -ForegroundColor Green
    
    $webSG = Get-EC2SecurityGroup -Filter @{Name="group-name"; Values="WebServer-SG"} | Select-Object -First 1
    if (-not $webSG) {
        throw "No se encontró el Security Group 'WebServer-SG'"
    }
    $webServerSecurityGroupId = $webSG.GroupId
    Write-Host "✓ WebServer Security Group encontrado: $webServerSecurityGroupId" -ForegroundColor Green
    
    $subnet1 = Get-EC2Subnet -Filter @{Name="tag:Name"; Values="Private-Subnet-1"} | Select-Object -First 1
    if (-not $subnet1) {
        throw "No se encontró 'Private-Subnet-1'"
    }
    $privateSubnet1Id = $subnet1.SubnetId
    Write-Host "✓ Private-Subnet-1 encontrada: $privateSubnet1Id" -ForegroundColor Green
    
    $subnet2 = Get-EC2Subnet -Filter @{Name="tag:Name"; Values="Private-Subnet-2"} | Select-Object -First 1
    if (-not $subnet2) {
        throw "No se encontró 'Private-Subnet-2'"
    }
    $privateSubnet2Id = $subnet2.SubnetId
    Write-Host "✓ Private-Subnet-2 encontrada: $privateSubnet2Id" -ForegroundColor Green
    
    $subnet3 = Get-EC2Subnet -Filter @{Name="tag:Name"; Values="Private-Subnet-3"} | Select-Object -First 1
    if (-not $subnet3) {
        throw "No se encontró 'Private-Subnet-3'"
    }
    $privateSubnet3Id = $subnet3.SubnetId
    Write-Host "✓ Private-Subnet-3 encontrada: $privateSubnet3Id`n" -ForegroundColor Green
    
    # ============================================
    # CREAR O USAR EFS SECURITY GROUP EXISTENTE
    # ============================================
    
    Write-Host "Verificando Security Group para EFS..." -ForegroundColor Yellow
    
    # Filtros separados para group-name y vpc-id
    $existingEFSSG = Get-EC2SecurityGroup -Filter @(
        @{Name="group-name"; Values="EFS-SG"}
        @{Name="vpc-id"; Values=$vpcId}
    ) -ErrorAction SilentlyContinue
    
    if ($existingEFSSG) {
        $efsSecurityGroup = $existingEFSSG.GroupId
        Write-Host "✓ EFS Security Group ya existe: $efsSecurityGroup" -ForegroundColor Yellow
    } else {
        $efsSGParams = @{
            GroupName = "EFS-SG"
            GroupDescription = "Security group for EFS"
            VpcId = $vpcId
        }
        $efsSecurityGroup = New-EC2SecurityGroup @efsSGParams
        Write-Host "✓ EFS Security Group creado: $efsSecurityGroup" -ForegroundColor Green
        
        # Añadir regla de ingreso NFS
        $ipPermission = New-Object Amazon.EC2.Model.IpPermission
        $ipPermission.IpProtocol = "tcp"
        $ipPermission.FromPort = 2049
        $ipPermission.ToPort = 2049
        
        $userIdGroupPair = New-Object Amazon.EC2.Model.UserIdGroupPair
        $userIdGroupPair.GroupId = $webServerSecurityGroupId
        $userIdGroupPair.Description = "NFS from Web Servers"
        $ipPermission.UserIdGroupPairs.Add($userIdGroupPair)
        
        Grant-EC2SecurityGroupIngress -GroupId $efsSecurityGroup -IpPermission $ipPermission
        Write-Host "✓ Regla NFS añadida al Security Group" -ForegroundColor Green
        
        # Tag
        $tag = New-Object Amazon.EC2.Model.Tag
        $tag.Key = "Name"
        $tag.Value = "EFS-SG"
        New-EC2Tag -Resource $efsSecurityGroup -Tag $tag
        Write-Host "✓ Tag aplicado al Security Group`n" -ForegroundColor Green
    }
    
    # ============================================
    # CREAR O USAR EFS FILE SYSTEM EXISTENTE
    # ============================================
    
    Write-Host "Verificando EFS File System..." -ForegroundColor Yellow
    
    $existingEFS = Get-EFSFileSystem | Where-Object { 
        $_.Tags | Where-Object { $_.Key -eq "Name" -and $_.Value -eq "WordPress-EFS" }
    } | Select-Object -First 1
    
    if ($existingEFS) {
        $efsFileSystem = $existingEFS
        Write-Host "✓ EFS File System ya existe: $($efsFileSystem.FileSystemId)" -ForegroundColor Yellow
    } else {
        $efsTag = New-Object Amazon.ElasticFileSystem.Model.Tag
        $efsTag.Key = "Name"
        $efsTag.Value = "WordPress-EFS"
        
        $efsParams = @{
            PerformanceMode = "generalPurpose"
            Encrypted = $true
            ThroughputMode = "bursting"
            Tag = @($efsTag)
        }
        $efsFileSystem = New-EFSFileSystem @efsParams
        Write-Host "✓ EFS File System creado: $($efsFileSystem.FileSystemId)" -ForegroundColor Green
        
        Write-Host "Esperando a que el EFS esté disponible..." -ForegroundColor Yellow
        do {
            Start-Sleep -Seconds 5
            $efsStatus = (Get-EFSFileSystem -FileSystemId $efsFileSystem.FileSystemId).LifeCycleState
            Write-Host "  Estado actual: $efsStatus" -ForegroundColor Gray
        } while ($efsStatus -ne "available")
        Write-Host "✓ EFS File System disponible`n" -ForegroundColor Green
        
        # Lifecycle Policy
        $lifecyclePolicy = New-Object Amazon.ElasticFileSystem.Model.LifecyclePolicy
        $lifecyclePolicy.TransitionToIA = "AFTER_30_DAYS"
        Write-EFSLifecycleConfiguration -FileSystemId $efsFileSystem.FileSystemId -LifecyclePolicy $lifecyclePolicy
        Write-Host "✓ Lifecycle Policy configurada`n" -ForegroundColor Green
    }
    
    # ============================================
    # CREAR MOUNT TARGETS
    # ============================================
    
    Write-Host "Creando Mount Targets..." -ForegroundColor Yellow
    
    $existingMountTargets = Get-EFSMountTarget -FileSystemId $efsFileSystem.FileSystemId
    
    # Mount Target 1
    if (-not ($existingMountTargets | Where-Object { $_.SubnetId -eq $privateSubnet1Id })) {
        $mountTarget1 = New-EFSMountTarget -FileSystemId $efsFileSystem.FileSystemId -SubnetId $privateSubnet1Id -SecurityGroup @($efsSecurityGroup)
        Write-Host "✓ Mount Target 1 creado: $($mountTarget1.MountTargetId)" -ForegroundColor Green
    } else {
        Write-Host "✓ Mount Target 1 ya existe" -ForegroundColor Yellow
    }
    
    # Mount Target 2
    if (-not ($existingMountTargets | Where-Object { $_.SubnetId -eq $privateSubnet2Id })) {
        $mountTarget2 = New-EFSMountTarget -FileSystemId $efsFileSystem.FileSystemId -SubnetId $privateSubnet2Id -SecurityGroup @($efsSecurityGroup)
        Write-Host "✓ Mount Target 2 creado: $($mountTarget2.MountTargetId)" -ForegroundColor Green
    } else {
        Write-Host "✓ Mount Target 2 ya existe" -ForegroundColor Yellow
    }
    
    # Mount Target 3
    if (-not ($existingMountTargets | Where-Object { $_.SubnetId -eq $privateSubnet3Id })) {
        $mountTarget3 = New-EFSMountTarget -FileSystemId $efsFileSystem.FileSystemId -SubnetId $privateSubnet3Id -SecurityGroup @($efsSecurityGroup)
        Write-Host "✓ Mount Target 3 creado: $($mountTarget3.MountTargetId)" -ForegroundColor Green
    } else {
        Write-Host "✓ Mount Target 3 ya existe" -ForegroundColor Yellow
    }
    
    # ============================================
    # OUTPUTS FINALES
    # ============================================
    
    $region = (Get-DefaultAWSRegion).Region
    $efsFileSystemId = $efsFileSystem.FileSystemId
    $efsDNS = "${efsFileSystemId}.efs.${region}.amazonaws.com"
    
    Write-Host "`n============================================" -ForegroundColor Cyan
    Write-Host "DESPLIEGUE COMPLETADO EXITOSAMENTE" -ForegroundColor Cyan
    Write-Host "============================================`n" -ForegroundColor Cyan
    
    Write-Host "EFS Security Group ID: $efsSecurityGroup" -ForegroundColor White
    Write-Host "EFS File System ID: $efsFileSystemId" -ForegroundColor White
    Write-Host "EFS DNS Name: $efsDNS" -ForegroundColor White
    
    Write-Host "`n--- COMANDO DE MONTAJE PARA INSTANCIAS EC2 ---" -ForegroundColor Cyan
    
    $mountCommand = @"
# Instalar cliente NFS
sudo apt-get install -y nfs-common

# Crear directorio de montaje
sudo mkdir -p /mnt/efs

# Montar EFS
sudo mount -t nfs4 -o nfsvers=4.1,rsize=1048576,wsize=1048576,hard,timeo=600,retrans=2,noresvport ${efsDNS}:/ /mnt/efs

# Para montaje permanente, añadir a /etc/fstab:
echo "${efsDNS}:/ /mnt/efs nfs4 nfsvers=4.1,rsize=1048576,wsize=1048576,hard,timeo=600,retrans=2,noresvport,_netdev 0 0" | sudo tee -a /etc/fstab
"@
    
    Write-Host $mountCommand -ForegroundColor Gray
    
    $output = [PSCustomObject]@{
        EFSSecurityGroupId = $efsSecurityGroup
        EFSFileSystemId = $efsFileSystemId
        EFSFileSystemDNS = $efsDNS
        MountCommand = $mountCommand
        Region = $region
    }
    
    $outputFile = "EFS-Deployment-Output.json"
    $output | ConvertTo-Json -Depth 10 | Out-File -FilePath $outputFile -Encoding UTF8
    Write-Host "`n✓ Resultados guardados en: $outputFile" -ForegroundColor Green
    
    return $output
    
} catch {
    Write-Host "`n❌ ERROR: $($_.Exception.Message)" -ForegroundColor Red
    Write-Host "`nDetalles del error:" -ForegroundColor Yellow
    Write-Host $_.ScriptStackTrace -ForegroundColor Gray
    exit 1
}


