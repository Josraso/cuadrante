<?php
// Configuraci?n de la base de datos
define('DB_HOST', 'localhost');
define('DB_NAME', 'cuadrante');
define('DB_USER', 'cuadrante'); // Cambiar seg?n tu configuraci?n
define('DB_PASS', 'cuadrante'); // Cambiar seg?n tu configuraci?n
define('DB_CHARSET', 'utf8mb4');

// Zona horaria
date_default_timezone_set('Europe/Madrid');

// Funci?n de conexi?n PDO
function getDB() {
    static $db = null;
    
    if ($db === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $db = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Error de conexi?n: " . $e->getMessage());
        }
    }
    
    return $db;
}

// Funci?n para respuestas JSON
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Funci?n para obtener lunes de una fecha
function getLunes($fecha) {
    $date = new DateTime($fecha);
    $dayOfWeek = $date->format('N');
    if ($dayOfWeek != 1) {
        $date->modify('-' . ($dayOfWeek - 1) . ' days');
    }
    return $date->format('Y-m-d');
}

// Funci?n para obtener viernes de una fecha
function getViernes($fecha) {
    $date = new DateTime($fecha);
    $dayOfWeek = $date->format('N');
    if ($dayOfWeek != 5) {
        $date->modify('+' . (5 - $dayOfWeek) . ' days');
    }
    return $date->format('Y-m-d');
}