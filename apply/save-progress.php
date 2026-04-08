<?php
/**
 * GSD — Auto-Save & Resume Endpoint
 * ══════════════════════════════════════════════════════════════
 * FIXES vs original:
 *  1. sanitizeToken acepta AMBOS formatos:
 *       GSD-CANDIDATE-123456  (legacy)
 *       GSD-AB12-XXXXX        (nuevo frontend)
 *  2. new_session INSERTA el token en la DB (antes solo lo generaba
 *     y nunca lo guardaba → "Token not found" en cada save)
 *  3. load acepta token ó email
 *  4. save hace upsert (insert si no existe, update si sí)
 * ══════════════════════════════════════════════════════════════
 */
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { out(['status'=>'error','message'=>'POST required'], 405); }

require_once __DIR__ . '/db.php';

function out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Acepta los dos formatos:
 *   GSD-CANDIDATE-123456   (legacy DB)
 *   GSD-AB12-MM9M2UJ8      (generado por el frontend actual)
 */
function sanitizeToken(string $t): string {
    $t = strtoupper(trim($t));
    if (preg_match('/^GSD-CANDIDATE-\d{4,6}$/', $t))             return $t; // legacy
    if (preg_match('/^GSD-[A-Z0-9]{2,8}-[A-Z0-9]{4,16}$/', $t)) return $t; // nuevo
    return '';
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

function normalizePhone(?string $code, ?string $number): ?string {
    $code = trim((string) $code);
    $number = trim((string) $number);

    if ($code === '' && $number === '') {
        return null;
    }

    return trim($code.' '.$number);
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

function normalizeAnswersPayload(array $raw): array {
    $normalized = [
        'VPA' => [],
        'HVA' => [],
        'HOP' => [],
        'MVA' => [],
        'HRO' => [],
        'MGR' => [],
        'ACM' => [],
        'SDR' => [],
        'personality' => [],
        '_form' => $raw,
    ];

    foreach ($raw as $key => $value) {
        if (! is_string($value) || trim($value) === '') {
            continue;
        }

        if (preg_match('/^radio_sk\-(vpa|hva|hop|mva|hro|mgr|acm|sdr)\-(.+)$/i', $key, $matches)) {
            $group = strtoupper($matches[1]);
            $question = strtoupper($matches[1]).' '.strtoupper(str_replace('-', ' ', $matches[2]));
            $normalized[$group][$question] = trim($value);
            continue;
        }

        if (preg_match('/^radio_(p\-\d+|pq\-[^ ]+)$/i', $key, $matches)) {
            $normalized['personality'][strtoupper(str_replace(['radio_', '-'], ['', ' '], $matches[1]))] = trim($value);
        }
    }

    return array_filter($normalized, static fn (mixed $value, string $key): bool => $key === '_form' || (is_array($value) && $value !== []), ARRAY_FILTER_USE_BOTH);
}

function buildDraftPayload(string $token, array $data): array {
    $name = trim((string) ($data['f-name'] ?? $data['name'] ?? ''));
    [$firstName, $lastName] = splitCandidateName($name);
    $email = trim((string) ($data['f-email'] ?? $data['email'] ?? ''));
    $phone = normalizePhone($data['f-phone-code'] ?? null, $data['f-phone'] ?? ($data['phone'] ?? null));
    $whatsapp = normalizePhone($data['f-whatsapp-code'] ?? null, $data['f-whatsapp'] ?? null);
    $rawAnswers = normalizeAnswersPayload($data);
    $city = nullIfBlank($data['f-city'] ?? null);
    $country = nullIfBlank($data['f-country'] ?? null);
    $postalCode = nullIfBlank($data['f-postal'] ?? null);
    $homeAddress = nullIfBlank($data['f-address'] ?? null);

    return [
        'token' => $token,
        'type' => 'candidate',
        'first_name' => $firstName,
        'last_name' => $lastName,
        'name' => $name !== '' ? $name : 'Draft Candidate',
        'professional_title' => nullIfBlank($data['f-jt1'] ?? null),
        'position_interest' => nullIfBlank($data['f-position'] ?? null),
        'email' => $email !== '' ? $email : 'draft+'.strtolower($token).'@local.gsd',
        'linked_in_url' => nullIfBlank($data['f-linkedin'] ?? null),
        'phone' => $phone,
        'whatsapp' => $whatsapp,
        'home_address' => $homeAddress,
        'city' => $city,
        'country' => $country,
        'postal_code' => $postalCode,
        'referrer' => nullIfBlank($data['f-referral'] ?? null),
        'processing_status' => 'pending',
        'cv_text_preview' => nullIfBlank($data['f-sum'] ?? null),
        'answers_all' => json_encode($rawAnswers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'english_reading' => nullIfBlank($data['radio_eng-reading'] ?? null),
        'english_listening' => nullIfBlank($data['radio_eng-listening'] ?? null),
        'salary_expectation' => parseSalary($data['f-salary'] ?? null),
        'years_total_experience' => nullIfBlank($data['f-exp-yrs'] ?? null),
        'languages' => nullIfBlank($data['f-lang'] ?? null),
        'highest_education' => nullIfBlank($data['f-edu-level'] ?? null),
        'current_notice_period' => nullIfBlank($data['f-avail'] ?? null),
        'is_education_healthcare_relevant' => normalizeYesNo($data['radio_r-edu-hc'] ?? $data['f-edu-hc'] ?? null),
        'prev_worked_healthcare' => normalizeYesNo($data['radio_r-work-hc'] ?? $data['f-worked-hc'] ?? null),
        'prev_worked_va' => normalizeYesNo($data['radio_r-va'] ?? $data['f-worked-va'] ?? null),
        'skills_json' => json_encode(array_values(array_filter(array_map('trim', explode(',', (string) ($data['f-skills'] ?? ''))))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'education_json' => json_encode(array_filter([
            'education_level' => nullIfBlank($data['f-edu-level'] ?? null),
            'main_degree' => nullIfBlank($data['f-deg1'] ?? null),
            'main_institution' => nullIfBlank($data['f-ins1'] ?? null),
            'main_years' => nullIfBlank($data['f-yr1'] ?? null),
            'other_degree' => nullIfBlank($data['f-deg2'] ?? null),
            'other_institution' => nullIfBlank($data['f-ins2'] ?? null),
        ], static fn (mixed $value): bool => $value !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'experience_json' => json_encode(array_filter([
            'exp_years' => nullIfBlank($data['f-exp-yrs'] ?? null),
            'main_company' => nullIfBlank($data['f-co1'] ?? null),
            'main_title' => nullIfBlank($data['f-jt1'] ?? null),
            'main_responsibilities' => nullIfBlank($data['f-resp1'] ?? null),
            'other_company' => nullIfBlank($data['f-co2'] ?? null),
            'other_title' => nullIfBlank($data['f-jt2'] ?? null),
            'other_responsibilities' => nullIfBlank($data['f-resp2'] ?? null),
        ], static fn (mixed $value): bool => $value !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'is_main' => 1,
    ];
}

function ensureDraftCandidate(PDO $db, string $token): void {
    $check = $db->prepare('SELECT id FROM gsd_candidates WHERE token = ? LIMIT 1');
    $check->execute([$token]);

    if ($check->fetchColumn()) {
        return;
    }

    $insert = $db->prepare("INSERT INTO gsd_candidates (token, type, name, email, processing_status, is_main, created_at, updated_at) VALUES (?, 'candidate', 'Draft Candidate', ?, 'pending', 1, NOW(), NOW())");
    $insert->execute([$token, 'draft+'.strtolower($token).'@local.gsd']);
}

function upsertDraftCandidate(PDO $db, string $token, array $payload): void {
    ensureDraftCandidate($db, $token);

    $sets = [];
    $values = [];

    foreach ($payload as $column => $value) {
        if ($column === 'token') {
            continue;
        }

        $sets[] = "`{$column}` = ?";
        $values[] = $value;
    }

    $values[] = $token;

    $db->prepare('UPDATE gsd_candidates SET '.implode(', ', $sets).', updated_at = NOW() WHERE token = ?')
        ->execute($values);
}

function decodeJsonColumn(?string $value): array {
    if (! is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

function splitStoredPhone(?string $value): array {
    $value = trim((string) $value);

    if ($value === '') {
        return ['', ''];
    }

    if (preg_match('/^(\+\d+)\s*(.*)$/', $value, $matches)) {
        return [trim($matches[1]), trim($matches[2])];
    }

    return ['', $value];
}

function candidateToDraftResponse(array $row): array {
    $answersAll = decodeJsonColumn($row['answers_all'] ?? null);
    $raw = is_array($answersAll['_form'] ?? null) ? $answersAll['_form'] : [];
    $education = decodeJsonColumn($row['education_json'] ?? null);
    $experience = decodeJsonColumn($row['experience_json'] ?? null);
    $skills = decodeJsonColumn($row['skills_json'] ?? null);
    [$phoneCode, $phoneNumber] = splitStoredPhone($row['phone'] ?? '');
    [$whatsCode, $whatsNumber] = splitStoredPhone($row['whatsapp'] ?? '');

    $response = [];
    foreach ($raw as $key => $value) {
        if (! is_string($key)) {
            continue;
        }

        if (str_starts_with($key, 'f-') || str_starts_with($key, 'radio_')) {
            $response[$key] = $value;
        }
    }

    $fallbacks = [
        'f-name' => $row['name'] ?? '',
        'f-email' => $row['email'] ?? '',
        'f-linkedin' => $row['linked_in_url'] ?? '',
        'f-phone-code' => $phoneCode,
        'f-phone' => $phoneNumber,
        'f-whatsapp-code' => $whatsCode,
        'f-whatsapp' => $whatsNumber,
        'f-avail' => $row['current_notice_period'] ?? '',
        'f-salary' => isset($row['salary_expectation']) ? (string) $row['salary_expectation'] : '',
        'f-sum' => $row['cv_text_preview'] ?? '',
        'f-position' => $row['position_interest'] ?? '',
        'f-referral' => $row['referrer'] ?? '',
        'f-city' => $row['city'] ?? '',
        'f-country' => $row['country'] ?? '',
        'f-postal' => $row['postal_code'] ?? '',
        'f-address' => $row['home_address'] ?? '',
        'f-edu-level' => $education['education_level'] ?? ($row['highest_education'] ?? ''),
        'f-deg1' => $education['main_degree'] ?? '',
        'f-ins1' => $education['main_institution'] ?? '',
        'f-yr1' => $education['main_years'] ?? '',
        'f-deg2' => $education['other_degree'] ?? '',
        'f-ins2' => $education['other_institution'] ?? '',
        'f-certs' => $education['certifications'] ?? '',
        'f-lang' => $row['languages'] ?? '',
        'f-exp-yrs' => $experience['exp_years'] ?? ($row['years_total_experience'] ?? ''),
        'f-co1' => $experience['main_company'] ?? '',
        'f-jt1' => $experience['main_title'] ?? ($row['professional_title'] ?? ''),
        'f-resp1' => $experience['main_responsibilities'] ?? '',
        'f-co2' => $experience['other_company'] ?? '',
        'f-jt2' => $experience['other_title'] ?? '',
        'f-resp2' => $experience['other_responsibilities'] ?? '',
        'f-skills' => is_array($skills) ? implode(', ', array_filter($skills, 'is_string')) : '',
        'f-role' => $raw['f-role'] ?? '',
        'f-edu-hc' => !empty($row['is_education_healthcare_relevant']) ? 'Yes' : 'No',
        'f-worked-hc' => !empty($row['prev_worked_healthcare']) ? 'Yes' : 'No',
        'f-worked-va' => !empty($row['prev_worked_va']) ? 'Yes' : 'No',
        'radio_r-edu-hc' => !empty($row['is_education_healthcare_relevant']) ? 'Yes' : 'No',
        'radio_r-work-hc' => !empty($row['prev_worked_healthcare']) ? 'Yes' : 'No',
        'radio_r-va' => !empty($row['prev_worked_va']) ? 'Yes' : 'No',
        'radio_eng-reading' => $row['english_reading'] ?? '',
        'radio_eng-listening' => $row['english_listening'] ?? '',
    ];

    foreach ($fallbacks as $key => $value) {
        if (($response[$key] ?? '') === '' && $value !== null) {
            $response[$key] = $value;
        }
    }

    return $response;
}

try {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    switch ($action) {

        /* ══════════════════════════════════════════
           NEW SESSION
           FIX: Ahora INSERTA el token en la DB.
           Antes solo lo retornaba sin guardarlo,
           causando "Token not found" en el primer save.
        ══════════════════════════════════════════ */
        case 'new_session': {
            $token = sanitizeToken($body['token'] ?? '');
            if (!$token) {
                $token = 'GSD-' . strtoupper(bin2hex(random_bytes(3))) . '-' . strtoupper(base_convert(time(), 10, 36));
            }
            $db = getDB();
            ensureDraftCandidate($db, $token);
            out(['status' => 'ok', 'token' => $token]);
        }

        /* ══════════════════════════════════════════
           SAVE
           FIX: Upsert — si el token no existe en DB
           lo crea en lugar de retornar error 404.
        ══════════════════════════════════════════ */
        case 'save': {
            $token = sanitizeToken($body['token'] ?? '');
            if (!$token) out(['status'=>'error','message'=>'token inválido o faltante'], 400);

            $data = $body['data'] ?? [];
            if (empty($data)) out(['status'=>'ok','message'=>'nothing to save']);

            $db = getDB();
            $payload = buildDraftPayload($token, $data);
            upsertDraftCandidate($db, $token, $payload);

            out(['status'=>'ok', 'saved_fields'=>count($payload), 'token'=>$token]);
        }

        /* ══════════════════════════════════════════
           LOAD
           Acepta token ó email
        ══════════════════════════════════════════ */
        case 'load': {
            $token = sanitizeToken($body['token'] ?? '');
            $email = trim($body['email'] ?? '');

            if (!$token && !$email) {
                out(['status'=>'error','message'=>'Provide token or email'], 400);
            }

            $db = getDB();

            if ($token) {
                $stmt = $db->prepare("SELECT * FROM gsd_candidates WHERE token = ? LIMIT 1");
                $stmt->execute([$token]);
            } else {
                $stmt = $db->prepare("SELECT * FROM gsd_candidates WHERE email = ? ORDER BY updated_at DESC LIMIT 1");
                $stmt->execute([$email]);
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                out(['status'=>'not_found','message'=>'No saved application found for that ' . ($token ? 'token' : 'email') . '.'], 404);
            }

            out([
                'status'       => 'ok',
                'token'        => $row['token'],
                'current_step' => 0,
                'data'         => candidateToDraftResponse($row),
            ]);
        }

        /* ══════════════════════════════════════════
           SUBMIT
        ══════════════════════════════════════════ */
        case 'submit': {
            $token = sanitizeToken($body['token'] ?? '');
            if (!$token) out(['status'=>'error','message'=>'token required'], 400);

            $db   = getDB();
            $stmt = $db->prepare("UPDATE gsd_candidates SET processing_status = 'reviewing', updated_at = NOW() WHERE token = ?");
            $stmt->execute([$token]);

            if ($stmt->rowCount() === 0) out(['status'=>'error','message'=>'Token not found'], 404);
            out(['status'=>'ok','message'=>'Application submitted successfully','token'=>$token]);
        }

        default:
            out(['status'=>'error','message'=>"Unknown action: {$action}"], 400);
    }

} catch (Throwable $e) {
    out([
        'status'  => 'error',
        'message' => 'Server error: ' . $e->getMessage(),
        'file'    => basename($e->getFile()) . ':' . $e->getLine(),
    ], 500);
}
