<?php
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../helpers.php';

$database = new Database();
$pdo = $database->getConnection();

$candidateId = (int) ($_GET['candidate_id'] ?? 0);
$email = $_GET['email'] ?? '';

if ($candidateId > 0) {
    $lookup = $pdo->prepare("SELECT email FROM gsd_candidates WHERE id = ? AND ".gsdViewerVisibleCandidateClause('gsd_candidates')." LIMIT 1");
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
                       AND (name IS NULL OR name <> 'Draft Candidate')
                       AND (token IS NULL OR token NOT LIKE 'TMP-%')
                       ORDER BY created_at DESC");
$stmt->execute([$email]);
$videos = array_map(static function (array $video): array {
    $video['stream_url'] = gsdViewerCandidateStreamUrl($video);
    $video['mp4_stream_url'] = gsdViewerCandidateStreamUrl($video, 'mp4');

    return $video;
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

echo json_encode($videos);
