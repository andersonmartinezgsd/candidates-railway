<?php
// logic/ai_functions.php

// Solo incluimos la configuración si la clase Database no está cargada aún
if (!class_exists('Database')) {
    require_once __DIR__ . '/../config/Database.php';
}

// 1. OBTENER EMOCIONES
function getEmotionSummary($db, $candidateId) {
    try {
        $stmt = $db->prepare("SELECT emotion, COUNT(*) as cnt FROM gsd_candidate_video_logs WHERE candidate_id = ? GROUP BY emotion ORDER BY cnt DESC LIMIT 3");
        $stmt->execute([$candidateId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if(empty($rows)) return "Sin datos de video.";
        $s = []; foreach($rows as $r) $s[] = $r['emotion'];
        return implode(", ", $s);
    } catch(Exception $e) { return "N/A"; }
}

// 2. FUNCIÓN MAESTRA GEMINI (JSON PURO)
function getHolisticAnalysis($transcript, $jobData, $emotions) {
    if(empty($transcript) || strlen($transcript) < 10) return null;

    // Usamos la constante GEMINI_API_KEY que viene de Database.php
    if (!defined('GEMINI_API_KEY')) {
        return ['error' => 'API Key no configurada en Database.php'];
    }

    $modelsToTry = ['gemini-2.0-flash', 'gemini-flash-latest', 'gemini-1.5-flash'];
    
    $instruction = !empty($jobData['custom_prompt']) ? $jobData['custom_prompt'] : "Actúa como experto en selección.";
    
    $prompt = "
    SYSTEM: $instruction
    TASK: Analyze candidate vs '{$jobData['job_title']}'.
    INPUT: Transcript: \"$transcript\". Emotions: \"$emotions\". Job: \"{$jobData['job_desc']}\"
    
    OUTPUT JSON (NO MARKDOWN):
    {
        \"match_score\": (Integer 0-100),
        \"match_reasoning\": (String short),
        \"ai_analysis\": (HTML String using <h4>, <ul>, <li>, <p>. NO markdown.)
    }";

    $data = [ "contents" => [[ "parts" => [[ "text" => $prompt ]] ]], "generationConfig" => [ "responseMimeType" => "application/json" ] ];

    foreach ($modelsToTry as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/$model:generateContent?key=" . GEMINI_API_KEY;
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $json = json_decode($response, true);
            if (isset($json['candidates'][0]['content']['parts'][0]['text'])) {
                $decoded = json_decode($json['candidates'][0]['content']['parts'][0]['text'], true);
                if (json_last_error() === JSON_ERROR_NONE && isset($decoded['match_score'])) return $decoded;
            }
        }
    }
    return ['error' => 'Fallo IA'];
}
?>