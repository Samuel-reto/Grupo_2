-- =====================================================
-- MIGRACIÓN: Sistema de Videollamadas Health2You
-- =====================================================

-- 1. Añadir campo "atiende_urgencias" a la tabla de médicos
ALTER TABLE medico 
ADD COLUMN atiende_urgencias TINYINT(1) DEFAULT 0 
COMMENT 'Indica si el médico atiende urgencias' 
AFTER especialidad;


-- 2. Crear tabla para gestionar solicitudes de videollamada
CREATE TABLE IF NOT EXISTS videollamada (
    videollamada_id INT(11) NOT NULL AUTO_INCREMENT,
    paciente_id INT(11) NOT NULL,
    medico_id INT(11) DEFAULT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    estado ENUM('solicitada', 'aceptada', 'en_curso', 'finalizada', 'rechazada', 'expirada') DEFAULT 'solicitada',
    motivo TEXT COMMENT 'Descripción de la urgencia',
    fecha_solicitud DATETIME NOT NULL,
    fecha_aceptacion DATETIME DEFAULT NULL,
    fecha_inicio DATETIME DEFAULT NULL,
    fecha_fin DATETIME DEFAULT NULL,
    expira_en DATETIME NOT NULL COMMENT 'Fecha de expiración del token',
    PRIMARY KEY (videollamada_id),
    INDEX idx_estado (estado),
    INDEX idx_medico (medico_id),
    INDEX idx_paciente (paciente_id),
    INDEX idx_token (token),
    INDEX idx_expiracion (expira_en),
    FOREIGN KEY (paciente_id) REFERENCES paciente(paciente_id) ON DELETE CASCADE,
    FOREIGN KEY (medico_id) REFERENCES medico(medico_id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tabla para gestión de videollamadas urgentes';


-- 3. Crear tabla para logs de la llamada
CREATE TABLE IF NOT EXISTS videollamada_log (
    log_id INT(11) NOT NULL AUTO_INCREMENT,
    videollamada_id INT(11) NOT NULL,
    usuario_id INT(11) NOT NULL,
    tipo_usuario ENUM('paciente', 'medico') NOT NULL,
    accion ENUM('entro', 'salio', 'expulsado') NOT NULL,
    timestamp DATETIME NOT NULL,
    PRIMARY KEY (log_id),
    INDEX idx_videollamada (videollamada_id),
    FOREIGN KEY (videollamada_id) REFERENCES videollamada(videollamada_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Log de eventos de videollamadas';


-- 4. Crear evento para limpiar automáticamente solicitudes expiradas
DELIMITER $$

CREATE EVENT IF NOT EXISTS limpiar_videollamadas_expiradas
ON SCHEDULE EVERY 10 MINUTE
DO
BEGIN
    UPDATE videollamada
    SET estado = 'expirada'
    WHERE estado IN ('solicitada', 'aceptada')
    AND expira_en < NOW();
END$$

DELIMITER ;


-- 5. Verificar que los eventos estén habilitados
SET GLOBAL event_scheduler = ON;


-- 6. Insertar datos de prueba (OPCIONAL)
-- UPDATE medico SET atiende_urgencias = 1 WHERE medico_id = 1;
