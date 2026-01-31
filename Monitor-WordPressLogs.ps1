#!/usr/bin/env pwsh
<#
.SYNOPSIS
    Script de monitoreo de logs de WordPress para infraestructura AWS
.DESCRIPTION
    Recopila logs de error de Apache desde instancias del Auto Scaling Group
    y envía notificaciones por email usando AWS SNS
.AUTHOR
    Grupo 2 - AG Informática
.DATE
    2026-01-30
#>

# Configuración
$ErrorActionPreference = "Continue"
$ASG_NAME = "WordPress-ASG"
$REGION = "us-east-1"
$SNS_TOPIC_ARN = "" # Se configurará desde variable de entorno o parámetro
$LOG_FILE = "/var/log/wordpress-monitor.log"
$TEMP_DIR = "/tmp/wordpress-logs"
$MAX_ERRORS = 50

# Función para escribir logs
function Write-Log {
    param([string]$Message)
    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $logMessage = "[$timestamp] $Message"
    Write-Host $logMessage
    Add-Content -Path $LOG_FILE -Value $logMessage
}

# Función para obtener instancias del ASG
function Get-ASGInstances {
    Write-Log "Obteniendo instancias del Auto Scaling Group: $ASG_NAME"

    try {
        $asgInfo = aws autoscaling describe-auto-scaling-groups `
            --auto-scaling-group-names $ASG_NAME `
            --region $REGION `
            --query 'AutoScalingGroups[0].Instances[?LifecycleState==`InService`].[InstanceId,PrivateIpAddress]' `
            --output json | ConvertFrom-Json

        if ($asgInfo.Count -eq 0) {
            Write-Log "⚠️  No se encontraron instancias en servicio"
            return @()
        }

        Write-Log "✓ Encontradas $($asgInfo.Count) instancias en servicio"
        return $asgInfo
    }
    catch {
        Write-Log "❌ Error obteniendo instancias del ASG: $_"
        return @()
    }
}

# Función para extraer logs de una instancia
function Get-InstanceLogs {
    param(
        [string]$InstanceId,
        [string]$PrivateIP
    )

    Write-Log "Conectando a instancia $InstanceId ($PrivateIP)..."

    $logContent = @{
        InstanceId = $InstanceId
        PrivateIP = $PrivateIP
        ApacheErrors = @()
        PHPErrors = @()
        WordPressErrors = @()
        Critical = 0
        Warning = 0
        Notice = 0
    }

    try {
        # Extraer últimas líneas del log de Apache (últimas 1000 líneas)
        $apacheLog = ssh -o StrictHostKeyChecking=no -o ConnectTimeout=10 `
            ubuntu@$PrivateIP "sudo tail -n 1000 /var/log/apache2/error.log 2>/dev/null"

        if ($LASTEXITCODE -eq 0 -and $apacheLog) {
            # Analizar logs línea por línea
            $apacheLog -split "`n" | ForEach-Object {
                $line = $_.Trim()
                if ($line) {
                    # Detectar nivel de error
                    if ($line -match "\[.*:error\]" -or $line -match "\[.*:crit\]" -or $line -match "\[.*:alert\]" -or $line -match "\[.*:emerg\]") {
                        $logContent.ApacheErrors += $line
                        $logContent.Critical++
                    }
                    elseif ($line -match "\[.*:warn\]") {
                        $logContent.Warning++
                    }
                    elseif ($line -match "\[.*:notice\]") {
                        $logContent.Notice++
                    }

                    # Detectar errores de PHP
                    if ($line -match "PHP (Fatal error|Parse error|Warning|Notice)") {
                        $logContent.PHPErrors += $line
                    }

                    # Detectar errores de WordPress
                    if ($line -match "(wp-|wordpress|MySQL|database connection)") {
                        $logContent.WordPressErrors += $line
                    }
                }
            }

            Write-Log "✓ Logs extraídos: $($logContent.Critical) críticos, $($logContent.Warning) warnings"
        }
        else {
            Write-Log "⚠️  No se pudieron obtener logs de Apache"
        }

        # Extraer información de uso de recursos
        $diskUsage = ssh -o StrictHostKeyChecking=no ubuntu@$PrivateIP "df -h /var/www/html | tail -1 | awk '{print \$5}'" 2>/dev/null
        $logContent.DiskUsage = $diskUsage.Trim()

        $memUsage = ssh -o StrictHostKeyChecking=no ubuntu@$PrivateIP "free -m | grep Mem | awk '{printf \"%d%%\", \$3/\$2 * 100}'" 2>/dev/null
        $logContent.MemoryUsage = $memUsage.Trim()

    }
    catch {
        Write-Log "❌ Error extrayendo logs de $InstanceId : $_"
    }

    return $logContent
}

