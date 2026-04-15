<?php
/**
 * GSD - Recruitment Notification via Google Chat Space
 * No email. Google Space card only.
 */

require_once __DIR__.'/../config/runtime.php';

const GSD_WEBHOOK_FALLBACK = 'https://chat.googleapis.com/v1/spaces/AAQAOMWjNEE/messages?key=AIzaSyDdI0hCZtE6vySjMm-WEfRq3CPzqKqqsHI&token=gKzm5QWeOfhfAjWfBOOUac0hV_jYqjUuVg9d_dff5u4';

error_reporting(E_ALL);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

function out(array $d, int $code = 200): void {
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function postWebhook(string $url, array $payload): array {
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ctx  = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json; charset=utf-8\r\nContent-Length: " . strlen($json),
        'content'       => $json,
        'timeout'       => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header)) {
        preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $m);
        $code = (int)($m[1] ?? 0);
    }
    return ['success' => $code >= 200 && $code < 300, 'http_code' => $code, 'response' => substr($resp ?: '', 0, 300)];
}

function gsdNotifyWebhook(): string
{
    return (string) (
        gsdRecruitmentEnv('GOOGLE_CHAT_WEBHOOK_URL')
        ?? gsdRecruitmentEnv('GOOGLE_SPACE_WEBHOOK_URL')
        ?? gsdRecruitmentEnv('RECRUITMENT_NOTIFY_WEBHOOK')
        ?? GSD_WEBHOOK_FALLBACK
    );
}

function gsdApplyBaseUrl(): string
{
    $https = (
        (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
    );
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $path = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));

    if ($path === '.' || $path === DIRECTORY_SEPARATOR) {
        $path = '';
    }

    return rtrim($scheme.'://'.$host.rtrim($path, '/'), '/').'/';
}

function markNotified(string $token): void {
    if (!$token) return;
    foreach ([__DIR__ . '/db.php', dirname(__DIR__) . '/db.php', __DIR__ . '/config/Database.php'] as $p) {
        if (!file_exists($p)) continue;
        try {
            require_once $p;
            $pdo = function_exists('getDB') ? getDB() : (new Database())->getConnection();
            if ($pdo) {
                $candidate = gsdPromoteDraftCandidate($pdo, $token);
                $officialTable = gsdOfficialCandidateTable($pdo);
                if ($candidate && $officialTable) {
                    $pdo->prepare('UPDATE `'.$officialTable.'` SET processing_status=\'reviewing\', updated_at=NOW() WHERE token=?')->execute([$token]);
                }
            }
        } catch (Throwable $e) { error_log('[notify] DB: ' . $e->getMessage()); }
        return;
    }
}

