<?php
// gsd-admin-hr/api/batch_upload.php
ob_start(); // Previene que cualquier warning se imprima antes de tiempo
header('Content-Type: application/json');

try {
    require_once '../classes/VideoHandler.php';

    $API_SECRET = "GSD_SECURE_BATCH_2026"; 
    
    // Forma más compatible de obtener la API KEY
    $headers = array_change_key_case(getallheaders(), CASE_LOWER);
    $authHeader = $headers['x-api-key'] ?? $_POST['api_key'] ?? '';

    if ($authHeader !== $API_SECRET) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Acceso denegado.']);
        exit;
    }

    if (empty($_FILES['video_blob'])) {
        echo json_encode(['status' => 'error', 'message' => 'No se recibió video_blob']);
        exit;
    }

    $handler = new VideoHandler();
    
    // Pasamos todos los parámetros que envía el Python
    $result = $handler->processBatchUpload(
        $_POST['token'] ?? '', 
        $_POST['name'] ?? 'Unknown', 
        $_POST['email'] ?? '', 
        $_POST['transcript'] ?? '', 
        $_POST['summary'] ?? '',
        $_FILES['video_blob'],
        $_POST['original_filename'] ?? '' // <--- Asegúrate que VideoHandler lo reciba
    );

    ob_clean(); // Limpia cualquier basura (warnings) antes de enviar el JSON
    echo json_encode($result);

} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}