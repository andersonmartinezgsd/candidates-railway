<?php
// api/save_biometrics.php
ini_set('display_errors', 0);
header('Content-Type: application/json');
require_once '../config/Database.php';

$input = file_get_contents("php://input");
$data = json_decode($input, true);

if(isset($data['candidate_id']) && isset($data['analysis_data'])) {
    try {
        $db = (new Database())->getConnection();
        
        // Convertimos el array de JS a un string JSON para guardarlo en MySQL
        $jsonString = json_encode($data['analysis_data']);
        
        $stmt = $db->prepare("UPDATE gsd_candidates SET biometric_json = ? WHERE id = ?");
        $stmt->execute([$jsonString, $data['candidate_id']]);
        
        echo json_encode(['status' => 'success', 'message' => 'Biometrics saved']);
    } catch(Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'No data']);
}
?>