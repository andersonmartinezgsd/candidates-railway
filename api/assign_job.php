<?php
// api/assign_job.php

// 1. INICIAR BUFFER (Captura cualquier texto basura previo)
ob_start();

set_time_limit(120); 
ini_set('display_errors', 0); 
error_reporting(E_ALL);

$response = [];

try {
    // 2. Validar Rutas
    $dbFile = __DIR__ . '/../config/Database.php';
    $aiFile = __DIR__ . '/../logic/ai_functions.php';

    if (!file_exists($dbFile)) throw new Exception("Falta Database.php");
    if (!file_exists($aiFile)) throw new Exception("Falta ai_functions.php");

    require_once $dbFile;
    require_once $aiFile;

    // 3. Recibir Datos
    $input = file_get_contents("php://input");
    $data = json_decode($input, true);

    if(!$data || !isset($data['candidate_id']) || !isset($data['job_id'])) {
        throw new Exception("Datos incompletos.");
    }

    $cid = $data['candidate_id'];
    $jid = $data['job_id'];
    $db = (new Database())->getConnection();

    // 4. Actualizar Cargo
    $stmt = $db->prepare("UPDATE gsd_candidates SET job_id = ? WHERE id = ?");
    $stmt->execute([$jid, $cid]);

    // 5. Obtener Datos
    $sql = "SELECT c.transcript, j.title as job_title, j.description as job_desc, j.custom_prompt 
            FROM gsd_candidates c 
            JOIN gsd_jobs j ON j.id = ? 
            WHERE c.id = ?";
    $q = $db->prepare($sql);
    $q->execute([$jid, $cid]);
    $info = $q->fetch(PDO::FETCH_ASSOC);

    $responseData = [
        'match_score' => 0, 
        'match_reasoning' => 'No transcript available.', 
        'ai_analysis' => '<p class="text-gray-400">No transcript found.</p>'
    ];

    // 6. Ejecutar IA
    if ($info && !empty($info['transcript'])) {
        $emotions = getEmotionSummary($db, $cid);
        
        $jobData = [
            'job_title' => $info['job_title'], 
            'job_desc' => $info['job_desc'], 
            'custom_prompt' => $info['custom_prompt']
        ];
        
        // Llamada a Gemini
        $result = getHolisticAnalysis($info['transcript'], $jobData, $emotions);

        if (isset($result['error'])) {
            throw new Exception("Gemini Error: " . $result['error']);
        }

        if (isset($result['match_score'])) {
                // --- ACTUALIZACIÓN: GUARDAR INGLÉS ---
                $engLvl = isset($result['english_level']) ? $result['english_level'] : 'N/A';
                $engScr = isset($result['english_score']) ? $result['english_score'] : 0;

                $upd = $db->prepare("UPDATE gsd_candidates SET match_score=?, ai_analysis=?, match_reasoning=?, english_level=?, english_score=? WHERE id=?");
                $upd->execute([
                    $result['match_score'], 
                    $result['ai_analysis'], 
                    $result['match_reasoning'],
                    $engLvl, // Nuevo
                    $engScr, // Nuevo
                    $cid
                ]);
                
                // Agregamos los datos a la respuesta JSON para el JS
                $result['english_level'] = $engLvl;
                $result['english_score'] = $engScr;
                
                $responseData = $result;
            }
    }

    $response = ['status' => 'success', 'data' => $responseData];

} catch (Exception $e) {
    $response = ['status' => 'error', 'message' => $e->getMessage()];
}

// 7. LIMPIEZA FINAL (CRÍTICO)
// Esto borra el "Epa que pena" o cualquier error PHP que haya ocurrido antes
ob_end_clean(); 

// 8. Enviar JSON puro
header('Content-Type: application/json');
echo json_encode($response);
exit;
?>