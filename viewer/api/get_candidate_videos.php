<?php
require_once __DIR__ . '/../../config/Database.php';

$database = new Database();
$pdo = $database->getConnection();

$candidateId = (int) ($_GET['candidate_id'] ?? 0);
$email = $_GET['email'] ?? '';

if ($candidateId > 0) {
    $lookup = $pdo->prepare('SELECT email FROM gsd_candidates WHERE id = ? LIMIT 1');
    $lookup->execute([$candidateId]);
    $resolvedEmail = $lookup->fetchColumn();

    if (is_string($resolvedEmail) && $resolvedEmail !== '') {
        $email = $resolvedEmail;
    }
}

// Buscamos los videos de este email que NO hayan sido descartados
$stmt = $pdo->prepare("SELECT id, token, email, created_at, video_processed_path, video_original_path, is_main 
                       FROM gsd_candidates 
                       WHERE email = ? AND processing_status != 'discarded' 
                       ORDER BY created_at DESC");
$stmt->execute([$email]);
$videos = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($videos);
