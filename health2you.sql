-- ========================================
-- Script para levantar DB WordPress + Health2You
-- Generado desde esquema INFORMATION_SCHEMA
-- Ejecutar en MySQL nuevo: CREATE DATABASE wordpress; USE wordpress;
-- ========================================

DROP DATABASE IF EXISTS `wordpress`;
CREATE DATABASE `wordpress` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `wordpress`;

-- TABLA: cita
CREATE TABLE `cita` (
  `cita_id` int NOT NULL AUTO_INCREMENT,
  `paciente_id` int NOT NULL,
  `medico_id` int NOT NULL,
  `fecha_hora_inicio` datetime NOT NULL,
  `fecha_hora_fin` datetime NOT NULL,
  `estado` varchar(20) DEFAULT 'pendiente',
  `fecha_creacion` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`cita_id`),
  KEY `paciente_id` (`paciente_id`),
  KEY `medico_id` (`medico_id`),
  KEY `fecha_hora_inicio` (`fecha_hora_inicio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: justificante
CREATE TABLE `justificante` (
  `justificante_id` int NOT NULL AUTO_INCREMENT,
  `cita_id` int NOT NULL,
  `fecha_emision` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `numero_serie` varchar(50) NOT NULL,
  `emitido_por` int NOT NULL,
  PRIMARY KEY (`justificante_id`),
  UNIQUE KEY `cita_id` (`cita_id`),
  UNIQUE KEY `numero_serie` (`numero_serie`),
  KEY `emitido_por` (`emitido_por`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: medico
CREATE TABLE `medico` (
  `medico_id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `colegiado` varchar(20) NOT NULL,
  `especialidad` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  PRIMARY KEY (`medico_id`),
  UNIQUE KEY `colegiado` (`colegiado`),
  KEY `especialidad` (`especialidad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- TABLA: paciente
CREATE TABLE `paciente` (
  `paciente_id` int NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `apellidos` varchar(100) NOT NULL,
  `numero_tsi` varchar(20) DEFAULT NULL,
  `telefono` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `fecha_registro` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`paciente_id`),
  UNIQUE KEY `numero_tsi` (`numero_tsi`),
  KEY `telefono` (`telefono`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- VISTA: vista_citas_completas
CREATE OR REPLACE VIEW `vista_citas_completas` AS
SELECT 
    c.cita_id, p.numero_tsi, p.telefono,
    CONCAT(p.nombre, ' ', p.apellidos) AS paciente_nombre,
    c.medico_id, CONCAT(m.nombre, ' ', m.apellidos) AS medico_nombre,
    m.especialidad, c.fecha_hora_inicio, c.estado,
    j.numero_serie, j.fecha_emision
FROM cita c
JOIN paciente p ON c.paciente_id = p.paciente_id
JOIN medico m ON c.medico_id = m.medico_id
LEFT JOIN justificante j ON c.cita_id = j.cita_id;

-- TABLAS WORDPRESS (estándar, solo las principales)
CREATE TABLE `wp_options` (
  `option_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `option_name` varchar(191) NOT NULL,
  `option_value` longtext NOT NULL,
  `autoload` varchar(20) NOT NULL DEFAULT 'yes',
  PRIMARY KEY (`option_id`),
  UNIQUE KEY `option_name` (`option_name`),
  KEY `autoload` (`autoload`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `wp_users` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_login` varchar(60) NOT NULL,
  `user_pass` varchar(255) NOT NULL,
  `user_nicename` varchar(50) NOT NULL,
  `user_email` varchar(100) NOT NULL,
  `user_url` varchar(100) NOT NULL,
  `user_registered` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `user_activation_key` varchar(255) NOT NULL,
  `user_status` int(11) NOT NULL DEFAULT 0,
  `display_name` varchar(250) NOT NULL,
  PRIMARY KEY (`ID`),
  KEY `user_login_key` (`user_login`),
  KEY `user_nicename` (`user_nicename`),
  KEY `user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Otras tablas WP (resumidas - ejecutar mysqldump completo para todas)
CREATE TABLE `wp_posts` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_author` bigint(20) unsigned NOT NULL DEFAULT 0,
  `post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content` longtext NOT NULL,
  `post_title` text NOT NULL,
  `post_excerpt` text NOT NULL,
  `post_status` varchar(20) NOT NULL DEFAULT 'publish',
  `comment_status` varchar(20) NOT NULL DEFAULT 'open',
  `ping_status` varchar(20) NOT NULL DEFAULT 'open',
  `post_password` varchar(255) NOT NULL DEFAULT '',
  `post_name` varchar(200) NOT NULL DEFAULT '',
  `post_modified` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_modified_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content_filtered` longtext NOT NULL,
  `post_parent` bigint(20) unsigned NOT NULL DEFAULT 0,
  `guid` varchar(255) NOT NULL DEFAULT '',
  `menu_order` int(11) NOT NULL DEFAULT 0,
  `post_type` varchar(20) NOT NULL DEFAULT 'post',
  `post_mime_type` varchar(100) NOT NULL DEFAULT '',
  `comment_count` bigint(20) NOT NULL DEFAULT 0,
  PRIMARY KEY (`ID`),
  KEY `post_name` (`post_name`),
  KEY `post_parent` (`post_parent`),
  KEY `post_type` (`post_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INSERT DATOS EJEMPLO (agrega tus datos reales después)
INSERT INTO `medico` (`nombre`, `apellidos`, `email`, `colegiado`, `especialidad`, `password_hash`) 
VALUES ('Juan', 'Pérez', 'juan@clinic.com', '123456', 'Cardiología', '$2y$10$demo_hash');

-- ========================================
-- ¡Script listo! Ejecutar completo en nuevo MySQL
-- ========================================
