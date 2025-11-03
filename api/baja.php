<?php
require_once '../config.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['persona_id'])) {
    jsonResponse(['success' => false, 'message' => 'ID de persona requerido'], 400);
}

$personaId = $data['persona_id'];
$fechaBaja = $data['fecha_baja'] ?? null; // NULL = quitar baja, fecha = marcar baja

try {
    if ($fechaBaja === null) {
        // QUITAR BAJA (recuperar de baja)
        $stmt = $db->prepare("UPDATE personas SET fecha_baja = NULL WHERE id = ?");
        $stmt->execute([$personaId]);

        jsonResponse([
            'success' => true,
            'message' => 'Baja eliminada correctamente. La persona ha sido recuperada.'
        ]);
    } else {
        // MARCAR BAJA
        $stmt = $db->prepare("UPDATE personas SET fecha_baja = ? WHERE id = ?");
        $stmt->execute([$fechaBaja, $personaId]);

        jsonResponse([
            'success' => true,
            'message' => 'Persona marcada de baja desde ' . date('d/m/Y', strtotime($fechaBaja))
        ]);
    }
} catch (Exception $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Error al actualizar baja: ' . $e->getMessage()
    ], 500);
}
