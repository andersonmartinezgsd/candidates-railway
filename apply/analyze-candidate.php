<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__.'/db.php';
require_once __DIR__.'/../logic/candidate_ai_pipeline.php';

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $token = trim((string) ($input['token'] ?? $_GET['token'] ?? $_POST['token'] ?? ''));

    if ($token === '') {
        respond(['status' => 'error', 'message' => 'Token is required'], 422);
    }

    $pdo = getDB();
    $candidate = gsdFindCandidateByToken(
        $pdo,
        $token,
        array_values(array_filter([gsdDraftCandidateTable($pdo), gsdOfficialCandidateTable($pdo)]))
    );

    if (! is_array($candidate)) {
        respond(['status' => 'error', 'message' => 'Candidate not found'], 404);
    }

    $analysis = gsdCandidateAiAnalyzeAndPersist($pdo, $candidate, gsdCandidateAiDecodeJson($candidate['biometric_json'] ?? null));

    respond([
        'status' => 'ok',
        'token' => $token,
        'candidate_id' => $candidate['id'] ?? null,
        'analysis' => $analysis,
    ]);
} catch (Throwable $exception) {
    respond([
        'status' => 'error',
        'message' => $exception->getMessage(),
    ], 500);
}
