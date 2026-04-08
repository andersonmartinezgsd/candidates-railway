<?php
/**
 * GSD — Video & Files Upload Endpoint
 * ════════════════════════════════════════════════════════════════
 * Receives: video_original, cv_file, id_file, photo_file,
 *           analysis_json, token + all form fields
 * Saves to: /uploads/{TOKEN}/
 *   ├── originals/video_original.webm
 *   ├── documents/cv.pdf|docx
 *   ├── documents/id.jpg|pdf
 *   ├── documents/photo.jpg
 *   └── analysis/video_analysis.json
 * Updates DB: gsd_candidates
 * ════════════════════════════════════════a════════════════════════
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

// Increase limits for video upload
ini_set('upload_max_filesize', '150M');
ini_set('post_max_size',        '160M');
ini_set('max_execution_time',   '120');
ini_set('memory_limit',         '256M');

function out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function sanitizeToken(string $t): string {
    $t = trim($t);
    return preg_match('/^GSD-[A-Z0-9]{4,8}-[A-Z0-9]{4,12}$/', $t) ? $t : '';
}

function safeFilename(string $name): string {
    return preg_replace('/[^a-zA-Z0-9._\-]/', '_', basename($name));
}

function nullIfBlank(mixed $value): mixed {
    if (! is_string($value)) {
        return $value;
    }

    $trimmed = trim($value);

    return $trimmed === '' ? null : $trimmed;
}

function normalizeYesNo(mixed $value): int {
    $normalized = strtolower(trim((string) $value));

    return in_array($normalized, ['yes', 'y', '1', 'true'], true) ? 1 : 0;
}

function splitCandidateName(?string $name): array {
    $name = trim((string) $name);

    if ($name === '') {
        return [null, null];
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $first = array_shift($parts);
    $last = $parts !== [] ? implode(' ', $parts) : null;

    return [$first ?: null, $last ?: null];
}

function parseSalary(?string $value): ?float {
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $numeric = preg_replace('/[^\d.,]/', '', $value);
    if ($numeric === null || $numeric === '') {
        return null;
    }

    if (str_contains($numeric, ',') && str_contains($numeric, '.')) {
        $numeric = str_replace(',', '', $numeric);
    } elseif (str_contains($numeric, ',')) {
        $numeric = str_replace(',', '.', $numeric);
    }

    return is_numeric($numeric) ? (float) $numeric : null;
}

function normalizePhone(?string $code, ?string $number): ?string {
    $code = trim((string) $code);
    $number = trim((string) $number);

    if ($code === '' && $number === '') {
        return null;
    }

    return trim($code.' '.$number);
}

function buildAnswersPayload(array $post): array {
    $rawAnswers = !empty($post['answers_all']) ? (json_decode($post['answers_all'], true) ?: []) : [];
    $skills = is_array($rawAnswers['skills'] ?? null) ? $rawAnswers['skills'] : [];
    $personality = is_array($rawAnswers['personality'] ?? null) ? $rawAnswers['personality'] : [];

    $groups = [
        'VPA' => [],
        'HVA' => [],
        'HOP' => [],
        'MVA' => [],
        'HRO' => [],
        'MGR' => [],
        'ACM' => [],
        'SDR' => [],
        'personality' => [],
    ];

    foreach ($skills as $key => $value) {
        if (! is_string($value) || trim($value) === '') {
            continue;
        }

        if (preg_match('/^(vpa|hva|hop|mva|hro|mgr|acm|sdr)_q(.+)$/i', (string) $key, $matches)) {
            $group = strtoupper($matches[1]);
            $groups[$group]['Q'.$matches[2]] = trim($value);
        }
    }

    foreach ($personality as $key => $value) {
        if (is_string($value) && trim($value) !== '') {
            $groups['personality'][strtoupper(str_replace('-', ' ', (string) $key))] = trim($value);
        }
    }

    $groups['_form'] = $post;

    return array_filter($groups, static fn (mixed $value, string $key): bool => $key === '_form' || (is_array($value) && $value !== []), ARRAY_FILTER_USE_BOTH);
}

function jsonOrNull(array $payload): ?string {
    if ($payload === []) {
        return null;
    }

    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function hydrateAnalysisPayload(array $analysis, array $post): array {
    $transcript = trim((string) ($post['transcript'] ?? ''));
    if ($transcript !== '' && trim((string) ($analysis['transcript'] ?? '')) === '') {
        $analysis['transcript'] = $transcript;
    }

    $language = trim((string) ($post['language'] ?? $post['spoken_language'] ?? ''));
    if ($language !== '' && trim((string) ($analysis['language'] ?? '')) === '') {
        $analysis['language'] = $language;
    }

    $languageLabel = trim((string) ($post['language_label'] ?? ''));
    if ($languageLabel !== '' && trim((string) ($analysis['language_label'] ?? '')) === '') {
        $analysis['language_label'] = $languageLabel;
    }

    $sentimentScore = $post['sentiment_score'] ?? null;
    if ($sentimentScore !== null && $sentimentScore !== '' && ! isset($analysis['sentiment']['score'])) {
        $score = (float) $sentimentScore;
        $analysis['sentiment'] ??= [];
        $analysis['sentiment']['score'] = $score;
        $analysis['sentiment']['label'] ??= $score >= 65 ? 'Positive' : ($score >= 40 ? 'Neutral' : 'Needs Work');
    }

    $dominantEmotion = trim((string) ($post['dominant_emotion'] ?? ''));
    if ($dominantEmotion !== '' && trim((string) ($analysis['facial_analysis']['dominant'] ?? '')) === '') {
        $analysis['facial_analysis'] ??= [];
        $analysis['facial_analysis']['dominant'] = $dominantEmotion;
    }

    return $analysis;
}

try {
    /* ─── Token ─── */
    $token = sanitizeToken($_POST['token'] ?? '');
    if (!$token) {
        // Generate a new one if none provided
        $token = 'GSD-' . strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(base_convert(time(), 10, 36));
    }

    /* ─── Base upload directory ─── */
    // Adjust this path to match your server structure
    $baseDir   = __DIR__ . '/uploads/' . $token . '/';
    $originDir = $baseDir . 'originals/';
    $docDir    = $baseDir . 'documents/';
    $anaDir    = $baseDir . 'analysis/';

    foreach ([$originDir, $docDir, $anaDir] as $dir) {
        if (!file_exists($dir)) mkdir($dir, 0755, true);
    }

    $savedFiles = [];
    $errors     = [];

    /* ═══════════════════════════════════════
       1. VIDEO ORIGINAL
    ═══════════════════════════════════════ */
    if (isset($_FILES['video_original']) && $_FILES['video_original']['error'] === UPLOAD_ERR_OK) {
        $vFile = $_FILES['video_original'];
        $ext   = strtolower(pathinfo($vFile['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['webm', 'mp4', 'ogg'])) $ext = 'webm';

        $ts       = date('Ymd_His');
        $vName    = "video_original_{$ts}.{$ext}";
        $vPath    = $originDir . $vName;
        $vDbPath  = "uploads/{$token}/originals/{$vName}";

        if (move_uploaded_file($vFile['tmp_name'], $vPath)) {
            $savedFiles['video_original_path'] = $vDbPath;
        } else {
            $errors[] = 'Failed to save video_original';
        }
    }

    /* ═══════════════════════════════════════
       2. CV FILE
    ═══════════════════════════════════════ */
    if (isset($_FILES['cv_file']) && $_FILES['cv_file']['error'] === UPLOAD_ERR_OK) {
        $f    = $_FILES['cv_file'];
        $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'docx', 'doc'];
        if (!in_array($ext, $allowed)) {
            $errors[] = 'CV: invalid extension';
        } else {
            $name = "cv_{$token}.{$ext}";
            if (move_uploaded_file($f['tmp_name'], $docDir . $name)) {
                $savedFiles['cv_file_path'] = "uploads/{$token}/documents/{$name}";
            }
        }
    }

    /* ═══════════════════════════════════════
       3. ID FILE
    ═══════════════════════════════════════ */
    if (isset($_FILES['id_file']) && $_FILES['id_file']['error'] === UPLOAD_ERR_OK) {
        $f    = $_FILES['id_file'];
        $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        if (in_array($ext, $allowed)) {
            $name = "id_{$token}.{$ext}";
            if (move_uploaded_file($f['tmp_name'], $docDir . $name)) {
                $savedFiles['id_file_path'] = "uploads/{$token}/documents/{$name}";
            }
        }
    }

    /* ═══════════════════════════════════════
       4. PHOTO FILE
    ═══════════════════════════════════════ */
    if (isset($_FILES['photo_file']) && $_FILES['photo_file']['error'] === UPLOAD_ERR_OK) {
        $f    = $_FILES['photo_file'];
        $ext  = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (in_array($ext, $allowed)) {
            $name = "photo_{$token}.{$ext}";
            if (move_uploaded_file($f['tmp_name'], $docDir . $name)) {
                $savedFiles['photo_file_path'] = "uploads/{$token}/documents/{$name}";
            }
        }
    }

    /* ═══════════════════════════════════════
       5. ANALYSIS JSON
    ═══════════════════════════════════════ */
    $analysisData = [];
    if (isset($_FILES['analysis_json']) && $_FILES['analysis_json']['error'] === UPLOAD_ERR_OK) {
        $jsonContent = file_get_contents($_FILES['analysis_json']['tmp_name']);
        $analysisData = json_decode($jsonContent, true) ?? [];
        $jsonName = "video_analysis_{$token}.json";
        file_put_contents($anaDir . $jsonName, $jsonContent);
        $savedFiles['analysis_json_path'] = "uploads/{$token}/analysis/{$jsonName}";
    } elseif (!empty($_POST['video_analysis'])) {
        // Also accept as POST field
        $jsonContent  = $_POST['video_analysis'];
        $analysisData = json_decode($jsonContent, true) ?? [];
        $jsonName     = "video_analysis_{$token}.json";
        file_put_contents($anaDir . $jsonName, $jsonContent);
        $savedFiles['analysis_json_path'] = "uploads/{$token}/analysis/{$jsonName}";
    }

    $analysisData = hydrateAnalysisPayload($analysisData, $_POST);
    if ($analysisData !== [] && isset($savedFiles['analysis_json_path'])) {
        $analysisDiskPath = $baseDir . ltrim(str_replace("uploads/{$token}/", '', (string) $savedFiles['analysis_json_path']), '/');
        if (is_string($analysisDiskPath) && $analysisDiskPath !== '') {
            @file_put_contents($analysisDiskPath, json_encode($analysisData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }
    }

    /* ═══════════════════════════════════════
       6. SAVE TO DATABASE
    ═══════════════════════════════════════ */
    $dbResult = saveToDatabase($token, $_POST, $savedFiles, $analysisData);

    /* ─── Response ─── */
    $scheme = isset($_SERVER['HTTPS']) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    // Detect subdirectory (e.g., /prb-recruitment)
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    $scriptPath = $scriptPath === '/' ? '' : $scriptPath;
    $baseUrl = $scheme . '://' . $host . $scriptPath;
    
    out([
        'status'       => 'ok',
        'token'        => $token,
        'saved_files'  => $savedFiles,
        'db'           => $dbResult,
        'ai'           => $dbResult['ai'] ?? null,
        'errors'       => $errors,
        'video_url'    => isset($savedFiles['video_original_path'])
                            ? $baseUrl . '/' . $savedFiles['video_original_path']
                            : null,
        'cv_url'       => isset($savedFiles['cv_file_path'])
                            ? $baseUrl . '/' . $savedFiles['cv_file_path']
                            : null,
        'candidate_url'=> $baseUrl . "/views/new-candidate.php?token={$token}",
    ]);

} catch (Throwable $e) {
    out([
        'status'  => 'error',
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()) . ':' . $e->getLine()
    ], 500);
}

/* ════════════════════════════════════════════════════════════════
   DATABASE — Saves to your custom table
   IMPORTANT: Adjust table name & columns to match your DB schema
════════════════════════════════════════════════════════════════ */
function saveToDatabase(string $token, array $post, array $files, array $analysis): array {
    try {
        // Load DB connection — adjust path if needed
        $dbFile = null;
        foreach ([
            __DIR__ . '/db.php',
            __DIR__ . '/../db.php',
            __DIR__ . '/config/Database.php',
            __DIR__ . '/../config/Database.php',
        ] as $p) {
            if (file_exists($p)) { $dbFile = $p; break; }
        }

        if (!$dbFile) return ['status' => 'skipped', 'reason' => 'db.php not found'];
        require_once $dbFile;

        // Get PDO connection — supports both db.php (getDB()) and Database class
        $pdo = null;
        if (function_exists('getDB')) {
            $pdo = getDB();
        } elseif (class_exists('Database')) {
            $db  = new Database();
            $pdo = $db->getConnection();
        }
        if (!$pdo) return ['status' => 'error', 'reason' => 'No PDO connection'];

        /* ── Collect all candidate fields ── */
        $name     = trim($post['f-name']     ?? $post['name']      ?? '');
        $email    = trim($post['f-email']    ?? $post['email']     ?? '');
        $phone    = trim($post['f-phone']    ?? $post['phone']     ?? '');
        $whatsapp = trim($post['f-whatsapp-code'] ?? '') . ' ' . trim($post['f-whatsapp'] ?? '');
        $country  = trim($post['f-country']  ?? '');
        $city     = trim($post['f-city']     ?? '');
        $address  = trim($post['f-address']  ?? '');
        $postal   = trim($post['f-postal']   ?? '');
        $linkedin = trim($post['f-linkedin'] ?? '');
        $salary   = trim($post['f-salary']   ?? '');
        $avail    = trim($post['f-avail']    ?? '');
        $summary  = trim($post['f-sum']      ?? '');
        $skills   = trim($post['f-skills']   ?? '');
        $position = trim($post['f-position']  ?? '');
        $referral = trim($post['f-referral'] ?? '');
        
        // Education
        $eduLevel  = trim($post['f-edu-level']  ?? '');
        $degree1   = trim($post['f-deg1']       ?? '');
        $inst1     = trim($post['f-ins1']       ?? '');
        $years1    = trim($post['f-yr1']        ?? '');
        $degree2   = trim($post['f-deg2']       ?? '');
        $inst2     = trim($post['f-ins2']       ?? '');
        $certifications = trim($post['f-certs']  ?? '');
        
        // Experience
        $expYrs   = trim($post['f-exp-yrs']   ?? '');
        $company1  = trim($post['f-co1']       ?? '');
        $title1    = trim($post['f-jt1']       ?? '');
        $resp1     = trim($post['f-resp1']     ?? '');
        $company2  = trim($post['f-co2']       ?? '');
        $title2    = trim($post['f-jt2']       ?? '');
        $resp2     = trim($post['f-resp2']     ?? '');
        
        // Languages & certifications
        $languages = trim($post['f-lang']     ?? '');
        
        // English scores
        $engRead   = trim($post['radio_eng-reading']   ?? $post['eng-reading'] ?? '');
        $engListen = trim($post['radio_eng-listening']  ?? $post['eng-listening'] ?? '');
        
        // Healthcare background
        $eduHc    = trim($post['radio_r-edu-hc'] ?? $post['r-edu-hc'] ?? $post['f-edu-hc'] ?? '');
        $workHc   = trim($post['radio_r-work-hc'] ?? $post['r-work-hc'] ?? $post['f-worked-hc'] ?? '');
        $workVa   = trim($post['radio_r-va'] ?? $post['r-va'] ?? $post['f-worked-va'] ?? '');

        // Certifications - note: table doesn't have this column, but we collect it
        // $certifications = trim($post['f-certs'] ?? ''); // Uncomment when column is added

        // Full address
        $fullAddress = $address . ($postal ? ', ' . $postal : '') . ', ' . $city . ', ' . $country;

        // All questionnaire answers in single field
        $answersAll = !empty($post['answers_all']) ? json_decode($post['answers_all'], true) : [];
        
        // Debug: log what's being received
        error_log('[upload-video] Token: ' . $token);
        error_log('[upload-video] Name: ' . $name . ', Email: ' . $email);
        error_log('[upload-video] Position: ' . $position);
        error_log('[upload-video] Received answers_all: ' . ($post['answers_all'] ?? 'EMPTY'));
        error_log('[upload-video] Parsed answersAll: ' . json_encode($answersAll));
        error_log('[upload-all POST keys]: ' . implode(', ', array_keys($post)));

        $videoPath  = $files['video_original_path'] ?? null;
        $cvPath     = $files['cv_file_path']         ?? null;
        $idPath     = $files['id_file_path']         ?? null;
        $photoPath  = $files['photo_file_path']      ?? null;
        $analysisPath = $files['analysis_json_path'] ?? null;

        $sentimentScore = isset($analysis['combined_score'])
            ? (float) $analysis['combined_score']
            : (isset($analysis['sentiment']['score']) ? (float) $analysis['sentiment']['score'] : 0.0);
        $transcript     = $analysis['transcript'] ?? nullIfBlank($post['transcript'] ?? null);
        $aiAnalysisJson = !empty($analysis) ? json_encode($analysis, JSON_UNESCAPED_UNICODE) : null;
        $dominantEmo    = $analysis['facial_analysis']['dominant'] ?? null;
        $videoLang      = $analysis['language'] ?? nullIfBlank($post['language'] ?? $post['spoken_language'] ?? null);

        [$firstName, $lastName] = splitCandidateName($name);
        $cvTextRaw = trim((string) ($post['cv_text_raw'] ?? ''));
        $cvExtractedJson = json_decode((string) ($post['cv_extracted_json'] ?? ''), true);
        $answersAll = buildAnswersPayload($post);
        $skillsJson = array_values(array_filter(array_map('trim', explode(',', $skills))));
        $educationJson = array_filter([
            'education_level' => $eduLevel,
            'main_degree' => $degree1,
            'main_institution' => $inst1,
            'main_years' => $years1,
            'other_degree' => $degree2,
            'other_institution' => $inst2,
            'certifications' => $certifications,
        ], static fn (mixed $value): bool => $value !== '');
        $experienceJson = array_filter([
            'exp_years' => $expYrs,
            'main_company' => $company1,
            'main_title' => $title1,
            'main_responsibilities' => $resp1,
            'other_company' => $company2,
            'other_title' => $title2,
            'other_responsibilities' => $resp2,
        ], static fn (mixed $value): bool => $value !== '');
        $biometricJson = is_array($analysis['facial_analysis'] ?? null) ? $analysis['facial_analysis'] : [];
        $processingStatus = 'reviewing';
        $matchScore = isset($analysis['combined_score']) ? (float) $analysis['combined_score'] : 0.0;
        $phoneValue = normalizePhone($post['f-phone-code'] ?? null, $post['f-phone'] ?? null) ?: ($phone ?: $whatsapp);
        $whatsappValue = normalizePhone($post['f-whatsapp-code'] ?? null, $post['f-whatsapp'] ?? null);
        $cvPreview = $cvTextRaw !== '' ? mb_substr($cvTextRaw, 0, 5000) : ($summary !== '' ? $summary : null);
        $positionInterest = $position !== '' ? $position : null;
        $professionalTitle = $title1 !== '' ? $title1 : $positionInterest;
        $aiAnalysisText = $aiAnalysisJson ?: ($summary !== '' ? $summary : null);

        $check = $pdo->prepare("SELECT id FROM gsd_candidates WHERE token = ? LIMIT 1");
        $check->execute([$token]);
        $exists = $check->fetchColumn();

        if ($exists) {
            $sql = "UPDATE gsd_candidates SET
                type='candidate',
                first_name=:first_name, last_name=:last_name, name=:name,
                professional_title=:professional_title, position_interest=:position_interest,
                email=:email, linked_in_url=:linked_in_url, phone=:phone, whatsapp=:whatsapp,
                home_address=:home_address, city=:city, country=:country, postal_code=:postal_code,
                referrer=:referrer,
                video_original_path=:video_original_path, photo_path=:photo_path, id_card_path=:id_card_path,
                processing_status=:processing_status,
                transcript=:transcript, match_score=:match_score, ai_analysis=:ai_analysis,
                sentiment_score=:sentiment_score, dominant_emotion=:dominant_emotion,
                spoken_language=:spoken_language,
                cv_filename=:cv_filename, cv_text=:cv_text, cv_text_preview=:cv_text_preview,
                biometric_json=:biometric_json, answers_all=:answers_all,
                english_reading=:english_reading, english_listening=:english_listening,
                salary_expectation=:salary_expectation, skills_json=:skills_json,
                experience_json=:experience_json, years_total_experience=:years_total_experience,
                languages=:languages, education_json=:education_json,
                highest_education=:highest_education, current_notice_period=:current_notice_period,
                is_education_healthcare_relevant=:is_education_healthcare_relevant,
                prev_worked_healthcare=:prev_worked_healthcare, prev_worked_va=:prev_worked_va,
                is_main=1, updated_at=NOW()
                WHERE token=:token";
        } else {
            $sql = "INSERT INTO gsd_candidates
                (token, type, first_name, last_name, name, professional_title, position_interest,
                 email, linked_in_url, phone, whatsapp, home_address, city, country, postal_code,
                 referrer, video_original_path, photo_path, id_card_path, processing_status,
                 transcript, match_score, ai_analysis, sentiment_score, dominant_emotion, spoken_language,
                 cv_filename, cv_text, cv_text_preview, biometric_json, answers_all,
                 english_reading, english_listening, salary_expectation, skills_json,
                 experience_json, years_total_experience, languages, education_json,
                 highest_education, current_notice_period, is_education_healthcare_relevant,
                 prev_worked_healthcare, prev_worked_va, is_main, created_at, updated_at)
                VALUES
                (:token, 'candidate', :first_name, :last_name, :name, :professional_title, :position_interest,
                 :email, :linked_in_url, :phone, :whatsapp, :home_address, :city, :country, :postal_code,
                 :referrer, :video_original_path, :photo_path, :id_card_path, :processing_status,
                 :transcript, :match_score, :ai_analysis, :sentiment_score, :dominant_emotion, :spoken_language,
                 :cv_filename, :cv_text, :cv_text_preview, :biometric_json, :answers_all,
                 :english_reading, :english_listening, :salary_expectation, :skills_json,
                 :experience_json, :years_total_experience, :languages, :education_json,
                 :highest_education, :current_notice_period, :is_education_healthcare_relevant,
                 :prev_worked_healthcare, :prev_worked_va, 1, NOW(), NOW())";
        }

        $stmt = $pdo->prepare($sql);
        try {
            $stmt->execute([
                ':token'             => $token,
                ':first_name'        => $firstName,
                ':last_name'         => $lastName,
                ':name'              => $name,
                ':professional_title' => $professionalTitle,
                ':position_interest' => $positionInterest,
                ':email'             => $email,
                ':linked_in_url'     => $linkedin,
                ':phone'             => $phoneValue,
                ':whatsapp'          => $whatsappValue,
                ':home_address'      => $address !== '' ? $address : null,
                ':city'              => $city !== '' ? $city : null,
                ':country'           => $country !== '' ? $country : null,
                ':postal_code'       => $postal !== '' ? $postal : null,
                ':referrer'          => $referral !== '' ? $referral : null,
                ':video_original_path' => $videoPath,
                ':photo_path'        => $photoPath,
                ':id_card_path'      => $idPath,
                ':processing_status' => $processingStatus,
                ':transcript'        => $transcript,
                ':match_score'       => $matchScore,
                ':ai_analysis'       => $aiAnalysisText,
                ':sentiment_score'   => $sentimentScore,
                ':dominant_emotion'  => $dominantEmo,
                ':spoken_language'   => $videoLang,
                ':cv_filename'       => $cvPath,
                ':cv_text'           => $cvTextRaw !== '' ? $cvTextRaw : null,
                ':cv_text_preview'   => $cvPreview,
                ':biometric_json'    => jsonOrNull($biometricJson),
                ':answers_all'       => jsonOrNull($answersAll),
                ':english_reading'   => $engRead !== '' ? $engRead : null,
                ':english_listening' => $engListen !== '' ? $engListen : null,
                ':salary_expectation' => parseSalary($salary),
                ':skills_json'       => jsonOrNull($skillsJson),
                ':experience_json'   => jsonOrNull($experienceJson),
                ':years_total_experience' => $expYrs !== '' ? $expYrs : null,
                ':languages'         => $languages,
                ':education_json'    => jsonOrNull($educationJson),
                ':highest_education' => $degree1 !== '' ? $degree1 : ($eduLevel !== '' ? $eduLevel : null),
                ':current_notice_period' => $avail !== '' ? $avail : null,
                ':is_education_healthcare_relevant' => normalizeYesNo($eduHc),
                ':prev_worked_healthcare' => normalizeYesNo($workHc),
                ':prev_worked_va'    => normalizeYesNo($workVa),
            ]);
            $candidateIdStmt = $pdo->prepare('SELECT * FROM gsd_candidates WHERE token = ? LIMIT 1');
            $candidateIdStmt->execute([$token]);
            $candidateRow = $candidateIdStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            $aiResult = null;
            if (is_array($candidateRow)) {
                $aiPipelineFile = __DIR__.'/../logic/candidate_ai_pipeline.php';
                if (file_exists($aiPipelineFile)) {
                    require_once $aiPipelineFile;
                    if (function_exists('gsdCandidateAiAnalyzeAndPersist')) {
                        $aiResult = gsdCandidateAiAnalyzeAndPersist($pdo, $candidateRow, $analysis);
                    }
                }
            }

            return [
                'status' => 'ok',
                'rows' => $stmt->rowCount(),
                'action' => $exists ? 'updated' : 'inserted',
                'candidate_id' => $candidateRow['id'] ?? null,
                'ai' => $aiResult,
            ];
        } catch (PDOException $e) {
            error_log('[upload-video] Execute error: ' . $e->getMessage());
            return ['status' => 'error', 'message' => 'Execute failed: ' . $e->getMessage()];
        }

    } catch (Throwable $e) {
        error_log('[upload-video] DB error: ' . $e->getMessage());
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}
