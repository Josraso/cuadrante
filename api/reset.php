<?php
require_once '../config.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}

try {
    $db->beginTransaction();

    // Eliminar todos los cuadrantes y asignaciones (por CASCADE)
    $db->exec("DELETE FROM cuadrantes");

    // Eliminar todo el histórico de turnos
    $db->exec("DELETE FROM historico_turnos");

    $db->commit();

    jsonResponse([
        'success' => true,
        'message' => 'Sistema reseteado correctamente. Todos los cuadrantes e histórico han sido eliminados.'
    ]);

} catch (Exception $e) {
    $db->rollBack();
    jsonResponse([
        'success' => false,
        'message' => 'Error al resetear: ' . $e->getMessage()
    ], 500);
}
