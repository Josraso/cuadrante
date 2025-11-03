<?php
require_once '../config.php';

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // Obtener TODAS las personas (activas e inactivas)
        $stmt = $db->query("SELECT * FROM personas ORDER BY activo DESC, nombre");
        $personas = $stmt->fetchAll();
        jsonResponse(['success' => true, 'data' => $personas]);
        break;
        
    case 'POST':
        // Crear nueva persona
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['nombre'])) {
            jsonResponse(['success' => false, 'message' => 'El nombre es obligatorio'], 400);
        }
        
        try {
            $stmt = $db->prepare("INSERT INTO personas (nombre, puede_rotar, puede_lavar, activo) VALUES (?, ?, ?, 0)");
            $stmt->execute([
                $data['nombre'],
                isset($data['puede_rotar']) ? (int)$data['puede_rotar'] : 1,
                isset($data['puede_lavar']) ? (int)$data['puede_lavar'] : 1
            ]);

            jsonResponse([
                'success' => true,
                'message' => 'Persona creada correctamente como INACTIVA. Debes incorporarla al cuadrante para activarla.',
                'id' => $db->lastInsertId()
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                jsonResponse(['success' => false, 'message' => 'Ya existe una persona con ese nombre'], 400);
            }
            jsonResponse(['success' => false, 'message' => 'Error al crear persona: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'PUT':
        // Actualizar persona
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['id'])) {
            jsonResponse(['success' => false, 'message' => 'ID requerido'], 400);
        }
        
        try {
            $stmt = $db->prepare("UPDATE personas SET nombre = ?, puede_rotar = ?, puede_lavar = ? WHERE id = ?");
            $stmt->execute([
                $data['nombre'],
                (int)$data['puede_rotar'],
                (int)$data['puede_lavar'],
                $data['id']
            ]);
            
            jsonResponse(['success' => true, 'message' => 'Persona actualizada correctamente']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Error al actualizar: ' . $e->getMessage()], 500);
        }
        break;
        
    case 'DELETE':
        // Borrar persona PERMANENTEMENTE de la BD
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data['id'])) {
            jsonResponse(['success' => false, 'message' => 'ID requerido'], 400);
        }

        try {
            // Verificar si tiene asignaciones
            $stmt = $db->prepare("SELECT COUNT(*) as total FROM asignaciones WHERE persona_id = ?");
            $stmt->execute([$data['id']]);
            $result = $stmt->fetch();

            if ($result['total'] > 0) {
                jsonResponse([
                    'success' => false,
                    'message' => 'No se puede borrar: la persona tiene asignaciones en cuadrantes. Desactívala en su lugar.'
                ], 400);
            }

            // Borrar permanentemente
            $stmt = $db->prepare("DELETE FROM personas WHERE id = ?");
            $stmt->execute([$data['id']]);

            jsonResponse(['success' => true, 'message' => 'Persona borrada PERMANENTEMENTE de la base de datos']);
        } catch (PDOException $e) {
            jsonResponse(['success' => false, 'message' => 'Error al borrar: ' . $e->getMessage()], 500);
        }
        break;
        
    default:
        jsonResponse(['success' => false, 'message' => 'Método no permitido'], 405);
}