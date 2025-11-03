<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$personaId = $data['persona_id'] ?? null;
$accion = $data['accion'] ?? 'consultar'; // 'consultar' o 'eliminar'
$semanasSeleccionadas = $data['semanas'] ?? []; // Array de lunes de semanas a recuperar

if (!$personaId) {
    jsonResponse(['success' => false, 'message' => 'persona_id es requerido'], 400);
}

try {
    $db = getDB();

    if ($accion === 'consultar') {
        // Obtener todas las sustituciones futuras de esta persona
        $hoy = date('Y-m-d');

        $stmt = $db->prepare("
            SELECT
                asig_sust.id as sustitucion_id,
                asig_sust.fecha,
                asig_sust.turno,
                asig_sust.puesto,
                asig_sust.persona_id as sustituto_id,
                p_sust.nombre as sustituto_nombre,
                asig_orig.id as original_id,
                c.id as cuadrante_id,
                c.nombre as cuadrante_nombre
            FROM asignaciones asig_orig
            INNER JOIN asignaciones asig_sust ON asig_sust.sustituye_a = asig_orig.id
            INNER JOIN personas p_sust ON p_sust.id = asig_sust.persona_id
            INNER JOIN cuadrantes c ON c.id = asig_orig.cuadrante_id
            WHERE asig_orig.persona_id = ?
            AND asig_orig.fecha >= ?
            AND asig_sust.es_sustitucion = 1
            ORDER BY asig_orig.fecha
        ");
        $stmt->execute([$personaId, $hoy]);
        $sustituciones = $stmt->fetchAll();

        if (empty($sustituciones)) {
            jsonResponse([
                'success' => true,
                'tiene_sustituciones' => false,
                'sustituciones_futuras' => []
            ]);
        }

        // Agrupar por semana
        $semanas = [];
        foreach ($sustituciones as $sust) {
            $lunes = getLunes($sust['fecha']);
            if (!isset($semanas[$lunes])) {
                $semanas[$lunes] = [
                    'lunes' => $lunes,
                    'viernes' => getViernes($lunes),
                    'cuadrante_id' => $sust['cuadrante_id'],
                    'cuadrante_nombre' => $sust['cuadrante_nombre'],
                    'asignaciones' => []
                ];
            }
            $semanas[$lunes]['asignaciones'][] = [
                'sustitucion_id' => $sust['sustitucion_id'],
                'original_id' => $sust['original_id'],
                'fecha' => $sust['fecha'],
                'turno' => $sust['turno'],
                'puesto' => $sust['puesto'],
                'sustituto_nombre' => $sust['sustituto_nombre']
            ];
        }

        jsonResponse([
            'success' => true,
            'tiene_sustituciones' => true,
            'sustituciones_futuras' => array_values($semanas)
        ]);

    } elseif ($accion === 'eliminar') {
        // Eliminar sustituciones de las semanas seleccionadas
        if (empty($semanasSeleccionadas)) {
            jsonResponse(['success' => false, 'message' => 'Debe seleccionar al menos una semana'], 400);
        }

        $db->beginTransaction();

        $eliminadas = 0;
        foreach ($semanasSeleccionadas as $lunes) {
            $viernes = getViernes($lunes);

            // Eliminar sustituciones de esa semana
            $stmt = $db->prepare("
                DELETE asig_sust
                FROM asignaciones asig_sust
                INNER JOIN asignaciones asig_orig ON asig_sust.sustituye_a = asig_orig.id
                WHERE asig_orig.persona_id = ?
                AND asig_orig.fecha >= ?
                AND asig_orig.fecha <= ?
                AND asig_sust.es_sustitucion = 1
            ");
            $stmt->execute([$personaId, $lunes, $viernes]);
            $eliminadas += $stmt->rowCount();
        }

        $db->commit();

        jsonResponse([
            'success' => true,
            'message' => "$eliminadas sustitución(es) eliminada(s). La persona volverá a sus turnos originales."
        ]);

    } else {
        jsonResponse(['success' => false, 'message' => 'Acción no válida'], 400);
    }

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
