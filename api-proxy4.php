<?php
/**
 * GSD ASSOCIATES — AI API Proxy v3
 * ─────────────────────────────────
 * Soporta múltiples keys por provider.
 * Cuando una key se agota (429/400 créditos), pasa a la siguiente.
 *
 * .env keys:
 *   GEMINI_API_KEY=key1,key2,key3
 *   CLAUDE_API_KEY=key1,key2
 *   OPENAI_API_KEY=key1,key2
 *   GROQ_API_KEY=key1,key2
 *   OPENROUTER_API_KEY=key1,key2
 *   AI_ORDER=gemini,claude,openai
 *   CLAUDE_MODEL_ORDER=claude-sonnet-4-20250514,claude-3-7-sonnet-20250219
 *
 * GET  ?action=ping  → health check
 * POST {action:extract_cv, cv_text:...} → extracción IA
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/config/runtime.php';

/* ════════════════════════════════════════════════════════
   1. CARGAR .env
════════════════════════════════════════════════════════ */
$env = gsdRecruitmentLoadEnv();
$envLoaded = ! empty($env);
$envFoundPath = $env['__path'] ?? '';

/* ════════════════════════════════════════════════════════
   2. PARSEAR KEYS (soporte para múltiples separadas por coma)
════════════════════════════════════════════════════════ */
function parseKeys(string $raw, bool $isClaude = false): array {
    $keys = array_filter(array_map('trim', explode(',', $raw)));
    if ($isClaude) {
        $keys = array_map(fn($k) => preg_replace('/^(sk-ant-){2,}/i', 'sk-ant-', $k), $keys);
    }
    return array_values($keys);
}

function parseModelList(string $raw, array $defaults): array {
    $models = array_values(array_filter(array_map('trim', explode(',', $raw))));
    return $models !== [] ? $models : $defaults;
}

$CLAUDE_KEYS = parseKeys($env['CLAUDE_API_KEY'] ?? '', true);
$GEMINI_KEYS = parseKeys($env['GEMINI_API_KEY'] ?? '');
$OPENAI_KEYS = parseKeys($env['OPENAI_API_KEY'] ?? '');
$GROQ_KEYS = parseKeys($env['GROQ_API_KEY'] ?? '');
$OPENROUTER_KEYS = parseKeys($env['OPENROUTER_API_KEY'] ?? '');
$AI_ORDER    = trim($env['AI_ORDER'] ?? 'gemini,claude,openai');
$CLAUDE_MODELS = parseModelList(
    $env['CLAUDE_MODEL_ORDER'] ?? '',
    [
        'claude-sonnet-4-20250514',
        'claude-3-7-sonnet-20250219',
        'claude-3-5-sonnet-20241022',
        'claude-3-5-haiku-20241022',
    ]
);
$GROQ_MODELS = parseModelList(
    $env['GROQ_MODEL_ORDER'] ?? '',
    [
        'llama-3.3-70b-versatile',
        'openai/gpt-oss-120b',
        'llama-3.1-8b-instant',
    ]
);
$OPENROUTER_MODELS = parseModelList(
    $env['OPENROUTER_MODEL_ORDER'] ?? '',
    [
        'openai/gpt-4o-mini',
        'meta-llama/llama-3.3-70b-instruct',
        'anthropic/claude-3.5-sonnet',
    ]
);

/* ════════════════════════════════════════════════════════
   3. PING  (GET ?action=ping)
════════════════════════════════════════════════════════ */
$isGet  = $_SERVER['REQUEST_METHOD'] === 'GET';
$isPing = ($_GET['action'] ?? '') === 'ping';

