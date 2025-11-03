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

    // Obtener información de la persona
    $stmt = $db->prepare("SELECT * FROM personas WHERE id = ?");
    $stmt->execute([$personaId]);
    $persona = $stmt->fetch();

    if (!$persona) {
        jsonResponse(['success' => false, 'message' => 'Persona no encontrada'], 404);
    }

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
    $totalSemanasIlegales = 0;
    $totalSemanasConProblemas = 0;

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

        // Agrupar por semana
        $asignacionesPorSemana = [];
        foreach ($asignaciones as $asig) {
            $lunes = getLunes($asig['fecha']);
            if (!isset($asignacionesPorSemana[$lunes])) {
                $asignacionesPorSemana[$lunes] = [];
            }
            $asignacionesPorSemana[$lunes][] = $asig;
        }

        $semanasDetalle = [];

        foreach ($asignacionesPorSemana as $lunes => $asigsSemana) {
            $viernes = getViernes($lunes);

            // ANÁLISIS COMPLETO DE LA SEMANA

            // 1. Contar distribución ACTUAL (con la persona)
            $stmt = $db->prepare("
                SELECT
                    COUNT(DISTINCT CASE WHEN turno = 'mañana' THEN persona_id END) as total_manana,
                    COUNT(DISTINCT CASE WHEN turno = 'tarde' THEN persona_id END) as total_tarde
                FROM asignaciones
                WHERE cuadrante_id = ?
                AND fecha >= ?
                AND fecha <= ?
                AND es_sustitucion = 0
            ");
            $stmt->execute([$cuadrante['id'], $lunes, $viernes]);
            $distribucionActual = $stmt->fetch();

            // 2. Contar distribución SIN la persona (simulando baja)
            $stmt = $db->prepare("
                SELECT
                    COUNT(DISTINCT CASE WHEN turno = 'mañana' THEN persona_id END) as total_manana,
                    COUNT(DISTINCT CASE WHEN turno = 'tarde' THEN persona_id END) as total_tarde
                FROM asignaciones
                WHERE cuadrante_id = ?
                AND fecha >= ?
                AND fecha <= ?
                AND persona_id != ?
                AND es_sustitucion = 0
            ");
            $stmt->execute([$cuadrante['id'], $lunes, $viernes, $personaId]);
            $distribucionSinPersona = $stmt->fetch();

            // 3. Detectar si la persona está de TARDE esta semana
            $estaDeTarde = false;
            foreach ($asigsSemana as $asig) {
                if ($asig['turno'] === 'tarde') {
                    $estaDeTarde = true;
                    break;
                }
            }

            // 4. Clasificar semana
            $esIlegal = false;
            $tieneProblemas = false;
            $motivo = '';

            if ($estaDeTarde && $distribucionSinPersona['total_tarde'] < 2) {
                $esIlegal = true;
                $motivo = "ILEGAL: Solo quedaría " . $distribucionSinPersona['total_tarde'] . " persona de tarde (mínimo 2 requerido)";
                $totalSemanasIlegales++;
            } elseif ($estaDeTarde && $distribucionSinPersona['total_tarde'] == 2) {
                $tieneProblemas = true;
                $motivo = "ADVERTENCIA: Quedarían exactamente 2 de tarde (límite mínimo)";
                $totalSemanasConProblemas++;
            } elseif (!$estaDeTarde) {
                $motivo = "OK: Persona solo tiene turnos de mañana";
            } else {
                $motivo = "OK: Distribución sigue siendo válida";
            }

            // 5. Obtener candidatos para sustituir
            $candidatos = [];
            if ($esIlegal || $tieneProblemas) {
                $stmt = $db->prepare("
                    SELECT
                        p.id,
                        p.nombre,
                        COALESCE(
                            (SELECT COUNT(DISTINCT fecha_inicio_semana)
                             FROM historico_turnos ht
                             WHERE ht.persona_id = p.id
                             AND ht.turno = 'tarde'
                             AND ht.fecha_inicio_semana < ?
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
            }

            $semanasDetalle[] = [
                'lunes' => $lunes,
                'viernes' => $viernes,
                'distribucion_actual' => [
                    'manana' => $distribucionActual['total_manana'],
                    'tarde' => $distribucionActual['total_tarde']
                ],
                'distribucion_sin_persona' => [
                    'manana' => $distribucionSinPersona['total_manana'],
                    'tarde' => $distribucionSinPersona['total_tarde']
                ],
                'persona_esta_tarde' => $estaDeTarde,
                'es_ilegal' => $esIlegal,
                'tiene_problemas' => $tieneProblemas,
                'motivo' => $motivo,
                'asignaciones' => $asigsSemana,
                'candidatos' => $candidatos
            ];
        }

        if (!empty($semanasDetalle)) {
            $afectaciones[] = [
                'cuadrante_id' => $cuadrante['id'],
                'cuadrante_nombre' => $cuadrante['nombre'],
                'fecha_inicio' => $cuadrante['fecha_inicio'],
                'fecha_fin' => $cuadrante['fecha_fin'],
                'semanas' => $semanasDetalle
            ];
        }
    }

    jsonResponse([
        'success' => true,
        'persona' => $persona,
        'fecha_baja' => $fechaBaja,
        'afectaciones' => $afectaciones,
        'total_semanas_ilegales' => $totalSemanasIlegales,
        'total_semanas_con_problemas' => $totalSemanasConProblemas,
        'total_cuadrantes_afectados' => count($afectaciones)
    ]);

} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
