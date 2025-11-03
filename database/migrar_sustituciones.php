<?php
require_once __DIR__ . '/../config.php';

try {
    $db = getDB();

    echo "Aplicando migración de sustituciones...\n";

    // Leer el archivo SQL
    $sql = file_get_contents(__DIR__ . '/add_sustituciones.sql');

    // Ejecutar cada statement
    $statements = explode(';', $sql);

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }

        try {
            $db->exec($statement);
            echo "✓ Ejecutado: " . substr($statement, 0, 50) . "...\n";
        } catch (PDOException $e) {
            // Ignorar errores de "columna ya existe"
            if (strpos($e->getMessage(), 'duplicate column name') !== false) {
                echo "⚠ Ya existe, saltando: " . substr($statement, 0, 50) . "...\n";
            } else {
                throw $e;
            }
        }
    }

    echo "\n✓ Migración completada exitosamente.\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