if ($isGet || $isPing) {
    $mask = fn(array $keys) => array_map(fn($k) => '...' . substr($k, -6), $keys);
    $providerHealth = [
        'claude' => providerHealthSnapshot('claude', $CLAUDE_KEYS, $mask),
        'gemini' => providerHealthSnapshot('gemini', $GEMINI_KEYS, $mask),
        'openai' => providerHealthSnapshot('openai', $OPENAI_KEYS, $mask),
        'groq' => providerHealthSnapshot('groq', $GROQ_KEYS, $mask),
        'openrouter' => providerHealthSnapshot('openrouter', $OPENROUTER_KEYS, $mask),
    ];
    $coreProviders = ['claude', 'gemini', 'openai'];
    $allHealthy = count(array_filter($coreProviders, fn(string $name): bool => !empty($providerHealth[$name]['healthy']))) === count($coreProviders);
    $alert = (($_GET['notify'] ?? '1') !== '0')
        ? maybeSendProviderHealthAlert($providerHealth, $envLoaded, $envFoundPath, $AI_ORDER)
        : ['sent' => false, 'reason' => 'notify_disabled'];

    echo json_encode([
        'status'     => 'ok',
        'env_loaded' => $envLoaded,
        'env_path'   => $envLoaded ? $envFoundPath : 'NOT FOUND',
        'providers'  => $providerHealth,
        'all_healthy' => $allHealthy,
        'order' => $AI_ORDER,
        'curl'  => function_exists('curl_init') ? 'available' : 'MISSING',
        'php'   => PHP_VERSION,
        'alert' => $alert,
    ], JSON_PRETTY_PRINT);
    exit;
}

/* ════════════════════════════════════════════════════════
   4. VALIDAR POST
════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'error' => 'Method not allowed. Use POST.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || ($body['action'] ?? '') !== 'extract_cv') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Invalid action. Expected: extract_cv']);
    exit;
}

$cvText = trim($body['cv_text'] ?? '');
if (strlen($cvText) < 20) {
    echo json_encode(['status' => 'error', 'error' => 'cv_text too short (min 20 chars)']);
    exit;
}

$prompt     = trim($body['prompt'] ?? '') ?: buildPrompt();
$fullPrompt = $prompt . "\n\n" . mb_substr($cvText, 0, 12000);

/* ════════════════════════════════════════════════════════
   5. ITERAR PROVIDERS Y KEYS
════════════════════════════════════════════════════════ */
$order  = array_filter(array_map('trim', explode(',', strtolower($AI_ORDER))));
$errors = [];

foreach ($order as $provider) {
    $keys = match($provider) {
        'gemini' => $GEMINI_KEYS,
        'claude' => $CLAUDE_KEYS,
        'openai' => $OPENAI_KEYS,
        'groq' => $GROQ_KEYS,
        'openrouter' => $OPENROUTER_KEYS,
        default  => [],
    };

    if (empty($keys)) {
        $errors[] = "$provider: no keys configured";
        continue;
    }

    foreach ($keys as $i => $key) {
        $keyLabel = $provider . '[key' . ($i + 1) . ']';
        try {
            $result = match($provider) {
                'gemini' => callGemini($key, $fullPrompt),
                'claude' => callClaude($key, $fullPrompt),
                'openai' => callOpenAI($key, $fullPrompt),
                'groq' => callGroq($key, $fullPrompt),
                'openrouter' => callOpenRouter($key, $fullPrompt),
                default  => null,
            };

            if ($result && countFilled($result) > 0) {
                echo json_encode([
                    'status'   => 'ok',
                    'provider' => $provider,
                    'key_used' => $i + 1,
                    'fields'   => countFilled($result),
                    'data'     => $result,
                ]);
                exit;
            }

            $errors[] = "$keyLabel: 0 fields extracted";

        } catch (QuotaException $e) {
            // Cuota agotada → intentar siguiente key del mismo provider
            $errors[] = "$keyLabel: quota/credits exhausted — " . $e->getMessage();
            error_log("GSD proxy $keyLabel quota: " . $e->getMessage());
            continue; // siguiente key

        } catch (Throwable $e) {
            // Error distinto (red, parse, etc.) → parar este provider
            $errors[] = "$keyLabel: " . $e->getMessage();
            error_log("GSD proxy $keyLabel error: " . $e->getMessage());
            break; // siguiente provider
        }
    }
}

