<?php
header('Content-Type: application/json');
require_once '../classes/VideoHandler.php';

// 1. Validaciones básicas
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

if (empty($_FILES['video_blob']) || $_FILES['video_blob']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['status' => 'error', 'message' => 'Archivo de video no válido']);
    exit;
}

// 2. Instanciar el Handler
$handler = new VideoHandler();

// 3. Capturar datos del POST
$token = $_POST['token'] ?? '';

// --- NUEVOS CAMPOS ---
$firstName = $_POST['first_name'] ?? ''; 
$lastName = $_POST['last_name'] ?? '';   
// El JS envía 'name' como la concatenación, lo usamos como Full Name
$fullName = $_POST['name'] ?? ($firstName . ' ' . $lastName); 
// ---------------------

$email = $_POST['email'] ?? '';
$transcript = $_POST['transcript'] ?? '';
$score = $_POST['sentiment_score'] ?? 0;
$userDataJson = $_POST['user_data_json'] ?? null;

// 4. Llamar a la función con los nuevos parámetros
// El orden debe coincidir con la función en VideoHandler.php
$result = $handler->processUpload(
    $token, 
    $firstName,   // Nuevo
    $lastName,    // Nuevo
    $fullName,    // Nombre completo
    $email, 
    $transcript, 
    $score, 
    $_FILES['video_blob'], // ESTE es el video con el fondo ahora
    $_FILES['video_blob_processed'] ?? null, // Añade el segundo archivo (el original) si existe
    $userDataJson
);

echo json_encode($result);
?>