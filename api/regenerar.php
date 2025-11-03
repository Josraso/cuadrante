<?php
require_once '../config.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$cuadranteId = $data['cuadrante_id'] ?? null;
$fechaDesde = $data['fecha_desde'] ?? null;

if (!$cuadranteId || !$fechaDesde) {
    jsonResponse(['success' => false, 'message' => 'ID de cuadrante y fecha desde son requeridos'], 400);
}

try {
    $db->beginTransaction();

    // Obtener información del cuadrante
    $stmt = $db->prepare("SELECT * FROM cuadrantes WHERE id = ?");
    $stmt->execute([$cuadranteId]);
    $cuadrante = $stmt->fetch();

    if (!$cuadrante) {
        jsonResponse(['success' => false, 'message' => 'Cuadrante no encontrado'], 404);
    }

    // Verificar que la fecha desde esté dentro del rango del cuadrante
    if ($fechaDesde < $cuadrante['fecha_inicio'] || $fechaDesde > $cuadrante['fecha_fin']) {
        jsonResponse(['success' => false, 'message' => 'La fecha debe estar dentro del rango del cuadrante'], 400);
    }

    // Eliminar asignaciones desde la fecha especificada
    $stmt = $db->prepare("DELETE FROM asignaciones WHERE cuadrante_id = ? AND fecha >= ?");
    $stmt->execute([$cuadranteId, $fechaDesde]);

    // Eliminar histórico de turnos desde esa fecha
    $stmt = $db->prepare("DELETE FROM historico_turnos WHERE fecha_inicio_semana >= ?");
    $stmt->execute([$fechaDesde]);

    $db->commit();

    jsonResponse([
        'success' => true,
        'message' => 'Asignaciones eliminadas desde ' . date('d/m/Y', strtotime($fechaDesde)) . '. Ahora genera un nuevo cuadrante desde esa fecha.'
    ]);

} catch (Exception $e) {
    $db->rollBack();
    jsonResponse([
        'success' => false,
        'message' => 'Error al regenerar: ' . $e->getMessage()
    ], 500);
}
