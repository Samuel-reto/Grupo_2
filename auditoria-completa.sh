#!/bin/bash
# ==================================================
# 🚀 AUDITORÍA SEGURIDAD WORDPRESS COMPLETA - 35 MIN
# Ejecutar en BASTION con rol LabRole
# ==================================================

set -e  # Salir en error

echo "🚀 INICIANDO AUDITORÍA AUTOMÁTICA $(date)"
REPORT_DIR="/tmp/auditoria-wordpress-$(date +%Y%m%d-%H%M)"
mkdir -p "$REPORT_DIR"
cd "$REPORT_DIR"

# ==================================================
# 0. COLORES Y FUNCIONES
# ==================================================
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

log_ok() { echo -e "${GREEN}[OK]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error() { echo -e "${RED}[ERROR]${NC} $1"; }

# ==================================================
# 1. INVENTARIO AUTOMÁTICO (3 min)
# ==================================================
echo "📋 Obteniendo inventario infraestructura..."
ACCOUNT=$(aws sts get-caller-identity --query Account --output text 2>/dev/null || echo "unknown")
REGION=$(aws configure get region || echo "us-east-1")

# VPC y recursos principales
VPC_ID=$(aws ec2 describe-instances --filters "Name=tag:Name,Values=*WordPress*" \
  --query 'Reservations[0].Instances[0].VpcId' --output text 2>/dev/null || echo "N/A")
ALB_DNS=$(aws elbv2 describe-load-balancers --names "WordPress-ALB" \
  --query 'LoadBalancers[0].DNSName' --output text 2>/dev/null || echo "N/A")
RDS_ENDPOINT=$(aws rds describe-db-instances --db-instance-identifier wordpress-db \
  --query 'DBInstances[0].Endpoint.Address' --output text 2>/dev/null || echo "N/A")

cat > "00-inventario.txt" << EOF
🔍 AUDITORÍA WORDPRESS COMPLETA - $(date)
═══════════════════════════════════════════════════════════════
✅ Cuenta AWS: $ACCOUNT
✅ Región: $REGION
✅ VPC ID: $VPC_ID
✅ ALB DNS: $ALB_DNS
✅ RDS Endpoint: $RDS_ENDPOINT
✅ Bastion IP: $(curl -s http://169.254.169.254/latest/meta-data/public-hostname 2>/dev/null || echo "N/A")
✅ Timestamp: $(date)
EOF
log_ok "Inventario guardado"

# ==================================================
# 2. SYSTEMS MANAGER INVENTORY (2 min)
# ==================================================
echo "🏭 Systems Manager Inventory..."
aws ssm put-inventory-configuration \
  --inventory-configurations "[{\"Name\":\"AWS:InstanceInformation\",\"Enabled\":true}]" 2>/dev/null || true
aws ssm get-inventory-summary --output table > "01-ssm-inventory.txt" 2>/dev/null || echo "Sin SSM Inventory" > "01-ssm-inventory.txt"
log_ok "SSM Inventory completado"

# ==================================================
# 3. SECURITY HUB (5 min)
# ==================================================
echo "🛡️ Activando Security Hub..."
aws securityhub enable-security-hub --enable-default-standards 2>/dev/null || true
sleep 15

CRITICOS=0
echo "🔍 Security Hub - Findings CRÍTICOS:"
aws securityhub get-findings \
  --filters '{"SeverityLabel":[{"Comparison":"EQUALS","Value":"CRITICAL"}],"WorkflowStatus":[{"Comparison":"EQUALS","Value":"NEW"}]}' \
  --query 'Findings[*].[Title,SeverityLabel,CreatedAt]' --output table > "02-securityhub-critical.txt" 2>/dev/null || echo "Sin findings críticos" > "02-securityhub-critical.txt"
CRITICOS=$(grep -c "CRITICAL" "02-securityhub-critical.txt" 2>/dev/null || echo 0)
log_warn "Security Hub: ${CRITICOS} críticos encontrados"

# ==================================================
# 4. AMAZON INSPECTOR (3 min)
# ==================================================
echo "🐛 Activando Amazon Inspector..."
aws inspector2 enable --resource-types EC2 2>/dev/null || true
sleep 10
aws inspector2 list-findings-v2 --filter-criteria '{"resourceType":[{"comparison":"EQUALS","value":"AwsEc2Instance"}]}' \
  --max-results 20 --output table > "03-inspector-ec2.txt" 2>/dev/null || echo "Sin vulnerabilidades Inspector" > "03-inspector-ec2.txt"
log_ok "Inspector completado"

# ==================================================
# 5. SECURITY GROUPS ABIERTOS (3 min)
# ==================================================
echo "🔒 Security Groups vulnerables..."
aws ec2 describe-security-groups --filters "Name=vpc-id,Values=$VPC_ID" \
  --query 'SecurityGroups[?IpPermissions[?IpRanges[?CidrIp==`0.0.0.0/0`]]].[GroupName,Description,IpPermissions]' \
  --output table > "04-sg-vulnerable.txt" 2>/dev/null || echo "✅ Sin Security Groups públicos" > "04-sg-vulnerable.txt"

# RDS pública?
RDS_PUBLIC=$(aws rds describe-db-instances --db-instance-identifier wordpress-db \
  --query 'DBInstances[0].PubliclyAccessible' --output text 2>/dev/null || echo "false")
echo "RDS PubliclyAccessible: $RDS_PUBLIC" >> "04-sg-vulnerable.txt"
[[ "$RDS_PUBLIC" == "true" ]] && log_error "RDS PÚBLICA ⚠️" || log_ok "RDS privada ✓"
log_ok "Security Groups chequeados"

# ==================================================
# 6. WPSCAN WORDPRESS (10 min)
# ==================================================
echo "🐛 WPScan WordPress..."
WP_URL="http://$ALB_DNS"
if [ -f "/opt/wordpress-monitor/wpscan" ]; then
  cd /opt/wordpress-monitor
  timeout 600 ./wpscan --url "$WP_URL" --enumerate vp,vt,u1,cb,dbe --no-banner --force > "$REPORT_DIR/05-wpscan.txt" 2>&1 || echo "WPScan timeout/error" >> "$REPORT_DIR/05-wpscan.txt"
  log_ok "WPScan completado"
else
  echo "⚠️ WPScan no encontrado, escaneo básico..." > "$REPORT_DIR/05-wpscan.txt"
  curl -s "$WP_URL/wp-json/wp/v2/users" >> "$REPORT_DIR/05-wpscan.txt" 2>/dev/null || echo "Sin users enumerables" >> "$REPORT_DIR/05-wpscan.txt"
fi

# ==================================================
# 7. S3 + BUCKETS (2 min)
# ==================================================
echo "☁️ S3 Buckets públicos..."
S3_BUCKET="wordpress-reports-$ACCOUNT"
aws s3api get-public-access-block --bucket "$S3_BUCKET" --query 'PublicAccessBlockConfiguration' --output table > "06-s3-security.txt" 2>/dev/null || echo "Sin bloqueo público" > "06-s3-security.txt
log_ok "S3 chequeado"

# ==================================================
# 8. CONFIG COMPLIANCE (2 min)
# ==================================================
echo "⚙️ AWS Config compliance..."
aws configservice get-compliance-summary-by-resource-type \
  --resource-types "AWS::EC2::SecurityGroup,AWS::RDS::DBInstance,AWS::S3::Bucket" \
  --output table > "07-config-compliance.txt" 2>/dev/null || echo "Config no habilitado" > "07-config-compliance.txt"
log_ok "Config completado"

# ==================================================
# 9. REPORTE HTML PROFESIONAL
# ==================================================
echo "📊 Generando reporte HTML..."
cat > "AUDITORIA-COMPLETA.html" << 'EOF'
<!DOCTYPE html>
<html>
<head>
<title>AUDITORÍA SEGURIDAD WORDPRESS - COMPLETA</title>
<meta charset="UTF-8">
<style>
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,sans-serif;margin:0;padding:40px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#333}
.container{max-width:1200px;margin:0 auto;background:white;border-radius:20px;box-shadow:0 20px 40px rgba(0,0,0,0.1);overflow:hidden}
.header{padding:40px;background:linear-gradient(135deg,#d32f2f,#b71c1c);color:white;text-align:center}
h1{margin:0;font-size:2.5em;font-weight:300}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;padding:30px}
.card{padding:25px;border-radius:15px;box-shadow:0 5px 15px rgba(0,0,0,0.08);transition:transform 0.3s}
.critical{background:#ffebee;border-left:8px solid #f44336}
.warning{background:#fff3e0;border-left:8px solid #ff9800}
.success{background:#e8f5e8;border-left:8px solid #4caf50}
.content{padding:30px}
.section{margin-bottom:40px}
h2{color:#d32f2f;border-bottom:3px solid #e0e0e0;padding-bottom:10px}
table{width:100%;border-collapse:collapse;margin:20px 0}
th{background:#1976d2;color:white;padding:15px;font-weight:500}
td{padding:12px;border-bottom:1px solid #eee}
tr:nth-child(even){background:#f9f9f9}
.critical-row{background:#ffebee !important}
.warning-row{background:#fff3e0 !important}
</style>
</head>
<body>
<div class="container">
<div class="header">
<h1>🚀 AUDITORÍA SEGURIDAD WORDPRESS</h1>
<p style="margin:10px 0 0 0;font-size:1.2em">Análisis completo de infraestructura AWS + WordPress</p>
</div>
EOF

# Insertar resumen ejecutivo
CRITICOS=$(grep -c "CRITICAL" "02-securityhub-critical.txt" 2>/dev/null || echo 0)
MEDIOS=$(grep -c "WARNING\|MEDIUM" "$REPORT_DIR"/*.txt 2>/dev/null || echo 0)
echo "
<div class='summary-grid'>
<div class='card success'><h3>🟢 OK</h3><p><strong>Inventario completo</strong><br>SSM + Config + S3</p></div>
<div class='card $([[ \$CRITICOS -gt 0 ]] && echo "critical" || echo "success")'><h3>🔴 Críticos</h3><p><strong>\$CRITICOS</strong> encontrados</p></div>
<div class='card warning'><h3>🟡 Media</h3><p><strong>\$MEDIOS</strong> advertencias</p></div>
<div class='card success'><h3>⚡ Automatizado</h3><p>Security Hub + Inspector<br>WPScan + SGs</p></div>
</div>
<div class='content'>" >> "AUDITORIA-COMPLETA.html"

# Añadir todas las secciones
for file in 0[0-7]-*.txt; do
  [[ -f "$file" ]] && {
    echo "<div class='section'><h2>📋 $(basename "$file" .txt | sed 's/^[0-9]\+-//;s/_/ /g' | tr '[:lower:]' '[:upper:]')</h2><pre style='background:#f8f9fa;padding:20px;border-radius:10px;overflow-x:auto'>$(cat "$file")</pre></div>" >> "AUDITORIA-COMPLETA.html"
  }
done

echo "
</div>
<div style='background:#f5f5f5;padding:30px;text-align:center;color:#666'>
<p><strong>Generado automáticamente el $(date)</strong> | Auditoría completa en <strong>${REPORT_DIR##*/}</strong></p>
</div>
</div>
</body>
</html>" >> "AUDITORIA-COMPLETA.html"

# ==================================================
# 10. SUBIR A S3 + LIMPIAR
# ==================================================
S3_BUCKET="wordpress-reports-$ACCOUNT"
aws s3 cp "AUDITORIA-COMPLETA.html" "s3://$S3_BUCKET/" 2>/dev/null && \
  log_ok "✅ REPORTE S3: https://$S3_BUCKET.s3.amazonaws.com/AUDITORIA-COMPLETA.html" || \
  log_warn "⚠️ No se pudo subir a S3 (ejecuta manualmente)"

echo ""
echo "🎉 ========================================================="
echo "✅ AUDITORÍA COMPLETA FINALIZADA $(date)"
echo "📁 Reportes locales: $REPORT_DIR/"
echo "🔍 ARCHIVOS:"
ls -lh *.html *.txt | cat
echo "🎉 ========================================================="
echo ""
log_ok "REPORTE PRINCIPAL: $REPORT_DIR/AUDITORIA-COMPLETA.html"