echo json_encode([
    'status'     => 'error',
    'error'      => 'All AI providers and keys failed',
    'details'    => $errors,
    'configured' => [
        'gemini' => count($GEMINI_KEYS),
        'claude' => count($CLAUDE_KEYS),
        'openai' => count($OPENAI_KEYS),
        'groq' => count($GROQ_KEYS),
        'openrouter' => count($OPENROUTER_KEYS),
    ],
    'env_loaded' => $envLoaded,
]);
exit;

/* ════════════════════════════════════════════════════════
   EXCEPCIÓN ESPECIAL: CUOTA AGOTADA
════════════════════════════════════════════════════════ */
class QuotaException extends RuntimeException {}

/* ════════════════════════════════════════════════════════
   PROVIDERS
════════════════════════════════════════════════════════ */
function callGemini(string $key, string $prompt): ?array {
    $payload = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['maxOutputTokens' => 2500, 'temperature' => 0.1],
    ]);

    [$body, $code] = curlPost(
        "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$key}",
        $payload,
        ['Content-Type: application/json']
    );

    if ($code === 429 || $code === 403) {
        $e = json_decode($body, true);
        throw new QuotaException("HTTP $code: " . ($e['error']['message'] ?? substr($body, 0, 150)));
    }
    if ($code !== 200) {
        $e = json_decode($body, true);
        throw new RuntimeException("HTTP $code: " . ($e['error']['message'] ?? substr($body, 0, 150)));
    }

    $d = json_decode($body, true);
    return parseJSON($d['candidates'][0]['content']['parts'][0]['text'] ?? '');
}

function callClaude(string $key, string $prompt): ?array {
    global $CLAUDE_MODELS;

    $lastException = null;
    foreach ($CLAUDE_MODELS as $model) {
        try {
            return callClaudeModel($key, $prompt, $model);
        } catch (QuotaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $lastException = $exception;
            if (shouldTryNextClaudeModel($exception)) {
                error_log("[claude-model-fallback][$model] ".$exception->getMessage());
                continue;
            }
            throw $exception;
        }
    }

    if ($lastException) {
        throw $lastException;
    }

    throw new RuntimeException('Claude model list is empty');
}

function callClaudeModel(string $key, string $prompt, string $model): ?array {
    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => 2500,
        'messages'   => [['role' => 'user', 'content' => $prompt]],
    ]);

    [$body, $code] = curlPost(
        'https://api.anthropic.com/v1/messages',
        $payload,
        [
            'Content-Type: application/json',
            'anthropic-version: 2023-06-01',
            'x-api-key: ' . $key,
        ]
    );

    if ($code === 429 || ($code === 400 && str_contains($body, 'credit balance'))) {
        $e = json_decode($body, true);
        throw new QuotaException("HTTP $code: " . ($e['error']['message'] ?? substr($body, 0, 150)));
    }
    if ($code !== 200) {
        $e = json_decode($body, true);
        throw new RuntimeException("HTTP $code: " . ($e['error']['message'] ?? substr($body, 0, 150)));
    }

    $d = json_decode($body, true);
    return parseJSON(implode('', array_column($d['content'] ?? [], 'text')));
}

function shouldTryNextClaudeModel(Throwable $exception): bool {
    $message = strtolower($exception->getMessage());
    return str_contains($message, '404')
        || str_contains($message, 'model')
        || str_contains($message, 'not found')
        || str_contains($message, 'unavailable');
}

function callOpenAI(string $key, string $prompt): ?array {
    return callOpenAICompatible(
        $key,
        $prompt,
        [
            'base_url' => 'https://api.openai.com/v1/chat/completions',
            'models' => ['gpt-4o-mini', 'gpt-4.1-mini'],
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
        ]
    );
}

