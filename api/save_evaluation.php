<?php
// api/save_evaluation.php
header('Content-Type: application/json');
require_once '../config/Database.php';

$input = file_get_contents("php://input");
$data = json_decode($input, true);
$db = (new Database())->getConnection();

if(isset($data['id'])) {
    try {
        // CASO A: SOLO GUARDAR PUNTAJE (Desde el input pequeño)
        if (isset($data['mode']) && $data['mode'] === 'score_only') {
            $stmt = $db->prepare("UPDATE gsd_candidates SET manual_score = ? WHERE id = ?");
            $stmt->execute([$data['score'], $data['id']]);
        } 
        // CASO B: GUARDADO COMPLETO (Desde el botón "SAVE NOTE & SCORE")
        else {
            // Si viene score, lo actualizamos
            if(isset($data['score'])) {
                $stmt = $db->prepare("UPDATE gsd_candidates SET manual_score = ? WHERE id = ?");
                $stmt->execute([$data['score'], $data['id']]);
            }
            // Nota: Las notas se guardan en la otra API (notes_handler), aquí solo nos encargamos del score en la tabla candidates
        }
        
        echo json_encode(['status' => 'success']);

    } catch(Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['status' => 'error', 'message' => 'Missing ID']);
}
?>