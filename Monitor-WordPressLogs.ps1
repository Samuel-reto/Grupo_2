#!/usr/bin/env pwsh
<#
.SYNOPSIS
    WordPress Enterprise Monitoring Dashboard v3.0
.DESCRIPTION
    Monitorea ASG WordPress + RDS + ALB y genera dashboard HTML en S3
    ✨ AUTO-DETECTA RDS y ALB - NO requiere modificar config.env
.NOTES
    Author: Valentin Gutierrez (ASIR2)
    Version: 3.0 - Auto-detección de recursos
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
$STACK_NAME = if($env:STACK_NAME) { $env:STACK_NAME } else { "grupo2" }
$LOG_FILE = "/var/log/wordpress-monitor.log"

function Write-Log {
    param([string]$Message)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    "$timestamp $Message" | Out-File -FilePath $LOG_FILE -Append -Encoding utf8
    Write-Host $Message
}

Write-Log "========================================"
Write-Log "🚀 WordPress Enterprise Dashboard v3.0"
Write-Log "========================================"

# =========================================
# 0. AUTO-DETECTAR RDS Y ALB
# =========================================
Write-Log "🔍 Auto-detectando recursos AWS..."

# Detectar RDS del stack
$rdsInstanceId = aws cloudformation describe-stack-resources `
    --stack-name $STACK_NAME `
    --query 'StackResources[?ResourceType==`AWS::RDS::DBInstance`].PhysicalResourceId' `
    --output text --region $AWS_REGION 2>$null

if (-not $rdsInstanceId -or $rdsInstanceId -eq "") {
    # Fallback: buscar cualquier RDS con "wordpress" en el nombre
    $rdsInstanceId = aws rds describe-db-instances `
        --query 'DBInstances[?contains(DBInstanceIdentifier,`wordpress`)].DBInstanceIdentifier' `
        --output text --region $AWS_REGION 2>$null
    if ($rdsInstanceId) {
        $rdsInstanceId = $rdsInstanceId.Split()[0]  # Tomar el primero si hay varios
    }
}

if (-not $rdsInstanceId) {
    $rdsInstanceId = "wordpress-db"  # Default
}
Write-Log "✅ RDS detectado: $rdsInstanceId"

# Detectar ALB del stack
$albArn = aws cloudformation describe-stack-resources `
    --stack-name $STACK_NAME `
    --query 'StackResources[?ResourceType==`AWS::ElasticLoadBalancingV2::LoadBalancer`].PhysicalResourceId' `
    --output text --region $AWS_REGION 2>$null

if (-not $albArn -or $albArn -eq "") {
    # Fallback: buscar ALB con "wordpress" o el nombre del stack
    $albArn = aws elbv2 describe-load-balancers `
        --query "LoadBalancers[?contains(LoadBalancerName,'WordPress') || contains(LoadBalancerName,'$STACK_NAME')].LoadBalancerArn" `
        --output text --region $AWS_REGION 2>$null
    if ($albArn) {
        $albArn = $albArn.Split()[0]  # Tomar el primero
    }
}

