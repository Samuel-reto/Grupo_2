#!/usr/bin/env pwsh
<#
.SYNOPSIS
    WordPress Enterprise Monitoring Dashboard v3.1 - Enhanced Instance Detection
.DESCRIPTION
    Monitorea ASG WordPress + RDS + ALB con métricas reales disponibles
    ✨ Incluye uso de CPU, Memoria, Disco de instancias EC2
    🆕 NUEVA CARACTERÍSTICA: Detecta instancias caídas/unhealthy/terminando
.NOTES
    Author: Valentin Gutierrez (ASIR2)
    Version: 3.1 Enhanced
    CRON: 0 */6 * * * + 0 8 * * *
#>

param()

# =========================================
# CONFIGURACIÓN
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

function Get-CloudWatchMetric {
    param(
        [string]$Namespace,
        [string]$MetricName,
        [hashtable]$Dimensions,
        [datetime]$StartTime,
        [datetime]$EndTime,
        [string]$Statistic = "Average",
        [int]$Period = 300
    )
    
    try {
        # Construir dimensiones para el comando
        $dimArgs = @()
        foreach ($key in $Dimensions.Keys) {
            $dimArgs += "Name=$key,Value=$($Dimensions[$key])"
        }
        
        $result = aws cloudwatch get-metric-statistics `
            --namespace $Namespace `
            --metric-name $MetricName `
            --dimensions $dimArgs `
            --start-time $StartTime.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ss") `
            --end-time $EndTime.ToUniversalTime().ToString("yyyy-MM-ddTHH:mm:ss") `
            --period $Period `
            --statistics $Statistic `
            --region $AWS_REGION `
            --output json 2>$null
        
        if ($LASTEXITCODE -ne 0 -or -not $result) {
            return $null
        }
        
        $data = $result | ConvertFrom-Json
        
        if (-not $data.Datapoints -or $data.Datapoints.Count -eq 0) {
            return $null
        }
        
        $latestDatapoint = $data.Datapoints | Sort-Object Timestamp -Descending | Select-Object -First 1
        
        $value = switch ($Statistic) {
            "Average" { $latestDatapoint.Average }
            "Sum" { $latestDatapoint.Sum }
            "Maximum" { $latestDatapoint.Maximum }
            "Minimum" { $latestDatapoint.Minimum }
        }
        
        return $value
        
    } catch {
        return $null
    }
}

Write-Log "========================================"
Write-Log "🚀 WordPress Dashboard v3.1 Enhanced"
Write-Log "========================================"

$endTime = Get-Date
$startTime = $endTime.AddMinutes(-30)  # Últimos 30 minutos

Write-Log "⏰ Ventana: $($startTime.ToString('HH:mm')) - $($endTime.ToString('HH:mm'))"

# =========================================
# 0. AUTO-DETECTAR RECURSOS
# =========================================
Write-Log "🔍 Auto-detectando recursos..."

# RDS
$rdsInstanceId = aws cloudformation describe-stack-resources `
    --stack-name $STACK_NAME `
    --query 'StackResources[?ResourceType==`AWS::RDS::DBInstance`].PhysicalResourceId' `
    --output text --region $AWS_REGION 2>$null

if (-not $rdsInstanceId) {
    $rdsInstanceId = aws rds describe-db-instances `
        --query 'DBInstances[0].DBInstanceIdentifier' `
        --output text --region $AWS_REGION 2>$null
}

if (-not $rdsInstanceId) { $rdsInstanceId = "wordpress-db" }
Write-Log "✅ RDS: $rdsInstanceId"

# ALB
$albArn = aws cloudformation describe-stack-resources `
    --stack-name $STACK_NAME `
    --query 'StackResources[?ResourceType==`AWS::ElasticLoadBalancingV2::LoadBalancer`].PhysicalResourceId' `
    --output text --region $AWS_REGION 2>$null

if (-not $albArn) {
    $albArn = aws elbv2 describe-load-balancers `
        --query 'LoadBalancers[0].LoadBalancerArn' `
        --output text --region $AWS_REGION 2>$null
}

if ($albArn) {
    Write-Log "✅ ALB detectado"
} else {
    Write-Log "⚠️  ALB no encontrado"
}

# =========================================
# 1. OBTENER INFO COMPLETA DEL ASG
# =========================================
Write-Log "🌐 Obteniendo info completa del ASG: $ASG_NAME"

