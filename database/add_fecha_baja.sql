-- Añadir campo fecha_baja a tabla personas
ALTER TABLE personas ADD COLUMN fecha_baja DATE NULL;

-- Comentario: NULL significa que la persona NO está de baja
-- Si tiene fecha, significa que está de baja desde esa fecha
