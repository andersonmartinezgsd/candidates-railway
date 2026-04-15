<?php
/**
 * GSD — Review Final Screen
 * ═══════════════════════════════════════════════════════════════
 * Shows candidate the recorded video and application data
 * Options: Re-record (go back to index) or Confirm & Submit (save + email)
 */
require_once __DIR__.'/db.php';
require_once __DIR__.'/../config/runtime.php';

function reviewDecodeJsonValue(mixed $value): array
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

function reviewNormalizeList(mixed $value): array
{
    $items = is_array($value) ? $value : (preg_split('/[,;\n]+/', (string) $value) ?: []);

    return array_values(array_filter(array_map(static function (mixed $item): string {
        return trim((string) $item);
    }, $items), static fn (string $item): bool => $item !== ''));
}

function reviewPathToPublicUrl(?string $path): string
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

function reviewNonEmptyString(?string $value): ?string
{
    $value = trim((string) $value);

    return $value !== '' ? $value : null;
}

$_token = $_GET['token'] ?? '';
$_baseUrl = rtrim((function (): string {
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

    return $scheme.'://'.$host.rtrim($path, '/').'/';
})(), '/').'/';

// Basic sanitization
if (!preg_match('/^GSD-[A-Z0-9-]{6,40}$/', $_token)) {
    die('Invalid token');
}

$_serverReviewData = [];

