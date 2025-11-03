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

        // Detectar si hay personas de baja con sustituciones en este cuadrante
        $stmt = $db->prepare("
            SELECT DISTINCT p.id, p.nombre, p.fecha_baja
            FROM personas p
            INNER JOIN asignaciones a_orig ON a_orig.persona_id = p.id
            INNER JOIN asignaciones a_sust ON a_sust.sustituye_a = a_orig.id
            WHERE a_orig.cuadrante_id = ?
            AND a_orig.fecha >= ?
            AND p.fecha_baja IS NOT NULL
            AND a_sust.es_sustitucion = 1
        ");
        $stmt->execute([$cuadranteId, $fechaIncorporacion]);
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
                // Obtener cuadrante
                $stmt = $db->prepare("SELECT * FROM cuadrantes WHERE id = ?");
                $stmt->execute([$cuadranteId]);
                $cuadrante = $stmt->fetch();

                if (!$cuadrante) {
                    throw new Exception('Cuadrante no encontrado');
                }

                // Buscar personas de baja en el rango de fechas
                $stmt = $db->prepare("
                    SELECT DISTINCT p.id, p.nombre
                    FROM personas p
                    INNER JOIN asignaciones a_orig ON a_orig.persona_id = p.id
                    WHERE a_orig.cuadrante_id = ?
                    AND a_orig.fecha >= ?
                    AND a_orig.es_sustitucion = 0
                    AND p.fecha_baja IS NOT NULL
                    AND p.fecha_baja <= ?
                ");
                $stmt->execute([$cuadranteId, $fechaIncorporacion, $cuadrante['fecha_fin']]);
                $personasDeBaja = $stmt->fetchAll();

                if (empty($personasDeBaja)) {
                    throw new Exception('No se encontraron personas de baja para cubrir');
                }

                // Tomar la primera persona de baja (o podríamos pedir al usuario que elija)
                $personaBaja = $personasDeBaja[0];
                $personaBajaId = $personaBaja['id'];

                // Obtener TODAS las asignaciones originales de la persona de baja desde la fecha
                $stmt = $db->prepare("
                    SELECT id FROM asignaciones
                    WHERE persona_id = ?
                    AND cuadrante_id = ?
                    AND fecha >= ?
                    AND es_sustitucion = 0
                ");
                $stmt->execute([$personaBajaId, $cuadranteId, $fechaIncorporacion]);
                $asignacionesOriginales = $stmt->fetchAll();

                $sustitucionesCreadas = 0;
                $sustitucionesActualizadas = 0;

                // Para cada asignación original, crear/actualizar sustitución
                foreach ($asignacionesOriginales as $asigOrig) {
                    // Verificar si ya existe una sustitución
                    $stmt = $db->prepare("
                        SELECT id FROM asignaciones
                        WHERE sustituye_a = ?
                        AND es_sustitucion = 1
                    ");
                    $stmt->execute([$asigOrig['id']]);
                    $sustExistente = $stmt->fetch();

                    if ($sustExistente) {
                        // ACTUALIZAR sustitución existente con la nueva persona
                        $stmt = $db->prepare("
                            UPDATE asignaciones
                            SET persona_id = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$personaId, $sustExistente['id']]);
                        $sustitucionesActualizadas++;
                    } else {
                        // CREAR nueva sustitución
                        // Obtener datos de la asignación original
                        $stmt = $db->prepare("SELECT * FROM asignaciones WHERE id = ?");
                        $stmt->execute([$asigOrig['id']]);
                        $asigOriginal = $stmt->fetch();

                        $stmt = $db->prepare("
                            INSERT INTO asignaciones (cuadrante_id, persona_id, fecha, turno, puesto, es_sustitucion, sustituye_a)
                            VALUES (?, ?, ?, ?, ?, 1, ?)
                        ");
                        $stmt->execute([
                            $asigOriginal['cuadrante_id'],
                            $personaId,
                            $asigOriginal['fecha'],
                            $asigOriginal['turno'],
                            $asigOriginal['puesto'],
                            $asigOrig['id']
                        ]);
                        $sustitucionesCreadas++;
                    }
                }

                $db->commit();

                jsonResponse([
                    'success' => true,
                    'message' => "{$persona['nombre']} ahora cubre la baja de {$personaBaja['nombre']}. Se crearon $sustitucionesCreadas sustituciones y se actualizaron $sustitucionesActualizadas. La persona de baja seguirá visible en el cuadrante.",
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