function callGroq(string $key, string $prompt): ?array {
    global $GROQ_MODELS;

    return callOpenAICompatible(
        $key,
        $prompt,
        [
            'base_url' => 'https://api.groq.com/openai/v1/chat/completions',
            'models' => $GROQ_MODELS,
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
            ],
        ]
    );
}

function callOpenRouter(string $key, string $prompt): ?array {
    global $OPENROUTER_MODELS;

    return callOpenAICompatible(
        $key,
        $prompt,
        [
            'base_url' => 'https://openrouter.ai/api/v1/chat/completions',
            'models' => $OPENROUTER_MODELS,
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $key,
                'HTTP-Referer: https://candidates.gsdoutsource.com',
                'X-Title: GSD Candidates Intake',
            ],
        ]
    );
}

function callOpenAICompatible(string $key, string $prompt, array $config): ?array {
    $lastException = null;
    foreach ($config['models'] as $model) {
        try {
            [$body, $code] = curlPost(
                $config['base_url'],
                json_encode([
                    'model'           => $model,
                    'max_tokens'      => 2500,
                    'temperature'     => 0.1,
                    'response_format' => ['type' => 'json_object'],
                    'messages'        => [
                        ['role' => 'system', 'content' => 'CV data extractor. Respond with valid JSON only.'],
                        ['role' => 'user',   'content' => $prompt],
                    ],
                ]),
                $config['headers']
            );

            if ($code === 429 || $code === 402) {
                $e = json_decode($body, true);
                throw new QuotaException("HTTP $code: " . ($e['error']['message'] ?? substr($body, 0, 150)));
            }
            if ($code !== 200) {
                $e = json_decode($body, true);
                $message = $e['error']['message'] ?? substr($body, 0, 150);
                $exception = new RuntimeException("HTTP $code: $message");
                if (shouldTryNextModel($exception)) {
                    $lastException = $exception;
                    continue;
                }
                throw $exception;
            }

            $d = json_decode($body, true);
            return parseJSON($d['choices'][0]['message']['content'] ?? '');
        } catch (QuotaException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            if (shouldTryNextModel($exception)) {
                $lastException = $exception;
                continue;
            }
            throw $exception;
        }
    }

    if ($lastException) {
        throw $lastException;
    }

    throw new RuntimeException('Provider model list is empty');
}

function shouldTryNextModel(Throwable $exception): bool {
    $message = strtolower($exception->getMessage());
    return str_contains($message, '404')
        || str_contains($message, '422')
        || str_contains($message, 'model')
        || str_contains($message, 'not found')
        || str_contains($message, 'unsupported');
}

function providerHealthSnapshot(string $provider, array $keys, callable $masker): array {
    if (empty($keys)) {
        return [
            'configured' => false,
            'healthy' => false,
            'code' => 'CFG',
            'message' => 'Not configured in .env',
            'masked' => [],
        ];
    }

    $masked = $masker($keys);
    $lastFailure = [
        'configured' => true,
        'healthy' => false,
        'code' => 'ERR',
        'message' => 'Unknown provider error',
        'masked' => $masked,
    ];

    foreach ($keys as $index => $key) {
        try {
            probeProvider($provider, $key);

            return [
                'configured' => true,
                'healthy' => true,
                'code' => 'OK',
                'message' => 'Provider online',
                'masked' => $masked,
                'key_used' => $index + 1,
            ];
        } catch (Throwable $exception) {
            $lastFailure = classifyProviderFailure($exception, $masked, $index + 1);
            error_log("[provider-health][$provider][key".($index + 1)."] ".$exception->getMessage());
        }
    }

    return $lastFailure;
}

