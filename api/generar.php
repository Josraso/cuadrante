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
// (fecha_baja IS NULL o fecha_baja > fecha_fin del cuadrante)
$stmt = $db->prepare("
    SELECT * FROM personas
    WHERE activo = 1
    AND (fecha_baja IS NULL OR fecha_baja > ?)
    ORDER BY puede_rotar ASC, nombre
");
$stmt->execute([$fechaFin]);
$personas = $stmt->fetchAll();

if (count($personas) < 4) {
    jsonResponse(['success' => false, 'message' => 'Se necesitan al menos 4 personas para generar el cuadrante'], 400);
}

// Separar personas por tipo
$soloMañanas = array_filter($personas, fn($p) => !$p['puede_rotar']);
$rotan = array_filter($personas, fn($p) => $p['puede_rotar']);

if (count($rotan) < 2) {
    jsonResponse(['success' => false, 'message' => 'Se necesitan al menos 2 personas que puedan rotar para cubrir las tardes'], 400);
}

// Obtener histórico de turnos de tarde de las últimas semanas
$stmt = $db->prepare("
    SELECT persona_id, fecha_inicio_semana
    FROM historico_turnos
    WHERE turno = 'tarde' AND fecha_inicio_semana >= DATE_SUB(?, INTERVAL 2 WEEK)
    ORDER BY fecha_inicio_semana DESC
");
$stmt->execute([$fechaInicio]);
$historicoTardesArray = $stmt->fetchAll();

// Agrupar histórico de tardes por persona
$historicoTardes = [];
foreach ($historicoTardesArray as $registro) {
    $historicoTardes[$registro['persona_id']][] = $registro['fecha_inicio_semana'];
}

// Obtener histórico de lavado de las últimas semanas
$stmt = $db->prepare("
    SELECT persona_id, fecha_inicio_semana
    FROM historico_turnos
    WHERE turno = 'lavado' AND fecha_inicio_semana >= DATE_SUB(?, INTERVAL 2 WEEK)
    ORDER BY fecha_inicio_semana DESC
");
$stmt->execute([$fechaInicio]);
$historicoLavadoArray = $stmt->fetchAll();

// Agrupar histórico de lavado por persona
$historicoLavado = [];
foreach ($historicoLavadoArray as $registro) {
    $historicoLavado[$registro['persona_id']][] = $registro['fecha_inicio_semana'];
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
$advertencias = []; // Advertencias sobre repeticiones consecutivas

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

    // ===== PASO 1: SELECCIONAR TARDE =====
    // PUESTOS: Mañana 6 (1 lavado + 5 pulido) | Tarde 5 (5 pulido, NO se lava)
    $totalPersonas = count($soloMañanas) + count($rotan);

    // Calcular: llenar primero mañana (max 6), resto a tarde (max 5)
    if ($totalPersonas <= 6) {
        // Si hay 6 o menos, mínimo 2 de tarde (requisito)
        $numPersonasTarde = 2;
    } else {
        // Si hay más de 6, el exceso va de tarde
        $numPersonasTarde = $totalPersonas - 6;
    }

    // Validar límites: mínimo 2, máximo 5 de tarde
    $numPersonasTarde = max(2, $numPersonasTarde);
    $numPersonasTarde = min(5, $numPersonasTarde);

    // No superar las que pueden rotar
    $numPersonasTarde = min($numPersonasTarde, count($rotan));

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
            $personasTardes[] = $candidatosFueron[$i];
        }
    }

    // Registrar tarde y actualizar contadores
    $personasTardesPorSemana[$lunesSemana] = array_column($personasTardes, 'id');
    foreach ($personasTardes as $p) {
        $contadorTardes[$p['id']]++;
    }

    // ===== PASO 2: SELECCIONAR LAVADO (de los que están de MAÑANA) =====
    // Personas disponibles para mañana: solo-mañanas + rotatorios que NO están de tarde
    $personasMañanaDisponibles = array_merge(
        $soloMañanas,
        array_filter($rotan, fn($p) => !in_array($p['id'], $personasTardesPorSemana[$lunesSemana]))
    );

    // Filtrar los que pueden lavar
    $candidatosLavado = array_filter($personasMañanaDisponibles, fn($p) => $p['puede_lavar']);

    if (count($candidatosLavado) === 0) {
        jsonResponse([
            'success' => false,
            'message' => 'No hay personas disponibles para lavado en semana ' . ($semana + 1)
        ], 400);
    }

    // Ordenar por contador (el que menos ha lavado)
    $candidatosLavado = array_values($candidatosLavado);
    usort($candidatosLavado, function($a, $b) use ($contadorLavado) {
        return $contadorLavado[$a['id']] <=> $contadorLavado[$b['id']];
    });

    // Seleccionar el primero
    $personaLavado = $candidatosLavado[0];

    // Registrar lavado
    $personaLavadoPorSemana[$lunesSemana] = $personaLavado['id'];
    $contadorLavado[$personaLavado['id']]++;

    // ===== PASO 3: ASIGNAR DÍAS (lunes a viernes) =====
    for ($dia = 0; $dia < 5; $dia++) {
        $fecha = $fechaActual->format('Y-m-d');

        // ===== TURNO DE MAÑANA =====
        // Asignar LAVADO (la misma persona toda la semana)
        $asignaciones[] = [
            'persona_id' => $personaLavado['id'],
            'fecha' => $fecha,
            'turno' => 'mañana',
            'puesto' => 'lavado',
            'es_consecutivo' => false // Lavado puede repetir si es necesario
        ];

        // Asignar PULIDOS (excluir persona de lavado y personas de tarde)
        $personasMañana = array_merge(
            $soloMañanas,
            array_filter($rotan, fn($p) => !in_array($p['id'], $personasTardesPorSemana[$lunesSemana]))
        );

        // Excluir persona de lavado
        $personasPulido = array_filter($personasMañana, fn($p) => $p['id'] !== $personaLavado['id']);
        $personasPulido = array_values($personasPulido);

        // IMPORTANTE: Asignar TODOS los trabajadores disponibles en mañana
        // No limitar a solo 5, sino incluir a TODOS
        for ($i = 0; $i < count($personasPulido); $i++) {
            // Generar nombre de puesto dinámicamente (pulido1, pulido2, ..., pulidoN)
            $puesto = 'pulido' . ($i + 1);

            $asignaciones[] = [
                'persona_id' => $personasPulido[$i]['id'],
                'fecha' => $fecha,
                'turno' => 'mañana',
                'puesto' => $puesto,
                'es_consecutivo' => false
            ];
        }

        // ===== TURNO DE TARDE (2 personas) =====
        for ($i = 0; $i < count($personasTardes); $i++) {
            $puesto = 'pulido' . ($i + 1); // pulido1, pulido2 para las 2 personas de tarde

            // Verificar si ESTA persona específica repite consecutivo
            $personaTardeId = $personasTardes[$i]['id'];
            $esConsecutivo = false;

            $fechaObj = new DateTime($lunesSemana);
            $fechaObj->modify('-7 days');
            $semanaAnterior = $fechaObj->format('Y-m-d');

            if ((isset($personasTardesPorSemana[$semanaAnterior]) && in_array($personaTardeId, $personasTardesPorSemana[$semanaAnterior])) ||
                tuvoTardeSemanaAnterior($personaTardeId, $lunesSemana, $historicoTardes)) {
                $esConsecutivo = true;
            }

            $asignaciones[] = [
                'persona_id' => $personaTardeId,
                'fecha' => $fecha,
                'turno' => 'tarde',
                'puesto' => $puesto,
                'es_consecutivo' => $esConsecutivo
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