<?php
/**
 * GSD ASSOCIATES — AI API Proxy v2
 * Keys live in .env — never exposed to browser.
 *
 * GET  ?action=ping        → health check
 * POST {action:extract_cv} → AI extraction
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once dirname(__DIR__) . '/config/runtime.php';

// ── Load .env ────────────────────────────────────────
$env = gsdRecruitmentLoadEnv();
$envLoaded = ! empty($env);
$envFoundPath = $env['__path'] ?? '';

// ── Clean API keys (fix accidental double-prefix) ─────
function cleanKey(string $k): string {
    // Fix "sk-ant-sk-ant-api03-..." → "sk-ant-api03-..."
    $k = preg_replace('/^(sk-ant-){2,}/i', 'sk-ant-', $k);
    return trim($k);
}

$CLAUDE_KEY = cleanKey($env['CLAUDE_API_KEY'] ?? '');
$GEMINI_KEY = trim($env['GEMINI_API_KEY'] ?? '');
$OPENAI_KEY = trim($env['OPENAI_API_KEY'] ?? '');
$AI_ORDER   = trim($env['AI_ORDER']       ?? 'gemini,claude,openai');

// ── PING endpoint (GET or ?action=ping) ───────────────
$isGet    = $_SERVER['REQUEST_METHOD'] === 'GET';
$isPing   = ($_GET['action'] ?? '') === 'ping';
if ($isGet || $isPing) {
    echo json_encode([
        'status'     => 'ok',
        'env_loaded' => $envLoaded,
        'env_path'   => $envLoaded ? $envFoundPath : 'NOT FOUND',
        'providers'  => [
            'claude' => $CLAUDE_KEY ? 'configured (...' . substr($CLAUDE_KEY, -6) . ')' : 'NOT SET',
            'gemini' => $GEMINI_KEY ? 'configured (...' . substr($GEMINI_KEY, -6) . ')' : 'NOT SET',
            'openai' => $OPENAI_KEY ? 'configured (...' . substr($OPENAI_KEY, -6) . ')' : 'NOT SET',
        ],
        'order'   => $AI_ORDER,
        'curl'    => function_exists('curl_init') ? 'available' : 'MISSING',
        'php'     => PHP_VERSION,
    ]);
    exit;
}

// ── Require POST ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method not allowed. Use POST.']);
    exit;
}

// ── Parse body ────────────────────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body || ($body['action'] ?? '') !== 'extract_cv') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid action']);
    exit;
}
$cvText = trim($body['cv_text'] ?? '');
if (strlen($cvText) < 20) {
    echo json_encode(['status' => 'error', 'error' => 'cv_text too short']);
    exit;
}

$userPrompt = trim($body['prompt'] ?? '');
if (empty($userPrompt)) $userPrompt = buildPrompt();
$fullPrompt = $userPrompt . "\n\n" . mb_substr($cvText, 0, 12000);

// ── Try providers in order ────────────────────────────
$order  = array_filter(array_map('trim', explode(',', strtolower($AI_ORDER))));
$errors = [];

foreach ($order as $provider) {
    try {
        $result = null;
        switch ($provider) {
            case 'claude': if ($CLAUDE_KEY) $result = callClaude($CLAUDE_KEY, $fullPrompt); break;
            case 'gemini': if ($GEMINI_KEY) $result = callGemini($GEMINI_KEY, $fullPrompt); break;
            case 'openai': if ($OPENAI_KEY) $result = callOpenAI($OPENAI_KEY, $fullPrompt); break;
        }
        if ($result && countFilled($result) > 0) {
            echo json_encode([
                'status'   => 'ok',
                'provider' => $provider,
                'fields'   => countFilled($result),
                'data'     => $result,
            ]);
            exit;
        }
        $errors[] = "$provider: " . ($result === null ? 'no key or skipped' : '0 fields extracted');
    } catch (Throwable $e) {
        $errors[] = "$provider: " . $e->getMessage();
        error_log("GSD proxy [$provider]: " . $e->getMessage());
    }
}

echo json_encode([
    'status'     => 'error',
    'error'      => 'All AI providers failed',
    'details'    => $errors,
    'configured' => ['claude' => !empty($CLAUDE_KEY), 'gemini' => !empty($GEMINI_KEY), 'openai' => !empty($OPENAI_KEY)],
    'env_loaded' => $envLoaded,
]);
exit;

/* ════════════════════════════════════════════════════════
   PROVIDERS
════════════════════════════════════════════════════════ */
function callClaude(string $key, string $prompt): ?array {
    $payload = json_encode([
        // CAMBIA ESTA LÍNEA:
        'model'      => 'claude-3-5-sonnet-20241022', 
        'max_tokens' => 2500,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);
    [$body, $code] = curlPost('https://api.anthropic.com/v1/messages', $payload, [
        'Content-Type: application/json',
        'anthropic-version: 2023-06-01',
        'x-api-key: ' . $key,
    ]);
    if ($code !== 200) {
        $e = json_decode($body, true);
        throw new RuntimeException("HTTP $code: " . ($e['error']['message'] ?? substr($body, 0, 200)));
    }
    $d = json_decode($body, true);
    return parseJSON(implode('', array_column($d['content'] ?? [], 'text')));
}

function callGemini(string $key, string $prompt): ?array {
    $payload = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['maxOutputTokens' => 2500, 'temperature' => 0.1],
    ]);
    [$body, $code] = curlPost(
        "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$key}",
        $payload, ['Content-Type: application/json']
    );
    if ($code !== 200) {
        $e = json_decode($body, true);
        throw new RuntimeException("HTTP $code: " . ($e['error']['message'] ?? substr($body, 0, 200)));
    }
    $d = json_decode($body, true);
    return parseJSON($d['candidates'][0]['content']['parts'][0]['text'] ?? '');
}