function probeProvider(string $provider, string $key): void {
    global $CLAUDE_MODELS, $GROQ_MODELS, $OPENROUTER_MODELS;

    switch ($provider) {
        case 'gemini':
            [, $code] = curlPost(
                "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key={$key}",
                json_encode([
                    'contents' => [['parts' => [['text' => 'ping']]]],
                    'generationConfig' => ['maxOutputTokens' => 1, 'temperature' => 0],
                ]),
                ['Content-Type: application/json']
            );
            break;

        case 'claude':
            $lastException = null;
            foreach ($CLAUDE_MODELS as $model) {
                [$body, $code] = probeClaudeModel($key, $model);
                if ($code === 200) {
                    return;
                }
                if ($code === 429 || ($code === 400 && str_contains($body, 'credit balance'))) {
                    throw new QuotaException("HTTP {$code}: quota or rate limit");
                }
                if (in_array($code, [400, 401, 403], true)) {
                    throw new RuntimeException("HTTP {$code}: auth or permissions");
                }
                $lastException = new RuntimeException("HTTP {$code}: provider unavailable");
                if (in_array($code, [404, 422], true)) {
                    continue;
                }
                throw $lastException;
            }
            if ($lastException) {
                throw $lastException;
            }
            throw new RuntimeException('Claude model list is empty');

        case 'openai':
            [, $code] = curlPost(
                'https://api.openai.com/v1/chat/completions',
                json_encode([
                    'model' => 'gpt-4o-mini',
                    'max_tokens' => 1,
                    'temperature' => 0,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                ]),
                [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $key,
                ]
            );
            break;

        case 'groq':
            probeOpenAICompatibleProvider(
                'https://api.groq.com/openai/v1/models',
                [
                    'Authorization: Bearer ' . $key,
                    'Content-Type: application/json',
                ]
            );
            return;

        case 'openrouter':
            probeOpenAICompatibleProvider(
                'https://openrouter.ai/api/v1/models',
                [
                    'Authorization: Bearer ' . $key,
                    'HTTP-Referer: https://candidates.gsdoutsource.com',
                    'X-Title: GSD Candidates Intake',
                    'Content-Type: application/json',
                ]
            );
            return;

        default:
            throw new RuntimeException('Unsupported provider');
    }

    if ($code === 200) {
        return;
    }

    if ($code === 429) {
        throw new QuotaException("HTTP {$code}: quota or rate limit");
    }

    if (in_array($code, [400, 401, 403], true)) {
        throw new RuntimeException("HTTP {$code}: auth or permissions");
    }

    throw new RuntimeException("HTTP {$code}: provider unavailable");
}

function probeClaudeModel(string $key, string $model): array {
    return curlPost(
        'https://api.anthropic.com/v1/messages',
        json_encode([
            'model' => $model,
            'max_tokens' => 1,
            'messages' => [['role' => 'user', 'content' => 'ping']],
        ]),
        [
            'Content-Type: application/json',
            'anthropic-version: 2023-06-01',
            'x-api-key: ' . $key,
        ]
    );
}

function probeOpenAICompatibleProvider(string $url, array $headers): void {
    [, $code] = curlRequest('GET', $url, null, $headers);

    if ($code === 200) {
        return;
    }

    if ($code === 429 || $code === 402) {
        throw new QuotaException("HTTP {$code}: quota or rate limit");
    }

    if (in_array($code, [400, 401, 403], true)) {
        throw new RuntimeException("HTTP {$code}: auth or permissions");
    }

    throw new RuntimeException("HTTP {$code}: provider unavailable");
}

function classifyProviderFailure(Throwable $exception, array $masked, int $keyIndex): array {
    $message = $exception->getMessage();
    $upper = strtoupper($message);

    $code = match (true) {
        $exception instanceof QuotaException,
        str_contains($upper, '429'),
        str_contains($upper, '402') => 'QUOTA',
        str_contains($upper, '401'),
        str_contains($upper, '403'),
        str_contains($upper, 'AUTH') => 'AUTH',
        str_contains($upper, 'CURL') => 'CURL',
        str_contains($upper, 'HTTP') => 'HTTP',
        default => 'ERR',
    };

    return [
        'configured' => true,
        'healthy' => false,
        'code' => $code,
        'message' => $message,
        'masked' => $masked,
        'key_used' => $keyIndex,
    ];
}

