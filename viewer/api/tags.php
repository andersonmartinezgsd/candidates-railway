<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../config/Database.php';

$db = (new Database())->getConnection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $candId = $_GET['candidate_id'];
    
    // Obtener todas las etiquetas y marcar cuáles tiene el candidato
    $sql = "SELECT t.*, 
            (SELECT COUNT(*) FROM gsd_candidate_tag_map WHERE candidate_id = :cand AND tag_id = t.id) as selected
            FROM gsd_tags t ORDER BY name ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute(['cand' => $candId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if ($data['action'] === 'add') {
        $stmt = $db->prepare("INSERT IGNORE INTO gsd_candidate_tag_map (candidate_id, tag_id) VALUES (?, ?)");
        $stmt->execute([$data['candidate_id'], $data['tag_id']]);
    } 
    elseif ($data['action'] === 'remove') {
        $stmt = $db->prepare("DELETE FROM gsd_candidate_tag_map WHERE candidate_id = ? AND tag_id = ?");
        $stmt->execute([$data['candidate_id'], $data['tag_id']]);
    }
    elseif ($data['action'] === 'create') {
        $stmt = $db->prepare("INSERT IGNORE INTO gsd_tags (name) VALUES (?)");
        $stmt->execute([$data['name']]);
    }
    echo json_encode(['status' => 'success']);
}