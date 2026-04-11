<?php

require_once __DIR__.'/../db.php';
require_once dirname(__DIR__, 2).'/config/runtime.php';

$token = trim((string) ($_GET['token'] ?? ''));
$source = trim((string) ($_GET['source'] ?? 'auto'));
$format = strtolower(trim((string) ($_GET['format'] ?? '')));

if ($token === '') {
    http_response_code(400);
    exit('Missing token.');
}

$pdo = getDB();
$stmt = $pdo->prepare('SELECT video_processed_path, video_original_path FROM gsd_candidates WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$candidate = $stmt->fetch(PDO::FETCH_ASSOC);

if (! is_array($candidate)) {
    http_response_code(404);
    exit('Candidate not found.');
}

function normalizeVideoPath(?string $path): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        $path = (string) (parse_url($path, PHP_URL_PATH) ?: '');
    }

    $path = str_replace('\\', '/', $path);

    if (str_contains($path, '/uploads/')) {
        return ltrim(substr($path, strpos($path, '/uploads/') + 1), '/');
    }

    if (str_starts_with($path, 'uploads/')) {
        return $path;
    }

    return 'uploads/'.ltrim($path, '/');
}

function preferredCandidateVideoPath(array $candidate, string $source): string
{
    $processed = normalizeVideoPath($candidate['video_processed_path'] ?? '');
    $original = normalizeVideoPath($candidate['video_original_path'] ?? '');

    return match ($source) {
        'processed' => $processed,
        'original' => $original,
        default => $processed !== '' ? $processed : $original,
    };
}

function diskCandidatesForRelative(string $relativePath, string $format): array
{
    $relativePath = normalizeVideoPath($relativePath);

    if ($relativePath === '') {
        return [];
    }

    $variants = [];
    $extension = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));

    if ($format === 'mp4') {
        if ($extension === 'mp4') {
            $variants[] = $relativePath;
        } else {
            $variants[] = preg_replace('/\.[^.]+$/', '.mp4', $relativePath) ?: $relativePath;
        }
    } else {
        $variants[] = $relativePath;
    }
    $variants = array_values(array_unique(array_filter($variants)));

    $roots = [
        dirname(__DIR__, 2),
        dirname(__DIR__),
    ];

    $paths = [];

    foreach ($variants as $variant) {
        $trimmed = preg_replace('#^uploads/#', '', $variant) ?? $variant;

        foreach ($roots as $root) {
            $paths[] = rtrim($root, '/').'/uploads/'.$trimmed;
        }
    }

    return array_values(array_unique($paths));
}

function detectMimeTypeForPath(string $path): string
{
    return match (strtolower((string) pathinfo($path, PATHINFO_EXTENSION))) {
        'webm' => 'video/webm',
        'ogg', 'ogv' => 'video/ogg',
        default => 'video/mp4',
    };
}

$relativeVideoPath = preferredCandidateVideoPath($candidate, $source);
$diskPath = null;

foreach (diskCandidatesForRelative($relativeVideoPath, $format) as $candidatePath) {
    if (is_file($candidatePath)) {
        $diskPath = $candidatePath;
        break;
    }
}

if (! is_string($diskPath) || $diskPath === '') {
    http_response_code(404);
    exit('Video file not found.');
}

$fileSize = filesize($diskPath);

if ($fileSize === false) {
    http_response_code(500);
    exit('Unable to read video file.');
}

$start = 0;
$end = $fileSize - 1;
$statusCode = 200;

header('Content-Type: '.detectMimeTypeForPath($diskPath));
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="'.basename($diskPath).'"');
header('X-Content-Type-Options: nosniff');

if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=(\d*)-(\d*)/i', (string) $_SERVER['HTTP_RANGE'], $matches)) {
    $statusCode = 206;

    if ($matches[1] !== '') {
        $start = (int) $matches[1];
    }

    if ($matches[2] !== '') {
        $end = (int) $matches[2];
    }

    $start = max(0, $start);
    $end = min($end, $fileSize - 1);

    if ($start > $end) {
        header('Content-Range: bytes */'.$fileSize);
        http_response_code(416);
        exit;
    }

    header('Content-Range: bytes '.$start.'-'.$end.'/'.$fileSize);
}

$length = $end - $start + 1;
header('Content-Length: '.(string) $length);
http_response_code($statusCode);

$handle = fopen($diskPath, 'rb');

if ($handle === false) {
    http_response_code(500);
    exit('Unable to open video file.');
}

fseek($handle, $start);
$remaining = $length;

while (! feof($handle) && $remaining > 0) {
    $chunkSize = min(8192, $remaining);
    $buffer = fread($handle, $chunkSize);

    if ($buffer === false) {
        break;
    }

    echo $buffer;
    flush();
    $remaining -= strlen($buffer);
}

fclose($handle);
