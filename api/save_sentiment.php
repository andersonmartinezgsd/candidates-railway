<?php
header('Content-Type: application/json');
require_once '../config/Database.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Validamos que existan los datos mínimos necesarios
    if(isset($_POST['token']) && isset($_POST['text_fragment'])) {
        
        // Instancia idéntica a tu ejemplo
        $db = (new Database())->getConnection();
        
        try {
            // Recibir y sanitizar datos básicos
            $token = $_POST['token'];
            $text = $_POST['text_fragment'];
            // Convertimos a entero para asegurar el tipo de dato en DB
            $score = isset($_POST['score_fragment']) ? intval($_POST['score_fragment']) : 0;
            $total = isset($_POST['total_score']) ? intval($_POST['total_score']) : 0;

            // Preparamos la sentencia SQL (PDO estándar)
            $stmt = $db->prepare("INSERT INTO gsd_interview_sentiment_logs (token_id, text_fragment, score_fragment, cumulative_score, created_at) VALUES (?, ?, ?, ?, NOW())");
            
            // Ejecutamos pasando el array de variables (igual que tu ejemplo)
            if($stmt->execute([$token, $text, $score, $total])) {
                echo json_encode(['status' => 'success']);
            } else {
                echo json_encode(['status' => 'error', 'message' => 'Could not save log']);
            }

        } catch(PDOException $e) { 
            // Capturamos error de PDO manteniendo el formato JSON
            echo json_encode(['status' => 'error', 'message' => $e->getMessage()]); 
        }

    } else {
        echo json_encode(['status' => 'error', 'message' => 'Missing required data']);
    }

} else {
    echo json_encode(['status' => 'error', 'message' => 'Invalid Request Method']);
}
?>