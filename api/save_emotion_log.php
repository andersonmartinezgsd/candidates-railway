<?php
header('Content-Type: application/json');
require_once '../config/Database.php';

$data = json_decode(file_get_contents("php://input"));

if(isset($data->candidate_id) && isset($data->emotion)) {
    $database = new Database();
    $db = $database->getConnection();

    try {
        $stmt = $db->prepare("INSERT INTO gsd_candidate_video_logs (candidate_id, video_timestamp, dominant_emotion, score) VALUES (?, ?, ?, ?)");
        if($stmt->execute([$data->candidate_id, $data->timestamp, $data->emotion, $data->score])) {
            echo json_encode(['status' => 'success']);
        } else {
            echo json_encode(['status' => 'error']);
        }
    } catch(PDOException $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
}
?>