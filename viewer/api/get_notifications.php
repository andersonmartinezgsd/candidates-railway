<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/Database.php';

try {
    $db = (new Database())->getConnection();

    // 1. Contar feedbacks no leídos
    $sqlFeedback = "SELECT COUNT(*) as total FROM gsd_candidate_feedback WHERE is_read = 0";
    $countFeedback = $db->query($sqlFeedback)->fetch(PDO::FETCH_ASSOC)['total'];

    // 2. Contar videos/candidatos nuevos (últimas 48 horas)
    $sqlNewOnes = "SELECT COUNT(*) as total FROM gsd_candidates WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)";
    $countNew = $db->query($sqlNewOnes)->fetch(PDO::FETCH_ASSOC)['total'];

    echo json_encode([
        'unread_feedback' => (int)$countFeedback,
        'new_candidates' => (int)$countNew,
        'total' => (int)$countFeedback + (int)$countNew
    ]);
} catch (Exception $e) {
    echo json_encode(['unread_feedback' => 0, 'new_candidates' => 0, 'total' => 0]);
}