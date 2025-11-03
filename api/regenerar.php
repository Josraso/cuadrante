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

    // Ajustar a lunes
    $lunesDesde = getLunes($fechaDesde);

    // Eliminar asignaciones desde la fecha especificada
    $stmt = $db->prepare("DELETE FROM asignaciones WHERE cuadrante_id = ? AND fecha >= ?");
    $stmt->execute([$cuadranteId, $lunesDesde]);
    $asignacionesBorradas = $stmt->rowCount();

    // Eliminar histórico de turnos desde esa fecha
    $stmt = $db->prepare("DELETE FROM historico_turnos WHERE fecha_inicio_semana >= ?");
    $stmt->execute([$lunesDesde]);

    // === AHORA REGENERAR AUTOMÁTICAMENTE ===

    // Calcular cuántas semanas regenerar
    $fechaInicio = new DateTime($lunesDesde);
    $fechaFin = new DateTime($cuadrante['fecha_fin']);
    $interval = $fechaInicio->diff($fechaFin);
    $numSemanas = ceil($interval->days / 7) + 1;

    // Obtener personas activas (excluyendo las de baja)
    $stmt = $db->prepare("
        SELECT * FROM personas
        WHERE activo = 1
        AND (fecha_baja IS NULL OR fecha_baja > ?)
        ORDER BY puede_rotar ASC, nombre
    ");
    $stmt->execute([$lunesDesde]);
    $personasActivas = $stmt->fetchAll();

    if (count($personasActivas) < 4) {
        throw new Exception('Se necesitan al menos 4 personas activas para regenerar el cuadrante');
    }

    // Separar por capacidad de rotación
    $soloMañanas = array_filter($personasActivas, fn($p) => $p['puede_rotar'] == 0);
    $rotan = array_filter($personasActivas, fn($p) => $p['puede_rotar'] == 1);

    if (count($rotan) < 2) {
        throw new Exception('Se necesitan al menos 2 personas que puedan rotar turnos');
    }

    // Obtener histórico ANTERIOR a la fecha de regeneración
    $historicoTardes = [];
    $stmt = $db->prepare("
        SELECT persona_id, fecha_inicio_semana
        FROM historico_turnos
        WHERE turno = 'tarde'
        AND fecha_inicio_semana < ?
        ORDER BY fecha_inicio_semana DESC
    ");
    $stmt->execute([$lunesDesde]);
    while ($row = $stmt->fetch()) {
        $historicoTardes[$row['persona_id']][] = $row['fecha_inicio_semana'];
    }

    // Contadores de turnos
    $contadorTardes = [];
    $contadorLavado = [];

    foreach ($personasActivas as $p) {
        $contadorTardes[$p['id']] = isset($historicoTardes[$p['id']]) ? count($historicoTardes[$p['id']]) : 0;
        $contadorLavado[$p['id']] = 0;
    }

    // GENERAR SEMANAS
    $asignacionesNuevas = [];
    $fechaActual = new DateTime($lunesDesde);
    $personasTardesPorSemana = [];
    $personaLavadoPorSemana = [];

    for ($semana = 0; $semana < $numSemanas; $semana++) {
        $lunesSemana = $fechaActual->format('Y-m-d');

        // PASO 1: DETERMINAR CUÁNTAS PERSONAS VAN A CADA TURNO
        $numSoloMañanas = count($soloMañanas);
        $numRotadores = count($rotan);

        if ($numRotadores < 2) {
            throw new Exception('Se necesitan al menos 2 rotadores');
        }

        // Calcular distribución
        $espaciosLibresMañana = 6 - $numSoloMañanas;
        $rotadoresDisponiblesParaMañana = $numRotadores - 2;
        $rotadoresEnMañana = min($espaciosLibresMañana, max(0, $rotadoresDisponiblesParaMañana));
        $numPersonasTarde = $numRotadores - $rotadoresEnMañana;

        if ($numPersonasTarde > 5) {
            throw new Exception('Hay demasiados rotadores para los puestos disponibles');
        }

        // PASO 2: SELECCIONAR PERSONAS PARA TARDE
        $candidatosTarde = array_filter($rotan, function($p) use ($lunesSemana, $historicoTardes, $personasTardesPorSemana) {
            // Verificar histórico
            if (isset($historicoTardes[$p['id']])) {
                $fechaObj = new DateTime($lunesSemana);
                $fechaObj->modify('-7 days');
                $semanaAnterior = $fechaObj->format('Y-m-d');
                if (in_array($semanaAnterior, $historicoTardes[$p['id']])) {
                    return false;
                }
            }
            // Verificar en generación actual
            $fechaObj = new DateTime($lunesSemana);
            $fechaObj->modify('-7 days');
            $semanaAnterior = $fechaObj->format('Y-m-d');
            if (isset($personasTardesPorSemana[$semanaAnterior]) && in_array($p['id'], $personasTardesPorSemana[$semanaAnterior])) {
                return false;
            }
            return true;
        });

        $candidatosTarde = array_values($candidatosTarde);
        usort($candidatosTarde, function($a, $b) use ($contadorTardes) {
            return $contadorTardes[$a['id']] <=> $contadorTardes[$b['id']];
        });

        $personasTardes = [];
        $numPreferidos = min(count($candidatosTarde), $numPersonasTarde);
        for ($i = 0; $i < $numPreferidos; $i++) {
            $personasTardes[] = $candidatosTarde[$i];
        }

        // Si faltan, completar con cualquiera
        if (count($personasTardes) < $numPersonasTarde) {
            $resto = array_filter($rotan, function($p) use ($personasTardes) {
                foreach ($personasTardes as $pt) {
                    if ($p['id'] === $pt['id']) return false;
                }
                return true;
            });
            $resto = array_values($resto);
            usort($resto, function($a, $b) use ($contadorTardes) {
                return $contadorTardes[$a['id']] <=> $contadorTardes[$b['id']];
            });
            $faltan = $numPersonasTarde - count($personasTardes);
            for ($i = 0; $i < $faltan && $i < count($resto); $i++) {
                $personasTardes[] = $resto[$i];
            }
        }

        $personasTardesPorSemana[$lunesSemana] = array_column($personasTardes, 'id');
        foreach ($personasTardes as $p) {
            $contadorTardes[$p['id']]++;
        }

        // PASO 3: ASIGNAR PERSONAS A MAÑANA
        $personasMañana = array_merge(
            $soloMañanas,
            array_filter($rotan, fn($p) => !in_array($p['id'], $personasTardesPorSemana[$lunesSemana]))
        );
        $personasMañana = array_values($personasMañana);

        // PASO 4: SELECCIONAR LAVADO
        $candidatosLavado = array_filter($personasMañana, fn($p) => $p['puede_lavar']);

        if (count($candidatosLavado) === 0) {
            throw new Exception('No hay personas disponibles para lavado');
        }

        $candidatosLavado = array_values($candidatosLavado);
        usort($candidatosLavado, function($a, $b) use ($contadorLavado) {
            return $contadorLavado[$a['id']] <=> $contadorLavado[$b['id']];
        });

        $personaLavado = $candidatosLavado[0];
        $personaLavadoPorSemana[$lunesSemana] = $personaLavado['id'];
        $contadorLavado[$personaLavado['id']]++;

        // PASO 5: ASIGNAR DÍAS (lunes a viernes)
        for ($dia = 0; $dia < 5; $dia++) {
            $fecha = $fechaActual->format('Y-m-d');

            // TURNO DE MAÑANA - Lavado
            $asignacionesNuevas[] = [
                'persona_id' => $personaLavado['id'],
                'fecha' => $fecha,
                'turno' => 'mañana',
                'puesto' => 'lavado'
            ];

            // TURNO DE MAÑANA - Pulidos (excluir lavado, máximo 5)
            $personasPulido = array_filter($personasMañana, fn($p) => $p['id'] !== $personaLavado['id']);
            $personasPulido = array_values($personasPulido);
            for ($i = 0; $i < min(count($personasPulido), 5); $i++) {
                $asignacionesNuevas[] = [
                    'persona_id' => $personasPulido[$i]['id'],
                    'fecha' => $fecha,
                    'turno' => 'mañana',
                    'puesto' => 'pulido' . ($i + 1)
                ];
            }

            // TURNO DE TARDE - Pulidos
            for ($i = 0; $i < count($personasTardes); $i++) {
                $asignacionesNuevas[] = [
                    'persona_id' => $personasTardes[$i]['id'],
                    'fecha' => $fecha,
                    'turno' => 'tarde',
                    'puesto' => 'pulido' . ($i + 1)
                ];
            }

            $fechaActual->modify('+1 day');
        }
        $fechaActual->modify('+2 days'); // Saltar fin de semana
    }

    // Insertar asignaciones
    $stmt = $db->prepare("
        INSERT INTO asignaciones (cuadrante_id, persona_id, fecha, turno, puesto, es_sustitucion)
        VALUES (?, ?, ?, ?, ?, 0)
    ");

    foreach ($asignacionesNuevas as $asig) {
        $stmt->execute([
            $cuadranteId,
            $asig['persona_id'],
            $asig['fecha'],
            $asig['turno'],
            $asig['puesto']
        ]);
    }

    // Actualizar histórico de turnos
    $stmt = $db->prepare("INSERT INTO historico_turnos (persona_id, fecha_inicio_semana, turno) VALUES (?, ?, ?)");

    foreach ($personasTardesPorSemana as $lunesSemana => $personasIds) {
        foreach ($personasIds as $personaId) {
            $stmt->execute([$personaId, $lunesSemana, 'tarde']);
        }
    }

    foreach ($personaLavadoPorSemana as $lunesSemana => $personaId) {
        $stmt->execute([$personaId, $lunesSemana, 'lavado']);
    }

    $db->commit();

    jsonResponse([
        'success' => true,
        'message' => "Cuadrante regenerado correctamente. Se eliminaron $asignacionesBorradas asignaciones y se crearon " . count($asignacionesNuevas) . " nuevas desde " . date('d/m/Y', strtotime($lunesDesde)),
        'asignaciones_borradas' => $asignacionesBorradas,
        'asignaciones_creadas' => count($asignacionesNuevas),
        'semanas_regeneradas' => $numSemanas
    ]);

} catch (Exception $e) {
    $db->rollBack();
    jsonResponse([
        'success' => false,
        'message' => 'Error al regenerar: ' . $e->getMessage()
    ], 500);
}
