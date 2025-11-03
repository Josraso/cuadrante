<?php
require_once '../config.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            // Obtener un cuadrante específico con sus asignaciones
            $id = (int)$_GET['id'];
            
            $stmt = $db->prepare("SELECT * FROM cuadrantes WHERE id = ?");
            $stmt->execute([$id]);
            $cuadrante = $stmt->fetch();
            
            if (!$cuadrante) {
                jsonResponse(['success' => false, 'message' => 'Cuadrante no encontrado'], 404);
            }
            
            // Obtener asignaciones (incluyendo sustituciones)
            $stmt = $db->prepare("
                SELECT a.*, p.nombre as persona_nombre
                FROM asignaciones a
                JOIN personas p ON a.persona_id = p.id
                WHERE a.cuadrante_id = ?
                ORDER BY a.fecha, a.turno, a.puesto, a.es_sustitucion
            ");
            $stmt->execute([$id]);
            $asignaciones = $stmt->fetchAll();

            // Para cada asignación que NO es sustitución, buscar si tiene un sustituto
            foreach ($asignaciones as &$asig) {
                if ($asig['es_sustitucion'] == 0) {
                    $stmt = $db->prepare("
                        SELECT a_sust.*, p_sust.nombre as persona_nombre
                        FROM asignaciones a_sust
                        JOIN personas p_sust ON a_sust.persona_id = p_sust.id
                        WHERE a_sust.sustituye_a = ?
                        AND a_sust.es_sustitucion = 1
                    ");
                    $stmt->execute([$asig['id']]);
                    $sustituto = $stmt->fetch();
                    $asig['sustituto'] = $sustituto ?: null;
                }
            }
            unset($asig); // Romper la referencia
            
            $cuadrante['asignaciones'] = $asignaciones;
            jsonResponse(['success' => true, 'data' => $cuadrante]);
            
        } else {
            // Listar todos los cuadrantes
            $stmt = $db->query("SELECT * FROM cuadrantes ORDER BY fecha_inicio DESC");
            $cuadrantes = $stmt->fetchAll();
            jsonResponse(['success' => true, 'data' => $cuadrantes]);
        }
        break;
        
    case 'POST':
        // Crear nuevo cuadrante (se usa desde generar.php)
        $data = json_decode(file_get_contents('php://input'), true);
        
        try {
            $db->beginTransaction();
            
            // Crear cuadrante
            $stmt = $db->prepare("INSERT INTO cuadrantes (nombre, fecha_inicio, fecha_fin, num_semanas) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $data['nombre'],
                $data['fecha_inicio'],
                $data['fecha_fin'],
                $data['num_semanas']
            ]);
            
            $cuadranteId = $db->lastInsertId();
            
            // Insertar asignaciones
            if (!empty($data['asignaciones'])) {
                $stmt = $db->prepare("
                    INSERT INTO asignaciones (cuadrante_id, persona_id, fecha, turno, puesto) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                foreach ($data['asignaciones'] as $asig) {
                    $stmt->execute([
                        $cuadranteId,
                        $asig['persona_id'],
                        $asig['fecha'],
                        $asig['turno'],
                        $asig['puesto']
                    ]);
                }
            }
            
            $db->commit();
            jsonResponse(['success' => true, 'message' => 'Cuadrante creado', 'id' => $cuadranteId]);
            
        } catch (Exception $e) {
            $db->rollBack();
            jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'PUT':
        // Actualizar asignación individual
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['id'])) {
            jsonResponse(['success' => false, 'message' => 'ID de asignación requerido'], 400);
        }
        
        try {
            $stmt = $db->prepare("
                UPDATE asignaciones 
                SET persona_id = ?, puesto = ?, editado_manualmente = 1 
                WHERE id = ?
            ");
            $stmt->execute([
                $data['persona_id'],
                $data['puesto'],
                $data['id']
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Asignación actualizada']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'DELETE':
        // Eliminar cuadrante
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['id'])) {
            jsonResponse(['success' => false, 'message' => 'ID requerido'], 400);
        }
        
        try {
            $stmt = $db->prepare("DELETE FROM cuadrantes WHERE id = ?");
            $stmt->execute([$data['id']]);
            
            jsonResponse(['success' => true, 'message' => 'Cuadrante eliminado']);
        } catch (Exception $e) {
            jsonResponse(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
        }
        break;
        
    default:
        jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}