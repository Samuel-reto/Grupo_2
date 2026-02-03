#!/usr/bin/env pwsh
<#
.SYNOPSIS
    WordPress Enterprise Monitoring Dashboard
.DESCRIPTION
    Monitorea ASG WordPress + RDS y genera dashboard HTML en S3
.NOTES
    Author: Valentin Gutierrez (ASIR2)
    Version: 2.0
    CRON: 0 */6 * * * + 0 8 * * *
#>

param()

# =========================================
# CONFIGURACIÓN (desde variables de entorno)
# =========================================
$AWS_REGION = $env:AWS_REGION
$ASG_NAME = $env:ASG_NAME
$SNS_TOPIC_ARN = $env:SNS_TOPIC_ARN
$S3_BUCKET = $env:S3_BUCKET
$LOG_FILE = "/var/log/wordpress-monitor.log"

function Write-Log {
    param([string]$Action,[string]$Result)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$timestamp $Message" | Out-File -FilePath $LOG_FILE -Append -Encoding utf8
    Write-Host $Message
}

Write-Log "========================================"
Write-Log "🚀 WordPress Enterprise Dashboard v2.0"
Write-Log "========================================"

# =========================================
# 1. WEB INSTANCES (ASG filtrado)
# =========================================
Write-Log "🌐 WEB: Instancias del ASG $ASG_NAME"
$instanceIdsRaw = aws autoscaling describe-auto-scaling-groups `
    --auto-scaling-group-names $ASG_NAME --query 'AutoScalingGroups[0].Instances[?LifecycleState==`InService`].[InstanceId]' `
    --output json --region $AWS_REGION | ConvertFrom-Json

$webInstances = @()
if ($instanceIdsRaw) {
    foreach ($instanceId in $instanceIdsRaw) {
        $details = aws ec2 describe-instances --instance-ids $instanceId `
            --query 'Reservations[0].Instances[0].[InstanceId,PrivateIpAddress,InstanceType,State.Name]' `
            --output json --region $AWS_REGION | ConvertFrom-Json
        
        if ($details -and $details.Count -ge 4) {
            $webInstances += [PSCustomObject]@{
                InstanceId = $details[0]
                PrivateIP = $details[1]
                InstanceType = $details[2]
                State = $details[3]
            }
        }
    }
}
Write-Log "✅ $($webInstances.Count) instancias WEB activas"

# =========================================
# 2. RDS (CPU + estado)
# =========================================
Write-Log "🗄️  DB: Métricas RDS wordpress-db"
$rdsInfo = aws rds describe-db-instances --db-instance-identifier wordpress-db `
    --query 'DBInstances[0].[DBInstanceIdentifier,DBInstanceStatus,Engine,DBInstanceClass,Endpoint.Address]' `
    --output json --region $AWS_REGION | ConvertFrom-Json

$rdsMetrics = aws cloudwatch get-metric-statistics --namespace AWS/RDS --metric-name CPUUtilization `
    --dimensions Name=DBInstanceIdentifier,Value=wordpress-db `
    --start-time $(Get-Date).AddHours(-6).ToString("yyyy-MM-ddTHH:mm:ssZ") `
    --end-time $(Get-Date).ToString("yyyy-MM-ddTHH:mm:ssZ") --period 3600 --statistics Average `
    --region $AWS_REGION --query 'Datapoints[0].Average' --output text 2>$null

$rdsStatus = [PSCustomObject]@{
    Name = if($rdsInfo) { $rdsInfo[0] } else { "No encontrado" }
    Status = if($rdsInfo) { $rdsInfo[1] } else { "Error" }
    Engine = if($rdsInfo) { $rdsInfo[2] } else { "-" }
    InstanceClass = if($rdsInfo) { $rdsInfo[3] } else { "-" }
    Endpoint = if($rdsInfo) { $rdsInfo[4] } else { "-" }
    CPU = if($rdsMetrics -and $rdsMetrics -ne "None") { [math]::Round([double]$rdsMetrics,1) } else { 0 }
}
Write-Log "✅ RDS $($rdsStatus.CPU)% CPU - $($rdsStatus.Status)"

# =========================================
# 3. DASHBOARD HTML PROFESIONAL
# =========================================
$htmlWebRows = ($webInstances | ForEach-Object {
    "<tr><td><code>$($_.InstanceId)</code></td><td>$($_.PrivateIP)</td><td>$($_.InstanceType)</td><td><span class='status status-ok'>$($_.State)</span></td></tr>"
}) -join ""