# Función para generar reporte HTML
function New-HTMLReport {
    param($AllLogs)

    $timestamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
    $totalCritical = ($AllLogs | Measure-Object -Property Critical -Sum).Sum
    $totalWarnings = ($AllLogs | Measure-Object -Property Warning -Sum).Sum

    $html = @"
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f5f5f5; }
        .header { background-color: #232f3e; color: white; padding: 20px; border-radius: 5px; }
        .summary { background-color: #fff; padding: 15px; margin: 20px 0; border-radius: 5px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .critical { color: #d32f2f; font-weight: bold; }
        .warning { color: #f57c00; }
        .ok { color: #388e3c; }
        .instance { background-color: #fff; padding: 15px; margin: 10px 0; border-left: 4px solid #ff9900; border-radius: 5px; }
        .error-log { background-color: #fff3e0; padding: 10px; margin: 5px 0; border-radius: 3px; font-family: monospace; font-size: 12px; overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        th { background-color: #232f3e; color: white; padding: 10px; text-align: left; }
        td { padding: 8px; border-bottom: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Reporte de Monitoreo - WordPress Infrastructure</h1>
        <p>Generado: $timestamp</p>
    </div>

    <div class="summary">
        <h2>Resumen General</h2>
        <table>
            <tr>
                <th>Métrica</th>
                <th>Valor</th>
            </tr>
            <tr>
                <td>Instancias monitorizadas</td>
                <td>$($AllLogs.Count)</td>
            </tr>
            <tr>
                <td>Errores críticos totales</td>
                <td class="$(if($totalCritical -gt 0){'critical'}else{'ok'})">$totalCritical</td>
            </tr>
            <tr>
                <td>Warnings totales</td>
                <td class="$(if($totalWarnings -gt 10){'warning'}else{'ok'})">$totalWarnings</td>
            </tr>
        </table>
    </div>
"@

    foreach ($log in $AllLogs) {
        $statusClass = if ($log.Critical -gt 0) { "critical" } elseif ($log.Warning -gt 5) { "warning" } else { "ok" }

        $html += @"
    <div class="instance">
        <h3 class="$statusClass">🖥️ Instancia: $($log.InstanceId)</h3>
        <p><strong>IP Privada:</strong> $($log.PrivateIP)</p>
        <p><strong>Uso de Disco:</strong> $($log.DiskUsage) | <strong>Uso de Memoria:</strong> $($log.MemoryUsage)</p>
        <p><strong>Errores críticos:</strong> <span class="critical">$($log.Critical)</span> | 
           <strong>Warnings:</strong> <span class="warning">$($log.Warning)</span></p>

"@

        if ($log.ApacheErrors.Count -gt 0) {
            $html += "<h4>Errores de Apache (mostrando máximo $MAX_ERRORS):</h4>`n"
            $errorsToShow = $log.ApacheErrors | Select-Object -First $MAX_ERRORS
            foreach ($error in $errorsToShow) {
                $html += "<div class='error-log'>$($error -replace '<','&lt;' -replace '>','&gt;')</div>`n"
            }
            if ($log.ApacheErrors.Count -gt $MAX_ERRORS) {
                $html += "<p><em>... y $($log.ApacheErrors.Count - $MAX_ERRORS) errores más</em></p>`n"
            }
        }

        if ($log.PHPErrors.Count -gt 0) {
            $html += "<h4>Errores de PHP:</h4>`n"
            $phpErrorsToShow = $log.PHPErrors | Select-Object -First 10
            foreach ($error in $phpErrorsToShow) {
                $html += "<div class='error-log'>$($error -replace '<','&lt;' -replace '>','&gt;')</div>`n"
            }
        }

        $html += "</div>`n"
    }

    $html += @"
    <div class="summary">
        <p><em>Este reporte ha sido generado automáticamente por el sistema de monitoreo de WordPress.</em></p>
        <p>Infraestructura gestionada por AG Informática - Grupo 2</p>
    </div>
</body>
</html>
"@

    return $html
}

# Función para enviar notificación por SNS
function Send-SNSNotification {
    param(
        [string]$Subject,
        [string]$HTMLMessage
    )

    if ([string]::IsNullOrEmpty($SNS_TOPIC_ARN)) {
        Write-Log "⚠️  SNS_TOPIC_ARN no configurado. Guardando reporte localmente..."
        $reportPath = "/tmp/wordpress-report-$(Get-Date -Format 'yyyyMMdd-HHmmss').html"
        $HTMLMessage | Out-File -FilePath $reportPath -Encoding UTF8
        Write-Log "✓ Reporte guardado en: $reportPath"
        return
    }

    Write-Log "Enviando notificación por SNS..."

    try {
        # Guardar el HTML temporalmente
        $tempFile = "/tmp/sns-message.html"
        $HTMLMessage | Out-File -FilePath $tempFile -Encoding UTF8

        # Enviar por SNS
        aws sns publish `
            --topic-arn $SNS_TOPIC_ARN `
            --subject $Subject `
            --message "file://$tempFile" `
            --region $REGION

        if ($LASTEXITCODE -eq 0) {
            Write-Log "✓ Notificación enviada correctamente"
        }
        else {
            Write-Log "❌ Error enviando notificación por SNS"
        }

        Remove-Item $tempFile -ErrorAction SilentlyContinue
    }
    catch {
        Write-Log "❌ Error en Send-SNSNotification: $_"
    }
}

# SCRIPT PRINCIPAL
Write-Log "========================================="
Write-Log "Iniciando monitoreo de WordPress"
Write-Log "========================================="

# Crear directorio temporal
New-Item -ItemType Directory -Force -Path $TEMP_DIR | Out-Null

# Obtener instancias del ASG
$instances = Get-ASGInstances

if ($instances.Count -eq 0) {
    Write-Log "❌ No hay instancias para monitorizar. Finalizando."
    exit 1
}

# Recolectar logs de todas las instancias
$allLogs = @()

foreach ($instance in $instances) {
    $instanceId = $instance[0]
    $privateIP = $instance[1]

    $logs = Get-InstanceLogs -InstanceId $instanceId -PrivateIP $privateIP
    $allLogs += $logs
}

# Generar reporte
$totalCritical = ($allLogs | Measure-Object -Property Critical -Sum).Sum
$totalWarnings = ($allLogs | Measure-Object -Property Warning -Sum).Sum

$subject = "WordPress Monitoring Report - "
if ($totalCritical -gt 0) {
    $subject += "⚠️ ERRORES CRÍTICOS DETECTADOS"
}
elseif ($totalWarnings -gt 10) {
    $subject += "⚠️ Múltiples Warnings"
}
else {
    $subject += "✓ Sistema Operativo Correctamente"
}

$htmlReport = New-HTMLReport -AllLogs $allLogs

# Enviar notificación
Send-SNSNotification -Subject $subject -HTMLMessage $htmlReport

Write-Log "========================================="
Write-Log "Monitoreo completado"
Write-Log "Críticos: $totalCritical | Warnings: $totalWarnings"
Write-Log "========================================="

# Limpiar archivos temporales antiguos (más de 7 días)
Get-ChildItem -Path "/tmp" -Filter "wordpress-report-*.html" | 
    Where-Object { $_.LastWriteTime -lt (Get-Date).AddDays(-7) } | 
    Remove-Item -Force

exit 0
