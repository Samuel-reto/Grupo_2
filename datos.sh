#!/bin/bash
# wait-config.sh - Espera INFINITA SNS/S3 → Configura monitor AUTOMÁTICO
set -e

echo "🔄 [$(date)] Esperando stack completo para SNS/S3..."
mkdir -p /var/log/wordpress-wait
LOG="/var/log/wordpress-wait-config.log"

# LOOP INFINITO - MÁX 30 MINUTOS (180 intentos x 10s)
MAX_TRIES=180
for i in $(seq 1 $MAX_TRIES); do
  echo "🔄 Intento $i/$MAX_TRIES - $(date)" | tee -a $LOG

  # CONFIGURAR AWS CLI
  aws configure set region us-east-1 2>/dev/null || true

  # AUTO-DETECT STACK NAME
  STACK_NAME=$(aws cloudformation describe-stacks \
    --query 'Stacks[?StackStatus==`CREATE_COMPLETE`].[StackName]' \
    --output text 2>/dev/null | head -1)

  if [ -z "$STACK_NAME" ]; then
    echo "❌ No stack CREATE_COMPLETE encontrado, reintentando..."
    sleep 10
    continue
  fi

  echo "✅ Stack detectado: $STACK_NAME"

  # OBTENER SNS + S3
  SNS_TOPIC_ARN=$(aws cloudformation describe-stacks \
    --stack-name "$STACK_NAME" \
    --query 'Stacks[0].Outputs[?OutputKey==`MonitoringSNSTopicArn`].OutputValue' \
    --output text 2>/dev/null)

  S3_BUCKET=$(aws cloudformation describe-stacks \
    --stack-name "$STACK_NAME" \
    --query 'Stacks[0].Outputs[?OutputKey==`ReportsBucketName`].OutputValue' \
    --output text 2>/dev/null)

  echo "SNS: '${SNS_TOPIC_ARN:-VACÍO}' | S3: '${S3_BUCKET:-VACÍO}'"

  # ✅ AMBOS OK? → CONFIGURAR Y SALIR
  if [ -n "$SNS_TOPIC_ARN" ] && [ -n "$S3_BUCKET" ] && [ "$SNS_TOPIC_ARN" != "None" ] && [ "$S3_BUCKET" != "None" ]; then
    echo "🎉 ✅ SNS+S3 encontrados en intento $i!"

    # ESCRIBIR config.env
    cat > /opt/wordpress-monitor/config.env << EOFCONFIG
export AWS_REGION="us-east-1"
export ASG_NAME="WordPress-ASG"
export SNS_TOPIC_ARN="$SNS_TOPIC_ARN"
export S3_BUCKET="$S3_BUCKET"
export STACK_NAME="$STACK_NAME"
EOFCONFIG

    # PERMISOS + PRIMER TEST
    chmod 644 /opt/wordpress-monitor/config.env
    chown ubuntu:ubuntu /opt/wordpress-monitor/config.env

    # EJECUTAR PRIMERA VEZ
    sudo -u ubuntu /opt/wordpress-monitor/run-monitor.sh || true

    echo "✅ config.env CREADO - $(date)" | tee -a $LOG
    echo "SNS: $SNS_TOPIC_ARN" | tee -a $LOG
    echo "S3:  $S3_BUCKET" | tee -a $LOG

    # CRON YA CONFIGURADO POR UserData
    echo "🎉 SISTEMA COMPLETO - CRON activo $(date)"
    exit 0
  fi

  echo "⏳ SNS/S3 no listos, reintentando en 10s..."
  sleep 10
done

echo 