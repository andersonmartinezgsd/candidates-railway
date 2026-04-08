<?php
require_once __DIR__ . '/../../config/Database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = (new Database())->getConnection();
    
    // Si agregaste evaluator_name a la tabla usa esto:
    $sql = "INSERT INTO gsd_candidate_feedback (candidate_id, client_token, evaluator_name, rating, comment) 
            VALUES (:cand, :token, :eval, :rate, :comm)";
    
    $stmt = $db->prepare($sql);
    $success = $stmt->execute([
        ':cand'  => $_POST['candidate_id'],
        ':token' => $_POST['client_token'],
        ':eval'  => $_POST['evaluator'] ?? '',
        ':rate'  => $_POST['rating'],
        ':comm'  => $_POST['comment']
    ]);

    header('Content-Type: application/json');
    echo json_encode(['status' => $success ? 'success' : 'error']);
}