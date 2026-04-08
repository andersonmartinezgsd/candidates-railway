<?php

require_once __DIR__.'/../config/Database.php';

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $database = new Database();
    $pdo = $database->getConnection();

    if (! $pdo instanceof PDO) {
        throw new RuntimeException('Database connection is not available for recruitment/apply.');
    }

    return $pdo;
}

function generateToken(): string
{
    $db = getDB();

    $tempToken = 'TMP-'.bin2hex(random_bytes(8));
    $stmt = $db->prepare("INSERT INTO gsd_candidates (token, type, name, email, processing_status, is_main, created_at, updated_at) VALUES (?, 'candidate', 'Draft Candidate', ?, 'pending', 1, NOW(), NOW())");
    $stmt->execute([$tempToken, 'draft+'.strtolower($tempToken).'@local.gsd']);

    $id = (int) $db->lastInsertId();
    $token = 'GSD-CANDIDATE-'.str_pad((string) $id, 4, '0', STR_PAD_LEFT);

    $updateStmt = $db->prepare('UPDATE gsd_candidates SET token = ?, email = ? WHERE id = ?');
    $updateStmt->execute([$token, 'draft+'.strtolower($token).'@local.gsd', $id]);

    return $token;
}
