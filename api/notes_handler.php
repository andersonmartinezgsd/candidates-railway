<?php
header('Content-Type: application/json');
require_once '../config/Database.php';

$method = $_SERVER['REQUEST_METHOD'];
$database = new Database();
$db = $database->getConnection();

if ($method === 'POST') {
    // GUARDAR NUEVA NOTA
    $data = json_decode(file_get_contents("php://input"));
    if(isset($data->candidate_id) && isset($data->note)) {
        try {
            $stmt = $db->prepare("INSERT INTO gsd_candidate_notes (candidate_id, author, note_text) VALUES (?, ?, ?)");
            // Aquí puedes cambiar 'HR Director' por una variable de sesión si tienes login
            $author = $data->author ?? 'HR Director'; 
            if($stmt->execute([$data->candidate_id, $author, $data->note])) {
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error']);
            }
        } catch(PDOException $e) { echo json_encode(['error' => $e->getMessage()]); }
    }
} 
elseif ($method === 'GET') {
    // OBTENER NOTAS DE UN CANDIDATO
    if(isset($_GET['id'])) {
        $stmt = $db->prepare("SELECT * FROM gsd_candidate_notes WHERE candidate_id = ? ORDER BY created_at DESC");
        $stmt->execute([$_GET['id']]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($notes);
    }
}
?>