-- ============================================================================
-- Health2You - Modificaciones Base de Datos Sistema Videollamadas
-- Compatible con MySQL RDS
-- ============================================================================


-- =====================================================
-- 1. Añadir columna fecha_fin si no existe
-- =====================================================

SET @columna_existe := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'videollamada'
    AND COLUMN_NAME = 'fecha_fin'
);

SET @sql = IF(
    @columna_existe = 0,
    'ALTER TABLE videollamada 
     ADD COLUMN fecha_fin DATETIME NULL
     COMMENT "Fecha y hora de finalización de la videollamada"
     AFTER fecha_inicio',
    'SELECT "Columna fecha_fin ya existe"'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;



-- =====================================================
-- 2. Índice para cooldown por paciente
-- =====================================================

SET @idx_existe := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'videollamada'
    AND INDEX_NAME = 'idx_paciente_fecha'
);

SET @sql = IF(
    @idx_existe = 0,
    'ALTER TABLE videollamada
     ADD INDEX idx_paciente_fecha (paciente_id, fecha_solicitud)',
    'SELECT "Índice idx_paciente_fecha ya existe"'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;



-- =====================================================
-- 3. Índice para token + estado
-- =====================================================

SET @idx_existe := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'videollamada'
    AND INDEX_NAME = 'idx_token_estado'
);

SET @sql = IF(
    @idx_existe = 0,
    'ALTER TABLE videollamada
     ADD INDEX idx_token_estado (token, estado)',
    'SELECT "Índice idx_token_estado ya existe"'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;



-- =====================================================
-- 4. Consulta verificación estructura tabla
-- =====================================================

SELECT 
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_DEFAULT,
    COLUMN_COMMENT
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME = 'videollamada'
ORDER BY ORDINAL_POSITION;
