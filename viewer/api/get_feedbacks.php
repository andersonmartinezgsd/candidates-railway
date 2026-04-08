<?php
// viewer/api/get_feedbacks.php
header('Content-Type: application/json');
error_reporting(0); 

// Ajustamos la ruta para llegar a config (subir 2 niveles)
require_once __DIR__ . '/../../config/Database.php';

$candidateId = $_GET['id'] ?? null;

if (!$candidateId) {
    echo json_encode(['error' => 'No ID provided']);
    exit;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    // IMPORTANTE: Asegúrate de que el nombre de la tabla sea este
    $sql = "SELECT evaluator_name, rating, comment, DATE_FORMAT(created_at, '%b %d, %Y') as created_at 
            FROM gsd_candidate_feedback 
            WHERE candidate_id = :id 
            ORDER BY created_at DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->execute(['id' => $candidateId]);
    $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($feedbacks ?: []); 
} catch (Exception $e) {
    echo json_encode(['error' => 'Database connection failed']);
}