try {
    $pdo = getDB();
    $candidate = gsdFindCandidateByToken(
        $pdo,
        $_token,
        array_values(array_filter([gsdOfficialCandidateTable($pdo), gsdDraftCandidateTable($pdo)]))
    );

    if (is_array($candidate)) {
        $aiInsightStmt = $pdo->prepare('SELECT * FROM gsd_candidate_ai_insights WHERE candidate_token = ? ORDER BY id DESC LIMIT 1');
        $aiInsightStmt->execute([$_token]);
        $aiInsight = $aiInsightStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $biometric = reviewDecodeJsonValue($candidate['biometric_json'] ?? null);
        $visualInsight = reviewDecodeJsonValue($aiInsight['visual_analysis'] ?? null);
        $englishInsight = reviewDecodeJsonValue($aiInsight['english_analysis'] ?? null);
        $behavioralInsight = reviewDecodeJsonValue($aiInsight['behavioral_analysis'] ?? null);
        $aiEnrichment = reviewDecodeJsonValue($biometric['_ai_enrichment'] ?? null);
        $skills = reviewNormalizeList(reviewDecodeJsonValue($candidate['skills_json'] ?? null));
        $roleScores = is_array($behavioralInsight['role_scores'] ?? null) ? $behavioralInsight['role_scores'] : (is_array($aiEnrichment['role_scores'] ?? null) ? $aiEnrichment['role_scores'] : []);
        $spontaneity = is_array($behavioralInsight['spontaneity_analysis'] ?? null) ? $behavioralInsight['spontaneity_analysis'] : (is_array($aiEnrichment['spontaneity'] ?? null) ? $aiEnrichment['spontaneity'] : []);
        $transcriptAnalysis = is_array($behavioralInsight['transcript_analysis'] ?? null) ? $behavioralInsight['transcript_analysis'] : (is_array($aiEnrichment['transcript_analysis'] ?? null) ? $aiEnrichment['transcript_analysis'] : []);
        $bestRole = is_array($roleScores[0] ?? null) ? $roleScores[0] : [];
        $overallScore = (float) ($aiInsight['overall_score'] ?? $candidate['match_score'] ?? 0);
        $matchReasoning = reviewNonEmptyString($candidate['match_reasoning'] ?? null);
        $transcript = reviewNonEmptyString($candidate['transcript'] ?? null);
        $cvPreview = reviewNonEmptyString($candidate['cv_text_preview'] ?? null);
        $summary = reviewNonEmptyString($candidate['professional_summary'] ?? null)
            ?? ($cvPreview ? mb_substr($cvPreview, 0, 280) : null);

        $_serverReviewData = array_filter([
            'name' => reviewNonEmptyString($candidate['name'] ?? null),
            'email' => reviewNonEmptyString($candidate['email'] ?? null),
            'phone' => reviewNonEmptyString($candidate['phone'] ?? null),
            'linkedin' => reviewNonEmptyString($candidate['linked_in_url'] ?? null),
            'country' => reviewNonEmptyString($candidate['country'] ?? null),
            'city' => reviewNonEmptyString($candidate['city'] ?? null),
            'salary' => reviewNonEmptyString(isset($candidate['salary_expectation']) ? (string) $candidate['salary_expectation'] : null),
            'availability' => reviewNonEmptyString($candidate['current_notice_period'] ?? null),
            'summary' => $summary,
            'skills' => $skills !== [] ? implode(', ', $skills) : null,
            'education_level' => reviewNonEmptyString($candidate['highest_education'] ?? null),
            'exp_years' => reviewNonEmptyString(isset($candidate['years_total_experience']) ? (string) $candidate['years_total_experience'] : null),
            'job_title' => reviewNonEmptyString($candidate['professional_title'] ?? null),
            'position' => reviewNonEmptyString($candidate['position_interest'] ?? null),
            'referral' => reviewNonEmptyString($candidate['referrer'] ?? null),
            'languages' => reviewNonEmptyString($candidate['languages'] ?? null),
            'transcript' => $transcript,
            'overallScore' => $overallScore > 0 ? (string) round($overallScore) : null,
            'score' => $overallScore > 0 ? (string) round($overallScore) : null,
            'bestPosition' => reviewNonEmptyString($behavioralInsight['best_position'] ?? null) ?? reviewNonEmptyString($aiEnrichment['best_position'] ?? null) ?? reviewNonEmptyString($candidate['position_interest'] ?? null),
            'bestPositionCode' => reviewNonEmptyString($bestRole['code'] ?? null),
            'englishLevel' => reviewNonEmptyString($candidate['english_level'] ?? null) ?? reviewNonEmptyString($englishInsight['level'] ?? null),
            'englishScore' => isset($candidate['english_score']) ? (string) $candidate['english_score'] : null,
            'dominantEmotion' => reviewNonEmptyString($candidate['dominant_emotion'] ?? null) ?? reviewNonEmptyString($visualInsight['dominant_emotion'] ?? null),
            'sentimentLabel' => reviewNonEmptyString($biometric['sentiment']['label'] ?? null),
            'spontaneityLabel' => reviewNonEmptyString($spontaneity['label'] ?? null),
            'spontaneitySummary' => reviewNonEmptyString($spontaneity['summary'] ?? null),
            'gestureSummary' => reviewNonEmptyString($visualInsight['gesture_word_alignment']['summary'] ?? null),
            'matchReasoning' => $matchReasoning,
            'videoUrl' => reviewPathToPublicUrl($candidate['video_processed_path'] ?: $candidate['video_original_path'] ?: ''),
            'roleScores' => $roleScores,
            'aiProfile' => [
                'gesture_word_alignment' => $visualInsight['gesture_word_alignment'] ?? [],
                'spontaneity_analysis' => $spontaneity,
                'transcript_analysis' => $transcriptAnalysis,
            ],
            'videoAnalysis' => [
                'combined_score' => $overallScore > 0 ? (int) round($overallScore) : 0,
                'sentiment' => [
                    'label' => reviewNonEmptyString($biometric['sentiment']['label'] ?? null) ?? 'Pending',
                ],
                'language' => reviewNonEmptyString($transcriptAnalysis['language_code'] ?? null) ?? reviewNonEmptyString($candidate['spoken_language'] ?? null) ?? 'en',
                'facial_analysis' => [
                    'dominant' => reviewNonEmptyString($candidate['dominant_emotion'] ?? null) ?? reviewNonEmptyString($visualInsight['dominant_emotion'] ?? null) ?? 'Pending',
                ],
                'transcript' => $transcript ?? '',
            ],
        ], static fn (mixed $value): bool => ! ($value === null || $value === '' || $value === []));
    }
} catch (Throwable) {
    $_serverReviewData = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>GSD Associates — Review Application</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<style>
:root{--g:#5A3988;--gl:#8C52FF;--gd:#3d2460;}
*{font-family:'Inter',system-ui,sans-serif;box-sizing:border-box;}
body{background:linear-gradient(160deg,#0f172a,#1e1035);min-height:100vh;}
.confirm-card{
  background:linear-gradient(160deg,#0f172a,#1e1035);
  border-radius:20px;overflow:hidden;
  border:1px solid rgba(140,82,255,.2);
  box-shadow:0 30px 80px rgba(0,0,0,.6);
  font-family:'Inter',sans-serif;
}
.header-gradient{
  background:linear-gradient(135deg,#5A3988,#8C52FF);
  padding:1.75rem;text-align:center;
}
.vid-wrap{aspect-ratio:16/9;background:#000;border-radius:12px;overflow:hidden;border:1px solid rgba(140,82,255,.2);}
.stat-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:.45rem;}
.stat-box{
  background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);
  border-radius:9px;padding:.65rem;text-align:center;
}
.btn-primary{
  flex:2;background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;font-weight:900;
  padding:.85rem;border-radius:12px;font-size:.78rem;cursor:pointer;
  text-transform:uppercase;letter-spacing:.08em;font-family:'Inter',sans-serif;
  border:none;box-shadow:0 4px 20px rgba(34,197,94,.3);transition:.2s;
}
.btn-primary:hover{transform:translateY(-2px)}
.btn-primary:disabled{background:#374151;cursor:not-allowed;transform:none;}
.btn-secondary{
  flex:1;background:rgba(255,255,255,.07);border:1.5px solid rgba(255,255,255,.12);
  color:rgba(255,255,255,.65);font-weight:700;padding:.85rem;border-radius:12px;
  font-size:.73rem;cursor:pointer;text-transform:uppercase;letter-spacing:.06em;
  font-family:'Inter',sans-serif;transition:.2s;
}
.btn-secondary:hover{background:rgba(255,255,255,.13)}
.tokensection{
  background:rgba(140,82,255,.1);border:1px solid rgba(140,82,255,.25);
  border-radius:12px;padding:1rem;text-align:center;
}
.cv-summary{
  background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);
  border-radius:12px;padding:.9rem;max-height:400px;overflow-y:auto;
}
.cv-field{
  margin-bottom:.45rem;padding:.5rem .7rem;background:rgba(255,255,255,.03);
  border-radius:7px;border-left:3px solid rgba(140,82,255,.3);
}
.cv-label{
  color:rgba(255,255,255,.28);font-size:.53rem;font-weight:800;
  text-transform:uppercase;letter-spacing:.07em;
}
.cv-value{
  color:rgba(255,255,255,.72);font-size:.68rem;line-height:1.4;margin-top:2px;
  max-height:2.5rem;overflow:hidden;
}
.transcript-box{
  background:rgba(0,0,0,.3);border-radius:9px;padding:.75rem;
  border:1px solid rgba(255,255,255,.06);
}
.review-grid{
  padding:1.5rem;display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;
}
.section-label{
  color:rgba(255,255,255,.3);font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;margin-bottom:.6rem;
}
.panel-shell{
  background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.06);
  border-radius:12px;
  overflow:hidden;
}
.panel-header{
  display:flex;align-items:center;justify-content:space-between;gap:.75rem;
  padding:.85rem 1rem;border-bottom:1px solid rgba(255,255,255,.06);
}
.panel-title{
  color:#fff;font-size:.72rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;
}
.panel-subtitle{
  color:rgba(255,255,255,.38);font-size:.58rem;text-transform:uppercase;letter-spacing:.08em;
}
.bounded-copy{
  max-height:12rem;overflow:auto;padding:1rem;
  scrollbar-width:thin;scrollbar-color:rgba(140,82,255,.35) transparent;
}
.bounded-copy::-webkit-scrollbar,
.cv-summary::-webkit-scrollbar{
  width:8px;
}
.bounded-copy::-webkit-scrollbar-thumb,
.cv-summary::-webkit-scrollbar-thumb{
  background:rgba(140,82,255,.3);border-radius:999px;
}
.copy-text{
  color:rgba(255,255,255,.72);font-size:.7rem;line-height:1.65;
  white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;
}
.ai-grid{
  display:grid;gap:.75rem;
}
.ai-item{
  background:rgba(255,255,255,.03);
  border:1px solid rgba(255,255,255,.06);
  border-radius:10px;padding:.75rem .85rem;
}
.ai-item-label{
  color:rgba(255,255,255,.34);font-size:.56rem;font-weight:800;
  text-transform:uppercase;letter-spacing:.08em;
}
.ai-item-value{
  color:rgba(255,255,255,.78);font-size:.68rem;line-height:1.55;
  margin-top:.3rem;word-break:break-word;overflow-wrap:anywhere;
}
.list-clean{
  margin:.45rem 0 0;padding-left:1rem;
}
.list-clean li{
  color:rgba(255,255,255,.72);font-size:.67rem;line-height:1.5;
  word-break:break-word;overflow-wrap:anywhere;
}
.transcript-label{
  color:rgba(255,255,255,.28);font-size:.57rem;font-weight:800;
  text-transform:uppercase;margin-bottom:.35rem;
}
.transcript-text{
  color:rgba(255,255,255,.6);font-size:.68rem;font-style:italic;line-height:1.5;
}
.spinner{
  display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);
  border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;
}
@keyframes spin{to{transform:rotate(360deg)}}
@media (max-width: 900px){
  .review-grid{grid-template-columns:1fr;padding:1rem;}
}
.review-actions{
  padding:0 1.5rem 1.5rem;display:flex;flex-direction:column-reverse;gap:.75rem;
}
@media (min-width: 640px){
  .review-actions{flex-direction:row;}
}
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">

<div id="confirm-screen" class="confirm-card w-full max-w-4xl">
  
  <!-- Header -->
  <div class="header-gradient">
    <div style="font-size:2.5rem;margin-bottom:.6rem;">🎉</div>
    <h2 style="color:#fff;font-size:1.25rem;font-weight:900;margin:0 0 .35rem;letter-spacing:-.02em;">Almost Done, <span id="candidate-name">Candidate</span>!</h2>
    <p style="color:rgba(255,255,255,.65);font-size:.72rem;text-transform:uppercase;letter-spacing:.1em;font-weight:600;">Review your application before final submission</p>
  </div>

  <!-- Content -->
  <div class="review-grid">
    
    <!-- Left: Video -->
    <div>
      <p class="section-label">📹 Interview Recording</p>
      <div class="vid-wrap" id="video-container">
        <div style="height:100%;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.3);font-size:.75rem;">Loading video...</div>
      </div>
      
      <div class="stat-grid" style="margin-bottom:.75rem;margin-top:.75rem;" id="stats-grid">
        <!-- Stats populated by JS -->
      </div>

      <div id="transcript-section" class="panel-shell" style="display:none;">
        <div class="panel-header">
          <div>
            <p class="panel-title">Transcript</p>
            <p class="panel-subtitle">Recorded candidate narration</p>
          </div>
        </div>
        <div class="bounded-copy">
          <p class="copy-text transcript-text" id="transcript-text"></p>
        </div>
      </div>

      <div id="ai-section" class="panel-shell" style="display:none;margin-top:.75rem;">
        <div class="panel-header">
          <div>
            <p class="panel-title">ATS-HR AI Analysis</p>
            <p class="panel-subtitle">Structured fit and delivery review</p>
          </div>
          <button id="btn-refresh-ai" type="button" onclick="refreshAiProfile()" style="background:rgba(140,82,255,.16);border:1px solid rgba(140,82,255,.3);color:#c4b5fd;border-radius:999px;padding:.35rem .75rem;font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;cursor:pointer;">
            Refresh AI
          </button>
        </div>
        <div class="bounded-copy">
          <div id="ai-summary"></div>
        </div>
      </div>
    </div>

    <!-- Right: Application Summary -->
    <div>
      <p class="section-label">📋 Application Summary</p>
      <div class="cv-summary" id="cv-summary">
        <!-- Populated by JS from localStorage -->
      </div>
    </div>
  </div>

  <!-- Token Section -->
  <div class="tokensection" style="margin:0 1.5rem 1.25rem;">
    <p style="color:rgba(255,255,255,.3);font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;margin-bottom:.4rem;">🔖 Your Application Token</p>
    <p style="color:#c4b5fd;font-family:monospace;font-size:1rem;font-weight:900;letter-spacing:.06em;" id="display-token"><?php echo htmlspecialchars($_token); ?></p>
    <p style="color:rgba(255,255,255,.22);font-size:.6rem;margin-top:.3rem;">Screenshot this — use it to resume at any time</p>
  </div>

  <!-- Action Buttons -->
  <div class="review-actions">
    <button onclick="goToReRecord()" class="btn-secondary">← Re-record</button>
    <button id="btn-final-confirm" onclick="confirmAndSubmit()" class="btn-primary">✅ Confirm & Submit</button>
  </div>

</div>

<script>
const TOKEN = '<?php echo $_token; ?>';
const GSD_BASE_URL = '<?php echo htmlspecialchars($_baseUrl, ENT_QUOTES); ?>';
const BASE_URL = GSD_BASE_URL;
const SERVER_APP_DATA = <?php echo json_encode($_serverReviewData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};

// Load data from localStorage (set by video-step7.js before redirect)
let appData = {};
try {
  appData = JSON.parse(localStorage.getItem('gsd_app_data') || '{}');
} catch(e) { appData = {}; }

function hasMeaningfulValue(value) {
  if (Array.isArray(value)) return value.length > 0;
  if (value && typeof value === 'object') return Object.keys(value).length > 0;
  return !(value === null || value === undefined || value === '');
}

function mergeReviewData(serverData, clientData) {
  const merged = { ...(serverData || {}) };
  for (const [key, value] of Object.entries(clientData || {})) {
    if (hasMeaningfulValue(value)) {
      merged[key] = value;
    }
  }
  return merged;
}

appData = mergeReviewData(SERVER_APP_DATA, appData);

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function renderReview() {
document.getElementById('candidate-name').textContent = (appData.name || 'Candidate').split(' ')[0];
document.getElementById('display-token').textContent = TOKEN;

const videoContainer = document.getElementById('video-container');
if (appData.videoUrl) {
  videoContainer.innerHTML = `<video src="${appData.videoUrl}" controls style="width:100%;height:100%;object-fit:cover;"></video>`;
} else {
  videoContainer.innerHTML = '<div style="height:100%;display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.3);font-size:.75rem;">No video available</div>';
}

const overallScore = appData.overallScore || appData.score || '--';
const numericScore = parseInt(overallScore, 10);
const scoreColor = numericScore >= 70 ? '#4ade80' : numericScore >= 40 ? '#fbbf24' : '#f87171';

const stats = [
  ['Overall', `${overallScore}%`, scoreColor],
  ['Best Role', appData.bestPositionCode || appData.bestPosition || '--', '#c084fc'],
  ['English', appData.englishLevel || appData.languageLabel || '--', '#60a5fa'],
  ['Expression', appData.dominantEmotion || 'N/A', '#34d399'],
  ['Sentiment', appData.sentimentLabel || '--', '#f9a8d4'],
  ['Delivery', appData.spontaneityLabel || '--', '#f59e0b']
];

document.getElementById('stats-grid').innerHTML = stats.map(([l,v,c]) =>
  `<div class="stat-box"><div style="color:${c};font-size:.95rem;font-weight:900;">${v}</div><div style="color:rgba(255,255,255,.3);font-size:.56rem;text-transform:uppercase;letter-spacing:.06em;margin-top:2px;">${l}</div></div>`
).join('');

if (appData.transcript) {
  document.getElementById('transcript-section').style.display = 'block';
  document.getElementById('transcript-text').textContent = appData.transcript;
}

renderAiSummary();

const cvFields = [
  ['Name', appData.name], ['Email', appData.email], ['Phone', appData.phone],
  ['LinkedIn', appData.linkedin], ['Country', appData.country], ['City', appData.city],
  ['Salary', appData.salary], ['Availability', appData.availability],
  ['Summary', appData.summary], ['Skills', appData.skills],
  ['Education', appData.education_level], ['Degree', appData.degree],
  ['Institution', appData.institution], ['Exp. Years', appData.exp_years],
  ['Company', appData.company], ['Job Title', appData.job_title],
  ['Position', appData.position], ['Referral', appData.referral],
  ['Languages', appData.languages]
];

document.getElementById('cv-summary').innerHTML = cvFields
  .filter(([lbl, val]) => val)
  .map(([lbl, val]) => `
    <div class="cv-field">
      <div class="cv-label">${escapeHtml(lbl)}</div>
      <div class="cv-value" style="white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;max-height:${String(val).length > 160 ? '7rem' : 'none'};overflow-y:${String(val).length > 160 ? 'auto' : 'visible'};">${escapeHtml(val)}</div>
    </div>
  `).join('') || '<p style="color:rgba(255,255,255,.4);font-size:.7rem;">No application data available</p>';
}

function renderAiSummary() {
  const aiSection = document.getElementById('ai-section');
  const aiSummary = document.getElementById('ai-summary');
  const roleScores = Array.isArray(appData.roleScores) ? appData.roleScores.slice(0, 3) : [];
  const aiProfile = appData.aiProfile || null;

  if (!aiProfile && !appData.matchReasoning && roleScores.length === 0) {
    aiSection.style.display = 'none';
    return;
  }

  aiSection.style.display = 'block';
  aiSummary.innerHTML = `
    <div class="ai-grid">
      <div class="ai-item">
        <div class="ai-item-label">Best position</div>
        <div class="ai-item-value">${escapeHtml(appData.bestPosition || 'Pending')} ${appData.bestPositionCode ? `(${escapeHtml(appData.bestPositionCode)})` : ''}</div>
      </div>
      <div class="ai-item">
        <div class="ai-item-label">Match reasoning</div>
        <div class="ai-item-value">${escapeHtml(appData.matchReasoning || 'Pending')}</div>
      </div>
      <div class="ai-item">
        <div class="ai-item-label">Gesture vs words</div>
        <div class="ai-item-value">${escapeHtml(appData.gestureSummary || aiProfile?.gesture_word_alignment?.summary || 'Pending')}</div>
      </div>
      <div class="ai-item">
        <div class="ai-item-label">Delivery style</div>
        <div class="ai-item-value">${escapeHtml(appData.spontaneitySummary || aiProfile?.spontaneity_analysis?.summary || 'Pending')}</div>
      </div>
      ${roleScores.length ? `<div class="ai-item"><div class="ai-item-label">Top role scores</div><ul class="list-clean">${roleScores.map(role => `<li>${escapeHtml(role.label || role.code || 'Role')}: ${escapeHtml(role.score || 0)}/100</li>`).join('')}</ul></div>` : ''}
    </div>
  `;
}

async function refreshAiProfile() {
  const button = document.getElementById('btn-refresh-ai');
  button.disabled = true;
  button.textContent = 'Refreshing...';

  try {
    const response = await fetch(BASE_URL + 'analyze-candidate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: TOKEN })
    });
    const result = await response.json();
    if (!response.ok || result.status !== 'ok') {
      throw new Error(result.message || 'AI refresh failed');
    }

    const analysis = result.analysis || {};
    appData.aiProfile = analysis;
    appData.overallScore = analysis.overall_score || appData.overallScore || '';
    appData.bestPosition = analysis.best_position || appData.bestPosition || '';
    appData.bestPositionCode = analysis.best_position_code || appData.bestPositionCode || '';
    appData.matchReasoning = analysis.match_reasoning || appData.matchReasoning || '';
    appData.spontaneityLabel = analysis.spontaneity_analysis?.label || appData.spontaneityLabel || '';
    appData.spontaneitySummary = analysis.spontaneity_analysis?.summary || appData.spontaneitySummary || '';
    appData.englishLevel = analysis.english_level || appData.englishLevel || '';
    appData.englishScore = analysis.english_score || appData.englishScore || '';
    appData.gestureSummary = analysis.gesture_word_alignment?.summary || appData.gestureSummary || '';
    appData.roleScores = analysis.role_scores || appData.roleScores || [];
    appData.dominantEmotion = analysis.visual_analysis?.dominant_emotion || appData.dominantEmotion || '';
    localStorage.setItem('gsd_app_data', JSON.stringify(appData));
    renderReview();
  } catch (error) {
    alert('AI refresh error: ' + error.message);
  } finally {
    button.disabled = false;
    button.textContent = 'Refresh AI';
  }
}

renderReview();
if (!appData.aiProfile && TOKEN) {
  refreshAiProfile().catch(() => {});
}

// Re-record: go back to index with token
function goToReRecord() {
  // Save token to sessionStorage for the intake shell to resume the flow.
  sessionStorage.setItem('gsd_resume_token', TOKEN);
  window.location.href = BASE_URL + 'index.php';
}

// Confirm & Submit
async function confirmAndSubmit() {
  const btn = document.getElementById('btn-final-confirm');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner mr-2"></span> Sending...';

  try {
    // Call notify-recruitment.php
    const response = await fetch(BASE_URL + 'notify-recruitment.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        token: TOKEN,
        email: appData.email || '',
        name: appData.name || '',
        position: appData.position || '',
        candidate_url: BASE_URL + 'views/new-candidate.php?token=' + TOKEN,
        video_analysis: appData.videoAnalysis || {}
      })
    });

    const result = await response.json().catch(() => ({}));
    if (!response.ok || result.status !== 'ok' || result.webhook?.success === false) {
      throw new Error(result.message || 'Recruitment notification could not be completed.');
    }

    // Show success
    showFinalSuccess();
    
    // Clear localStorage
    localStorage.removeItem('gsd_app_data');
    
  } catch(err) {
    btn.disabled = false;
    btn.innerHTML = '❌ Error - Try Again';
    alert('Error submitting: ' + err.message);
  }
}