if ($albArn) {
    Write-Log "✅ ALB detectado: $albArn"
} else {
    Write-Log "⚠️  ALB no encontrado - métricas de ALB no disponibles"
}

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
            --query 'Reservations[0].Instances[0].[InstanceId,PrivateIpAddress,InstanceType,State.Name,LaunchTime]' `
            --output json --region $AWS_REGION | ConvertFrom-Json

        if ($details -and $details.Count -ge 4) {
            $webInstances += [PSCustomObject]@{
                InstanceId = $details[0]
                PrivateIP = $details[1]
                InstanceType = $details[2]
                State = $details[3]
                LaunchTime = if($details.Count -ge 5) { $details[4] } else { $null }
            }
        }
    }
}
Write-Log "✅ $($webInstances.Count) instancias WEB activas"

# =========================================
# 2. RDS (Métricas expandidas)
# =========================================
Write-Log "🗄️  DB: Métricas RDS $rdsInstanceId"

# Info básica RDS
$rdsInfo = aws rds describe-db-instances --db-instance-identifier $rdsInstanceId `
    --query 'DBInstances[0].[DBInstanceIdentifier,DBInstanceStatus,Engine,EngineVersion,DBInstanceClass,Endpoint.Address,Endpoint.Port,AllocatedStorage,MultiAZ,AvailabilityZone]' `
    --output json --region $AWS_REGION 2>$null | ConvertFrom-Json

# Métricas CloudWatch (últimas 6 horas)
$endTime = Get-Date
$startTime = $endTime.AddHours(-6)

$rdsCPU = aws cloudwatch get-metric-statistics --namespace AWS/RDS --metric-name CPUUtilization `
    --dimensions Name=DBInstanceIdentifier,Value=$rdsInstanceId `
    --start-time $startTime.ToString("yyyy-MM-ddTHH:mm:ssZ") `
    --end-time $endTime.ToString("yyyy-MM-ddTHH:mm:ssZ") --period 3600 --statistics Average `
    --region $AWS_REGION --query 'Datapoints[0].Average' --output text 2>$null

$rdsConnections = aws cloudwatch get-metric-statistics --namespace AWS/RDS --metric-name DatabaseConnections `
    --dimensions Name=DBInstanceIdentifier,Value=$rdsInstanceId `
    --start-time $startTime.ToString("yyyy-MM-ddTHH:mm:ssZ") `
    --end-time $endTime.ToString("yyyy-MM-ddTHH:mm:ssZ") --period 3600 --statistics Average `
    --region $AWS_REGION --query 'Datapoints[0].Average' --output text 2>$null

$rdsFreeStorage = aws cloudwatch get-metric-statistics --namespace AWS/RDS --metric-name FreeStorageSpace `
    --dimensions Name=DBInstanceIdentifier,Value=$rdsInstanceId `
    --start-time $startTime.ToString("yyyy-MM-ddTHH:mm:ssZ") `
    --end-time $endTime.ToString("yyyy-MM-ddTHH:mm:ssZ") --period 3600 --statistics Average `
    --region $AWS_REGION --query 'Datapoints[0].Average' --output text 2>$null

$rdsReadLatency = aws cloudwatch get-metric-statistics --namespace AWS/RDS --metric-name ReadLatency `
    --dimensions Name=DBInstanceIdentifier,Value=$rdsInstanceId `
    --start-time $startTime.ToString("yyyy-MM-ddTHH:mm:ssZ") `
    --end-time $endTime.ToString("yyyy-MM-ddTHH:mm:ssZ") --period 3600 --statistics Average `
    --region $AWS_REGION --query 'Datapoints[0].Average' --output text 2>$null

$rdsStatus = [PSCustomObject]@{
    Name = if($rdsInfo) { $rdsInfo[0] } else { "No encontrado" }
    Status = if($rdsInfo) { $rdsInfo[1] } else { "Error" }
    Engine = if($rdsInfo) { "$($rdsInfo[2]) $($rdsInfo[3])" } else { "-" }
    InstanceClass = if($rdsInfo) { $rdsInfo[4] } else { "-" }
    Endpoint = if($rdsInfo) { "$($rdsInfo[5]):$($rdsInfo[6])" } else { "-" }
    Storage = if($rdsInfo) { "$($rdsInfo[7]) GB" } else { "-" }
    MultiAZ = if($rdsInfo) { if($rdsInfo[8] -eq $true) { "Sí" } else { "No" } } else { "-" }
    AZ = if($rdsInfo) { $rdsInfo[9] } else { "-" }
    CPU = if($rdsCPU -and $rdsCPU -ne "None") { [math]::Round([double]$rdsCPU,1) } else { 0 }
    Connections = if($rdsConnections -and $rdsConnections -ne "None") { [math]::Round([double]$rdsConnections,0) } else { 0 }
    FreeStorageGB = if($rdsFreeStorage -and $rdsFreeStorage -ne "None") { [math]::Round([double]$rdsFreeStorage/1024/1024/1024,2) } else { 0 }
    ReadLatencyMs = if($rdsReadLatency -and $rdsReadLatency -ne "None") { [math]::Round([double]$rdsReadLatency*1000,2) } else { 0 }
}
Write-Log "✅ RDS: $($rdsStatus.CPU)% CPU | $($rdsStatus.Connections) conexiones | $($rdsStatus.Status)"

# =========================================
# 3. ALB (Application Load Balancer)
# =========================================
$albStatus = $null
$targetHealth = @()
$htmlTargetRows = ""

if ($albArn) {
    Write-Log "⚖️  ALB: Métricas del balanceador"

    # Info básica ALB
    $albInfo = aws elbv2 describe-load-balancers `
        --load-balancer-arns $albArn `
        --query 'LoadBalancers[0].[LoadBalancerName,DNSName,State.Code,Scheme,VpcId]' `
        --output json --region $AWS_REGION 2>$null | ConvertFrom-Json

    # Target Health
    $targetGroupArn = aws elbv2 describe-target-groups `
        --load-balancer-arn $albArn `
        --query 'TargetGroups[0].TargetGroupArn' `
        --output text --region $AWS_REGION 2>$null

    if ($targetGroupArn) {
        $targetHealth = aws elbv2 describe-target-health `
            --target-group-arn $targetGroupArn `
            --query 'TargetHealthDescriptions[*].[Target.Id,TargetHealth.State]' `
            --output json --region $AWS_REGION 2>$null | ConvertFrom-Json
    }

    $healthyTargets = ($targetHealth | Where-Object { $_[1] -eq "healthy" }).Count
    $totalTargets = $targetHealth.Count

    # Métricas CloudWatch ALB
    $albShortArn = $albArn -replace 'arn:aws:elasticloadbalancing:[^:]+:[^:]+:loadbalancer/',''

    $albRequestCount = aws cloudwatch get-metric-statistics --namespace AWS/ApplicationELB --metric-name RequestCount `
        --dimensions Name=LoadBalancer,Value=$albShortArn `
        --start-time $startTime.ToString("yyyy-MM-ddTHH:mm:ssZ") `
        --end-time $endTime.ToString("yyyy-MM-ddTHH:mm:ssZ") --period 3600 --statistics Sum `
        --region $AWS_REGION --query 'Datapoints[0].Sum' --output text 2>$null

    $albTargetResponseTime = aws cloudwatch get-metric-statistics --namespace AWS/ApplicationELB --metric-name TargetResponseTime `
        --dimensions Name=LoadBalancer,Value=$albShortArn `
        --start-time $startTime.ToString("yyyy-MM-ddTHH:mm:ssZ") `
        --end-time $endTime.ToString("yyyy-MM-ddTHH:mm:ssZ") --period 3600 --statistics Average `
        --region $AWS_REGION --query 'Datapoints[0].Average' --output text 2>$null

    $albHTTP5XX = aws cloudwatch get-metric-statistics --namespace AWS/ApplicationELB --metric-name HTTPCode_Target_5XX_Count `
        --dimensions Name=LoadBalancer,Value=$albShortArn `
        --start-time $startTime.ToString("yyyy-MM-ddTHH:mm:ssZ") `
        --end-time $endTime.ToString("yyyy-MM-ddTHH:mm:ssZ") --period 3600 --statistics Sum `
        --region $AWS_REGION --query 'Datapoints[0].Sum' --output text 2>$null

    $albStatus = [PSCustomObject]@{
        Name = if($albInfo) { $albInfo[0] } else { "No encontrado" }
        DNS = if($albInfo) { $albInfo[1] } else { "-" }
        State = if($albInfo) { $albInfo[2] } else { "Error" }
        Scheme = if($albInfo) { $albInfo[3] } else { "-" }
        HealthyTargets = $healthyTargets
        TotalTargets = $totalTargets
        RequestCount = if($albRequestCount -and $albRequestCount -ne "None") { [math]::Round([double]$albRequestCount,0) } else { 0 }
        ResponseTimeMs = if($albTargetResponseTime -and $albTargetResponseTime -ne "None") { [math]::Round([double]$albTargetResponseTime*1000,2) } else { 0 }
        HTTP5XX = if($albHTTP5XX -and $albHTTP5XX -ne "None") { [math]::Round([double]$albHTTP5XX,0) } else { 0 }
    }

    # Generar filas HTML para target health
    $htmlTargetRows = ($targetHealth | ForEach-Object {
        $statusClass = if($_[1] -eq "healthy") { "status-ok" } else { "status-error" }
        "<tr><td><code>$($_[0])</code></td><td><span class='status $statusClass'>$($_[1])</span></td></tr>"
    }) -join ""

    Write-Log "✅ ALB: $($albStatus.HealthyTargets)/$($albStatus.TotalTargets) targets sanos | $($albStatus.ResponseTimeMs)ms resp"
} else {
    Write-Log "⚠️  ALB no detectado - dashboard sin métricas de balanceo"
}

