<?php
require_once __DIR__.'/../db.php';
require_once dirname(__DIR__, 2).'/config/runtime.php';

$token = $_GET['token'] ?? null;
$preEvaluator = $_GET['evaluator'] ?? '';
$clientRef = $_GET['client'] ?? 'direct-link';

if (! $token) {
    die('Invalid access.');
}

$pdo = getDB();
$stmt = $pdo->prepare('SELECT * FROM gsd_candidates WHERE token = ? LIMIT 1');
$stmt->execute([$token]);
$candidate = $stmt->fetch(PDO::FETCH_ASSOC);

if (! is_array($candidate)) {
    die('Candidate not found.');
}

$aiInsight = null;
try {
    $aiInsightStmt = $pdo->prepare('SELECT * FROM gsd_candidate_ai_insights WHERE candidate_token = ? ORDER BY id DESC LIMIT 1');
    $aiInsightStmt->execute([$token]);
    $aiInsight = $aiInsightStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable) {
    $aiInsight = null;
}

function decodeJsonValue(mixed $value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (! is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);

    return is_array($decoded) ? $decoded : [];
}

function pathToPublicUrl(?string $path): string
{
    $path = trim((string) $path);

    if ($path === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    return rtrim(gsdRecruitmentUploadsBaseUrl(), '/').'/'.ltrim($path, '/');
}

function videoMimeType(string $url): string
{
    $extension = strtolower((string) pathinfo(parse_url($url, PHP_URL_PATH) ?: $url, PATHINFO_EXTENSION));

    return match ($extension) {
        'webm' => 'video/webm',
        'ogg', 'ogv' => 'video/ogg',
        default => 'video/mp4',
    };
}

function formatMoneyValue(mixed $value): string
{
    if ($value === null || $value === '') {
        return 'Not provided';
    }

    if (is_numeric($value)) {
        return '$'.number_format((float) $value, 2);
    }

    return (string) $value;
}

function boolLabel(mixed $value): string
{
    return (int) $value === 1 ? 'Yes' : 'No';
}

function normalizeList(mixed $value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map(static function (mixed $item): string {
            return trim((string) $item);
        }, $value), static fn (string $item): bool => $item !== ''));
    }

    $string = trim((string) $value);

    if ($string === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $string) ?: [])));
}

function flattenAiInsights(array $analysis): array
{
    $highlights = [];

    foreach ($analysis as $key => $value) {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                if (is_scalar($childValue) && trim((string) $childValue) !== '') {
                    $highlights[] = [
                        'label' => ucwords(str_replace('_', ' ', (string) $childKey)),
                        'value' => (string) $childValue,
                    ];
                }
            }

            continue;
        }

        if (is_scalar($value) && trim((string) $value) !== '') {
            $highlights[] = [
                'label' => ucwords(str_replace('_', ' ', (string) $key)),
                'value' => (string) $value,
            ];
        }
    }

    return $highlights;
}

$experience = decodeJsonValue($candidate['experience_json'] ?? null);
$education = decodeJsonValue($candidate['education_json'] ?? null);
$skills = normalizeList(decodeJsonValue($candidate['skills_json'] ?? null));
$answersAll = decodeJsonValue($candidate['answers_all'] ?? null);
$biometric = decodeJsonValue($candidate['biometric_json'] ?? null);
$aiEnrichment = decodeJsonValue($biometric['_ai_enrichment'] ?? null);
$aiAnalysis = decodeJsonValue($candidate['ai_analysis'] ?? null);
$cvPreview = trim((string) ($candidate['cv_text_preview'] ?? ''));
$transcript = trim((string) ($candidate['transcript'] ?? ''));
$videoUrl = pathToPublicUrl($candidate['video_processed_path'] ?: $candidate['video_original_path'] ?: '');
$videoMime = $videoUrl !== '' ? videoMimeType($videoUrl) : 'video/mp4';
$cvUrl = pathToPublicUrl($candidate['cv_filename'] ?? '');
$analysisScore = (float) ($candidate['match_score'] ?: ($aiAnalysis['combined_score'] ?? $candidate['sentiment_score'] ?? 0));
$aiHighlights = flattenAiInsights($aiAnalysis);
$visualInsight = decodeJsonValue($aiInsight['visual_analysis'] ?? null);
$englishInsight = decodeJsonValue($aiInsight['english_analysis'] ?? null);
$behavioralInsight = decodeJsonValue($aiInsight['behavioral_analysis'] ?? null);

