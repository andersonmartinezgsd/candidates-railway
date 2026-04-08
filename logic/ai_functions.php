<?php
// logic/ai_functions.php

if (!class_exists('Database')) {
    // Intenta cargar solo si no existe
    $path = __DIR__ . '/../config/Database.php';
    if(file_exists($path)) require_once $path;
}

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', 'AIzaSyCX3uYgtoD6UvgbxamLFYgt-YqWwPDCsMI'); 
}

// 1. OBTENER EMOCIONES
if (!function_exists('getEmotionSummary')) {
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
}

// 2. FUNCIÓN MAESTRA GEMINI
if (!function_exists('getHolisticAnalysis')) {
    function getHolisticAnalysis($transcript, $jobData, $emotions, $cvText = "") {
        if(empty($transcript) || strlen($transcript) < 10) return null;

        $modelsToTry = ['gemini-2.0-flash', 'gemini-flash-latest'];
        $instruction = !empty($jobData['custom_prompt']) ? $jobData['custom_prompt'] : "Actúa como experto en selección.";

        // Prompt JSON Estricto
        $prompt = "
        SYSTEM: $instruction
        TASK: Analyze candidate vs '{$jobData['job_title']}'.
        
        INPUT:
        - Transcript: \"$transcript\"
        - CV Content: \"$cvText\"
        - Emotions: \"$emotions\"
        - Job Desc: \"{$jobData['job_desc']}\"

        OUTPUT JSON:
        {
            \"match_score\": (Integer 0-100),
            \"match_reasoning\": (String max 20 words),
            \"ai_analysis\": (HTML String using <h4>, <ul>, <li>, <p>. No markdown.)
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
        return ['error' => 'Fallo Conexión IA'];
    }
}

/**
 * Realiza un análisis estadístico para estimar el nivel de inglés de un texto.
 *
 * @param string $text El texto a analizar (CV, transcripción, etc.).
 * @return array Un array con 'english_level' y 'english_score'.
 */
function getEnglishLevelAnalysis($text) {
    if (empty(trim($text))) {
        return ['english_level' => 'N/A', 'english_score' => 0];
    }

    // 1. Estadísticas básicas del texto
    $word_count = str_word_count($text);
    if ($word_count < 50) { // No analizar si el texto es muy corto
        return ['english_level' => 'Not enough text', 'english_score' => 0];
    }
    
    $sentence_count = count(preg_split('/[.?!]+/', $text, -1, PREG_SPLIT_NO_EMPTY));
    $sentence_count = ($sentence_count == 0) ? 1 : $sentence_count;
    $syllable_count = count_syllables_in_text($text);

    // --- CÁLCULO DE MÉTRICAS ---

    // MÉTRICA 1: Complejidad (Flesch Reading Ease, normalizado de 0 a 100 donde más alto es mejor)
    // Fórmula: 206.835 - 1.015 * (total_words / total_sentences) - 84.6 * (total_syllables / total_words)
    $flesch_score = 206.835 - 1.015 * ($word_count / $sentence_count) - 84.6 * ($syllable_count / $word_count);
    $complexity_score = max(0, min(100, $flesch_score)); // Lo limitamos a 0-100
    // Invertimos el puntaje para que la complejidad dé un puntaje más alto. Un texto universitario tiene un Flesch de ~30.
    // (100 - 30) = 70. Lo normalizamos.
    $normalized_complexity = max(0, (100 - $complexity_score) * 1.4);


    // MÉTRICA 2: Riqueza de Vocabulario (Type-Token Ratio, normalizado de 0 a 100)
    $words = str_word_count(strtolower($text), 1);
    $unique_words = count(array_unique($words));
    $ttr = ($unique_words / $word_count) * 100; // TTR es sensible a la longitud del texto, pero es un buen indicador.
    $vocabulary_score = min(100, $ttr * 2); // Multiplicamos para escalar el valor.

    // MÉTRICA 3: Penalización por Palabras de Relleno (Filler Words)
    $filler_words = ['like', 'you know', 'actually', 'basically', 'i mean', 'so', 'well', 'um', 'uh', 'er'];
    $filler_count = 0;
    foreach ($filler_words as $filler) {
        $filler_count += substr_count(strtolower($text), " " . $filler . " ");
    }
    $errors_per_100_words = ($filler_count / $word_count) * 100;
    $fluency_score = max(0, 100 - ($errors_per_100_words * 5)); // Penalizamos 5 puntos por cada filler por 100 palabras.

    // --- CÁLCULO FINAL ---
    // Damos pesos a cada métrica para el puntaje final
    $final_score = ($normalized_complexity * 0.35) + ($vocabulary_score * 0.45) + ($fluency_score * 0.20);
    $final_score = round(max(0, min(100, $final_score)));

    // Mapeo del puntaje a niveles CEFR (Marco Común Europeo de Referencia)
    $level = "A1 - Basic";
    if ($final_score > 90) $level = "C2 - Mastery";
    elseif ($final_score > 80) $level = "C1 - Advanced";
    elseif ($final_score > 70) $level = "B2 - Upper-Intermediate";
    elseif ($final_score > 60) $level = "B1 - Intermediate";
    elseif ($final_score > 40) $level = "A2 - Elementary";

    return [
        'english_level' => $level,
        'english_score' => $final_score
    ];
}


/**
 * Función auxiliar para contar sílabas en un texto (heurística).
 * Es una aproximación, pero suficiente para el análisis estadístico.
 */
function count_syllables_in_text($text) {
    $total_syllables = 0;
    $words = str_word_count(strtolower($text), 1);
    foreach ($words as $word) {
        // Regla básica: contar grupos de vocales
        $syllable_count = preg_match_all('/[aeiouy]+/', $word, $matches);
        
        // Ajustes comunes
        if (substr($word, -1) === 'e' && $syllable_count > 1 && !preg_match('/[aeiouy]le$/', $word)) {
            $syllable_count--;
        }
        $total_syllables += max(1, $syllable_count); // Cada palabra tiene al menos una sílaba
    }
    return $total_syllables;
}
?>