# =========================================
# 4. DASHBOARD HTML PROFESIONAL v3.0
# =========================================
$htmlWebRows = ($webInstances | ForEach-Object {
    $uptime = if($_.LaunchTime) {
        try {
            $diff = (Get-Date) - [DateTime]::Parse($_.LaunchTime)
            "$($diff.Days)d $($diff.Hours)h"
        } catch {
            "-"
        }
    } else { "-" }
    "<tr><td><code>$($_.InstanceId)</code></td><td>$($_.PrivateIP)</td><td>$($_.InstanceType)</td><td><span class='status status-ok'>$($_.State)</span></td><td>$uptime</td></tr>"
}) -join ""

# Sección ALB (solo si existe)
$albSection = ""
if ($albStatus) {
    $albSection = @"
<div class="section">
<h2>⚖️ Application Load Balancer</h2>
<div class="alb-card">
<h3 style="margin-top:0;">$($albStatus.Name)</h3>
<p><strong>DNS:</strong> <code style="font-size:0.9em;background:rgba(255,255,255,0.2);padding:4px 8px;border-radius:4px;">$($albStatus.DNS)</code></p>
<p><strong>Estado:</strong> $($albStatus.State) | <strong>Scheme:</strong> $($albStatus.Scheme)</p>
<div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-top:15px;">
<div style="background:rgba(255,255,255,0.2);padding:10px;border-radius:8px;"><div style="font-size:1.5em;font-weight:bold;">$($albStatus.RequestCount)</div><div style="font-size:0.8em;">Requests (6h)</div></div>
<div style="background:rgba(255,255,255,0.2);padding:10px;border-radius:8px;"><div style="font-size:1.5em;font-weight:bold;">$($albStatus.ResponseTimeMs)ms</div><div style="font-size:0.8em;">Avg Response</div></div>
<div style="background:rgba(255,255,255,0.2);padding:10px;border-radius:8px;"><div style="font-size:1.5em;font-weight:bold;">$($albStatus.HTTP5XX)</div><div style="font-size:0.8em;">5XX Errors</div></div>
</div>
</div>

<h3 style="margin-top:20px;color:#2d3748;">Target Group Health</h3>
<div class="table-container">
<table><tr><th>Target ID</th><th>Estado</th></tr>$htmlTargetRows</table>
</div>
</div>
"@
}

