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

        // Ajustar fecha_desde al lunes más cercano
        $lunesDesde = getLunes($fechaDesde);

        // Obtener semanas que se mantendrán (antes de fecha_desde)
        $semanasMantenidas = [];
        $currentLunes = getLunes($cuadrante['fecha_inicio']);
        while ($currentLunes < $lunesDesde) {
            $viernes = getViernes($currentLunes);
            $semanasMantenidas[] = [
                'lunes' => $currentLunes,
                'viernes' => $viernes
            ];
            $currentLunes = date('Y-m-d', strtotime($currentLunes . ' +7 days'));
        }

        // Obtener semanas que se regenerarán (desde fecha_desde hasta fin)
        $semanasRegeneradas = [];
        $currentLunes = $lunesDesde;
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
        $stmt->execute([$cuadranteId, $lunesDesde]);
        $personasDeBaja = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'cuadrante' => $cuadrante,
            'persona' => $persona,
            'lunes_desde' => $lunesDesde,
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

            // Ajustar a lunes
            $lunesDesde = getLunes($fechaDesde);

            // MODO: CUBRIR BAJA
            if ($cubrirBaja) {
                // Obtener cuadrante
                $stmt = $db->prepare("SELECT * FROM cuadrantes WHERE id = ?");
                $stmt->execute([$cuadranteId]);
                $cuadrante = $stmt->fetch();

                if (!$cuadrante) {
                    throw new Exception('Cuadrante no encontrado');
                }

                // Buscar personas de baja con sustituciones en el rango de fechas
                $stmt = $db->prepare("
                    SELECT DISTINCT p.id, p.nombre
                    FROM personas p
                    INNER JOIN asignaciones a_orig ON a_orig.persona_id = p.id
                    INNER JOIN asignaciones a_sust ON a_sust.sustituye_a = a_orig.id
                    WHERE a_orig.cuadrante_id = ?
                    AND a_orig.fecha >= ?
                    AND p.fecha_baja IS NOT NULL
                    AND a_sust.es_sustitucion = 1
                ");
                $stmt->execute([$cuadranteId, $lunesDesde]);
                $personasDeBaja = $stmt->fetchAll();

                if (empty($personasDeBaja)) {
                    throw new Exception('No se encontraron personas de baja para cubrir');
                }

                // Tomar la primera persona de baja (o podríamos pedir al usuario que elija)
                $personaBaja = $personasDeBaja[0];
                $personaBajaId = $personaBaja['id'];

                // Transferir asignaciones: cambiar persona_id de las asignaciones originales
                $stmt = $db->prepare("
                    UPDATE asignaciones
                    SET persona_id = ?
                    WHERE persona_id = ?
                    AND cuadrante_id = ?
                    AND fecha >= ?
                    AND es_sustitucion = 0
                ");
                $stmt->execute([$personaId, $personaBajaId, $cuadranteId, $lunesDesde]);
                $asignacionesTransferidas = $stmt->rowCount();

                // Eliminar todas las sustituciones de esa persona desde la fecha
                $stmt = $db->prepare("
                    DELETE asig_sust
                    FROM asignaciones asig_sust
                    INNER JOIN asignaciones asig_orig ON asig_sust.sustituye_a = asig_orig.id
                    WHERE asig_orig.persona_id = ?
                    AND asig_orig.cuadrante_id = ?
                    AND asig_orig.fecha >= ?
                    AND asig_sust.es_sustitucion = 1
                ");
                $stmt->execute([$personaId, $cuadranteId, $lunesDesde]);
                $sustitucionesEliminadas = $stmt->rowCount();

                $db->commit();

                jsonResponse([
                    'success' => true,
                    'message' => "{$persona['nombre']} ha cubierto la baja de {$personaBaja['nombre']}. Se transfirieron $asignacionesTransferidas asignaciones y se eliminaron $sustitucionesEliminadas sustituciones.",
                    'modo' => 'cubrir_baja',
                    'asignaciones_transferidas' => $asignacionesTransferidas,
                    'sustituciones_eliminadas' => $sustitucionesEliminadas
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

                // 3. Borrar asignaciones desde fecha_desde (incluyendo sustituciones)
                $stmt = $db->prepare("
                    DELETE FROM asignaciones
                    WHERE cuadrante_id = ?
                    AND fecha >= ?
                ");
                $stmt->execute([$cuadranteId, $lunesDesde]);
                $asignacionesBorradas = $stmt->rowCount();

                // 4. Calcular cuántas semanas regenerar
                $fechaInicio = new DateTime($lunesDesde);
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
                $stmt->execute([$lunesDesde]);
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
                $stmt->execute([$cuadranteId, $lunesDesde]);
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
                $stmt->execute([$cuadranteId, $lunesDesde]);
                while ($row = $stmt->fetch()) {
                    $contadorLavado[$row['persona_id']] = (int)$row['total'];
                }

                // 7. GENERAR SEMANAS (CON LÓGICA CORRECTA)
                $asignacionesNuevas = [];
                $fechaActual = new DateTime($lunesDesde);
                $personasTardesPorSemana = [];
                $personaLavadoPorSemana = [];

                for ($semana = 0; $semana < $numSemanas; $semana++) {
                    $lunesSemana = $fechaActual->format('Y-m-d');

                    // PASO 1: DETERMINAR CUÁNTAS PERSONAS VAN A CADA TURNO
                    // REGLA: Llenar MAÑANA primero (máximo 6), resto a TARDE (máximo 5)
                    $totalPersonas = count($personasActivas);
                    $numPersonasMañana = min($totalPersonas, 6); // Máximo 6 de mañana
                    $numPersonasTarde = $totalPersonas - $numPersonasMañana; // El resto a tarde

                    // VALIDAR: Máximo 5 de tarde
                    if ($numPersonasTarde > 5) {
                        throw new Exception('Hay ' . $totalPersonas . ' personas activas. Máximo permitido: 11 (6 mañana + 5 tarde)');
                    }

                    // VALIDAR: Mínimo 1 de tarde (permitir con advertencia)
                    if ($numPersonasTarde == 0) {
                        throw new Exception('No hay suficientes personas para cubrir tardes');
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
                        'message' => "Persona incorporada correctamente. Se regeneraron $numSemanas semana(s) desde " . date('d/m/Y', strtotime($lunesDesde)),
                        'asignaciones_borradas' => $asignacionesBorradas,
                        'asignaciones_creadas' => count($asignacionesNuevas),
                        'semanas_regeneradas' => $numSemanas
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