/* ════ REEMPLAZA LA FUNCIÓN showFinalSuccess CON ESTA ════ */

function showFinalSuccess() {
  const el = document.getElementById('confirm-screen');
  const applicantName = (appData?.name || 'Candidate').trim() || 'Candidate';
  
  // WhatsApp completion handoff
  const BOT_PHONE = "15102143287";
  const reviewUrl = `${BASE_URL}views/new-candidate.php?token=${encodeURIComponent(TOKEN)}`;
  const WA_MESSAGE = encodeURIComponent(
    `Hello GSD Associates team,\n\nI have completed my application and I am sharing my review link below for follow-up.\n\nCandidate: ${applicantName}\nReview link: ${reviewUrl}\n\nThank you.`
  );
  const waUrl = `https://wa.me/${BOT_PHONE}?text=${WA_MESSAGE}`;

  el.innerHTML = `
    <style>
      @keyframes pop { from{transform:scale(.2);opacity:0} to{transform:scale(1);opacity:1} }
      @keyframes glow { 0%,100%{box-shadow:0 0 0 0 rgba(34,197,94,.4)} 50%{box-shadow:0 0 0 22px rgba(34,197,94,0)} }
      .btn-wa {
        display:inline-flex;align-items:center;gap:.5rem;background:#25D366;color:#fff;
        text-decoration:none;padding:.85rem 1.75rem;border-radius:12px;font-weight:800;
        font-size:.78rem;text-transform:uppercase;letter-spacing:.08em;
        font-family:'Inter',sans-serif;box-shadow:0 4px 20px rgba(37,211,102,.4);
        transition: .3s; margin-top: 10px;
      }
      .btn-wa:hover { transform: translateY(-3px); background: #20ba5a; }
      .panel-wrap {
        max-width: 560px;
        margin: 0 auto;
        padding: 2.25rem 2rem;
        border-radius: 28px;
        background: linear-gradient(145deg, rgba(255,255,255,.1), rgba(140,82,255,.12));
        border: 1px solid rgba(255,255,255,.18);
        box-shadow: 0 25px 80px rgba(36,15,58,.38);
        backdrop-filter: blur(10px);
      }
      .eyebrow {
        color: rgba(216,180,254,.92);
        font-size: .72rem;
        font-weight: 900;
        letter-spacing: .28em;
        text-transform: uppercase;
        margin-bottom: .85rem;
      }
      .meta-pill {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:.45rem;
        padding:.55rem .95rem;
        border-radius:999px;
        background: rgba(255,255,255,.08);
        border: 1px solid rgba(255,255,255,.12);
        color: rgba(255,255,255,.78);
        font-size:.75rem;
        font-weight:800;
        letter-spacing:.08em;
        text-transform:uppercase;
      }
      .review-link {
        color: rgba(255,255,255,.88);
        text-decoration:none;
        font-size:.78rem;
        font-weight:800;
        letter-spacing:.08em;
        text-transform:uppercase;
        border-bottom:1px solid rgba(255,255,255,.16);
        padding-bottom:.18rem;
      }
    </style>
    <div style="padding:4rem 1.5rem;text-align:center;">
      <div class="panel-wrap">
        <div style="width:88px;height:88px;background:linear-gradient(135deg,#22c55e,#16a34a);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 1.75rem;animation:pop .5s cubic-bezier(.175,.885,.32,1.275),glow 2s 1s infinite;">
          <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
        </div>
        <div class="eyebrow">GSD Associates</div>
        <h2 style="color:#fff;font-size:2rem;font-weight:900;margin:0 0 .75rem;letter-spacing:-.04em;">Application Completed</h2>
        <p style="color:rgba(255,255,255,0.68);font-size:0.92rem;line-height:1.8;max-width:460px;margin:0 auto .9rem;">
          Thank you, <span style="color:#fff;font-weight:800;">${applicantName}</span>. Your application has been successfully submitted and your candidate review is ready for the final handoff.
        </p>
        <p style="color:rgba(255,255,255,0.62);font-size:0.88rem;line-height:1.8;max-width:460px;margin:0 auto 1.4rem;">
          Continue on WhatsApp to share your review link and keep the process moving with the GSD Associates team.
        </p>
        <div style="display:flex;flex-wrap:wrap;justify-content:center;gap:.75rem;margin:0 0 1.5rem;">
          <span class="meta-pill">Final Step Ready</span>
          <span class="meta-pill">Support: +1 (510) 214-3287</span>
        </div>

        <!-- BOTÓN DE WHATSAPP -->
        <a href="${waUrl}" class="btn-wa">
          <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
          Send Review Link on WhatsApp
        </a>

        <div style="margin-top:1.2rem;">
          <a href="${BASE_URL}views/new-candidate.php?token=${TOKEN}" target="_blank" class="review-link">
            View Candidate Review
          </a>
        </div>
      </div>
    </div>
  `;
}
</script>

</body>
</html>