function maybeSendProviderHealthAlert(array $providerHealth, bool $envLoaded, string $envPath, string $aiOrder): array {
    $issues = array_filter($providerHealth, fn(array $provider): bool => ! $provider['healthy']);
    if ($issues === []) {
        return ['sent' => false, 'reason' => 'all_healthy'];
    }

    $cacheFile = rtrim(sys_get_temp_dir(), '/').'/gsd_ai_provider_health_alert.json';
    $signature = sha1(json_encode([
        'env_loaded' => $envLoaded,
        'env_path' => $envPath,
        'order' => $aiOrder,
        'issues' => $issues,
    ]));
    $now = time();

    if (is_readable($cacheFile)) {
        $previous = json_decode((string) file_get_contents($cacheFile), true);
        $lastAttempt = (int) ($previous['attempted_at'] ?? 0);
        if (($previous['signature'] ?? '') === $signature && ($now - $lastAttempt) < 1800) {
            return ['sent' => false, 'reason' => 'cooldown', 'to' => $previous['to'] ?? null];
        }
    }

    $to = gsdRecruitmentEnv('AI_PROVIDER_ALERT_EMAIL', 'anderson.martinez@gsdoutsource.com');
    $from = gsdRecruitmentEnv('AI_PROVIDER_ALERT_FROM', 'noreply@gsdoutsource.com');
    $subject = '[GSD Candidates] AI provider health warning';

    $lines = [
        'AI provider health warning detected on candidates intake.',
        '',
        'Environment loaded: '.($envLoaded ? 'yes' : 'no'),
        'Environment path: '.($envPath !== '' ? $envPath : 'not found'),
        'Order: '.$aiOrder,
        '',
        'Provider status:',
    ];

    foreach ($providerHealth as $name => $provider) {
        $lines[] = strtoupper($name).': '.($provider['code'] ?? 'ERR').' — '.($provider['message'] ?? 'Unknown issue');
    }

    $sent = false;
    if (function_exists('mail') && filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $headers = implode("\r\n", [
            'From: '.$from,
            'Content-Type: text/plain; charset=UTF-8',
        ]);
        $sent = @mail($to, $subject, implode("\n", $lines), $headers);
    }

    @file_put_contents($cacheFile, json_encode([
        'signature' => $signature,
        'attempted_at' => $now,
        'sent' => $sent,
        'to' => $to,
    ], JSON_PRETTY_PRINT));

    return ['sent' => $sent, 'reason' => $sent ? 'email_sent' : 'mail_failed', 'to' => $to];
}

/* ════════════════════════════════════════════════════════
   UTILIDADES
════════════════════════════════════════════════════════ */
function curlRequest(string $method, string $url, ?string $payload, array $headers): array {
    if (!function_exists('curl_init')) throw new RuntimeException('cURL not available');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'GSD-Recruitment/3.0',
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException("cURL error: $cerr");
    return [$body, $code];
}

function curlPost(string $url, string $payload, array $headers): array {
    return curlRequest('POST', $url, $payload, $headers);
}

function parseJSON(string $text): ?array {
    $clean = preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', trim($text));
    if (preg_match('/\{[\s\S]+\}/u', $clean, $m)) {
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
{"name":"","email":"","phone":"","address":"","country":"","city":"","linkedin":"","availability":"","salary":"","summary":"","skills":"","education_level":"","main_degree":"","main_institution":"","main_years":"","other_degree":"","other_institution":"","other_years":"","exp_years":"","main_company":"","main_title":"","main_responsibilities":"","other_company":"","other_title":"","other_years_exp":"","other_responsibilities":"","languages":"","certifications":"","edu_healthcare":"","worked_healthcare":"","worked_va":"","suggested_role":""}

RULES:
- name: Full name from top of CV.
- country: EXACTLY one of these when possible: Colombia | United States | Mexico | Spain | Argentina | Chile.
- city: candidate city if clearly present in CV.
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
