-- Añadir campos para sistema de sustituciones
ALTER TABLE asignaciones
ADD COLUMN sustituye_a INT NULL,
ADD COLUMN es_sustitucion TINYINT DEFAULT 0;

-- Añadir clave foránea (con ON DELETE CASCADE para que si se borra la original, se borre la sustitución)
ALTER TABLE asignaciones
ADD FOREIGN KEY (sustituye_a) REFERENCES asignaciones(id) ON DELETE CASCADE;

-- Añadir índice para mejorar rendimiento en búsquedas
CREATE INDEX idx_sustitucion ON asignaciones(sustituye_a, es_sustitucion);
