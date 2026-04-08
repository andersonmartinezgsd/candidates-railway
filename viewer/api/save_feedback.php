<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
    exit;
}

$candidate_id = $_POST['candidate_id'] ?? null;
$token = $_POST['token'] ?? null;
$rating = $_POST['rating'] ?? null;
$comment = $_POST['comment'] ?? '';

if (!$candidate_id || !$token) {
    echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
    exit;
}

try {
    $database = new Database();
    $pdo = $database->getConnection();

    $sql = "INSERT INTO gsd_candidate_feedback (candidate_id, client_token, rating, comment) 
            VALUES (:cid, :token, :rating, :comment)";
    
    $stmt = $pdo->prepare($sql);
    $res = $stmt->execute([
        'cid' => $candidate_id,
        'token' => $token,
        'rating' => $rating,
        'comment' => $comment
    ]);

    if ($res) {
        echo json_encode(['status' => 'success', 'message' => 'Feedback guardado']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo guardar']);
    }

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}