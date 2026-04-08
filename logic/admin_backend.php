<?php
// logic/admin_backend.php

// 1. Configuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/ai_functions.php'; 

$db = (new Database())->getConnection();

// 2. Obtener Cargos
$jobs = [];
try {
    $jobs = $db->query("SELECT * FROM gsd_jobs")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// 3. Obtener Candidatos
$sql = "SELECT 
            c.id, 
            c.name, 
            c.email, 
            c.video_processed_path as video_filename,    /* <--- CRÍTICO: Asegúrate que esta columna exista en tu DB */
            c.transcript,        /* <--- CRÍTICO: El texto de la entrevista */
            c.job_id, 
            c.match_score, 
            c.ai_analysis, 
            c.match_reasoning,
            c.sentiment_score,
            c.manual_score,
            c.english_level,
            c.english_score,
            c.cv_filename,
            c.cv_text,
            c.biometric_json,
            j.title as job_title, 
            j.description as job_desc, 
            j.required_skills, 
            j.custom_prompt 
        FROM gsd_candidates c 
        LEFT JOIN gsd_jobs j ON c.job_id = j.id 
        ORDER BY c.id DESC";

$candidates = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Debug rápido (opcional): Si algún video viene vacío, le ponemos un placeholder para probar
foreach ($candidates as &$c) {
    // Si la ruta no empieza con 'uploads/', se la agregamos (Ajusta esto según tu carpeta real)
    if (!empty($c['video_filename']) && strpos($c['video_filename'], 'uploads/') === false) {
        $c['video_filename'] = 'uploads/' . $c['video_filename'];
    }
}
unset($c);
$candidates = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// 4. Procesamiento Inicial
foreach ($candidates as &$c) {
    // Inicializar valores
    $c['match_score'] = $c['match_score'] ?? 0;
    $c['ai_analysis'] = $c['ai_analysis'] ?? "";
    $c['match_reasoning'] = $c['match_reasoning'] ?? "";
    $c['sentiment_score'] = $c['sentiment_score'] ?? 50;
    
    // Lógica IA (Si falta análisis)
    $needsAnalysis = (empty($c['ai_analysis']) || strpos($c['ai_analysis'], 'Fallo') !== false);

    if (!empty($c['job_id']) && !empty($c['transcript']) && $needsAnalysis) {
        
        $emociones = getEmotionSummary($db, $c['id']);
        $cvContent = !empty($c['cv_text']) ? $c['cv_text'] : "";

        $jobData = [
            'job_title' => $c['job_title'], 
            'job_desc' => $c['job_desc'], 
            'custom_prompt' => $c['custom_prompt']
        ];
        
        $result = getHolisticAnalysis($c['transcript'], $jobData, $emociones, $cvContent);

        if (isset($result['match_score'])) {
            $c['match_score'] = $result['match_score'];
            $c['ai_analysis'] = $result['ai_analysis'];
            $c['match_reasoning'] = $result['match_reasoning'];
            
            try {
                $upd = $db->prepare("UPDATE gsd_candidates SET match_score=?, ai_analysis=?, match_reasoning=? WHERE id=?");
                $upd->execute([$c['match_score'], $c['ai_analysis'], $c['match_reasoning'], $c['id']]);
            } catch(Exception $e) {}
        }
    }
}
unset($c);
?>