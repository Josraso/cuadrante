<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$personaId = $data['persona_id'] ?? null;
$fechaBaja = $data['fecha_baja'] ?? null;

if (!$personaId || !$fechaBaja) {
    jsonResponse(['success' => false, 'message' => 'persona_id y fecha_baja son requeridos'], 400);
}

try {
    $db = getDB();

    // Obtener todos los cuadrantes que tienen asignaciones futuras de esta persona
    $stmt = $db->prepare("
        SELECT DISTINCT c.id, c.nombre, c.fecha_inicio, c.fecha_fin
        FROM cuadrantes c
        INNER JOIN asignaciones a ON a.cuadrante_id = c.id
        WHERE a.persona_id = ?
        AND a.fecha >= ?
        AND a.es_sustitucion = 0
        ORDER BY c.fecha_inicio
    ");
    $stmt->execute([$personaId, $fechaBaja]);
    $cuadrantes = $stmt->fetchAll();

    $afectaciones = [];

    foreach ($cuadrantes as $cuadrante) {
        // Obtener asignaciones de esta persona en este cuadrante desde la fecha de baja
        $stmt = $db->prepare("
            SELECT * FROM asignaciones
            WHERE cuadrante_id = ?
            AND persona_id = ?
            AND fecha >= ?
            AND es_sustitucion = 0
            ORDER BY fecha
        ");
        $stmt->execute([$cuadrante['id'], $personaId, $fechaBaja]);
        $asignaciones = $stmt->fetchAll();

        if (empty($asignaciones)) continue;

        // Agrupar por semana y detectar semanas críticas
        $semanasCriticas = [];
        $semanasNoCriticas = [];

        $asignacionesPorSemana = [];
        foreach ($asignaciones as $asig) {
            $lunes = getLunes($asig['fecha']);
            if (!isset($asignacionesPorSemana[$lunes])) {
                $asignacionesPorSemana[$lunes] = [];
            }
            $asignacionesPorSemana[$lunes][] = $asig;
        }

        foreach ($asignacionesPorSemana as $lunes => $asigsSemana) {
            // Verificar si alguna asignación es de TARDE
            $tieneTardes = false;
            foreach ($asigsSemana as $asig) {
                if ($asig['turno'] === 'tarde') {
                    $tieneTardes = true;
                    break;
                }
            }

            if ($tieneTardes) {
                // Contar cuántas personas DIFERENTES están de tarde esa semana (sin contar a la persona de baja)
                $stmt = $db->prepare("
                    SELECT COUNT(DISTINCT persona_id) as total
                    FROM asignaciones
                    WHERE cuadrante_id = ?
                    AND fecha >= ?
                    AND fecha <= ?
                    AND turno = 'tarde'
                    AND persona_id != ?
                    AND es_sustitucion = 0
                ");
                $viernes = getViernes($lunes);
                $stmt->execute([$cuadrante['id'], $lunes, $viernes, $personaId]);
                $result = $stmt->fetch();
                $personasTardeRestantes = $result['total'];

                // Si quedaría solo 1 o menos, es CRÍTICO
                $esCritica = $personasTardeRestantes < 2;

                // Obtener candidatos para sustituir (personas que pueden rotar y tienen menos tardes acumuladas)
                // IMPORTANTE: Excluir a quien YA está de tarde ESA MISMA SEMANA
                $stmt = $db->prepare("
                    SELECT
                        p.id,
                        p.nombre,
                        COALESCE(
                            (SELECT COUNT(DISTINCT DATE(a2.fecha))
                             FROM asignaciones a2
                             WHERE a2.persona_id = p.id
                             AND a2.turno = 'tarde'
                             AND a2.fecha < ?
                             AND a2.es_sustitucion = 0
                            ), 0
                        ) as tardes_acumuladas
                    FROM personas p
                    WHERE p.puede_rotar = 1
                    AND p.activo = 1
                    AND (p.fecha_baja IS NULL OR p.fecha_baja > ?)
                    AND p.id != ?
                    AND p.id NOT IN (
                        SELECT DISTINCT persona_id
                        FROM asignaciones
                        WHERE cuadrante_id = ?
                        AND fecha >= ?
                        AND fecha <= ?
                        AND turno = 'tarde'
                        AND es_sustitucion = 0
                    )
                    ORDER BY tardes_acumuladas ASC, p.nombre ASC
                    LIMIT 5
                ");
                $stmt->execute([$lunes, $lunes, $personaId, $cuadrante['id'], $lunes, $viernes]);
                $candidatos = $stmt->fetchAll();

                $semanaInfo = [
                    'lunes' => $lunes,
                    'viernes' => $viernes,
                    'es_critica' => $esCritica,
                    'motivo' => $esCritica
                        ? "Solo queda $personasTardeRestantes de tarde (ILEGAL - mínimo 2 requeridos)"
                        : "Quedan $personasTardeRestantes personas de tarde",
                    'asignaciones' => $asigsSemana,
                    'candidatos' => $candidatos
                ];

                if ($esCritica) {
                    $semanasCriticas[] = $semanaInfo;
                } else {
                    $semanasNoCriticas[] = $semanaInfo;
                }
            } else {
                // Solo tiene mañanas, no es crítico
                $semanasNoCriticas[] = [
                    'lunes' => $lunes,
                    'viernes' => getViernes($lunes),
                    'es_critica' => false,
                    'motivo' => 'Solo tiene turnos de mañana',
                    'asignaciones' => $asigsSemana,
                    'candidatos' => []
                ];
            }
        }

        if (!empty($semanasCriticas) || !empty($semanasNoCriticas)) {
            $afectaciones[] = [
                'cuadrante_id' => $cuadrante['id'],
                'cuadrante_nombre' => $cuadrante['nombre'],
                'fecha_inicio' => $cuadrante['fecha_inicio'],
                'fecha_fin' => $cuadrante['fecha_fin'],
                'semanas_criticas' => $semanasCriticas,
                'semanas_no_criticas' => $semanasNoCriticas
            ];
        }
    }

    jsonResponse([
        'success' => true,
        'afectaciones' => $afectaciones,
        'total_semanas_criticas' => array_sum(array_map(fn($a) => count($a['semanas_criticas']), $afectaciones))
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