# Stats grid adaptativos
$statsCards = @"
<div class="stat-card"><div class="stat-number">$($webInstances.Count)</div><div style="color:#718096;font-size:0.9em;text-transform:uppercase;letter-spacing:1px;">Web Instances</div></div>
<div class="stat-card"><div class="stat-number">$($rdsStatus.CPU)%</div><div style="color:#718096;font-size:0.9em;text-transform:uppercase;letter-spacing:1px;">RDS CPU</div></div>
<div class="stat-card"><div class="stat-number">$($rdsStatus.Connections)</div><div style="color:#718096;font-size:0.9em;text-transform:uppercase;letter-spacing:1px;">DB Connections</div></div>
"@

if ($albStatus) {
    $statsCards += @"
<div class="stat-card"><div class="stat-number">$($albStatus.HealthyTargets)/$($albStatus.TotalTargets)</div><div style="color:#718096;font-size:0.9em;text-transform:uppercase;letter-spacing:1px;">Healthy Targets</div></div>
<div class="stat-card"><div class="stat-number">$($albStatus.ResponseTimeMs)ms</div><div style="color:#718096;font-size:0.9em;text-transform:uppercase;letter-spacing:1px;">ALB Response</div></div>
<div class="stat-card"><div class="stat-number">$($albStatus.HTTP5XX)</div><div style="color:#718096;font-size:0.9em;text-transform:uppercase;letter-spacing:1px;">5XX Errors</div></div>
"@
}