if ($visualInsight === []) {
    $visualInsight = array_filter([
        'dominant_emotion' => $candidate['dominant_emotion'] ?: ($biometric['facial_analysis']['dominant'] ?? null),
        'expression_summary' => $aiAnalysis['visual_analysis']['summary'] ?? null,
        'gesture_word_alignment' => $aiAnalysis['gesture_word_alignment'] ?? null,
    ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
}

if ($englishInsight === []) {
    $englishInsight = array_filter([
        'level' => $candidate['english_level'] ?? null,
        'score' => $candidate['english_score'] ?? null,
        'summary' => $aiAnalysis['english_analysis']['summary'] ?? null,
    ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
}

if ($behavioralInsight === []) {
    $behavioralInsight = array_filter([
        'spontaneity_analysis' => $aiEnrichment['spontaneity'] ?? ($aiAnalysis['spontaneity_analysis'] ?? null),
        'role_scores' => $aiEnrichment['role_scores'] ?? ($aiAnalysis['role_scores'] ?? null),
        'best_position' => $aiEnrichment['best_position'] ?? ($aiAnalysis['best_position'] ?? null),
        'transcript_analysis' => $aiEnrichment['transcript_analysis'] ?? ($aiAnalysis['transcript_analysis'] ?? null),
    ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
}

$roleRankings = is_array($behavioralInsight['role_scores'] ?? null) ? $behavioralInsight['role_scores'] : [];
$sentimentLabel = (string) (($biometric['sentiment']['label'] ?? $aiAnalysis['sentiment_analysis']['label'] ?? '') ?: '');
$sentimentSummary = (string) (($biometric['sentiment']['summary'] ?? $aiAnalysis['sentiment_analysis']['summary'] ?? '') ?: '');
$englishDisplay = trim(implode(' / ', array_filter([
    $candidate['english_level'] ?? null,
    isset($candidate['english_score']) && (int) $candidate['english_score'] > 0 ? ((string) $candidate['english_score']).' score' : null,
])));
$deliveryLabel = (string) (($behavioralInsight['spontaneity_analysis']['label'] ?? '') ?: '');
$deliverySummary = (string) (($behavioralInsight['spontaneity_analysis']['summary'] ?? '') ?: '');
$canRunAi = $transcript !== '' || $cvPreview !== '' || trim((string) ($candidate['cv_text'] ?? '')) !== '';

$technicalGroups = [
    'VPA' => 'Virtual Personal Assistant',
    'HVA' => 'Healthcare Virtual Assistant',
    'HOP' => 'Healthcare Operations',
    'MVA' => 'Marketing Virtual Assistant',
    'HRO' => 'HR Operations',
    'MGR' => 'Marketing Manager',
    'ACM' => 'Account Manager',
    'SDR' => 'Sales Development',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidate review | <?php echo htmlspecialchars($candidate['name']); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Martel:wght@700;800&family=Nunito+Sans:wght@400;600;700;800&family=Roboto:wght@400;500;700&display=swap');
        :root {
            --gsd-deep: #240f3a;
            --gsd-primary: #5a3988;
            --gsd-bright: #8c52ff;
            --gsd-soft: #f5efff;
            --gsd-border: #e6daf8;
            --gsd-text: #30194f;
            --gsd-muted: #6b5c88;
            --gsd-surface: #ffffff;
            --gsd-bg: #faf8ff;
        }

        body {
            font-family: 'Roboto', sans-serif;
            background:
                radial-gradient(circle at top left, rgba(193, 106, 255, 0.12), transparent 30%),
                radial-gradient(circle at bottom right, rgba(90, 57, 136, 0.08), transparent 35%),
                var(--gsd-bg);
            color: var(--gsd-text);
        }

        html {
            scroll-behavior: smooth;
        }

        h1, h2, h3 {
            font-family: 'Martel', serif;
        }

        .ui-label {
            font-family: 'Nunito Sans', sans-serif;
            letter-spacing: 0.25em;
        }

        .custom-scroll::-webkit-scrollbar {
            width: 8px;
        }

        .custom-scroll::-webkit-scrollbar-thumb {
            background: rgba(90, 57, 136, 0.25);
            border-radius: 999px;
        }

        .content-block {
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .content-block table,
        .content-block pre,
        .content-block code,
        .content-block iframe,
        .content-block img,
        .content-block video {
            max-width: 100%;
        }

        .content-block table,
        .content-block pre {
            display: block;
            overflow-x: auto;
        }

        .text-panel {
            border-radius: 1.1rem;
            background: var(--gsd-bg);
            border: 1px solid var(--gsd-border);
            overflow: hidden;
        }

        .text-panel-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--gsd-border);
            background: rgba(255, 255, 255, 0.7);
        }

        .text-panel-copy {
            max-height: 15rem;
            overflow-y: auto;
            padding: 1rem;
            scrollbar-width: thin;
            scrollbar-color: rgba(90, 57, 136, 0.28) transparent;
        }

        .text-panel-copy::-webkit-scrollbar {
            width: 8px;
        }

        .text-panel-copy::-webkit-scrollbar-thumb {
            background: rgba(90, 57, 136, 0.28);
            border-radius: 999px;
        }

        .text-panel-copy p,
        .text-panel-copy div,
        .text-panel-copy li {
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .info-value {
            word-break: break-word;
            overflow-wrap: anywhere;
        }

        .star-rating {
            direction: rtl;
            display: flex;
            justify-content: center;
            gap: 10px;
        }

        .star-rating input {
            display: none;
        }

        .star-rating label {
            color: #d6c9ee;
            cursor: pointer;
            font-size: 1.75rem;
            transition: transform 0.2s ease, color 0.2s ease;
        }

        .star-rating input:checked ~ label,
        .star-rating label:hover,
        .star-rating label:hover ~ label {
            color: #f59e0b;
            transform: translateY(-1px);
        }
    </style>
</head>
<body class="min-h-screen">
    <div class="mx-auto flex min-h-screen max-w-[1800px] flex-col xl:flex-row">
        <section class="flex min-h-[48vh] flex-1 flex-col border-b border-[var(--gsd-border)] bg-[var(--gsd-deep)] text-white xl:min-h-screen xl:border-b-0 xl:border-r">
            <div class="flex flex-col gap-4 px-6 py-5 md:px-10 lg:flex-row lg:items-center lg:justify-between">
                <div class="flex items-center gap-4">
                    <img src="../assets/images/iconGSD.png" alt="GSD" class="h-11 w-11 rounded-2xl bg-white/10 p-2 shadow-lg shadow-black/20">
                    <div>
                        <p class="ui-label text-[11px] text-white/65">Candidate review</p>
                        <h1 class="mt-1 text-3xl leading-none md:text-4xl"><?php echo htmlspecialchars($candidate['name']); ?></h1>
                    </div>
                </div>
                <div class="max-w-full self-start rounded-full border border-white/20 bg-white/10 px-4 py-2 text-left lg:self-auto lg:text-right">
                    <p class="ui-label text-[10px] text-white/65">Token</p>
                    <p class="break-all font-mono text-sm font-semibold"><?php echo htmlspecialchars((string) $candidate['token']); ?></p>
                </div>
            </div>

            <div class="flex flex-wrap gap-3 px-6 pb-5 md:px-10">
                <span class="rounded-full border border-white/15 bg-white/10 px-4 py-2 text-sm font-semibold">
                    <?php echo htmlspecialchars((string) ($candidate['position_interest'] ?: 'Candidate')); ?>
                </span>
                <span class="rounded-full border border-white/15 bg-white/10 px-4 py-2 text-sm font-semibold">
                    Status: <?php echo htmlspecialchars((string) ($candidate['processing_status'] ?: 'pending')); ?>
                </span>
                <span class="rounded-full border border-white/15 bg-white/10 px-4 py-2 text-sm font-semibold">
                    AI score: <?php echo number_format($analysisScore, 0); ?>%
                </span>
            </div>

            <div class="flex flex-1 items-center justify-center px-4 pb-6 md:px-8 xl:pb-8">
                <?php if ($videoUrl !== ''): ?>
                    <div class="w-full overflow-hidden rounded-[28px] border border-white/10 bg-black shadow-2xl shadow-black/25">
                        <video controls autoplay class="aspect-video w-full bg-black object-contain">
                            <source src="<?php echo htmlspecialchars($videoUrl); ?>" type="<?php echo htmlspecialchars($videoMime); ?>">
                            Your browser does not support embedded video.
                        </video>
                    </div>
                <?php else: ?>
                    <div class="flex aspect-video w-full flex-col items-center justify-center rounded-[28px] border border-dashed border-white/20 bg-white/5 px-8 text-center">
                        <div class="mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-white/10 text-2xl">
                            <i class="fa-solid fa-video-slash"></i>
                        </div>
                        <h2 class="text-2xl">Video pending</h2>
                        <p class="mt-3 max-w-xl text-sm text-white/70">
                            This candidate has not uploaded a processed or original video yet, or the file is not reachable from the current uploads base.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <aside class="flex w-full flex-col bg-transparent xl:max-w-[560px]">
            <div class="border-b border-[var(--gsd-border)] bg-white/80 px-6 py-5 backdrop-blur md:px-8">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="ui-label text-[11px] text-[var(--gsd-muted)]">Profile overview</p>
                        <h2 class="mt-2 text-3xl text-[var(--gsd-deep)]">Candidate intelligence</h2>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a href="#candidate-feedback" class="rounded-full border border-[var(--gsd-border)] bg-white px-4 py-2 text-sm font-semibold text-[var(--gsd-primary)] transition hover:bg-[var(--gsd-soft)]">
                            <i class="fa-regular fa-message mr-2"></i>Jump to feedback
                        </a>
                        <?php if ($cvUrl !== ''): ?>
                            <a href="<?php echo htmlspecialchars($cvUrl); ?>" target="_blank" rel="noreferrer" class="rounded-full border border-[var(--gsd-border)] bg-[var(--gsd-soft)] px-4 py-2 text-sm font-semibold text-[var(--gsd-primary)] transition hover:bg-white">
                                <i class="fa-regular fa-file-pdf mr-2"></i>Open CV
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="custom-scroll flex-1 overflow-y-auto px-6 py-6 md:px-8">
                <div class="space-y-6">
                    <section class="rounded-[28px] border border-[var(--gsd-border)] bg-[var(--gsd-surface)] p-6 shadow-[0_20px_60px_rgba(90,57,136,0.08)]">
                        <p class="ui-label text-[11px] text-[var(--gsd-muted)]">Key facts</p>
                        <div class="mt-5 grid gap-4 sm:grid-cols-2">
                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Email</p>
                                <p class="info-value mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) ($candidate['email'] ?: 'Not provided')); ?></p>
                            </div>
                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Phone</p>
                                <p class="info-value mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) ($candidate['phone'] ?: 'Not provided')); ?></p>
                            </div>
                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Professional title</p>
                                <p class="info-value mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) ($candidate['professional_title'] ?: 'Not provided')); ?></p>
                            </div>
                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Current notice period</p>
                                <p class="info-value mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) ($candidate['current_notice_period'] ?: 'Not provided')); ?></p>
                            </div>
                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Salary expectation</p>
                                <p class="info-value mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars(formatMoneyValue($candidate['salary_expectation'] ?? null)); ?></p>
                            </div>
                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Languages</p>
                                <p class="info-value mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) ($candidate['languages'] ?: 'Not provided')); ?></p>
                            </div>
                        </div>

                        <div class="mt-4 rounded-2xl bg-[var(--gsd-bg)] p-4">
                            <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Address</p>
                            <p class="info-value mt-2 text-sm font-semibold text-[var(--gsd-deep)]">
                                <?php
                                echo htmlspecialchars(trim(implode(', ', array_filter([
                                    $candidate['home_address'] ?? '',
                                    $candidate['city'] ?? '',
                                    $candidate['country'] ?? '',
                                    $candidate['postal_code'] ?? '',
                                ]))) ?: 'Not provided');
                                ?>
                            </p>
                            <?php if (! empty($candidate['linked_in_url'])): ?>
                                <a href="<?php echo htmlspecialchars((string) $candidate['linked_in_url']); ?>" target="_blank" rel="noreferrer" class="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-[var(--gsd-primary)] hover:underline">
                                    <i class="fa-brands fa-linkedin"></i>LinkedIn profile
                                </a>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="rounded-[28px] border border-[var(--gsd-border)] bg-white p-6">
                        <div class="flex items-start justify-between gap-4">
                            <div>
                                <p class="ui-label text-[11px] text-[var(--gsd-muted)]">AI review</p>
                                <h3 class="mt-2 text-2xl text-[var(--gsd-deep)]">Automated analysis</h3>
                            </div>
                            <div class="flex items-center gap-3">
                                <?php if ($canRunAi): ?>
                                    <button
                                        type="button"
                                        id="refreshAiBtn"
                                        data-token="<?php echo htmlspecialchars((string) $candidate['token']); ?>"
                                        class="rounded-full border border-[var(--gsd-border)] bg-white px-4 py-2 text-xs font-extrabold uppercase tracking-[0.18em] text-[var(--gsd-primary)] transition hover:bg-[var(--gsd-soft)]"
                                    >
                                        Refresh AI
                                    </button>
                                <?php endif; ?>
                                <div class="rounded-2xl bg-[var(--gsd-soft)] px-4 py-3 text-center">
                                    <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Score</p>
                                    <p class="mt-1 text-2xl font-black text-[var(--gsd-primary)]"><?php echo number_format($analysisScore, 0); ?>%</p>
                                </div>
                            </div>
                        </div>

                        <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Language</p>
                                <p class="mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars(strtoupper((string) ($candidate['spoken_language'] ?: 'n/a'))); ?></p>
                            </div>
                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Expression</p>
                                <p class="mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) (($visualInsight['dominant_emotion'] ?? $candidate['dominant_emotion'] ?? 'Not detected'))); ?></p>
                            </div>
                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">English</p>
                                <p class="mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars($englishDisplay !== '' ? $englishDisplay : (trim(implode(' / ', array_filter([$candidate['english_reading'] ?? '', $candidate['english_listening'] ?? '',]))) ?: 'Not rated')); ?></p>
                            </div>
                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Sentiment</p>
                                <p class="mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars($sentimentLabel !== '' ? $sentimentLabel : 'Pending'); ?></p>
                            </div>
                        </div>

                        <?php if ($aiInsight !== null): ?>
                            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                <div class="rounded-2xl border border-[var(--gsd-border)] p-4">
                                    <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Best position</p>
                                    <p class="mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) ($roleRankings[0]['label'] ?? $candidate['position_interest'] ?? 'Pending')); ?></p>
                                    <?php if (isset($roleRankings[0]['score'])): ?>
                                        <p class="mt-1 text-xs text-[var(--gsd-muted)]"><?php echo (int) $roleRankings[0]['score']; ?>/100 fit score</p>
                                    <?php endif; ?>
                                </div>
                                <div class="rounded-2xl border border-[var(--gsd-border)] p-4">
                                    <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Delivery</p>
                                    <p class="mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars($deliveryLabel !== '' ? $deliveryLabel : 'Pending'); ?></p>
                                    <p class="mt-1 text-xs text-[var(--gsd-muted)]"><?php echo htmlspecialchars($deliverySummary); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($sentimentSummary !== ''): ?>
                            <div class="mt-5 rounded-2xl border border-[var(--gsd-border)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Sentiment summary</p>
                                <p class="mt-2 text-sm text-[var(--gsd-deep)]"><?php echo htmlspecialchars($sentimentSummary); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if (! empty($candidate['match_reasoning'])): ?>
                            <div class="mt-5 text-panel">
                                <div class="text-panel-head">
                                    <div>
                                        <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Match reasoning</p>
                                        <p class="mt-1 text-xs text-[var(--gsd-muted)]">AI fit explanation for this candidate</p>
                                    </div>
                                </div>
                                <div class="text-panel-copy">
                                    <p class="content-block whitespace-pre-line text-sm leading-6 text-[var(--gsd-text)]"><?php echo htmlspecialchars((string) $candidate['match_reasoning']); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (! empty($candidate['ai_analysis'])): ?>
                            <div class="mt-5 text-panel">
                                <div class="text-panel-head">
                                    <div>
                                        <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">AI summary</p>
                                        <p class="mt-1 text-xs text-[var(--gsd-muted)]">Detailed structured analysis output</p>
                                    </div>
                                </div>
                                <div class="text-panel-copy prose prose-sm max-w-none prose-p:text-[var(--gsd-text)] prose-li:text-[var(--gsd-text)] prose-strong:text-[var(--gsd-deep)] prose-headings:text-[var(--gsd-deep)]">
                                    <div class="content-block"><?php echo (string) $candidate['ai_analysis']; ?></div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($visualInsight !== [] || $englishInsight !== [] || $behavioralInsight !== []): ?>
                            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                <?php if ($visualInsight !== []): ?>
                                    <div class="rounded-2xl border border-[var(--gsd-border)] p-4">
                                        <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Visual analysis</p>
                                        <div class="mt-2 max-h-32 overflow-y-auto text-sm font-semibold text-[var(--gsd-deep)]">
                                            <p class="content-block"><?php echo htmlspecialchars((string) ($visualInsight['expression_summary'] ?? $visualInsight['summary'] ?? 'Pending')); ?></p>
                                        </div>
                                        <?php if (! empty($visualInsight['gesture_word_alignment']['summary'] ?? null)): ?>
                                            <div class="mt-2 max-h-28 overflow-y-auto text-xs text-[var(--gsd-muted)]">
                                                <p class="content-block"><?php echo htmlspecialchars((string) $visualInsight['gesture_word_alignment']['summary']); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($englishInsight !== []): ?>
                                    <div class="rounded-2xl border border-[var(--gsd-border)] p-4">
                                        <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">English analysis</p>
                                        <p class="info-value mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) (($englishInsight['level'] ?? 'Pending').' / '.($englishInsight['score'] ?? '0'))); ?></p>
                                        <div class="mt-2 max-h-28 overflow-y-auto text-xs text-[var(--gsd-muted)]">
                                            <p class="content-block"><?php echo htmlspecialchars((string) ($englishInsight['summary'] ?? '')); ?></p>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <?php if ($behavioralInsight !== []): ?>
                                    <div class="rounded-2xl border border-[var(--gsd-border)] p-4 sm:col-span-2">
                                        <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Behavioral analysis</p>
                                        <div class="mt-2 max-h-32 overflow-y-auto text-sm font-semibold text-[var(--gsd-deep)]">
                                            <p class="content-block"><?php echo htmlspecialchars((string) ($behavioralInsight['transcript_analysis']['summary'] ?? $behavioralInsight['candidate_summary']['summary'] ?? 'Pending')); ?></p>
                                        </div>
                                        <?php if ($roleRankings !== []): ?>
                                            <div class="mt-3 grid gap-2 sm:grid-cols-3">
                                                <?php foreach (array_slice($roleRankings, 0, 3) as $roleRanking): ?>
                                                    <div class="rounded-2xl bg-[var(--gsd-bg)] px-4 py-3">
                                                        <p class="text-xs uppercase tracking-[0.14em] text-[var(--gsd-muted)]"><?php echo htmlspecialchars((string) ($roleRanking['code'] ?? 'Role')); ?></p>
                                                        <p class="mt-1 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) ($roleRanking['label'] ?? 'Pending')); ?></p>
                                                        <p class="mt-1 text-xs text-[var(--gsd-muted)]"><?php echo (int) ($roleRanking['score'] ?? 0); ?>/100</p>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($aiHighlights !== []): ?>
                            <div class="mt-5 grid gap-3 sm:grid-cols-2">
                                <?php foreach (array_slice($aiHighlights, 0, 8) as $highlight): ?>
                                    <div class="rounded-2xl border border-[var(--gsd-border)] p-4">
                                        <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]"><?php echo htmlspecialchars($highlight['label']); ?></p>
                                        <p class="mt-2 text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars($highlight['value']); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section class="rounded-[28px] border border-[var(--gsd-border)] bg-white p-6">
                        <p class="ui-label text-[11px] text-[var(--gsd-muted)]">Experience and education</p>
                        <div class="mt-5 grid gap-4">
                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Experience</p>
                                <p class="info-value mt-2 text-base font-bold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) ($experience['main_title'] ?? $candidate['professional_title'] ?? 'Not provided')); ?></p>
                                <p class="info-value mt-1 text-sm text-[var(--gsd-text)]"><?php echo htmlspecialchars((string) ($experience['main_company'] ?? 'Company not provided')); ?></p>
                                <div class="mt-3 max-h-40 overflow-y-auto rounded-2xl border border-[var(--gsd-border)] bg-white/70 p-3">
                                    <p class="content-block text-sm leading-6 text-[var(--gsd-text)]"><?php echo htmlspecialchars((string) ($experience['main_responsibilities'] ?? $candidate['cv_text_preview'] ?? 'Responsibilities were not extracted yet.')); ?></p>
                                </div>
                                <p class="mt-3 text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Total experience: <?php echo htmlspecialchars((string) ($candidate['years_total_experience'] ?: ($experience['exp_years'] ?? 'Not provided'))); ?></p>
                            </div>

                            <div class="rounded-2xl bg-[var(--gsd-bg)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Education</p>
                                <p class="mt-2 text-base font-bold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) ($education['main_degree'] ?? $candidate['highest_education'] ?? 'Not provided')); ?></p>
                                <p class="mt-1 text-sm text-[var(--gsd-text)]"><?php echo htmlspecialchars((string) ($education['main_institution'] ?? 'Institution not provided')); ?></p>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    <span class="rounded-full bg-white px-3 py-1 text-xs font-semibold text-[var(--gsd-primary)] ring-1 ring-[var(--gsd-border)]">
                                        Healthcare education relevance: <?php echo htmlspecialchars(boolLabel($candidate['is_education_healthcare_relevant'] ?? 0)); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-[28px] border border-[var(--gsd-border)] bg-white p-6">
                        <p class="ui-label text-[11px] text-[var(--gsd-muted)]">Skills and answers</p>
                        <div class="mt-5 flex flex-wrap gap-2">
                            <?php if ($skills === []): ?>
                                <span class="rounded-full bg-[var(--gsd-bg)] px-3 py-2 text-sm text-[var(--gsd-muted)]">No skills extracted yet</span>
                            <?php else: ?>
                                <?php foreach ($skills as $skill): ?>
                                    <span class="rounded-full bg-[var(--gsd-soft)] px-3 py-2 text-sm font-semibold text-[var(--gsd-primary)]"><?php echo htmlspecialchars($skill); ?></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <?php foreach ($technicalGroups as $groupKey => $groupLabel): ?>
                            <?php $groupAnswers = decodeJsonValue($answersAll[$groupKey] ?? []); ?>
                            <?php if ($groupAnswers === []): ?>
                                <?php continue; ?>
                            <?php endif; ?>
                            <div class="mt-5 rounded-2xl border border-[var(--gsd-border)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]"><?php echo htmlspecialchars($groupLabel); ?></p>
                                <div class="mt-3 space-y-2">
                                    <?php foreach ($groupAnswers as $question => $answer): ?>
                                        <div class="rounded-2xl bg-[var(--gsd-bg)] px-4 py-3">
                                            <p class="text-xs uppercase tracking-[0.14em] text-[var(--gsd-muted)]"><?php echo htmlspecialchars((string) $question); ?></p>
                                            <div class="mt-1 max-h-28 overflow-y-auto">
                                                <p class="content-block text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) $answer); ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php $personalityAnswers = decodeJsonValue($answersAll['personality'] ?? []); ?>
                        <?php if ($personalityAnswers !== []): ?>
                            <div class="mt-5 rounded-2xl border border-[var(--gsd-border)] p-4">
                                <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Personality</p>
                                <div class="mt-3 grid gap-2 sm:grid-cols-2">
                                    <?php foreach ($personalityAnswers as $question => $answer): ?>
                                        <div class="rounded-2xl bg-[var(--gsd-bg)] px-4 py-3">
                                            <p class="text-xs uppercase tracking-[0.14em] text-[var(--gsd-muted)]"><?php echo htmlspecialchars((string) $question); ?></p>
                                            <div class="mt-1 max-h-28 overflow-y-auto">
                                                <p class="content-block text-sm font-semibold text-[var(--gsd-deep)]"><?php echo htmlspecialchars((string) $answer); ?></p>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>

                    <?php if ($cvPreview !== '' || $transcript !== ''): ?>
                        <section class="rounded-[28px] border border-[var(--gsd-border)] bg-white p-6">
                            <p class="ui-label text-[11px] text-[var(--gsd-muted)]">Evidence</p>
                            <?php if ($cvPreview !== ''): ?>
                                <div class="mt-5 text-panel">
                                    <div class="text-panel-head">
                                        <div>
                                            <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">CV preview</p>
                                            <p class="mt-1 text-xs text-[var(--gsd-muted)]">Extracted content from the uploaded resume</p>
                                        </div>
                                    </div>
                                    <div class="text-panel-copy">
                                        <p class="content-block whitespace-pre-line text-sm leading-6 text-[var(--gsd-text)]"><?php echo htmlspecialchars($cvPreview); ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($transcript !== ''): ?>
                                <div class="mt-4 text-panel">
                                    <div class="text-panel-head">
                                        <div>
                                            <p class="text-xs uppercase tracking-[0.18em] text-[var(--gsd-muted)]">Video transcript</p>
                                            <p class="mt-1 text-xs text-[var(--gsd-muted)]">Captured speech evidence from the interview video</p>
                                        </div>
                                    </div>
                                    <div class="text-panel-copy">
                                        <p class="content-block whitespace-pre-line text-sm leading-6 text-[var(--gsd-text)]"><?php echo htmlspecialchars($transcript); ?></p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <section id="candidate-feedback" class="scroll-mt-24 rounded-[28px] border border-[var(--gsd-border)] bg-white p-6 shadow-[0_20px_60px_rgba(90,57,136,0.06)]">
                        <p class="ui-label text-[11px] text-[var(--gsd-muted)]">Evaluator feedback</p>
                        <h3 class="mt-2 text-2xl text-[var(--gsd-deep)]">Share your decision</h3>

                        <form id="candidateFeedbackForm" class="mt-5 space-y-4">
                            <input type="hidden" name="candidate_id" value="<?php echo htmlspecialchars((string) $candidate['id']); ?>">
                            <input type="hidden" name="client_token" value="<?php echo htmlspecialchars((string) $clientRef); ?>">

                            <div class="star-rating">
                                <input type="radio" id="s5" name="rating" value="5" required><label for="s5"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s4" name="rating" value="4"><label for="s4"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s3" name="rating" value="3"><label for="s3"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s2" name="rating" value="2"><label for="s2"><i class="fa-solid fa-star"></i></label>
                                <input type="radio" id="s1" name="rating" value="1"><label for="s1"><i class="fa-solid fa-star"></i></label>
                            </div>

                            <input
                                type="text"
                                name="evaluator"
                                value="<?php echo htmlspecialchars($preEvaluator); ?>"
                                required
                                placeholder="Evaluator name"
                                class="w-full rounded-2xl border border-[var(--gsd-border)] bg-[var(--gsd-bg)] px-4 py-3 text-sm text-[var(--gsd-deep)] outline-none transition focus:border-[var(--gsd-bright)]"
                            >

                            <textarea
                                name="comment"
                                placeholder="Notes and observations"
                                class="h-28 w-full rounded-2xl border border-[var(--gsd-border)] bg-[var(--gsd-bg)] px-4 py-3 text-sm text-[var(--gsd-deep)] outline-none transition focus:border-[var(--gsd-bright)]"
                            ></textarea>

                            <button
                                type="submit"
                                id="submitBtn"
                                class="w-full rounded-2xl bg-[var(--gsd-primary)] px-5 py-3 text-sm font-extrabold uppercase tracking-[0.2em] text-white transition hover:bg-[var(--gsd-bright)]"
                            >
                                Save feedback
                            </button>
                        </form>
                    </section>
                </div>
            </div>
        </aside>
    </div>

    <script>
        const refreshAiButton = document.getElementById('refreshAiBtn');

        async function refreshCandidateAi() {
            if (!refreshAiButton) {
                return;
            }

            const originalText = refreshAiButton.textContent;
            refreshAiButton.disabled = true;
            refreshAiButton.textContent = 'Refreshing...';

            try {
                const response = await fetch('../analyze-candidate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ token: refreshAiButton.dataset.token }),
                });

                const result = await response.json();

                if (!response.ok || result.status !== 'ok') {
                    throw new Error(result.message || 'AI refresh failed');
                }

                window.location.reload();
            } catch (error) {
                refreshAiButton.disabled = false;
                refreshAiButton.textContent = originalText;
                alert(error.message);
            }
        }

        if (refreshAiButton) {
            refreshAiButton.addEventListener('click', refreshCandidateAi);
            <?php if ($aiInsight === null && $canRunAi): ?>
            refreshCandidateAi().catch(() => {});
            <?php endif; ?>
        }

        document.getElementById('candidateFeedbackForm').addEventListener('submit', async function (event) {
            event.preventDefault();

            const button = document.getElementById('submitBtn');
            const originalText = button.textContent;
            button.textContent = 'Saving...';
            button.disabled = true;

            try {
                const response = await fetch('../../viewer/api/save_feedback_candidate.php', {
                    method: 'POST',
                    body: new FormData(this),
                });

                if (!response.ok) {
                    throw new Error('Feedback service returned ' + response.status);
                }

                const result = await response.json();

                if (result.status !== 'success') {
                    throw new Error(result.message || 'Feedback could not be saved');
                }

                this.innerHTML = `
                    <div class="rounded-3xl border border-[var(--gsd-border)] bg-[var(--gsd-bg)] px-6 py-10 text-center">
                        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 text-2xl text-emerald-600">
                            <i class="fa-solid fa-check"></i>
                        </div>
                        <h4 class="mt-5 text-xl font-black text-[var(--gsd-deep)]">Feedback saved</h4>
                        <p class="mt-2 text-sm text-[var(--gsd-muted)]">Your evaluation is now attached to this candidate review.</p>
                    </div>
                `;
            } catch (error) {
                console.error(error);
                button.textContent = originalText;
                button.disabled = false;
                alert(error.message);
            }
        });
    </script>
</body>
</html>
