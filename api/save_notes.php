<?php
// api/save_notes.php
header('Content-Type: application/json');
require_once '../config/Database.php';

$data = json_decode(file_get_contents("php://input"));

if(isset($data->id) && isset($data->notes)) {
    $database = new Database();
    $db = $database->getConnection();

    try {
        $stmt = $db->prepare("UPDATE gsd_candidates SET hr_notes = ? WHERE id = ?");
        if($stmt->execute([$data->notes, $data->id])) {
            echo json_encode(['status' => 'success', 'message' => 'Notas guardadas']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error al guardar']);
        }
    } catch(PDOException $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
}
?>