$asgInfoRaw = aws autoscaling describe-auto-scaling-groups `
    --auto-scaling-group-names $ASG_NAME `
    --region $AWS_REGION `
    --output json 2>$null

if (-not $asgInfoRaw) {
    Write-Log "❌ ERROR: No se pudo obtener info del ASG"
    exit 1
}

$asgInfo = $asgInfoRaw | ConvertFrom-Json
$asgData = $asgInfo.AutoScalingGroups[0]

$desiredCapacity = $asgData.DesiredCapacity
$minSize = $asgData.MinSize
$maxSize = $asgData.MaxSize
$currentCapacity = $asgData.Instances.Count

Write-Log "📊 ASG Configuración:"
Write-Log "   • Capacidad deseada: $desiredCapacity"
Write-Log "   • Min/Max: $minSize/$maxSize"
Write-Log "   • Instancias actuales: $currentCapacity"

# =========================================
# 2. OBTENER TODAS LAS INSTANCIAS (SIN FILTRAR)
# =========================================
Write-Log "🔍 Obteniendo TODAS las instancias del ASG..."

$allInstances = @()
$healthyInstances = @()
$unhealthyInstances = @()
$terminatingInstances = @()
$otherStateInstances = @()

foreach ($instance in $asgData.Instances) {
    $instanceId = $instance.InstanceId
    $lifecycleState = $instance.LifecycleState
    $healthStatus = $instance.HealthStatus
    $availabilityZone = $instance.AvailabilityZone
    
    Write-Log "   Procesando: $instanceId | Lifecycle: $lifecycleState | Health: $healthStatus"
    
    # Obtener detalles EC2
    $ec2Details = $null
    try {
        $ec2DetailsRaw = aws ec2 describe-instances --instance-ids $instanceId `
            --query 'Reservations[0].Instances[0].[InstanceId,PrivateIpAddress,InstanceType,State.Name,LaunchTime,PublicIpAddress]' `
            --output json --region $AWS_REGION 2>$null
        
        if ($ec2DetailsRaw) {
            $ec2Details = $ec2DetailsRaw | ConvertFrom-Json
        }
    } catch {
        Write-Log "   ⚠️  No se pudo obtener detalles EC2 para $instanceId"
    }

    # Inicializar objeto de instancia
    $instanceObj = [PSCustomObject]@{
        InstanceId = $instanceId
        LifecycleState = $lifecycleState
        HealthStatus = $healthStatus
        AvailabilityZone = $availabilityZone
        PrivateIP = if($ec2Details) { $ec2Details[1] } else { "N/A" }
        PublicIP = if($ec2Details -and $ec2Details.Count -gt 5) { $ec2Details[5] } else { "N/A" }
        InstanceType = if($ec2Details) { $ec2Details[2] } else { "N/A" }
        EC2State = if($ec2Details) { $ec2Details[3] } else { "unknown" }
        LaunchTime = if($ec2Details -and $ec2Details.Count -ge 5) { $ec2Details[4] } else { $null }
        CPUUtilization = 0
        NetworkInMB = 0
        NetworkOutMB = 0
        StatusCheckOK = $false
        IsHealthy = ($lifecycleState -eq "InService" -and $healthStatus -eq "Healthy")
    }

    # Solo obtener métricas si la instancia está running
    if ($lifecycleState -eq "InService" -and $ec2Details -and $ec2Details[3] -eq "running") {
        Write-Log "   📊 Obteniendo métricas CloudWatch de $instanceId"
        
        $cpuUtilization = Get-CloudWatchMetric `
            -Namespace "AWS/EC2" `
            -MetricName "CPUUtilization" `
            -Dimensions @{InstanceId=$instanceId} `
            -StartTime $startTime `
            -EndTime $endTime `
            -Statistic "Average" `
            -Period 300

        $networkIn = Get-CloudWatchMetric `
            -Namespace "AWS/EC2" `
            -MetricName "NetworkIn" `
            -Dimensions @{InstanceId=$instanceId} `
            -StartTime $startTime `
            -EndTime $endTime `
            -Statistic "Average" `
            -Period 300

        $networkOut = Get-CloudWatchMetric `
            -Namespace "AWS/EC2" `
            -MetricName "NetworkOut" `
            -Dimensions @{InstanceId=$instanceId} `
            -StartTime $startTime `
            -EndTime $endTime `
            -Statistic "Average" `
            -Period 300

        $statusCheckFailed = Get-CloudWatchMetric `
            -Namespace "AWS/EC2" `
            -MetricName "StatusCheckFailed" `
            -Dimensions @{InstanceId=$instanceId} `
            -StartTime $startTime `
            -EndTime $endTime `
            -Statistic "Maximum" `
            -Period 300

        $instanceObj.CPUUtilization = if($cpuUtilization) { [math]::Round([double]$cpuUtilization, 1) } else { 0 }
        $instanceObj.NetworkInMB = if($networkIn) { [math]::Round([double]$networkIn / 1048576, 2) } else { 0 }
        $instanceObj.NetworkOutMB = if($networkOut) { [math]::Round([double]$networkOut / 1048576, 2) } else { 0 }
        $instanceObj.StatusCheckOK = if($statusCheckFailed -eq 0 -or $null -eq $statusCheckFailed) { $true } else { $false }
        
        Write-Log "   ✅ CPU: $($instanceObj.CPUUtilization)% | Net IN: $($instanceObj.NetworkInMB)MB | Net OUT: $($instanceObj.NetworkOutMB)MB"
    } else {
        Write-Log "   ⚠️  Instancia no está InService/running - métricas omitidas"
    }

    # Clasificar instancia
    $allInstances += $instanceObj
    
    if ($lifecycleState -eq "InService" -and $healthStatus -eq "Healthy") {
        $healthyInstances += $instanceObj
    } elseif ($lifecycleState -match "Terminat") {
        $terminatingInstances += $instanceObj
    } elseif ($healthStatus -eq "Unhealthy") {
        $unhealthyInstances += $instanceObj
    } else {
        $otherStateInstances += $instanceObj
    }
}

Write-Log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
Write-Log "📊 RESUMEN DE INSTANCIAS:"
Write-Log "   ✅ Healthy (InService): $($healthyInstances.Count)"
Write-Log "   ⚠️  Unhealthy: $($unhealthyInstances.Count)"
Write-Log "   🔄 Terminando: $($terminatingInstances.Count)"
Write-Log "   ⚪ Otros estados: $($otherStateInstances.Count)"
Write-Log "   📦 TOTAL: $($allInstances.Count) / $desiredCapacity deseadas"
Write-Log "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# =========================================
# 3. OBTENER EVENTOS RECIENTES DEL ASG
# =========================================
Write-Log "📜 Obteniendo eventos recientes del ASG..."

$recentEventsRaw = aws autoscaling describe-scaling-activities `
    --auto-scaling-group-name $ASG_NAME `
    --max-records 5 `
    --region $AWS_REGION `
    --output json 2>$null

$recentEvents = @()
if ($recentEventsRaw) {
    $eventsData = $recentEventsRaw | ConvertFrom-Json
    foreach ($event in $eventsData.Activities) {
        $eventTime = [DateTime]::Parse($event.StartTime)
        $timeDiff = (Get-Date) - $eventTime
        
        # Solo eventos de las últimas 6 horas
        if ($timeDiff.TotalHours -le 6) {
            $recentEvents += [PSCustomObject]@{
                Description = $event.Description
                StatusCode = $event.StatusCode
                StartTime = $eventTime
                Cause = $event.Cause
            }
            
            Write-Log "   • $($event.StatusCode): $($event.Description) ($($timeDiff.Hours)h ago)"
        }
    }
}

# =========================================
# 4. RDS MÉTRICAS (solo las disponibles)
# =========================================
Write-Log "🗄️  Obteniendo métricas RDS: $rdsInstanceId"

$rdsInfo = aws rds describe-db-instances --db-instance-identifier $rdsInstanceId `
    --query 'DBInstances[0].[DBInstanceIdentifier,DBInstanceStatus,Engine,EngineVersion,DBInstanceClass,Endpoint.Address,Endpoint.Port,AllocatedStorage,MultiAZ,AvailabilityZone]' `
    --output json --region $AWS_REGION 2>$null | ConvertFrom-Json

if ($rdsInfo) {
    Write-Log "   ✅ Info RDS obtenida"
}

Write-Log "   📊 Métricas CloudWatch RDS"

$rdsCPU = Get-CloudWatchMetric `
    -Namespace "AWS/RDS" `
    -MetricName "CPUUtilization" `
    -Dimensions @{DBInstanceIdentifier=$rdsInstanceId} `
    -StartTime $startTime `
    -EndTime $endTime `
    -Statistic "Average"

$rdsConnections = Get-CloudWatchMetric `
    -Namespace "AWS/RDS" `
    -MetricName "DatabaseConnections" `
    -Dimensions @{DBInstanceIdentifier=$rdsInstanceId} `
    -StartTime $startTime `
    -EndTime $endTime `
    -Statistic "Average"

$rdsFreeStorage = Get-CloudWatchMetric `
    -Namespace "AWS/RDS" `
    -MetricName "FreeStorageSpace" `
    -Dimensions @{DBInstanceIdentifier=$rdsInstanceId} `
    -StartTime $startTime `
    -EndTime $endTime `
    -Statistic "Average"

$rdsReadLatency = Get-CloudWatchMetric `
    -Namespace "AWS/RDS" `
    -MetricName "ReadLatency" `
    -Dimensions @{DBInstanceIdentifier=$rdsInstanceId} `
    -StartTime $startTime `
    -EndTime $endTime `
    -Statistic "Average"

$rdsWriteLatency = Get-CloudWatchMetric `
    -Namespace "AWS/RDS" `
    -MetricName "WriteLatency" `
    -Dimensions @{DBInstanceIdentifier=$rdsInstanceId} `
    -StartTime $startTime `
    -EndTime $endTime `
    -Statistic "Average"

$rdsStatus = [PSCustomObject]@{
    Name = if($rdsInfo) { $rdsInfo[0] } else { "N/A" }
    Status = if($rdsInfo) { $rdsInfo[1] } else { "unknown" }
    Engine = if($rdsInfo) { "$($rdsInfo[2]) $($rdsInfo[3])" } else { "-" }
    InstanceClass = if($rdsInfo) { $rdsInfo[4] } else { "-" }
    Endpoint = if($rdsInfo) { "$($rdsInfo[5]):$($rdsInfo[6])" } else { "-" }
    Storage = if($rdsInfo) { "$($rdsInfo[7]) GB" } else { "-" }
    MultiAZ = if($rdsInfo -and $rdsInfo[8] -eq $true) { "✓ Sí" } else { "✗ No" }
    AZ = if($rdsInfo) { $rdsInfo[9] } else { "-" }
    CPU = if($rdsCPU) { [math]::Round([double]$rdsCPU, 1) } else { 0 }
    Connections = if($rdsConnections) { [math]::Round([double]$rdsConnections, 0) } else { 0 }
    FreeStorageGB = if($rdsFreeStorage) { [math]::Round([double]$rdsFreeStorage / 1073741824, 2) } else { 0 }
    ReadLatencyMs = if($rdsReadLatency) { [math]::Round([double]$rdsReadLatency * 1000, 2) } else { 0 }
    WriteLatencyMs = if($rdsWriteLatency) { [math]::Round([double]$rdsWriteLatency * 1000, 2) } else { 0 }
}

Write-Log "   ✅ CPU: $($rdsStatus.CPU)% | Conexiones: $($rdsStatus.Connections) | Storage libre: $($rdsStatus.FreeStorageGB)GB"

# =========================================
# 5. ALB MÉTRICAS (solo las disponibles)
# =========================================
$albStatus = $null
$targetHealth = @()

if ($albArn) {
    Write-Log "⚖️  Obteniendo métricas ALB"
    
    $albInfo = aws elbv2 describe-load-balancers `
        --load-balancer-arns $albArn `
        --query 'LoadBalancers[0].[LoadBalancerName,DNSName,State.Code,Scheme]' `
        --output json --region $AWS_REGION 2>$null | ConvertFrom-Json

    # Target Health
    $targetGroupArn = aws elbv2 describe-target-groups `
        --load-balancer-arn $albArn `
        --query 'TargetGroups[0].TargetGroupArn' `
        --output text --region $AWS_REGION 2>$null

    if ($targetGroupArn) {
        $targetHealth = aws elbv2 describe-target-health `
            --target-group-arn $targetGroupArn `
            --query 'TargetHealthDescriptions[*].[Target.Id,TargetHealth.State,TargetHealth.Reason]' `
            --output json --region $AWS_REGION 2>$null | ConvertFrom-Json
        
        $healthyTargets = ($targetHealth | Where-Object { $_[1] -eq "healthy" }).Count
        $totalTargets = $targetHealth.Count
    }

    $albShortArn = $albArn -replace '^arn:aws:elasticloadbalancing:[^:]+:[^:]+:loadbalancer/', ''

    Write-Log "   📊 Métricas CloudWatch ALB"

    $albRequestCount = Get-CloudWatchMetric `
        -Namespace "AWS/ApplicationELB" `
        -MetricName "RequestCount" `
        -Dimensions @{LoadBalancer=$albShortArn} `
        -StartTime $startTime `
        -EndTime $endTime `
        -Statistic "Sum" `
        -Period 300

    $albTargetResponseTime = Get-CloudWatchMetric `
        -Namespace "AWS/ApplicationELB" `
        -MetricName "TargetResponseTime" `
        -Dimensions @{LoadBalancer=$albShortArn} `
        -StartTime $startTime `
        -EndTime $endTime `
        -Statistic "Average" `
        -Period 300

    $albActiveConnections = Get-CloudWatchMetric `
        -Namespace "AWS/ApplicationELB" `
        -MetricName "ActiveConnectionCount" `
        -Dimensions @{LoadBalancer=$albShortArn} `
        -StartTime $startTime `
        -EndTime $endTime `
        -Statistic "Sum" `
        -Period 300

    $albStatus = [PSCustomObject]@{
        Name = if($albInfo) { $albInfo[0] } else { "N/A" }
        DNS = if($albInfo) { $albInfo[1] } else { "-" }
        State = if($albInfo) { $albInfo[2] } else { "unknown" }
        Scheme = if($albInfo) { $albInfo[3] } else { "-" }
        HealthyTargets = if($healthyTargets) { $healthyTargets } else { 0 }
        TotalTargets = if($totalTargets) { $totalTargets } else { 0 }
        HealthPercent = if($totalTargets -gt 0) { [math]::Round(($healthyTargets / $totalTargets) * 100, 0) } else { 0 }
        RequestCount = if($albRequestCount) { [math]::Round([double]$albRequestCount, 0) } else { 0 }
        ResponseTimeMs = if($albTargetResponseTime) { [math]::Round([double]$albTargetResponseTime * 1000, 2) } else { 0 }
        ActiveConnections = if($albActiveConnections) { [math]::Round([double]$albActiveConnections, 0) } else { 0 }
    }
    
    Write-Log "   ✅ Targets: $($albStatus.HealthyTargets)/$($albStatus.TotalTargets) | Requests: $($albStatus.RequestCount) | RT: $($albStatus.ResponseTimeMs)ms"
}

# =========================================
# 6. CALCULAR SALUD DEL SISTEMA (MEJORADO)
# =========================================
$overallHealth = "healthy"
$healthScore = 100
$healthIssues = @()

# Verificar déficit de capacidad
$capacityDeficit = $desiredCapacity - $healthyInstances.Count
if ($capacityDeficit -gt 0) {
    $overallHealth = "warning"
    $healthScore -= (15 * $capacityDeficit)
    $healthIssues += "❌ Faltan $capacityDeficit instancia(s) - Deseadas: $desiredCapacity, Healthy: $($healthyInstances.Count)"
}

# Verificar instancias unhealthy
if ($unhealthyInstances.Count -gt 0) {
    $overallHealth = "warning"
    $healthScore -= (10 * $unhealthyInstances.Count)
    $healthIssues += "⚠️  $($unhealthyInstances.Count) instancia(s) Unhealthy"
}

# Verificar instancias terminando
if ($terminatingInstances.Count -gt 0) {
    if ($overallHealth -ne "critical") { $overallHealth = "warning" }
    $healthIssues += "🔄 $($terminatingInstances.Count) instancia(s) terminando"
}

# Verificar CPU alta en instancias healthy
$highCPUInstances = ($healthyInstances | Where-Object { $_.CPUUtilization -gt 80 }).Count
if ($highCPUInstances -gt 0) {
    if ($overallHealth -eq "healthy") { $overallHealth = "warning" }
    $healthScore -= (10 * $highCPUInstances)
    $healthIssues += "🔥 $highCPUInstances instancia(s) con CPU alta (>80%)"
}

# Verificar RDS
if ($rdsStatus.CPU -gt 80) { 
    if ($overallHealth -eq "healthy") { $overallHealth = "warning" }
    $healthScore -= 20
    $healthIssues += "🗄️  RDS CPU alto ($($rdsStatus.CPU)%)"
}
if ($rdsStatus.FreeStorageGB -lt 2) {
    if ($overallHealth -eq "healthy") { $overallHealth = "warning" }
    $healthScore -= 15
    $healthIssues += "💾 Poco storage libre en RDS ($($rdsStatus.FreeStorageGB)GB)"
}

# Verificar ALB
if ($albStatus -and $albStatus.HealthPercent -lt 100) { 
    if ($overallHealth -eq "healthy") { $overallHealth = "warning" }
    $healthScore -= 15
    $healthIssues += "⚖️  Targets no sanos en ALB ($($albStatus.HealthyTargets)/$($albStatus.TotalTargets))"
}

# Caso crítico: sin instancias healthy
if ($healthyInstances.Count -eq 0) { 
    $overallHealth = "critical"
    $healthScore = 0
    $healthIssues += "🚨 CRÍTICO: Sin instancias web activas"
}

# Asegurar que healthScore no sea negativo
if ($healthScore -lt 0) { $healthScore = 0 }

$statusIndicator = switch ($overallHealth) {
    "healthy" { "🟢" }
    "warning" { "🟡" }
    "critical" { "🔴" }
    default { "⚪" }
}

$statusColor = switch ($overallHealth) {
    "healthy" { "#10b981" }
    "warning" { "#f59e0b" }
    "critical" { "#ef4444" }
    default { "#6b7280" }
}

Write-Log "🎯 Salud del sistema: $healthScore% ($overallHealth)"
if ($healthIssues.Count -gt 0) {
    Write-Log "⚠️  Issues detectados:"
    foreach ($issue in $healthIssues) {
        Write-Log "     $issue"
    }
}

# =========================================
# 7. GENERAR HTML (CON TODAS LAS INSTANCIAS)
# =========================================

# Función para generar fila de instancia
function Get-InstanceRow {
    param($instance)
    
    $uptime = if($instance.LaunchTime) {
        try {
            $diff = (Get-Date) - [DateTime]::Parse($instance.LaunchTime)
            if ($diff.Days -gt 0) { "$($diff.Days)d $($diff.Hours)h" }
            elseif ($diff.Hours -gt 0) { "$($diff.Hours)h $($diff.Minutes)m" }
            else { "$($diff.Minutes)m" }
        } catch { "-" }
    } else { "-" }
    
    # Determinar estado visual
    $stateClass = "badge-info"
    $stateText = $instance.LifecycleState
    
    if ($instance.IsHealthy) {
        $stateClass = "badge-success"
        $stateText = "✓ InService"
    } elseif ($instance.HealthStatus -eq "Unhealthy") {
        $stateClass = "badge-error"
        $stateText = "✗ Unhealthy"
    } elseif ($instance.LifecycleState -match "Terminat") {
        $stateClass = "badge-warning"
        $stateText = "⏳ Terminating"
    } elseif ($instance.LifecycleState -eq "Pending") {
        $stateClass = "badge-info"
        $stateText = "🔄 Pending"
    }
    
    $cpuClass = if($instance.CPUUtilization -gt 80) { "badge-error" } elseif($instance.CPUUtilization -gt 60) { "badge-warning" } else { "badge-success" }
    
    $ec2StateClass = switch($instance.EC2State) {
        "running" { "badge-success" }
        "stopped" { "badge-error" }
        "stopping" { "badge-warning" }
        "terminated" { "badge-error" }
        default { "badge-info" }
    }
    
    # Row class especial para instancias no healthy
    $rowClass = if(-not $instance.IsHealthy) { "instance-row unhealthy-instance" } else { "instance-row" }
    
    @"
<tr class="$rowClass">
    <td><span class="instance-id">$($instance.InstanceId)</span></td>
    <td><code class="ip-address">$($instance.PrivateIP)</code></td>
    <td><span class="instance-type">$($instance.InstanceType)</span></td>
    <td><span class="badge $cpuClass">$($instance.CPUUtilization)%</span></td>
    <td>↓ $($instance.NetworkInMB) MB<br>↑ $($instance.NetworkOutMB) MB</td>
    <td><span class="badge $stateClass">$stateText</span></td>
    <td><span class="badge $ec2StateClass">$($instance.EC2State)</span></td>
    <td class="uptime">$uptime</td>
</tr>
"@
}

$htmlWebRows = ($allInstances | ForEach-Object { Get-InstanceRow $_ }) -join ""

# Generar eventos recientes HTML
$htmlRecentEvents = if($recentEvents.Count -gt 0) {
    ($recentEvents | ForEach-Object {
        $statusClass = if($_.StatusCode -eq "Successful") { "badge-success" } else { "badge-warning" }
        $timeAgo = ((Get-Date) - $_.StartTime).Hours
        @"
<tr>
    <td class="uptime">$($_.StartTime.ToString('HH:mm:ss'))</td>
    <td><span class="badge $statusClass">$($_.StatusCode)</span></td>
    <td style="font-size: 0.875rem;">$($_.Description)</td>
</tr>
"@
    }) -join ""
} else {
    "<tr><td colspan='3' style='text-align: center; color: var(--color-text-dim);'>Sin eventos recientes</td></tr>"
}

$albSection = ""
$albStatsCards = ""

if ($albStatus) {
    $htmlTargetRows = ($targetHealth | ForEach-Object {
        $state = $_[1]
        $reason = if($_[2]) { $_[2] } else { "-" }
        $badgeClass = if($state -eq "healthy") { "badge-success" } 
                      elseif($state -eq "unhealthy") { "badge-error" }
                      else { "badge-warning" }
        @"
<tr>
    <td><span class="instance-id">$($_[0])</span></td>
    <td><span class="badge $badgeClass">$state</span></td>
    <td style="font-size: 0.875rem; color: var(--color-text-dim);">$reason</td>
</tr>
"@
    }) -join ""

    $albSection = @"
<section class="dashboard-section">
    <div class="section-header">
        <h2 class="section-title">⚖️ Load Balancer</h2>
        <span class="badge badge-info">ALB</span>
    </div>
    
    <div class="alb-overview">
        <div class="alb-info-card">
            <div class="alb-header">
                <h3>$($albStatus.Name)</h3>
                <span class="badge badge-success">$($albStatus.State)</span>
            </div>
            <div class="alb-dns">
                <label>DNS Endpoint</label>
                <code>$($albStatus.DNS)</code>
            </div>
            <div class="alb-meta">
                <span>Scheme: <strong>$($albStatus.Scheme)</strong></span>
                <span>Health: <strong>$($albStatus.HealthPercent)%</strong></span>
            </div>
        </div>

        <div class="metrics-row">
            <div class="metric-card metric-primary">
                <div class="metric-icon">📊</div>
                <div class="metric-value">$($albStatus.RequestCount)</div>
                <div class="metric-label">Requests (30 min)</div>
            </div>
            <div class="metric-card metric-success">
                <div class="metric-icon">⚡</div>
                <div class="metric-value">$($albStatus.ResponseTimeMs)ms</div>
                <div class="metric-label">Avg Response Time</div>
            </div>
            <div class="metric-card metric-info">
                <div class="metric-icon">🔗</div>
                <div class="metric-value">$($albStatus.ActiveConnections)</div>
                <div class="metric-label">Active Connections</div>
            </div>
        </div>
    </div>

    <div class="targets-health">
        <h3 class="subsection-title">Target Group Health</h3>
        <div class="table-wrapper">
            <table class="data-table">
                <thead><tr><th>Target Instance</th><th>Health Status</th><th>Reason</th></tr></thead>
                <tbody>$htmlTargetRows</tbody>
            </table>
        </div>
    </div>
</section>
"@

    $albStatsCards = @"
<div class="stat-card">
    <div class="stat-icon">⚖️</div>
    <div class="stat-value">$($albStatus.HealthyTargets)/$($albStatus.TotalTargets)</div>
    <div class="stat-label">Healthy Targets</div>
    <div class="stat-trend positive">$($albStatus.HealthPercent)%</div>
</div>
<div class="stat-card">
    <div class="stat-icon">⚡</div>
    <div class="stat-value">$($albStatus.ResponseTimeMs)ms</div>
    <div class="stat-label">Response Time</div>
</div>
"@
}

# Calcular uso promedio de recursos (solo instancias healthy)
$avgCPU = if($healthyInstances.Count -gt 0) { 
    [math]::Round(($healthyInstances | Measure-Object -Property CPUUtilization -Average).Average, 1) 
} else { 0 }

$totalNetworkInMB = if($healthyInstances.Count -gt 0) { 
    [math]::Round(($healthyInstances | Measure-Object -Property NetworkInMB -Sum).Sum, 2) 
} else { 0 }

$totalNetworkOutMB = if($healthyInstances.Count -gt 0) { 
    [math]::Round(($healthyInstances | Measure-Object -Property NetworkOutMB -Sum).Sum, 2) 
} else { 0 }

$reportContent = @"
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health2You Infrastructure Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&family=Outfit:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: #0f172a;
            --color-secondary: #1e293b;
            --color-accent: #3b82f6;
            --color-success: #10b981;
            --color-warning: #f59e0b;
            --color-error: #ef4444;
            --color-text: #e2e8f0;
            --color-text-dim: #94a3b8;
            --color-border: #334155;
            --color-bg-card: #1e293b;
            --color-bg-elevated: #0f172a;
            --font-display: 'Outfit', sans-serif;
            --font-mono: 'JetBrains Mono', monospace;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 10px 30px rgba(0, 0, 0, 0.5);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-display);
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--color-text);
            line-height: 1.6;
            min-height: 100vh;
            padding: 2rem;
        }

        .dashboard-container { max-width: 1400px; margin: 0 auto; }

        .dashboard-header {
            text-align: center;
            margin-bottom: 3rem;
            padding: 2rem;
            background: var(--color-bg-card);
            border-radius: 1.5rem;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--color-border);
            position: relative;
            overflow: hidden;
        }

        .dashboard-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--color-accent), var(--color-success));
        }

        .dashboard-title {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            background: linear-gradient(135deg, #fff, var(--color-text));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .dashboard-subtitle {
            color: var(--color-text-dim);
            font-size: 1rem;
            font-weight: 400;
        }

        .health-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-top: 1rem;
            padding: 0.5rem 1rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 2rem;
            font-family: var(--font-mono);
            font-size: 0.875rem;
        }

        .health-score {
            font-size: 1.5rem;
            font-weight: 700;
            color: $statusColor;
        }

        .capacity-indicator {
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            margin-top: 0.75rem;
            padding: 0.625rem 1.25rem;
            background: rgba(59, 130, 246, 0.1);
            border-radius: 2rem;
            border: 1px solid rgba(59, 130, 246, 0.3);
            font-family: var(--font-mono);
            font-size: 0.875rem;
        }

        .capacity-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--color-accent);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 3rem;
        }

        .stat-card {
            background: var(--color-bg-card);
            padding: 1.5rem;
            border-radius: 1rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--color-border);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 0.5rem;
            opacity: 0.9;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            font-family: var(--font-mono);
            line-height: 1;
            margin-bottom: 0.25rem;
        }

        .stat-label {
            color: var(--color-text-dim);
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        .stat-trend {
            margin-top: 0.5rem;
            font-size: 0.875rem;
            font-weight: 600;
        }

        .stat-trend.positive { color: var(--color-success); }

        .dashboard-section {
            background: var(--color-bg-card);
            border-radius: 1.5rem;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--color-border);
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--color-border);
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .subsection-title {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--color-text-dim);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.375rem 0.75rem;
            border-radius: 0.5rem;
            font-size: 0.75rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .badge-success {
            background: rgba(16, 185, 129, 0.15);
            color: var(--color-success);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .badge-warning {
            background: rgba(245, 158, 11, 0.15);
            color: var(--color-warning);
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .badge-error {
            background: rgba(239, 68, 68, 0.15);
            color: var(--color-error);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .badge-info {
            background: rgba(59, 130, 246, 0.15);
            color: var(--color-accent);
            border: 1px solid rgba(59, 130, 246, 0.3);
        }

        .table-wrapper {
            overflow-x: auto;
            border-radius: 0.75rem;
            border: 1px solid var(--color-border);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
        }

        .data-table thead { background: var(--color-bg-elevated); }

        .data-table th {
            padding: 1rem;
            text-align: left;
            font-weight: 600;
            color: var(--color-text-dim);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.75rem;
        }

        .data-table td {
            padding: 1rem;
            border-top: 1px solid var(--color-border);
        }

        .data-table tbody tr { transition: background 0.15s; }
        .data-table tbody tr:hover { background: rgba(255, 255, 255, 0.03); }

        .unhealthy-instance {
            background: rgba(239, 68, 68, 0.05) !important;
            border-left: 3px solid var(--color-error);
        }

        .unhealthy-instance:hover {
            background: rgba(239, 68, 68, 0.1) !important;
        }

        .instance-id {
            font-family: var(--font-mono);
            font-size: 0.8125rem;
            color: var(--color-accent);
        }

        .ip-address {
            font-family: var(--font-mono);
            background: rgba(59, 130, 246, 0.1);
            padding: 0.25rem 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.8125rem;
        }

        .instance-type {
            font-family: var(--font-mono);
            font-weight: 500;
        }

        .uptime {
            color: var(--color-text-dim);
            font-family: var(--font-mono);
        }

        .rds-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 0.75rem;
            border: 1px solid var(--color-border);
        }

        .rds-info-item {
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        .rds-info-item label {
            font-size: 0.75rem;
            color: var(--color-text-dim);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        .rds-info-item strong {
            font-size: 1rem;
            font-family: var(--font-mono);
        }

        .rds-info-item code {
            font-family: var(--font-mono);
            background: rgba(59, 130, 246, 0.1);
            padding: 0.5rem;
            border-radius: 0.375rem;
            font-size: 0.8125rem;
            display: block;
            overflow-x: auto;
        }

        .metrics-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }

        .metric-card {
            background: rgba(255, 255, 255, 0.03);
            padding: 1.25rem;
            border-radius: 0.75rem;
            border: 1px solid var(--color-border);
            text-align: center;
            transition: all 0.2s;
        }

        .metric-card:hover {
            transform: translateY(-2px);
            border-color: var(--color-accent);
        }

        .metric-card .metric-icon {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
        }

        .metric-card .metric-value {
            font-size: 1.75rem;
            font-weight: 700;
            font-family: var(--font-mono);
            margin-bottom: 0.25rem;
        }

        .metric-card .metric-label {
            font-size: 0.75rem;
            color: var(--color-text-dim);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .metric-primary { border-left: 3px solid var(--color-accent); }
        .metric-success { border-left: 3px solid var(--color-success); }
        .metric-warning { border-left: 3px solid var(--color-warning); }
        .metric-error { border-left: 3px solid var(--color-error); }
        .metric-info { border-left: 3px solid #6366f1; }

        .alb-overview { margin-bottom: 2rem; }

        .alb-info-card {
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(16, 185, 129, 0.1));
            padding: 1.5rem;
            border-radius: 0.75rem;
            border: 1px solid var(--color-border);
            margin-bottom: 1.5rem;
        }

        .alb-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .alb-header h3 {
            font-size: 1.25rem;
            font-weight: 700;
        }

        .alb-dns { margin-bottom: 1rem; }

        .alb-dns label {
            display: block;
            font-size: 0.75rem;
            color: var(--color-text-dim);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.5rem;
        }

        .alb-dns code {
            display: block;
            background: rgba(0, 0, 0, 0.3);
            padding: 0.75rem;
            border-radius: 0.375rem;
            font-family: var(--font-mono);
            font-size: 0.875rem;
            word-break: break-all;
        }

        .alb-meta {
            display: flex;
            gap: 2rem;
            font-size: 0.875rem;
            color: var(--color-text-dim);
        }

        .alert-box {
            background: rgba(239, 68, 68, 0.1);
            border: 2px solid var(--color-error);
            border-radius: 0.75rem;
            padding: 1rem;
            margin-bottom: 2rem;
        }

        .alert-box h3 {
            color: var(--color-error);
            margin-bottom: 0.5rem;
            font-size: 1.125rem;
        }

        .alert-box ul {
            list-style: none;
            padding-left: 0;
        }

        .alert-box li {
            padding: 0.5rem 0;
            border-bottom: 1px solid rgba(239, 68, 68, 0.2);
        }

        .alert-box li:last-child {
            border-bottom: none;
        }

        .dashboard-footer {
            text-align: center;
            padding: 2rem;
            color: var(--color-text-dim);
            font-size: 0.875rem;
            margin-top: 3rem;
        }

        .dashboard-footer a {
            color: var(--color-accent);
            text-decoration: none;
            transition: color 0.2s;
        }

        .dashboard-footer a:hover {
            color: var(--color-success);
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .stat-card, .dashboard-section {
            animation: fadeInUp 0.5s ease-out backwards;
        }

        .stat-card:nth-child(1) { animation-delay: 0.1s; }
        .stat-card:nth-child(2) { animation-delay: 0.15s; }
        .stat-card:nth-child(3) { animation-delay: 0.2s; }
        .stat-card:nth-child(4) { animation-delay: 0.25s; }
        .stat-card:nth-child(5) { animation-delay: 0.3s; }

        @media (max-width: 768px) {
            body { padding: 1rem; }
            .dashboard-title { font-size: 1.75rem; }
            .stats-grid { grid-template-columns: 1fr; }
            .metrics-row { grid-template-columns: 1fr; }
            .rds-info-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <header class="dashboard-header">
            <h1 class="dashboard-title">🏥 Health2You Infrastructure</h1>
            <p class="dashboard-subtitle">WordPress Production Monitoring Dashboard v3.1</p>
            <div class="health-indicator">
                <span>$statusIndicator</span>
                <span>System Health:</span>
                <span class="health-score">$healthScore%</span>
            </div>
            <div class="capacity-indicator">
                <span>📦</span>
                <span>Instancias:</span>
                <span class="capacity-number">$($healthyInstances.Count)/$desiredCapacity</span>
                <span>Healthy</span>
            </div>
            <p style="margin-top: 1rem; font-family: var(--font-mono); font-size: 0.875rem; color: var(--color-text-dim);">
                $(Get-Date -Format 'dddd, MMMM dd, yyyy • HH:mm:ss UTC')
            </p>
        </header>

        $(if ($healthIssues.Count -gt 0) { 
            @"
<div class="alert-box">
    <h3>⚠️ Problemas Detectados</h3>
    <ul>
        $(($healthIssues | ForEach-Object { "<li>$_</li>" }) -join "")
    </ul>
</div>
"@
        } else { "" })

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon">✅</div>
                <div class="stat-value">$($healthyInstances.Count)</div>
                <div class="stat-label">Healthy Instances</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">⚠️</div>
                <div class="stat-value">$($unhealthyInstances.Count)</div>
                <div class="stat-label">Unhealthy Instances</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">💻</div>
                <div class="stat-value">$avgCPU%</div>
                <div class="stat-label">Avg CPU Usage</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon">🗄️</div>
                <div class="stat-value">$($rdsStatus.CPU)%</div>
                <div class="stat-label">RDS CPU</div>
            </div>
            $albStatsCards
        </div>

        $albSection

        <section class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">🌐 WordPress Instances</h2>
                <span class="badge badge-info">ASG: $ASG_NAME</span>
            </div>
            
            <div style="margin-bottom: 1rem; padding: 1rem; background: rgba(255,255,255,0.03); border-radius: 0.5rem;">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; font-size: 0.875rem;">
                    <div>
                        <span style="color: var(--color-text-dim);">Desired Capacity:</span>
                        <strong style="color: var(--color-accent); margin-left: 0.5rem;">$desiredCapacity</strong>
                    </div>
                    <div>
                        <span style="color: var(--color-text-dim);">Min/Max:</span>
                        <strong style="margin-left: 0.5rem;">$minSize / $maxSize</strong>
                    </div>
                    <div>
                        <span style="color: var(--color-text-dim);">Total Instances:</span>
                        <strong style="margin-left: 0.5rem;">$currentCapacity</strong>
                    </div>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Instance ID</th>
                            <th>Private IP</th>
                            <th>Type</th>
                            <th>CPU</th>
                            <th>Network</th>
                            <th>ASG State</th>
                            <th>EC2 State</th>
                            <th>Uptime</th>
                        </tr>
                    </thead>
                    <tbody>
                        $htmlWebRows
                    </tbody>
                </table>
            </div>
        </section>

        <section class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">📜 Recent ASG Events</h2>
                <span class="badge badge-info">Last 6 hours</span>
            </div>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Status</th>
                            <th>Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        $htmlRecentEvents
                    </tbody>
                </table>
            </div>
        </section>

        <section class="dashboard-section">
            <div class="section-header">
                <h2 class="section-title">🗄️ RDS Database</h2>
                <span class="badge badge-success">$($rdsStatus.Status)</span>
            </div>

            <div class="rds-info-grid">
                <div class="rds-info-item">
                    <label>Instance ID</label>
                    <strong>$($rdsStatus.Name)</strong>
                </div>
                <div class="rds-info-item">
                    <label>Engine</label>
                    <strong>$($rdsStatus.Engine)</strong>
                </div>
                <div class="rds-info-item">
                    <label>Instance Class</label>
                    <strong>$($rdsStatus.InstanceClass)</strong>
                </div>
                <div class="rds-info-item">
                    <label>Storage</label>
                    <strong>$($rdsStatus.Storage)</strong>
                </div>
                <div class="rds-info-item">
                    <label>Multi-AZ</label>
                    <strong>$($rdsStatus.MultiAZ)</strong>
                </div>
                <div class="rds-info-item">
                    <label>Availability Zone</label>
                    <strong>$($rdsStatus.AZ)</strong>
                </div>
                <div class="rds-info-item" style="grid-column: 1 / -1;">
                    <label>Endpoint</label>
                    <code>$($rdsStatus.Endpoint)</code>
                </div>
            </div>

            <h3 class="subsection-title">📊 Performance Metrics (Last 30 Minutes)</h3>
            <div class="metrics-row">
                <div class="metric-card metric-primary">
                    <div class="metric-icon">💻</div>
                    <div class="metric-value">$($rdsStatus.CPU)%</div>
                    <div class="metric-label">CPU Utilization</div>
                </div>
                <div class="metric-card metric-success">
                    <div class="metric-icon">🔗</div>
                    <div class="metric-value">$($rdsStatus.Connections)</div>
                    <div class="metric-label">DB Connections</div>
                </div>
                <div class="metric-card metric-info">
                    <div class="metric-icon">💾</div>
                    <div class="metric-value">$($rdsStatus.FreeStorageGB) GB</div>
                    <div class="metric-label">Free Storage</div>
                </div>
                <div class="metric-card metric-warning">
                    <div class="metric-icon">📖</div>
                    <div class="metric-value">$($rdsStatus.ReadLatencyMs) ms</div>
                    <div class="metric-label">Read Latency</div>
                </div>
                <div class="metric-card metric-warning">
                    <div class="metric-icon">✍️</div>
                    <div class="metric-value">$($rdsStatus.WriteLatencyMs) ms</div>
                    <div class="metric-label">Write Latency</div>
                </div>
            </div>
        </section>

        <footer class="dashboard-footer">
            <p>
                <strong>WordPress Enterprise Monitoring v3.1 Enhanced</strong><br>
                Métricas últimos 30 minutos • Detección completa de estado de instancias<br>
                Actualizado: $(Get-Date -Format 'HH:mm:ss UTC')<br>
                <a href="https://github.com/Samuel-reto/Grupo_2" target="_blank">GitHub Repository</a>
            </p>
        </footer>
    </div>
</body>
</html>
"@

# =========================================
# 8. SUBIR Y NOTIFICAR
# =========================================
$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$localPath = "/tmp/wp-dashboard-$timestamp.html"
$s3Key = "dashboards/wp-dashboard-$timestamp.html"

Write-Log "📤 Subiendo a S3..."
$reportContent | Out-File -FilePath $localPath -Encoding utf8
aws s3 cp $localPath s3://$S3_BUCKET/$s3Key --region $AWS_REGION --content-type "text/html" 2>&1 | Out-Null
$dashboardUrl = "https://$S3_BUCKET.s3.amazonaws.com/$s3Key"

$snsMessage = @"
$statusIndicator WORDPRESS INFRASTRUCTURE
$(Get-Date -Format 'dd/MM/yyyy HH:mm:ss')

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
SYSTEM HEALTH: $healthScore%
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

📦 ASG CAPACITY: $($healthyInstances.Count)/$desiredCapacity (Min: $minSize, Max: $maxSize)
   ✅ Healthy: $($healthyInstances.Count)
   ⚠️  Unhealthy: $($unhealthyInstances.Count)
   🔄 Terminating: $($terminatingInstances.Count)
   ⚪ Other: $($otherStateInstances.Count)

🌐 WEB INSTANCES (Healthy):
   • CPU promedio: $avgCPU%
   • Network IN: $totalNetworkInMB MB
   • Network OUT: $totalNetworkOutMB MB

🗄️  RDS: $rdsInstanceId
   • CPU: $($rdsStatus.CPU)%
   • Conexiones: $($rdsStatus.Connections)
   • Storage libre: $($rdsStatus.FreeStorageGB) GB
"@

if ($albStatus) {
    $snsMessage += @"

⚖️  ALB: $($albStatus.Name)
   • Targets: $($albStatus.HealthyTargets)/$($albStatus.TotalTargets) ($($albStatus.HealthPercent)%)
   • Response: $($albStatus.ResponseTimeMs)ms
   • Requests: $($albStatus.RequestCount)
"@
}

if ($healthIssues.Count -gt 0) {
    $snsMessage += @"

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
⚠️  PROBLEMAS DETECTADOS:
$($healthIssues | ForEach-Object { "   $_" } | Out-String)
"@
}

if ($recentEvents.Count -gt 0) {
    $snsMessage += @"

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
📜 EVENTOS RECIENTES (6h):
$($recentEvents | Select-Object -First 3 | ForEach-Object { "   • $($_.StatusCode): $($_.Description)" } | Out-String)
"@
}

$snsMessage += @"

📊 DASHBOARD: $dashboardUrl
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
"@

Write-Log "📧 Enviando SNS..."
aws sns publish --topic-arn $SNS_TOPIC_ARN `
    --subject "$statusIndicator WordPress [$($healthyInstances.Count)/$desiredCapacity] - $healthScore% - $(Get-Date -Format 'dd/MM HH:mm')" `
    --message "$snsMessage" `
    --region $AWS_REGION 2>&1 | Out-Null

Write-Log "✅ Dashboard: $dashboardUrl"
Write-Log "🎉 COMPLETADO: Salud $healthScore% | Healthy: $($healthyInstances.Count)/$desiredCapacity"
Write-Log "========================================"