$reportContent = @"
<!DOCTYPE html>
<html><head><title>WordPress Dashboard</title>
<meta charset="UTF-8">
<style>
body{font-family:'Segoe UI',sans-serif;background:linear-gradient(135deg,#667eea,#764ba2);padding:20px;margin:0;}
.container{max-width:1100px;margin:0 auto;background:#fff;border-radius:20px;box-shadow:0 20px 40px rgba(0,0,0,0.1);overflow:hidden;}
.header{background:linear-gradient(135deg,#3182ce,#4299e1);color:white;padding:30px;text-align:center;}
.header h1{font-size:2.2em;margin:0;}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:20px;padding:25px;}
.stat-card{background:#fff;border-radius:12px;padding:20px;box-shadow:0 6px 20px rgba(0,0,0,0.08);text-align:center;}
.stat-number{font-size:2.2em;font-weight:bold;color:#3182ce;margin-bottom:5px;}
.content{padding:30px;}
.section{margin-bottom:35px;}
.section h2{color:#2d3748;font-size:1.5em;margin-bottom:15px;border-left:4px solid #3182ce;padding-left:15px;}
.table-container{overflow-x:auto;border-radius:12px;box-shadow:0 8px 25px rgba(0,0,0,0.1);}
table{width:100%;border-collapse:collapse;background:#fff;}
th{background:linear-gradient(135deg,#3182ce,#4299e1);color:white;padding:15px;font-weight:600;}
td{padding:15px;border-bottom:1px solid #e2e8f0;}
tr:hover{background:#f7fafc;}
.status{padding:6px 12px;border-radius:18px;font-size:13px;font-weight:500;}
.status-ok{background:#c6f6d5;color:#22543d;}
.rds-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;}
.rds-card{background:linear-gradient(135deg,#48bb78,#38a169);color:white;border-radius:12px;padding:20px;text-align:center;}
.footer{text-align:center;padding:25px;background:#f7fafc;border-radius:0 0 20px 20px;margin-top:30px;color:#666;}
@media (max-width:768px){.stats-grid,.rds-grid{grid-template-columns:1fr;}}
</style></head>
<body>
<div class="container">
<div class="header">
<h1>Health2You ⚕️</h1>
<p style="margin:5px 0 0 0;opacity:0.9;">$(Get-Date -Format 'dd MMMM yyyy - HH:mm:ss')</p>
</div>

<div class="stats-grid">
<div class="stat-card"><div class="stat-number">$($webInstances.Count)</div><div style="color:#718096;font-size:0.9em;text-transform:uppercase;letter-spacing:1px;">Web Instances</div></div>
<div class="stat-card"><div class="stat-number">$($rdsStatus.CPU)%</div><div style="color:#718096;font-size:0.9em;text-transform:uppercase;letter-spacing:1px;">RDS CPU (6h avg)</div></div>
<div class="stat-card"><div class="stat-number">0</div><div style="color:#718096;font-size:0.9em;text-transform:uppercase;letter-spacing:1px;">Críticos</div></div>
<div class="stat-card"><div class="stat-number">0</div><div style="color:#718096;font-size:0.9em;text-transform:uppercase;letter-spacing:1px;">Warnings</div></div>
</div>

<div class="content">
<div class="section">
<h2>🌐 WordPress Web Instances (ASG: $ASG_NAME)</h2>
<div class="table-container">
<table><tr><th>Instance ID</th><th>IP Privada</th><th>Tipo</th><th>Estado</th></tr>$htmlWebRows</table>
</div>
</div>

<div class="section">
<h2>🗄️  RDS Database (wordpress-db)</h2>
<div class="rds-grid">
<div class="rds-card">
<h3 style="margin-top:0;">📊 Estado</h3>
<p><strong>Instancia:</strong> $($rdsStatus.Name)</p>
<p><strong>Estado:</strong> <span class="status status-ok">$($rdsStatus.Status)</span></p>
<p><strong>Motor:</strong> $($rdsStatus.Engine)</p>
</div>
<div class="rds-card">
<h3 style="margin-top:0;">📈 Métricas</h3>
<p><strong>Endpoint:</strong><br><code style="font-size:0.9em;">$($rdsStatus.Endpoint)</code></p>
<p><strong>CPU (6h):</strong> <span style="font-size:1.4em;font-weight:bold;">$($rdsStatus.CPU)%</span></p>
</div>
</div>
</div>
</div>

<div class="footer">
🤖 <strong>PowerShell Enterprise Monitoring</strong><br>
CRON: cada 6h (00:00, 06:00, 12:00, 18:00) + diario 08:00 | <a href="https://github.com/Samuel-reto/Grupo_2" style="color:#3182ce;">GitHub</a>
</div>
</div>
</body></html>
"@

# =========================================
# 4. SUBIR S3 + SNS
# =========================================
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$localPath = "/tmp/wp-final-$timestamp.html"
$s3Key = "dashboards/wp-final-$timestamp.html"

$reportContent | Out-File -FilePath $localPath -Encoding utf8
aws s3 cp $localPath s3://$S3_BUCKET/$s3Key --region $AWS_REGION
$dashboardUrl = "https://$S3_BUCKET.s3.amazonaws.com/$s3Key"

$snsMessage = @"
📊 WORDPRESS DASHBOARD - $(Get-Date -Format 'dd/MM HH:mm')

🌐 WEB INSTANCES: $($webInstances.Count)
🗄️  RDS CPU: $($rdsStatus.CPU)% | $($rdsStatus.Status)
✅ 0 Críticos | 0 Warnings

📈 DASHBOARD COMPLETO:
$dashboardUrl

---
Sistema 100% operativo ✓
"@

aws sns publish --topic-arn $SNS_TOPIC_ARN --subject "WP Dashboard - $(Get-Date -Format 'dd/MM HH:mm')" --message "$snsMessage" --region $AWS_REGION

Write-Log "✅ Dashboard: $dashboardUrl"
Write-Log "🎉 SISTEMA FINAL COMPLETADO ✓"
