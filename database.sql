-- Crear base de datos
CREATE DATABASE IF NOT EXISTS cuadrante_pulido CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE cuadrante_pulido;

-- Tabla de personas
CREATE TABLE IF NOT EXISTS personas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    puede_rotar BOOLEAN DEFAULT 1 COMMENT '1=Puede rotar turnos, 0=Solo mañanas',
    puede_lavar BOOLEAN DEFAULT 1 COMMENT '1=Puede ir a Lavado, 0=No puede',
    activo BOOLEAN DEFAULT 0 COMMENT '0=Inactivo (recién creado), 1=Activo (incorporado a cuadrante)',
    fecha_baja DATE DEFAULT NULL COMMENT 'NULL=activo, Fecha=de baja desde esa fecha',
    fecha_creacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY idx_nombre (nombre),
    KEY idx_activo (activo),
    KEY idx_fecha_baja (fecha_baja)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de cuadrantes generados
CREATE TABLE IF NOT EXISTS cuadrantes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(200) NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    num_semanas INT NOT NULL,
    fecha_generacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fechas (fecha_inicio, fecha_fin)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de asignaciones
CREATE TABLE IF NOT EXISTS asignaciones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cuadrante_id INT NOT NULL,
    persona_id INT NOT NULL,
    fecha DATE NOT NULL,
    turno ENUM('mañana', 'tarde') NOT NULL,
    puesto ENUM('pulido1', 'pulido2', 'pulido3', 'pulido4', 'pulido5', 'lavado') NOT NULL,
    editado_manualmente BOOLEAN DEFAULT 0,
    es_sustitucion BOOLEAN DEFAULT 0 COMMENT '0=asignación original, 1=sustitución temporal',
    sustituye_a INT DEFAULT NULL COMMENT 'ID de la asignación original que está sustituyendo',
    FOREIGN KEY (cuadrante_id) REFERENCES cuadrantes(id) ON DELETE CASCADE,
    FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE CASCADE,
    FOREIGN KEY (sustituye_a) REFERENCES asignaciones(id) ON DELETE CASCADE,
    KEY idx_cuadrante_fecha (cuadrante_id, fecha, turno),
    KEY idx_persona_fecha (persona_id, fecha),
    KEY idx_sustitucion (sustituye_a, es_sustitucion)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabla de histórico de turnos (para control de semanas consecutivas)
CREATE TABLE IF NOT EXISTS historico_turnos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    persona_id INT NOT NULL,
    fecha_inicio_semana DATE NOT NULL COMMENT 'Lunes de la semana',
    turno ENUM('mañana', 'tarde', 'lavado') NOT NULL COMMENT 'tarde=turno tarde, lavado=lavado semanal',
    FOREIGN KEY (persona_id) REFERENCES personas(id) ON DELETE CASCADE,
    KEY idx_persona_semana (persona_id, fecha_inicio_semana),
    KEY idx_turno (turno, fecha_inicio_semana)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertar datos iniciales
INSERT INTO personas (nombre, puede_rotar, puede_lavar) VALUES
('Sindo', 0, 0),
('Juan Ramón', 0, 0),
('Alfaro', 0, 1),
('Alfonso', 1, 1),
('José Manuel', 1, 1),
('Chayan', 1, 1),
('El Cubano', 1, 1);
