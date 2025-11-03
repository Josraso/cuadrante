<?php
require_once '../config.php';

$db = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);

$fechaInicio = $data['fecha_inicio'] ?? null;
$numSemanas = (int)($data['num_semanas'] ?? 1);

if (!$fechaInicio || $numSemanas < 1) {
    jsonResponse(['success' => false, 'message' => 'Fecha de inicio y número de semanas requeridos'], 400);
}

// Ajustar al lunes más cercano
$fechaInicio = getLunes($fechaInicio);
$fechaInicioObj = new DateTime($fechaInicio);
$fechaFinObj = clone $fechaInicioObj;
$fechaFinObj->modify('+' . (($numSemanas * 7) - 1) . ' days');
$fechaFin = $fechaFinObj->format('Y-m-d');

// Obtener todas las personas activas que NO estén de baja
$stmt = $db->prepare("
    SELECT * FROM personas
    WHERE activo = 1
    AND (fecha_baja IS NULL OR fecha_baja > ?)
    ORDER BY puede_rotar ASC, nombre
");
$stmt->execute([$fechaFin]);
$personas = $stmt->fetchAll();

if (count($personas) < 4) {
    jsonResponse(['success' => false, 'message' => 'Se necesitan al menos 4 personas activas para generar el cuadrante'], 400);
}

// VALIDACIÓN: Máximo 11 personas activas (6 mañana + 5 tarde)
if (count($personas) > 11) {
    jsonResponse(['success' => false, 'message' => 'Hay ' . count($personas) . ' personas activas. Máximo permitido: 11 (6 mañana + 5 tarde)'], 400);
}

// Separar personas por tipo
$soloMañanas = array_filter($personas, fn($p) => !$p['puede_rotar']);
$rotan = array_filter($personas, fn($p) => $p['puede_rotar']);

if (count($rotan) < 1) {
    jsonResponse(['success' => false, 'message' => 'Se necesita al menos 1 persona que pueda rotar para cubrir tardes'], 400);
}

// Obtener histórico de turnos de tarde de las últimas 2 semanas
// IMPORTANTE: Considerar SUSTITUCIONES (quien realmente trabajó)
$stmt = $db->prepare("
    SELECT
        COALESCE(sust.persona_id, orig.persona_id) as persona_id,
        fecha_inicio_semana
    FROM historico_turnos ht
    LEFT JOIN asignaciones orig ON orig.persona_id = ht.persona_id
        AND orig.fecha >= ht.fecha_inicio_semana
        AND orig.fecha < DATE_ADD(ht.fecha_inicio_semana, INTERVAL 7 DAY)
        AND orig.turno = 'tarde'
        AND orig.es_sustitucion = 0
    LEFT JOIN asignaciones sust ON sust.sustituye_a = orig.id
        AND sust.es_sustitucion = 1
    WHERE ht.turno = 'tarde'
    AND ht.fecha_inicio_semana >= DATE_SUB(?, INTERVAL 2 WEEK)
    GROUP BY COALESCE(sust.persona_id, orig.persona_id), fecha_inicio_semana
    ORDER BY fecha_inicio_semana DESC
");
$stmt->execute([$fechaInicio]);
$historicoTardesArray = $stmt->fetchAll();

// Agrupar histórico de tardes por persona
$historicoTardes = [];
foreach ($historicoTardesArray as $registro) {
    if ($registro['persona_id']) { // Asegurar que no sea NULL
        $historicoTardes[$registro['persona_id']][] = $registro['fecha_inicio_semana'];
    }
}

// Obtener histórico de lavado de las últimas 2 semanas
// IMPORTANTE: Considerar SUSTITUCIONES (quien realmente trabajó)
$stmt = $db->prepare("
    SELECT
        COALESCE(sust.persona_id, orig.persona_id) as persona_id,
        fecha_inicio_semana
    FROM historico_turnos ht
    LEFT JOIN asignaciones orig ON orig.persona_id = ht.persona_id
        AND orig.fecha >= ht.fecha_inicio_semana
        AND orig.fecha < DATE_ADD(ht.fecha_inicio_semana, INTERVAL 7 DAY)
        AND orig.puesto = 'lavado'
        AND orig.es_sustitucion = 0
    LEFT JOIN asignaciones sust ON sust.sustituye_a = orig.id
        AND sust.es_sustitucion = 1
    WHERE ht.turno = 'lavado'
    AND ht.fecha_inicio_semana >= DATE_SUB(?, INTERVAL 2 WEEK)
    GROUP BY COALESCE(sust.persona_id, orig.persona_id), fecha_inicio_semana
    ORDER BY fecha_inicio_semana DESC
");
$stmt->execute([$fechaInicio]);
$historicoLavadoArray = $stmt->fetchAll();

// Agrupar histórico de lavado por persona
$historicoLavado = [];
foreach ($historicoLavadoArray as $registro) {
    if ($registro['persona_id']) { // Asegurar que no sea NULL
        $historicoLavado[$registro['persona_id']][] = $registro['fecha_inicio_semana'];
    }
}

// Función para verificar si una persona tuvo tarde la semana anterior
function tuvoTardeSemanaAnterior($personaId, $fechaSemana, $historico) {
    $fechaSemanaObj = new DateTime($fechaSemana);
    $fechaSemanaObj->modify('-7 days');
    $semanaAnterior = $fechaSemanaObj->format('Y-m-d');

    if (isset($historico[$personaId])) {
        return in_array($semanaAnterior, $historico[$personaId]);
    }
    return false;
}

// Función para verificar si una persona lavó la semana anterior
function lavoSemanaAnterior($personaId, $fechaSemana, $historico) {
    $fechaSemanaObj = new DateTime($fechaSemana);
    $fechaSemanaObj->modify('-7 days');
    $semanaAnterior = $fechaSemanaObj->format('Y-m-d');

    if (isset($historico[$personaId])) {
        return in_array($semanaAnterior, $historico[$personaId]);
    }
    return false;
}

// Generar asignaciones
$asignaciones = [];
$fechaActual = clone $fechaInicioObj;
$personasTardesPorSemana = []; // Registro de quién estuvo de tarde cada semana
$personaLavadoPorSemana = []; // Registro de quién lavó cada semana
$advertencias = []; // Advertencias sobre repeticiones consecutivas o falta de personal

// Contadores de turnos totales acumulados (para rotación equitativa)
$contadorTardes = [];
$contadorLavado = [];

// Inicializar contadores CON el histórico previo
foreach ($personas as $p) {
    // Contar cuántas veces ha estado de tarde en el histórico
    $contadorTardes[$p['id']] = isset($historicoTardes[$p['id']]) ? count($historicoTardes[$p['id']]) : 0;

    // Contar cuántas veces ha lavado en el histórico
    $contadorLavado[$p['id']] = isset($historicoLavado[$p['id']]) ? count($historicoLavado[$p['id']]) : 0;
}

for ($semana = 0; $semana < $numSemanas; $semana++) {
    $lunesSemana = $fechaActual->format('Y-m-d');

    // ===== PASO 1: DETERMINAR CUÁNTAS PERSONAS VAN A CADA TURNO =====
    // REGLA: Llenar MAÑANA primero (máximo 6), resto a TARDE (máximo 5)

    $totalPersonas = count($personas);
    $numPersonasMañana = min($totalPersonas, 6); // Máximo 6 de mañana
    $numPersonasTarde = $totalPersonas - $numPersonasMañana; // El resto a tarde

    // VALIDAR: Máximo 5 de tarde
    if ($numPersonasTarde > 5) {
        jsonResponse([
            'success' => false,
            'message' => 'Hay ' . $totalPersonas . ' personas activas. Se necesitan ' . $numPersonasMañana . ' de mañana, pero sobran ' . ($numPersonasTarde - 5) . ' para tarde. Máximo 5 de tarde permitidas.'
        ], 400);
    }

    // VALIDAR: Mínimo 2 de tarde (o al menos 1 con advertencia)
    if ($numPersonasTarde == 1) {
        $advertencias[] = "⚠️ Semana " . ($semana + 1) . " (" . date('d/m/Y', strtotime($lunesSemana)) . "): Solo hay 1 persona de tarde (mínimo recomendado: 2)";
    } elseif ($numPersonasTarde == 0) {
        jsonResponse([
            'success' => false,
            'message' => 'No hay suficientes personas para cubrir tardes en la semana ' . ($semana + 1) . '. Se necesita al menos 1 persona que pueda rotar.'
        ], 400);
    }

    // ===== PASO 2: SELECCIONAR PERSONAS PARA TARDE =====
    // Solo pueden ir de tarde las que ROTAN

    // Filtrar candidatos que NO estuvieron de tarde semana anterior
    $candidatosTardesPreferidos = array_filter($rotan, function($p) use ($lunesSemana, $historicoTardes, $personasTardesPorSemana) {
        // Verificar histórico en BD
        if (tuvoTardeSemanaAnterior($p['id'], $lunesSemana, $historicoTardes)) {
            return false;
        }

        // Verificar en las semanas que estamos generando
        $fechaObj = new DateTime($lunesSemana);
        $fechaObj->modify('-7 days');
        $semanaAnterior = $fechaObj->format('Y-m-d');

        if (isset($personasTardesPorSemana[$semanaAnterior]) &&
            in_array($p['id'], $personasTardesPorSemana[$semanaAnterior])) {
            return false;
        }

        return true;
    });

    // Ordenar candidatos preferidos por contador (los que menos han ido)
    $candidatosTardesPreferidos = array_values($candidatosTardesPreferidos);
    usort($candidatosTardesPreferidos, function($a, $b) use ($contadorTardes) {
        return $contadorTardes[$a['id']] <=> $contadorTardes[$b['id']];
    });

    // Seleccionar personas para tarde
    $personasTardes = [];

    // Llenar con los que NO fueron semana anterior
    $numPreferidos = min(count($candidatosTardesPreferidos), $numPersonasTarde);
    for ($i = 0; $i < $numPreferidos; $i++) {
        $personasTardes[] = $candidatosTardesPreferidos[$i];
    }

    // Si faltan más, completar con los que SÍ fueron (solo si es inevitable)
    if (count($personasTardes) < $numPersonasTarde) {
        $candidatosFueron = array_filter($rotan, function($p) use ($candidatosTardesPreferidos) {
            foreach ($candidatosTardesPreferidos as $pref) {
                if ($p['id'] === $pref['id']) {
                    return false;
                }
            }
            return true;
        });

        $candidatosFueron = array_values($candidatosFueron);
        usort($candidatosFueron, function($a, $b) use ($contadorTardes) {
            return $contadorTardes[$a['id']] <=> $contadorTardes[$b['id']];
        });

        $faltan = $numPersonasTarde - count($personasTardes);
        for ($i = 0; $i < $faltan && $i < count($candidatosFueron); $i++) {
            $persona = $candidatosFueron[$i];
            $personasTardes[] = $persona;

            // ADVERTENCIA: Esta persona repite tarde consecutiva
            $advertencias[] = "⚠️ " . $persona['nombre'] . " tiene 2 semanas consecutivas de tarde (semanas " . $semana . " y " . ($semana + 1) . ")";
        }
    }

    // Registrar tarde y actualizar contadores
    $personasTardesPorSemana[$lunesSemana] = array_column($personasTardes, 'id');
    foreach ($personasTardes as $p) {
        $contadorTardes[$p['id']]++;
    }

    // ===== PASO 3: ASIGNAR PERSONAS A MAÑANA =====
    // Mañana: solo-mañanas + rotatorios que NO están de tarde
    $personasMañanaDisponibles = array_merge(
        $soloMañanas,
        array_filter($rotan, fn($p) => !in_array($p['id'], $personasTardesPorSemana[$lunesSemana]))
    );

    $personasMañanaDisponibles = array_values($personasMañanaDisponibles);

    // ===== PASO 4: SELECCIONAR LAVADO (de los que están de MAÑANA) =====
    // Filtrar los que pueden lavar
    $candidatosLavado = array_filter($personasMañanaDisponibles, fn($p) => $p['puede_lavar']);

    if (count($candidatosLavado) === 0) {
        jsonResponse([
            'success' => false,
            'message' => 'No hay personas disponibles para lavado en semana ' . ($semana + 1)
        ], 400);
    }

    // Filtrar candidatos que NO lavaron semana anterior
    $candidatosLavadoPreferidos = array_filter($candidatosLavado, function($p) use ($lunesSemana, $historicoLavado, $personaLavadoPorSemana) {
        // Verificar histórico en BD
        if (lavoSemanaAnterior($p['id'], $lunesSemana, $historicoLavado)) {
            return false;
        }

        // Verificar en las semanas que estamos generando
        $fechaObj = new DateTime($lunesSemana);
        $fechaObj->modify('-7 days');
        $semanaAnterior = $fechaObj->format('Y-m-d');

        if (isset($personaLavadoPorSemana[$semanaAnterior]) &&
            $personaLavadoPorSemana[$semanaAnterior] === $p['id']) {
            return false;
        }

        return true;
    });

    $candidatosLavadoPreferidos = array_values($candidatosLavadoPreferidos);

    // Ordenar por contador (el que menos ha lavado)
    usort($candidatosLavadoPreferidos, function($a, $b) use ($contadorLavado) {
        return $contadorLavado[$a['id']] <=> $contadorLavado[$b['id']];
    });

    // Seleccionar persona para lavado
    $personaLavado = null;

    if (count($candidatosLavadoPreferidos) > 0) {
        $personaLavado = $candidatosLavadoPreferidos[0];
    } else {
        // No hay nadie que NO lavó semana anterior - tomar el que menos ha lavado
        $candidatosLavado = array_values($candidatosLavado);
        usort($candidatosLavado, function($a, $b) use ($contadorLavado) {
            return $contadorLavado[$a['id']] <=> $contadorLavado[$b['id']];
        });

        $personaLavado = $candidatosLavado[0];

        // ADVERTENCIA: Esta persona repite lavado consecutivo
        $advertencias[] = "⚠️ " . $personaLavado['nombre'] . " lava 2 semanas consecutivas (semanas " . $semana . " y " . ($semana + 1) . ")";
    }

    // Registrar lavado
    $personaLavadoPorSemana[$lunesSemana] = $personaLavado['id'];
    $contadorLavado[$personaLavado['id']]++;

    // ===== PASO 5: ASIGNAR DÍAS (lunes a viernes) =====
    for ($dia = 0; $dia < 5; $dia++) {
        $fecha = $fechaActual->format('Y-m-d');

        // ===== TURNO DE MAÑANA =====
        // Asignar LAVADO (la misma persona toda la semana)
        $asignaciones[] = [
            'persona_id' => $personaLavado['id'],
            'fecha' => $fecha,
            'turno' => 'mañana',
            'puesto' => 'lavado'
        ];

        // Asignar PULIDOS (excluir persona de lavado)
        $personasPulido = array_filter($personasMañanaDisponibles, fn($p) => $p['id'] !== $personaLavado['id']);
        $personasPulido = array_values($personasPulido);

        // Asignar hasta 5 puestos de pulido en mañana
        for ($i = 0; $i < min(count($personasPulido), 5); $i++) {
            $puesto = 'pulido' . ($i + 1);

            $asignaciones[] = [
                'persona_id' => $personasPulido[$i]['id'],
                'fecha' => $fecha,
                'turno' => 'mañana',
                'puesto' => $puesto
            ];
        }

        // ===== TURNO DE TARDE =====
        for ($i = 0; $i < count($personasTardes); $i++) {
            $puesto = 'pulido' . ($i + 1);

            $asignaciones[] = [
                'persona_id' => $personasTardes[$i]['id'],
                'fecha' => $fecha,
                'turno' => 'tarde',
                'puesto' => $puesto
            ];
        }

        $fechaActual->modify('+1 day');
    }

    // Saltar fin de semana
    $fechaActual->modify('+2 days');
}

// Guardar en BD
try {
    $db->beginTransaction();

    // Crear cuadrante
    $nombreCuadrante = "Cuadrante " . date('d/m/Y', strtotime($fechaInicio)) . " - " . date('d/m/Y', strtotime($fechaFin));
    $stmt = $db->prepare("INSERT INTO cuadrantes (nombre, fecha_inicio, fecha_fin, num_semanas) VALUES (?, ?, ?, ?)");
    $stmt->execute([$nombreCuadrante, $fechaInicio, $fechaFin, $numSemanas]);

    $cuadranteId = $db->lastInsertId();

    // Insertar asignaciones
    $stmt = $db->prepare("
        INSERT INTO asignaciones (cuadrante_id, persona_id, fecha, turno, puesto)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($asignaciones as $asig) {
        $stmt->execute([
            $cuadranteId,
            $asig['persona_id'],
            $asig['fecha'],
            $asig['turno'],
            $asig['puesto']
        ]);
    }

    // Actualizar histórico de turnos de tarde
    $stmt = $db->prepare("INSERT INTO historico_turnos (persona_id, fecha_inicio_semana, turno) VALUES (?, ?, ?)");

    foreach ($personasTardesPorSemana as $lunesSemana => $personasIds) {
        foreach ($personasIds as $personaId) {
            $stmt->execute([$personaId, $lunesSemana, 'tarde']);
        }
    }

    // Actualizar histórico de lavado
    foreach ($personaLavadoPorSemana as $lunesSemana => $personaId) {
        $stmt->execute([$personaId, $lunesSemana, 'lavado']);
    }

    $db->commit();

    $response = [
        'success' => true,
        'message' => 'Cuadrante generado correctamente',
        'cuadrante_id' => $cuadranteId
    ];

    // Incluir advertencias si las hay
    if (count($advertencias) > 0) {
        $response['advertencias'] = $advertencias;
        $response['message'] = 'Cuadrante generado con ' . count($advertencias) . ' advertencia(s). Ver detalles.';
    }

    jsonResponse($response);

} catch (Exception $e) {
    $db->rollBack();
    jsonResponse(['success' => false, 'message' => 'Error al generar: ' . $e->getMessage()], 500);
}
