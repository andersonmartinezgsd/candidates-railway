<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/Database.php';

try {
    $db = (new Database())->getConnection();
    $sql = "INSERT INTO gsd_candidate_feedback (candidate_id, client_token, evaluator_name, rating, comment, is_read) 
            VALUES (:cand, :token, :eval, :rate, :comm, 0)";
    
    $stmt = $db->prepare($sql);
    $success = $stmt->execute([
        ':cand'  => $_POST['candidate_id'],
        ':token' => $_POST['client_token'] ?? 'Anonymous',
        ':eval'  => $_POST['evaluator'] ?? 'Anonymous',
        ':rate'  => $_POST['rating'],
        ':comm'  => $_POST['comment']
    ]);
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}