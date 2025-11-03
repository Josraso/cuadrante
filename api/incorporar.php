<?php
require_once '../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}

$data = json_decode(file_get_contents('php://input'), true);
$personaId = $data['persona_id'] ?? null;
$accion = $data['accion'] ?? 'consultar'; // 'consultar' o 'regenerar'
$cuadranteId = $data['cuadrante_id'] ?? null;
$fechaDesde = $data['fecha_desde'] ?? null;

if (!$personaId) {
    jsonResponse(['success' => false, 'message' => 'persona_id es requerido'], 400);
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

    if ($accion === 'consultar') {
        // Obtener cuadrantes activos (que tienen fechas futuras)
        $hoy = date('Y-m-d');
        $stmt = $db->query("
            SELECT * FROM cuadrantes
            WHERE fecha_fin >= '$hoy'
            ORDER BY fecha_inicio
        ");
        $cuadrantes = $stmt->fetchAll();

        if (empty($cuadrantes)) {
            jsonResponse([
                'success' => true,
                'tiene_cuadrantes_activos' => false,
                'cuadrantes' => []
            ]);
        }

        jsonResponse([
            'success' => true,
            'tiene_cuadrantes_activos' => true,
            'cuadrantes' => $cuadrantes,
            'persona' => $persona
        ]);

    } elseif ($accion === 'analizar') {
        // Analizar impacto de incorporación en un cuadrante específico
        if (!$cuadranteId || !$fechaDesde) {
            jsonResponse(['success' => false, 'message' => 'cuadrante_id y fecha_desde son requeridos'], 400);
        }

        // Obtener cuadrante
        $stmt = $db->prepare("SELECT * FROM cuadrantes WHERE id = ?");
        $stmt->execute([$cuadranteId]);
        $cuadrante = $stmt->fetch();

        if (!$cuadrante) {
            jsonResponse(['success' => false, 'message' => 'Cuadrante no encontrado'], 404);
        }

        // Validar que fecha_desde esté dentro del cuadrante
        if ($fechaDesde < $cuadrante['fecha_inicio'] || $fechaDesde > $cuadrante['fecha_fin']) {
            jsonResponse([
                'success' => false,
                'message' => 'La fecha debe estar entre ' . $cuadrante['fecha_inicio'] . ' y ' . $cuadrante['fecha_fin']
            ], 400);
        }

        // Usar la fecha exacta proporcionada por el usuario (no ajustar al lunes)
        $fechaIncorporacion = $fechaDesde;

        // Obtener semanas que se mantendrán (antes de fecha_desde)
        $semanasMantenidas = [];
        $currentLunes = getLunes($cuadrante['fecha_inicio']);
        $lunesIncorporacion = getLunes($fechaIncorporacion);
        while ($currentLunes < $lunesIncorporacion) {
            $viernes = getViernes($currentLunes);
            $semanasMantenidas[] = [
                'lunes' => $currentLunes,
                'viernes' => $viernes
            ];
            $currentLunes = date('Y-m-d', strtotime($currentLunes . ' +7 days'));
        }

        // Obtener semanas que se regenerarán (desde semana de incorporación hasta fin)
        $semanasRegeneradas = [];
        $currentLunes = $lunesIncorporacion;
        while ($currentLunes <= $cuadrante['fecha_fin']) {
            $viernes = getViernes($currentLunes);
            // No pasar del fin del cuadrante
            if ($viernes > $cuadrante['fecha_fin']) {
                $viernes = $cuadrante['fecha_fin'];
            }
            $semanasRegeneradas[] = [
                'lunes' => $currentLunes,
                'viernes' => $viernes
            ];
            $currentLunes = date('Y-m-d', strtotime($currentLunes . ' +7 days'));
        }

        // Detectar TODAS las personas de baja (tengan o no sustituciones)
        $stmt = $db->prepare("
            SELECT DISTINCT p.id, p.nombre, p.fecha_baja
            FROM personas p
            WHERE p.fecha_baja IS NOT NULL
            AND p.fecha_baja <= ?
            AND p.activo = 1
            ORDER BY p.nombre
        ");
        $stmt->execute([$cuadrante['fecha_fin']]);
        $personasDeBaja = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'cuadrante' => $cuadrante,
            'persona' => $persona,
            'fecha_incorporacion' => $fechaIncorporacion,
            'semanas_mantenidas' => $semanasMantenidas,
            'semanas_regeneradas' => $semanasRegeneradas,
            'total_semanas_afectadas' => count($semanasRegeneradas),
            'personas_de_baja' => $personasDeBaja
        ]);

    } elseif ($accion === 'regenerar') {
        // Ejecutar regeneración parcial
        if (!$cuadranteId || !$fechaDesde) {
            jsonResponse(['success' => false, 'message' => 'cuadrante_id y fecha_desde son requeridos'], 400);
        }

        $cubrirBaja = $data['cubrir_baja'] ?? false;
        $personaBajaIdRecibido = $data['persona_baja_id'] ?? null;

        $db->beginTransaction();

        try {
            // 1. Activar persona si está inactiva
            if ($persona['activo'] == 0) {
                $stmt = $db->prepare("UPDATE personas SET activo = 1 WHERE id = ?");
                $stmt->execute([$personaId]);
            }

            // Usar la fecha exacta proporcionada por el usuario (no ajustar al lunes)
            $fechaIncorporacion = $fechaDesde;
            $lunesIncorporacion = getLunes($fechaIncorporacion);

            // MODO: CUBRIR BAJA
            if ($cubrirBaja) {
                // Validar que se haya enviado persona_baja_id
                if (!$personaBajaIdRecibido) {
                    throw new Exception('Debes especificar qué persona de baja quieres cubrir');
                }

                // Obtener cuadrante
                $stmt = $db->prepare("SELECT * FROM cuadrantes WHERE id = ?");
                $stmt->execute([$cuadranteId]);
                $cuadrante = $stmt->fetch();

                if (!$cuadrante) {
                    throw new Exception('Cuadrante no encontrado');
                }

                // Obtener información de la persona de baja seleccionada
                $stmt = $db->prepare("SELECT * FROM personas WHERE id = ?");
                $stmt->execute([$personaBajaIdRecibido]);
                $personaBaja = $stmt->fetch();

                if (!$personaBaja) {
                    throw new Exception('Persona de baja no encontrada');
                }

                if ($personaBaja['fecha_baja'] === null) {
                    throw new Exception('La persona seleccionada no está de baja');
                }

                $personaBajaId = $personaBaja['id'];

                // Obtener todos los rotadores activos (incluyendo el que se incorpora)
                $stmt = $db->prepare("
                    SELECT * FROM personas
                    WHERE activo = 1
                    AND puede_rotar = 1
                    AND id != ?
                    AND (fecha_baja IS NULL OR fecha_baja > ?)
                    ORDER BY nombre
                ");
                $stmt->execute([$personaBajaId, $fechaIncorporacion]);
                $rotadoresDisponibles = $stmt->fetchAll();

                if (empty($rotadoresDisponibles)) {
                    throw new Exception('No hay rotadores disponibles para cubrir la baja');
                }

                // Obtener contador de tardes de cada rotador ANTES de la fecha de incorporación
                $contadorTardesRotadores = [];
                foreach ($rotadoresDisponibles as $rot) {
                    $stmt = $db->prepare("
                        SELECT COUNT(DISTINCT DATE(fecha)) as total
                        FROM asignaciones
                        WHERE cuadrante_id = ?
                        AND persona_id = ?
                        AND turno = 'tarde'
                        AND fecha < ?
                        AND es_sustitucion = 0
                    ");
                    $stmt->execute([$cuadranteId, $rot['id'], $fechaIncorporacion]);
                    $result = $stmt->fetch();
                    $contadorTardesRotadores[$rot['id']] = (int)$result['total'];
                }

                // Obtener TODAS las asignaciones originales de la persona de baja desde la fecha
                $stmt = $db->prepare("
                    SELECT * FROM asignaciones
                    WHERE persona_id = ?
                    AND cuadrante_id = ?
                    AND fecha >= ?
                    AND es_sustitucion = 0
                    ORDER BY fecha, turno
                ");
                $stmt->execute([$personaBajaId, $cuadranteId, $fechaIncorporacion]);
                $asignacionesOriginales = $stmt->fetchAll();

                // Agrupar asignaciones por semana y turno para distribución equitativa
                $asignacionesPorSemana = [];
                foreach ($asignacionesOriginales as $asig) {
                    $lunes = getLunes($asig['fecha']);
                    if (!isset($asignacionesPorSemana[$lunes])) {
                        $asignacionesPorSemana[$lunes] = ['mañana' => [], 'tarde' => []];
                    }
                    $asignacionesPorSemana[$lunes][$asig['turno']][] = $asig;
                }

                $sustitucionesCreadas = 0;
                $sustitucionesActualizadas = 0;

                // Para cada semana, distribuir las tardes entre los rotadores
                foreach ($asignacionesPorSemana as $lunes => $turnos) {
                    // TARDES: Distribuir entre TODOS los rotadores de forma equitativa
                    if (!empty($turnos['tarde'])) {
                        // Ordenar rotadores por menor cantidad de tardes
                        uasort($rotadoresDisponibles, function($a, $b) use ($contadorTardesRotadores) {
                            return $contadorTardesRotadores[$a['id']] <=> $contadorTardesRotadores[$b['id']];
                        });

                        // Calcular cuántas tardes necesitamos cubrir en esta semana
                        $tardesSemana = count($turnos['tarde']);
                        $rotadoresArray = array_values($rotadoresDisponibles);
                        $numRotadores = count($rotadoresArray);

                        // Asignar cada día de tarde a un rotador diferente
                        $fechasTarde = [];
                        foreach ($turnos['tarde'] as $asig) {
                            $fechasTarde[$asig['fecha']] = true;
                        }
                        $fechasTarde = array_keys($fechasTarde);

                        // Distribuir cada día de tarde entre rotadores
                        $indiceRotador = 0;
                        foreach ($fechasTarde as $fecha) {
                            // Obtener todas las asignaciones de tarde de este día
                            $asignacionesDia = array_filter($turnos['tarde'], fn($a) => $a['fecha'] === $fecha);

                            // Seleccionar el rotador con menos tardes
                            $rotadorSeleccionado = $rotadoresArray[$indiceRotador % $numRotadores];

                            // Crear/actualizar sustituciones para todas las asignaciones de este día
                            foreach ($asignacionesDia as $asig) {
                                // Verificar si ya existe una sustitución
                                $stmt = $db->prepare("
                                    SELECT id FROM asignaciones
                                    WHERE sustituye_a = ?
                                    AND es_sustitucion = 1
                                ");
                                $stmt->execute([$asig['id']]);
                                $sustExistente = $stmt->fetch();

                                if ($sustExistente) {
                                    // ACTUALIZAR sustitución existente
                                    $stmt = $db->prepare("
                                        UPDATE asignaciones
                                        SET persona_id = ?
                                        WHERE id = ?
                                    ");
                                    $stmt->execute([$rotadorSeleccionado['id'], $sustExistente['id']]);
                                    $sustitucionesActualizadas++;
                                } else {
                                    // CREAR nueva sustitución
                                    $stmt = $db->prepare("
                                        INSERT INTO asignaciones (cuadrante_id, persona_id, fecha, turno, puesto, es_sustitucion, sustituye_a)
                                        VALUES (?, ?, ?, ?, ?, 1, ?)
                                    ");
                                    $stmt->execute([
                                        $asig['cuadrante_id'],
                                        $rotadorSeleccionado['id'],
                                        $asig['fecha'],
                                        $asig['turno'],
                                        $asig['puesto'],
                                        $asig['id']
                                    ]);
                                    $sustitucionesCreadas++;
                                }
                            }

                            // Incrementar contador de tardes para este rotador
                            $contadorTardesRotadores[$rotadorSeleccionado['id']]++;
                            $indiceRotador++;
                        }
                    }

                    // MAÑANAS: Asignar a la persona que se incorpora
                    if (!empty($turnos['mañana'])) {
                        foreach ($turnos['mañana'] as $asig) {
                            // Verificar si ya existe una sustitución
                            $stmt = $db->prepare("
                                SELECT id FROM asignaciones
                                WHERE sustituye_a = ?
                                AND es_sustitucion = 1
                            ");
                            $stmt->execute([$asig['id']]);
                            $sustExistente = $stmt->fetch();

                            if ($sustExistente) {
                                // ACTUALIZAR sustitución existente
                                $stmt = $db->prepare("
                                    UPDATE asignaciones
                                    SET persona_id = ?
                                    WHERE id = ?
                                ");
                                $stmt->execute([$personaId, $sustExistente['id']]);
                                $sustitucionesActualizadas++;
                            } else {
                                // CREAR nueva sustitución
                                $stmt = $db->prepare("
                                    INSERT INTO asignaciones (cuadrante_id, persona_id, fecha, turno, puesto, es_sustitucion, sustituye_a)
                                    VALUES (?, ?, ?, ?, ?, 1, ?)
                                ");
                                $stmt->execute([
                                    $asig['cuadrante_id'],
                                    $personaId,
                                    $asig['fecha'],
                                    $asig['turno'],
                                    $asig['puesto'],
                                    $asig['id']
                                ]);
                                $sustitucionesCreadas++;
                            }
                        }
                    }
                }

                $db->commit();

                jsonResponse([
                    'success' => true,
                    'message' => "Baja de {$personaBaja['nombre']} cubierta correctamente. Las mañanas las cubre {$persona['nombre']}, las tardes se han repartido entre " . count($rotadoresDisponibles) . " rotadores. Se crearon $sustitucionesCreadas sustituciones y se actualizaron $sustitucionesActualizadas.",
                    'modo' => 'cubrir_baja',
                    'sustituciones_creadas' => $sustitucionesCreadas,
                    'sustituciones_actualizadas' => $sustitucionesActualizadas
                ]);
            }

            // MODO: REGENERACIÓN NORMAL (incorporación sin cubrir baja)
            else {
                // 2. Obtener cuadrante
                $stmt = $db->prepare("SELECT * FROM cuadrantes WHERE id = ?");
                $stmt->execute([$cuadranteId]);
                $cuadrante = $stmt->fetch();

                if (!$cuadrante) {
                    throw new Exception('Cuadrante no encontrado');
                }

                // 3. Borrar asignaciones desde fecha_incorporacion (incluyendo sustituciones)
                $stmt = $db->prepare("
                    DELETE FROM asignaciones
                    WHERE cuadrante_id = ?
                    AND fecha >= ?
                ");
                $stmt->execute([$cuadranteId, $fechaIncorporacion]);
                $asignacionesBorradas = $stmt->rowCount();

                // 4. Calcular cuántas semanas regenerar desde el lunes de la semana de incorporación
                $fechaInicio = new DateTime($lunesIncorporacion);
                $fechaFin = new DateTime($cuadrante['fecha_fin']);
                $interval = $fechaInicio->diff($fechaFin);
                $numSemanas = ceil($interval->days / 7) + 1;

                // 5. Obtener personas activas (incluyendo la recién incorporada)
                $stmt = $db->prepare("
                    SELECT * FROM personas
                    WHERE activo = 1
                    AND (fecha_baja IS NULL OR fecha_baja > ?)
                    ORDER BY puede_rotar ASC, nombre
                ");
                $stmt->execute([$fechaIncorporacion]);
                $personasActivas = $stmt->fetchAll();

                if (count($personasActivas) < 3) {
                    throw new Exception('Se necesitan al menos 3 personas activas para regenerar el cuadrante');
                }

                // Separar por capacidad de rotación
                $soloMañanas = array_filter($personasActivas, fn($p) => $p['puede_rotar'] == 0);
                $rotan = array_filter($personasActivas, fn($p) => $p['puede_rotar'] == 1);

                if (empty($rotan)) {
                    throw new Exception('Se necesita al menos 1 persona que pueda rotar turnos');
                }

                // 6. Obtener histórico ANTERIOR a la fecha de regeneración
                $historicoTardes = [];
                $stmt = $db->prepare("
                    SELECT persona_id, fecha
                    FROM asignaciones
                    WHERE cuadrante_id = ?
                    AND turno = 'tarde'
                    AND fecha < ?
                    AND es_sustitucion = 0
                    ORDER BY fecha DESC
                ");
                $stmt->execute([$cuadranteId, $fechaIncorporacion]);
                $tardesHistorico = $stmt->fetchAll();

                foreach ($tardesHistorico as $row) {
                    $lunes = getLunes($row['fecha']);
                    if (!isset($historicoTardes[$row['persona_id']])) {
                        $historicoTardes[$row['persona_id']] = [];
                    }
                    if (!in_array($lunes, $historicoTardes[$row['persona_id']])) {
                        $historicoTardes[$row['persona_id']][] = $lunes;
                }
                }

                // Contadores de turnos
                $contadorTardes = [];
                $contadorLavado = [];

                foreach ($personasActivas as $p) {
                    $contadorTardes[$p['id']] = isset($historicoTardes[$p['id']]) ? count($historicoTardes[$p['id']]) : 0;
                    $contadorLavado[$p['id']] = 0;
                }

                $stmt = $db->prepare("
                    SELECT persona_id, COUNT(DISTINCT fecha) as total
                    FROM asignaciones
                    WHERE cuadrante_id = ?
                    AND puesto = 'lavado'
                    AND fecha < ?
                    AND es_sustitucion = 0
                    GROUP BY persona_id
                ");
                $stmt->execute([$cuadranteId, $fechaIncorporacion]);
                while ($row = $stmt->fetch()) {
                    $contadorLavado[$row['persona_id']] = (int)$row['total'];
                }

                // 7. GENERAR SEMANAS (CON LÓGICA CORRECTA)
                $asignacionesNuevas = [];
                $fechaActual = new DateTime($lunesIncorporacion);
                $personasTardesPorSemana = [];
                $personaLavadoPorSemana = [];

                for ($semana = 0; $semana < $numSemanas; $semana++) {
                    $lunesSemana = $fechaActual->format('Y-m-d');

                    // PASO 1: DETERMINAR CUÁNTAS PERSONAS VAN A CADA TURNO
                    // REGLA: Maximizar mañanas para rotadores, pero SIEMPRE mínimo 2 de tarde

                    $numSoloMañanas = count($soloMañanas);
                    $numRotadores = count($rotan);

                    // VALIDAR: Mínimo 2 rotadores para cubrir tarde
                    if ($numRotadores < 2) {
                        throw new Exception('Se necesitan al menos 2 personas que puedan rotar para cubrir tardes. Solo hay ' . $numRotadores);
                    }

                    // 1. Solo-mañanas van SIEMPRE a mañana
                    $personasEnMañana = $numSoloMañanas;

                    // 2. Calcular espacios libres en mañana (máximo 6 totales)
                    $espaciosLibresMañana = 6 - $personasEnMañana;

                    // 3. Calcular rotadores disponibles para mañana (reservar mínimo 2 para tarde)
                    $rotadoresDisponiblesParaMañana = $numRotadores - 2;

                    // 4. Rotadores que van a mañana = mínimo entre espacios libres y disponibles
                    $rotadoresEnMañana = min($espaciosLibresMañana, max(0, $rotadoresDisponiblesParaMañana));

                    // 5. El resto de rotadores van a tarde
                    $numPersonasTarde = $numRotadores - $rotadoresEnMañana;

                    // VALIDAR: Máximo 5 de tarde
                    if ($numPersonasTarde > 5) {
                        throw new Exception('Hay ' . $numRotadores . ' rotadores. Con solo ' . $numSoloMañanas . ' de solo-mañanas, quedan ' . $numPersonasTarde . ' para tarde (máximo 5 permitido).');
                    }

                    // PASO 2: SELECCIONAR PERSONAS PARA TARDE (solo rotan)
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

                    // Tomar las personas necesarias para tarde
                    $personasTardes = [];
                    $numPreferidos = min(count($candidatosTarde), $numPersonasTarde);
                    for ($i = 0; $i < $numPreferidos; $i++) {
                        $personasTardes[] = $candidatosTarde[$i];
                    }

                    // Si faltan más, completar con los que SÍ fueron (inevitable)
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
                    // Mañana: solo-mañanas + rotatorios que NO están de tarde
                    $personasMañana = array_merge(
                        $soloMañanas,
                        array_filter($rotan, fn($p) => !in_array($p['id'], $personasTardesPorSemana[$lunesSemana]))
                    );
                    $personasMañana = array_values($personasMañana);

                    // PASO 4: SELECCIONAR LAVADO (de los que están de MAÑANA)
                    $candidatosLavado = array_filter($personasMañana, fn($p) => $p['puede_lavar']);

                    if (count($candidatosLavado) === 0) {
                        throw new Exception('No hay personas disponibles para lavado en semana ' . ($semana + 1));
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

                        // Solo generar asignaciones desde la fecha de incorporación en adelante
                        if ($fecha >= $fechaIncorporacion && $fecha <= $cuadrante['fecha_fin']) {
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
                        }

                        $fechaActual->modify('+1 day');
                    }
                    $fechaActual->modify('+2 days'); // Saltar fin de semana
                }

                // 8. Insertar asignaciones
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

                $db->commit();

                    jsonResponse([
                        'success' => true,
                        'message' => "Persona incorporada correctamente desde " . date('d/m/Y', strtotime($fechaIncorporacion)) . ". Se regeneraron $numSemanas semana(s).",
                        'asignaciones_borradas' => $asignacionesBorradas,
                        'asignaciones_creadas' => count($asignacionesNuevas),
                        'semanas_regeneradas' => $numSemanas,
                        'fecha_incorporacion' => $fechaIncorporacion
                    ]);
            } // Fin del else (regeneración normal)

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }

    } else {
        jsonResponse(['success' => false, 'message' => 'Acción no válida'], 400);
    }

} catch (Exception $e) {
    jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
