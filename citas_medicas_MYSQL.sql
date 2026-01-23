-- =====================================================
-- DDL MySQL RDS - Citas Médicas + TELÉFONO
-- Convertido desde PostgreSQL para compatibilidad MySQL
-- =====================================================

-- 1. DROP de tablas, triggers y vistas
DROP TABLE IF EXISTS justificante;
DROP TABLE IF EXISTS cita;
DROP TABLE IF EXISTS paciente;
DROP TABLE IF EXISTS medico;
DROP TRIGGER IF EXISTS trigger_justificante;
DROP VIEW IF EXISTS vista_citas_completas;

-- 2. Crear tablas con AUTO_INCREMENT
CREATE TABLE paciente (
  paciente_id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  apellidos VARCHAR(100) NOT NULL,
  numero_tsi VARCHAR(20) UNIQUE,
  telefono VARCHAR(20),
  email VARCHAR(100),
  fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE medico (
  medico_id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  apellidos VARCHAR(100) NOT NULL,
  colegiado VARCHAR(20) UNIQUE NOT NULL,
  especialidad VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cita (
  cita_id INT AUTO_INCREMENT PRIMARY KEY,
  paciente_id INT NOT NULL,
  medico_id INT NOT NULL,
  fecha_hora_inicio DATETIME NOT NULL,
  fecha_hora_fin DATETIME NOT NULL,
  estado VARCHAR(20) DEFAULT 'pendiente',
  fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (paciente_id) REFERENCES paciente(paciente_id),
  FOREIGN KEY (medico_id) REFERENCES medico(medico_id),
  CHECK (estado IN ('pendiente', 'asistida', 'cancelada')),
  CHECK (fecha_hora_fin > fecha_hora_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE justificante (
  justificante_id INT AUTO_INCREMENT PRIMARY KEY,
  cita_id INT UNIQUE NOT NULL,
  fecha_emision TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  numero_serie VARCHAR(50) UNIQUE NOT NULL,
  emitido_por INT NOT NULL,
  FOREIGN KEY (cita_id) REFERENCES cita(cita_id),
  FOREIGN KEY (emitido_por) REFERENCES medico(medico_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. ÍNDICES
CREATE INDEX idx_paciente_tsi ON paciente(numero_tsi);
CREATE INDEX idx_paciente_telefono ON paciente(telefono);
CREATE INDEX idx_medico_colegiado ON medico(colegiado);
CREATE INDEX idx_medico_especialidad ON medico(especialidad);
CREATE INDEX idx_cita_paciente ON cita(paciente_id);
CREATE INDEX idx_cita_medico ON cita(medico_id);
CREATE INDEX idx_cita_fecha ON cita(fecha_hora_inicio);
CREATE INDEX idx_justificante_cita ON justificante(cita_id);

-- 4. TRIGGER (adaptado a MySQL)
DELIMITER $$

CREATE TRIGGER trigger_justificante
AFTER UPDATE ON cita
FOR EACH ROW
BEGIN
  IF NEW.estado = 'asistida' AND OLD.estado != 'asistida' THEN
    INSERT INTO justificante (cita_id, numero_serie, emitido_por)
    VALUES (
      NEW.cita_id,
      CONCAT('JST-', NEW.cita_id, '-', DATE_FORMAT(NOW(), '%Y%m%d')),
      NEW.medico_id
    );
  END IF;
END$$

DELIMITER ;

-- 5. VISTA con CONCAT en lugar de ||
CREATE VIEW vista_citas_completas AS
SELECT
  c.cita_id,
  p.numero_tsi,
  p.telefono,
  CONCAT(p.nombre, ' ', p.apellidos) AS paciente_nombre,
  m.medico_id,
  CONCAT(m.nombre, ' ', m.apellidos) AS medico_nombre,
  m.especialidad,
  c.fecha_hora_inicio,
  c.estado,
  j.numero_serie,
  j.fecha_emision
FROM cita c
JOIN paciente p ON c.paciente_id = p.paciente_id
JOIN medico m ON c.medico_id = m.medico_id
LEFT JOIN justificante j ON c.cita_id = j.cita_id;

-- 6. Datos de prueba con teléfono (COMENTADOS - descomentar si necesitas)
/*
INSERT INTO paciente (nombre, apellidos, numero_tsi, telefono, email) VALUES
('María', 'García López', 'CANT390123456789', '600123456', NULL),
('Juan', 'Pérez Martínez', 'CANT391234567890', '942765432', 'juan@email.com'),
('Ana', 'Rodríguez Torres', 'CANT392345678901', '600987654', NULL);

INSERT INTO medico (nombre, apellidos, colegiado, especialidad) VALUES
('Dr. Carlos', 'Sánchez Ruiz', '313103795', 'Medicina General'),
('Dra. Laura', 'Fernández Gómez', '280234567', 'Cardiología'),
('Dr. Miguel', 'López Herrera', '190876543', 'Pediatría');

INSERT INTO cita (paciente_id, medico_id, fecha_hora_inicio, fecha_hora_fin, estado) VALUES
(1, 1, '2026-01-21 10:00:00', '2026-01-21 10:20:00', 'pendiente'),
(2, 2, '2026-01-20 11:00:00', '2026-01-20 11:20:00', 'pendiente'),
(3, 3, '2026-01-20 15:30:00', '2026-01-20 15:50:00', 'pendiente');

-- Verificar con teléfono
SELECT * FROM vista_citas_completas;
*/