function callOpenAI(string $key, string $prompt): ?array {
    $payload = json_encode([
        'model' => 'gpt-4o-mini', 'max_tokens' => 2500, 'temperature' => 0.1,
        'response_format' => ['type' => 'json_object'],
        'messages' => [
            ['role' => 'system', 'content' => 'CV data extractor. Respond with valid JSON only.'],
            ['role' => 'user',   'content' => $prompt],
        ],
    ]);
    [$body, $code] = curlPost('https://api.openai.com/v1/chat/completions', $payload, [
        'Content-Type: application/json', 'Authorization: Bearer ' . $key,
    ]);
    if ($code !== 200) {
        $e = json_decode($body, true);
        throw new RuntimeException("HTTP $code: " . ($e['error']['message'] ?? substr($body, 0, 200)));
    }
    $d = json_decode($body, true);
    return parseJSON($d['choices'][0]['message']['content'] ?? '');
}

function curlPost(string $url, string $payload, array $headers): array {
    if (!function_exists('curl_init')) throw new RuntimeException('cURL not available');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'GSD-Recruitment/2.0',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException("cURL: $cerr");
    return [$body, $code];
}

function parseJSON(string $text): ?array {
    $clean = preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', trim($text));
    if (preg_match('/\{[\s\S]+\}/', $clean, $m)) {
        $data = json_decode($m[0], true);
        if (is_array($data)) return $data;
    }
    error_log('GSD proxy: JSON parse fail. Snippet: ' . mb_substr($clean, 0, 200));
    return null;
}

function countFilled(array $data): int {
    return count(array_filter($data, fn($v) => is_string($v) ? trim($v) !== '' : !empty($v)));
}

function buildPrompt(): string {
    return 'You are an expert HR data extractor. Read the CV below and extract ALL information.

Return ONLY a valid JSON object with these exact keys (use "" for missing, never omit):
{"name":"","email":"","phone":"","address":"","linkedin":"","availability":"","salary":"","summary":"","skills":"","education_level":"","main_degree":"","main_institution":"","main_years":"","other_degree":"","other_institution":"","other_years":"","exp_years":"","main_company":"","main_title":"","main_responsibilities":"","other_company":"","other_title":"","other_years_exp":"","other_responsibilities":"","languages":"","certifications":"","edu_healthcare":"","worked_healthcare":"","worked_va":"","suggested_role":""}

RULES:
- summary: 3-5 sentences FIRST PERSON professional bio. Generate from experience if not explicit.
- education_level: one of: Less than high school (Secondary school)|High school diploma / Secondary school certificate|Associate degree or equivalent|Bachelor\'s degree|Master\'s degree|Doctorate (PhD) or equivalent
- exp_years: one of: Less than 1 year|1 to 2 years|3 to 4 years|5+ years|Senior Level (7+ years)
- main_responsibilities: dash bullet points (-), 3-8 items.
- languages: "English C1, Spanish Native" CEFR format.
- edu_healthcare/worked_healthcare/worked_va: "Yes" or "No"
- suggested_role: one of: VPA|HVA|HOP|MVA|MGR|ACM|SDR|HRO

Return ONLY JSON. No markdown fences. No explanation.

RESUME:';
}
