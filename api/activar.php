<?php
require_once '../config.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

if (empty($data['id'])) {
    jsonResponse(['success' => false, 'message' => 'ID requerido'], 400);
}

$accion = $data['accion'] ?? null; // 'activar' o 'desactivar'

if (!in_array($accion, ['activar', 'desactivar'])) {
    jsonResponse(['success' => false, 'message' => 'Acción inválida'], 400);
}

try {
    $nuevoEstado = $accion === 'activar' ? 1 : 0;
    $stmt = $db->prepare("UPDATE personas SET activo = ? WHERE id = ?");
    $stmt->execute([$nuevoEstado, $data['id']]);

    $mensaje = $accion === 'activar' ? 'Persona activada correctamente' : 'Persona desactivada correctamente';
    jsonResponse(['success' => true, 'message' => $mensaje]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
