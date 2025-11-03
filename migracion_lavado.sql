-- Migración para añadir 'lavado' al ENUM de historico_turnos
-- Ejecutar este script si ya tienes la base de datos creada

USE cuadrante_pulido;

-- Modificar la columna turno para incluir 'lavado'
ALTER TABLE historico_turnos
MODIFY COLUMN turno ENUM('mañana', 'tarde', 'lavado') NOT NULL
COMMENT 'tarde=turno tarde, lavado=lavado semanal';

-- Añadir índice para mejor rendimiento en consultas de lavado
ALTER TABLE historico_turnos
ADD KEY idx_turno (turno, fecha_inicio_semana);

-- Verificar los cambios
DESCRIBE historico_turnos;
