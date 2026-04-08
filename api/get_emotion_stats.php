<?php
header('Content-Type: application/json');
require_once '../config/Database.php';

if(isset($_GET['id'])) {
    $db = (new Database())->getConnection();
    
    try {
        // Contamos cuántos registros hay por cada emoción
        $stmt = $db->prepare("SELECT dominant_emotion, COUNT(*) as count FROM gsd_candidate_video_logs WHERE candidate_id = ? GROUP BY dominant_emotion");
        $stmt->execute([$_GET['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if(count($rows) > 0) {
            $total_logs = 0;
            foreach($rows as $row) $total_logs += $row['count'];

            $stats = [];
            foreach($rows as $row) {
                $percent = round(($row['count'] / $total_logs) * 100);
                $stats[] = [
                    'emotion' => $row['dominant_emotion'],
                    'percent' => $percent
                ];
            }
            // Ordenar de mayor a menor porcentaje
            usort($stats, function($a, $b) { return $b['percent'] - $a['percent']; });

            echo json_encode(['status' => 'success', 'data' => $stats]);
        } else {
            echo json_encode(['status' => 'empty']);
        }
    } catch(PDOException $e) { echo json_encode(['status' => 'error']); }
}
?>