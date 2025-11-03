<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$asignacionesIds = $data['asignaciones_ids'] ?? null; // Array de IDs de asignaciones a sustituir
$personaSustitutoId = $data['persona_sustituto_id'] ?? null;

if (empty($asignacionesIds) || !$personaSustitutoId) {
    jsonResponse(['success' => false, 'message' => 'asignaciones_ids y persona_sustituto_id son requeridos'], 400);
}

if (!is_array($asignacionesIds)) {
    jsonResponse(['success' => false, 'message' => 'asignaciones_ids debe ser un array'], 400);
}

try {
    $db = getDB();
    $db->beginTransaction();

    $sustituciones = [];

    foreach ($asignacionesIds as $asignacionId) {
        // Obtener la asignación original
        $stmt = $db->prepare("SELECT * FROM asignaciones WHERE id = ? AND es_sustitucion = 0");
        $stmt->execute([$asignacionId]);
        $original = $stmt->fetch();

        if (!$original) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => "Asignación #$asignacionId no encontrada o ya es una sustitución"], 400);
        }

        // Verificar que no haya ya una sustitución para esta asignación
        $stmt = $db->prepare("SELECT id FROM asignaciones WHERE sustituye_a = ? AND es_sustitucion = 1");
        $stmt->execute([$asignacionId]);
        $existente = $stmt->fetch();

        if ($existente) {
            // Ya existe una sustitución, actualizarla
            $stmt = $db->prepare("
                UPDATE asignaciones
                SET persona_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$personaSustitutoId, $existente['id']]);
            $sustituciones[] = ['id' => $existente['id'], 'accion' => 'actualizada'];
        } else {
            // Crear nueva sustitución
            $stmt = $db->prepare("
                INSERT INTO asignaciones (cuadrante_id, persona_id, fecha, turno, puesto, sustituye_a, es_sustitucion)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([
                $original['cuadrante_id'],
                $personaSustitutoId,
                $original['fecha'],
                $original['turno'],
                $original['puesto'],
                $asignacionId
            ]);
            $sustituciones[] = ['id' => $db->lastInsertId(), 'accion' => 'creada'];
        }
    }

    $db->commit();

    // Obtener nombre del sustituto
    $stmt = $db->prepare("SELECT nombre FROM personas WHERE id = ?");
    $stmt->execute([$personaSustitutoId]);
    $sustituto = $stmt->fetch();

    jsonResponse([
        'success' => true,
        'message' => count($asignacionesIds) . " asignación(es) sustituida(s) por {$sustituto['nombre']}",
        'sustituciones' => $sustituciones
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