$reportContent = @"
<!DOCTYPE html>
<html><head><title>WordPress Dashboard v3.0</title>
<meta charset="UTF-8">
<style>
body{font-family:'Segoe UI',sans-serif;background:linear-gradient(135deg,#667eea,#764ba2);padding:20px;margin:0;}
.container{max-width:1200px;margin:0 auto;background:#fff;border-radius:20px;box-shadow:0 20px 40px rgba(0,0,0,0.1);overflow:hidden;}
.header{background:linear-gradient(135deg,#3182ce,#4299e1);color:white;padding:30px;text-align:center;}
.header h1{font-size:2.2em;margin:0;}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:20px;padding:25px;}
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
.status-error{background:#fed7d7;color:#742a2a;}
.metrics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-top:15px;}
.metric-box{background:linear-gradient(135deg,#48bb78,#38a169);color:white;border-radius:12px;padding:20px;text-align:center;}
.metric-value{font-size:2em;font-weight:bold;margin-bottom:5px;}
.alb-card{background:linear-gradient(135deg,#ed8936,#dd6b20);color:white;border-radius:12px;padding:20px;margin-bottom:20px;}
.footer{text-align:center;padding:25px;background:#f7fafc;border-radius:0 0 20px 20px;margin-top:30px;color:#666;}
@media (max-width:768px){.stats-grid,.metrics-grid{grid-template-columns:1fr;}}
</style></head>
<body>
<div class="container">
<div class="header">
<h1>🏥 Health2You Dashboard v3.0</h1>
<p style="margin:5px 0 0 0;opacity:0.9;">$(Get-Date -Format 'dd MMMM yyyy - HH:mm:ss')</p>
</div>

<div class="stats-grid">
$statsCards
</div>

<div class="content">
$albSection

<div class="section">
<h2>🌐 WordPress Web Instances (ASG: $ASG_NAME)</h2>
<div class="table-container">
<table><tr><th>Instance ID</th><th>IP Privada</th><th>Tipo</th><th>Estado</th><th>Uptime</th></tr>$htmlWebRows</table>
</div>
</div>

<div class="section">
<h2>🗄️ RDS Database ($($rdsStatus.Name))</h2>
<div style="background:#f7fafc;border-radius:12px;padding:20px;margin-bottom:15px;">
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:15px;">
<div><strong>Instancia:</strong> $($rdsStatus.Name)</div>
<div><strong>Estado:</strong> <span class="status status-ok">$($rdsStatus.Status)</span></div>
<div><strong>Motor:</strong> $($rdsStatus.Engine)</div>
<div><strong>Clase:</strong> $($rdsStatus.InstanceClass)</div>
<div><strong>Storage:</strong> $($rdsStatus.Storage)</div>
<div><strong>Multi-AZ:</strong> $($rdsStatus.MultiAZ)</div>
<div style="grid-column:1/-1;"><strong>Endpoint:</strong><br><code style="background:#fff;padding:8px;border-radius:4px;display:inline-block;margin-top:5px;">$($rdsStatus.Endpoint)</code></div>
</div>
</div>

<h3 style="color:#2d3748;margin-top:20px;">📊 Métricas (últimas 6 horas)</h3>
<div class="metrics-grid">
<div class="metric-box">
<div class="metric-value">$($rdsStatus.CPU)%</div>
<div style="font-size:0.9em;opacity:0.9;">CPU Utilization</div>
</div>
<div class="metric-box">
<div class="metric-value">$($rdsStatus.Connections)</div>
<div style="font-size:0.9em;opacity:0.9;">DB Connections</div>
</div>
<div class="metric-box">
<div class="metric-value">$($rdsStatus.FreeStorageGB) GB</div>
<div style="font-size:0.9em;opacity:0.9;">Free Storage</div>
</div>
<div class="metric-box">
<div class="metric-value">$($rdsStatus.ReadLatencyMs) ms</div>
<div style="font-size:0.9em;opacity:0.9;">Read Latency</div>
</div>
</div>
</div>
</div>

<div class="footer">
🤖 <strong>PowerShell Enterprise Monitoring v3.0</strong> - Auto-detección de recursos<br>
CRON: cada 6h (00:00, 06:00, 12:00, 18:00) + diario 08:00 | <a href="https://github.com/Samuel-reto/Grupo_2" style="color:#3182ce;">GitHub</a>
</div>
</div>
</body></html>
"@

# =========================================
# 5. SUBIR S3 + SNS
# =========================================
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$localPath = "/tmp/wp-dashboard-$timestamp.html"
$s3Key = "dashboards/wp-dashboard-$timestamp.html"

$reportContent | Out-File -FilePath $localPath -Encoding utf8
aws s3 cp $localPath s3://$S3_BUCKET/$s3Key --region $AWS_REGION
$dashboardUrl = "https://$S3_BUCKET.s3.amazonaws.com/$s3Key"

# Mensaje SNS adaptativo
$snsMessage = @"
📊 WORDPRESS DASHBOARD v3.0 - $(Get-Date -Format 'dd/MM HH:mm')

🌐 WEB INSTANCES: $($webInstances.Count) activas
🗄️  RDS: $($rdsStatus.CPU)% CPU | $($rdsStatus.Connections) conexiones | $($rdsStatus.Status)
"@

if ($albStatus) {
    $snsMessage += @"

⚖️  ALB: $($albStatus.HealthyTargets)/$($albStatus.TotalTargets) targets sanos | $($albStatus.ResponseTimeMs)ms resp
⚠️  ERRORES: $($albStatus.HTTP5XX) 5XX en últimas 6h
🔗 ALB DNS: $($albStatus.DNS)
"@
}

$snsMessage += @"


📈 DASHBOARD COMPLETO:
$dashboardUrl

---
Monitoreado: $(Get-Date -Format 'dd/MM/yyyy HH:mm:ss')
"@

aws sns publish --topic-arn $SNS_TOPIC_ARN --subject "WP Dashboard v3.0 - $(Get-Date -Format 'dd/MM HH:mm')" --message "$snsMessage" --region $AWS_REGION

Write-Log "✅ Dashboard: $dashboardUrl"
if ($albStatus) {
    Write-Log "🎉 MONITOREO COMPLETO: WEB($($webInstances.Count)) | ALB($($albStatus.HealthyTargets)/$($albStatus.TotalTargets)) | RDS($($rdsStatus.CPU)%)"
} else {
    Write-Log "🎉 MONITOREO COMPLETO: WEB($($webInstances.Count)) | RDS($($rdsStatus.CPU)%)"
}