try {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $token    = preg_replace('/[^A-Z0-9\-]/', '', strtoupper($body['token']    ?? ''));
    $name     = strip_tags($body['name']     ?? 'Candidate');
    $email    = strip_tags($body['email']    ?? '');
    $phone    = strip_tags($body['phone']    ?? '');
    $linkedin = strip_tags($body['linkedin'] ?? '');
    $position = strip_tags($body['position'] ?? 'Unknown');
    $analysis = $body['video_analysis']      ?? [];
    $url      = $body['candidate_url'] ?? (gsdApplyBaseUrl()."views/new-candidate.php?token={$token}");

    /* Analysis */
    $sc        = isset($analysis['combined_score']) ? (int)$analysis['combined_score'] : 0;
    $score     = $sc ? "{$sc}%" : 'N/A';
    $scoreIcon = $sc >= 70 ? '🟢' : ($sc >= 40 ? '🟡' : '🔴');
    $sentiment = $analysis['sentiment']['label']          ?? 'N/A';
    $lang      = ($analysis['language'] ?? 'en') === 'es' ? 'Spanish 🇪🇸' : 'English 🇺🇸';
    $emotion   = $analysis['facial_analysis']['dominant'] ?? 'N/A';
    $secs      = (int)($analysis['duration_seconds']      ?? 0);
    $duration  = $secs ? floor($secs/60).':'.str_pad($secs%60,2,'0',STR_PAD_LEFT).' min' : 'N/A';
    $transcript = !empty($analysis['transcript'])
        ? '"'.substr(strip_tags($analysis['transcript']),0,260).(strlen($analysis['transcript'])>260?'...':'').'"'
        : '';
    $now = date('M j, Y · g:i A');

    /* Candidate widgets */
    $cw = [['decoratedText' => ['topLabel' => 'Token', 'text' => "<b>{$token}</b>", 'startIcon' => ['knownIcon' => 'BOOKMARK']]]];
    if ($email)    $cw[] = ['decoratedText' => ['topLabel'=>'Email',    'text'=>$email,    'startIcon'=>['knownIcon'=>'EMAIL'],  'onClick'=>['openLink'=>['url'=>"mailto:{$email}"]]]];
    if ($phone)    $cw[] = ['decoratedText' => ['topLabel'=>'Phone',    'text'=>$phone,    'startIcon'=>['knownIcon'=>'PHONE']]];
    if ($linkedin) $cw[] = ['decoratedText' => ['topLabel'=>'LinkedIn', 'text'=>$linkedin, 'startIcon'=>['knownIcon'=>'PERSON'],'onClick'=>['openLink'=>['url'=>str_starts_with($linkedin,'http')?$linkedin:"https://{$linkedin}"]]]];

    /* AI widgets */
    $aw = [
        ['decoratedText' => ['topLabel'=>'Score',      'text'=>"<b>{$scoreIcon} {$score}</b>", 'startIcon'=>['knownIcon'=>'STAR']]],
        ['decoratedText' => ['topLabel'=>'Sentiment',  'text'=>"<b>{$sentiment}</b>"]],
        ['decoratedText' => ['topLabel'=>'Language',   'text'=>"<b>{$lang}</b>"]],
        ['decoratedText' => ['topLabel'=>'Expression', 'text'=>"<b>{$emotion}</b>"]],
    ];
    if ($transcript) $aw[] = ['decoratedText' => ['topLabel'=>"Transcript ({$duration})", 'text'=>"<i>{$transcript}</i>", 'wrapText'=>true]];

    /* Buttons */
    $btns = [['text'=>'👁  View Full Profile','color'=>['red'=>0.35,'green'=>0.22,'blue'=>0.53,'alpha'=>1.0],'onClick'=>['openLink'=>['url'=>$url]]]];
    if ($email) $btns[] = ['text'=>'✉  Email Candidate','onClick'=>['openLink'=>['url'=>'mailto:'.$email.'?subject='.rawurlencode("Your GSD Application [{$token}]")]]];

    /* cardsV2 */
    $card = ['cardsV2'=>[['cardId'=>"gsd-{$token}",'card'=>[
        'header'   => ['title'=>"🟣 New Application: {$name}",'subtitle'=>"{$position} · {$now}",'imageUrl'=>gsdApplyBaseUrl().'assets/images/iconGSD.png','imageType'=>'CIRCLE'],
        'sections' => [
            ['header'=>'👤 Candidate Details',    'collapsible'=>false,'widgets'=>$cw],
            ['header'=>'🤖 AI Interview Analysis','collapsible'=>false,'widgets'=>$aw],
            ['widgets'=>[['buttonList'=>['buttons'=>$btns]]]],
        ],
    ]]]];

    $result = postWebhook(gsdNotifyWebhook(), $card);

    /* Plain-text fallback */
    if (!$result['success']) {
        $lines = array_filter([
            "*🟣 New GSD Application*",
            "*{$name}* → *{$position}*",
            $email    ? "📧 {$email}"    : null,
            $phone    ? "📞 {$phone}"    : null,
            $linkedin ? "🔗 {$linkedin}" : null,
            "🤖 {$scoreIcon} {$score} | {$sentiment} | {$lang} | {$emotion}",
            $transcript ? "💬 {$transcript}" : null,
            "🔖 Token: `{$token}`",
            "👉 {$url}",
        ]);
        $result = postWebhook(gsdNotifyWebhook(), ['text' => implode("\n", $lines)]);
    }

    markNotified($token);
    out(['status' => 'ok', 'webhook' => $result]);

} catch (Throwable $e) {
    out(['status' => 'error', 'message' => $e->getMessage()], 500);
}
