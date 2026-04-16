<?php
require_once __DIR__.'/../db.php';
require_once dirname(__DIR__, 2).'/config/runtime.php';

$token = trim((string) ($_GET['token'] ?? ''));
$preEvaluator = trim((string) ($_GET['evaluator'] ?? ''));
$clientRef = trim((string) ($_GET['client'] ?? 'direct-link'));

if ($token === '') {
    die('Invalid access.');
}

$pdo = getDB();
$candidate = gsdFindCandidateByToken(
    $pdo,
    $token,
    array_values(array_filter([gsdOfficialCandidateTable($pdo), gsdDraftCandidateTable($pdo)]))
);

if (! is_array($candidate)) {
    die('Candidate not found.');
}

$streamBaseUrl = 'stream.php?token='.rawurlencode((string) $candidate['token']);
$streamUrl = $streamBaseUrl;
$mp4StreamUrl = $streamBaseUrl.'&format=mp4';
$candidateName = trim((string) ($candidate['name'] ?? 'Candidate'));
$candidateTitle = trim((string) ($candidate['professional_title'] ?? $candidate['position_interest'] ?? 'Candidate review'));
$matchReasoning = trim(strip_tags((string) ($candidate['match_reasoning'] ?? '')));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Evaluator Feedback | <?php echo htmlspecialchars($candidateName); ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Martel:wght@700;800&family=Nunito+Sans:wght@500;700;800&display=swap');
        :root {
            --gsd-deep: #240f3a;
            --gsd-primary: #5a3988;
            --gsd-bright: #8c52ff;
            --gsd-ink: #f6f2ff;
            --gsd-mist: rgba(246, 242, 255, 0.7);
            --gsd-line: rgba(255, 255, 255, 0.12);
            --gsd-card: rgba(10, 10, 25, 0.65);
        }

        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            font-family: 'Nunito Sans', sans-serif;
            color: var(--gsd-ink);
            background:
                radial-gradient(circle at top left, rgba(140, 82, 255, 0.22), transparent 28%),
                radial-gradient(circle at bottom right, rgba(52, 211, 153, 0.14), transparent 24%),
                linear-gradient(155deg, #12091e 0%, #201038 52%, #09070f 100%);
        }

        h1, h2 { font-family: 'Martel', serif; }

        .feedback-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(0, 1.65fr) minmax(320px, 460px);
        }

        .video-side {
            padding: 30px;
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        .video-topbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
            flex-wrap: wrap;
        }

        .eyebrow {
            letter-spacing: 0.24em;
            text-transform: uppercase;
            font-size: 11px;
            color: rgba(255,255,255,.56);
            font-weight: 800;
        }

        .candidate-chip {
            border: 1px solid var(--gsd-line);
            background: rgba(255,255,255,.06);
            border-radius: 999px;
            padding: 12px 18px;
            min-width: 220px;
            text-align: right;
            backdrop-filter: blur(12px);
        }

        .player-card {
            position: relative;
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: flex-start;
            gap: 18px;
            padding: 20px;
            border-radius: 30px;
            border: 1px solid rgba(255,255,255,.08);
            background: linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02));
            box-shadow: 0 24px 80px rgba(0,0,0,.32);
            overflow: hidden;
        }

        .player-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at top right, rgba(140, 82, 255, 0.18), transparent 28%),
                radial-gradient(circle at bottom left, rgba(255,255,255,.06), transparent 24%);
            pointer-events: none;
        }

        .player-frame {
            position: relative;
            z-index: 1;
            aspect-ratio: 16 / 9;
            border-radius: 24px;
            overflow: hidden;
            background: #000;
            border: 1px solid rgba(255,255,255,.08);
        }

        .player-frame video {
            width: 100%;
            height: 100%;
            display: block;
            object-fit: contain;
            background: #000;
        }

        .gsd-mark {
            position: absolute;
            top: 18px;
            left: 18px;
            z-index: 2;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: 999px;
            background: rgba(10,10,25,.42);
            border: 1px solid rgba(255,255,255,.12);
            backdrop-filter: blur(10px);
        }

        .gsd-mark-badge {
            width: 34px;
            height: 34px;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--gsd-bright), var(--gsd-primary));
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 900;
        }

        .insight-card {
            position: relative;
            z-index: 1;
            border-radius: 22px;
            border: 1px solid rgba(255,255,255,.08);
            background: rgba(255,255,255,.04);
            padding: 18px 20px;
        }

        .panel-side {
            border-left: 1px solid rgba(255,255,255,.08);
            background: rgba(8, 7, 16, 0.72);
            backdrop-filter: blur(18px);
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        .panel-inner {
            padding: 34px 30px;
            display: flex;
            flex-direction: column;
            gap: 24px;
            min-height: 100%;
        }

        .panel-card {
            border-radius: 28px;
            border: 1px solid rgba(255,255,255,.09);
            background: var(--gsd-card);
            box-shadow: inset 0 1px 0 rgba(255,255,255,.05);
            padding: 24px;
        }

        .meta-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            background: rgba(140,82,255,.16);
            border: 1px solid rgba(140,82,255,.32);
            color: #d8c7ff;
            padding: 8px 14px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        .field-label {
            display: block;
            margin-bottom: 7px;
            font-size: 10px;
            font-weight: 900;
            text-transform: uppercase;
            letter-spacing: .18em;
            color: rgba(255,255,255,.45);
        }

        .field-input,
        .field-area {
            width: 100%;
            border-radius: 18px;
            border: 1px solid rgba(255,255,255,.1);
            background: rgba(7, 10, 20, 0.82);
            color: #f8f5ff;
            padding: 14px 16px;
            outline: none;
            transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
            font: inherit;
        }

        .field-input:focus,
        .field-area:focus {
            border-color: rgba(140,82,255,.72);
            box-shadow: 0 0 0 4px rgba(140,82,255,.16);
            transform: translateY(-1px);
        }

        .field-area {
            min-height: 150px;
            resize: vertical;
        }

        .rating-row {
            display: flex;
            justify-content: center;
            gap: 10px;
            direction: rtl;
        }

        .rating-row input { display: none; }
        .rating-row label {
            font-size: 1.9rem;
            color: rgba(255,255,255,.14);
            cursor: pointer;
            transition: transform .18s ease, color .18s ease;
        }

        .rating-row input:checked ~ label,
        .rating-row label:hover,
        .rating-row label:hover ~ label {
            color: #f8b84b;
            transform: translateY(-2px);
        }

        .submit-btn {
            width: 100%;
            border: 0;
            border-radius: 18px;
            padding: 15px 18px;
            background: linear-gradient(135deg, var(--gsd-primary), var(--gsd-bright));
            color: #fff;
            font-size: 12px;
            font-weight: 900;
            letter-spacing: .18em;
            text-transform: uppercase;
            cursor: pointer;
            box-shadow: 0 16px 32px rgba(140,82,255,.22);
            transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
        }

        .submit-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 20px 36px rgba(140,82,255,.28);
            filter: brightness(1.04);
        }

        .submit-btn:disabled {
            cursor: progress;
            filter: saturate(.7);
            opacity: .78;
        }

        .footer-note {
            margin-top: auto;
            text-align: center;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .32em;
            text-transform: uppercase;
            color: rgba(255,255,255,.28);
        }

        @media (max-width: 1024px) {
            .feedback-shell {
                grid-template-columns: 1fr;
            }

            .panel-side {
                min-height: auto;
                border-left: 0;
                border-top: 1px solid rgba(255,255,255,.08);
            }

            .video-side,
            .panel-inner {
                padding: 22px 18px;
            }
        }
    </style>
</head>
<body>
    <main class="feedback-shell">
        <section class="video-side">
            <div class="video-topbar">
                <div>
                    <p class="eyebrow">Evaluator feedback</p>
                    <h1 style="margin:8px 0 0;font-size:clamp(2.3rem,4vw,4.2rem);line-height:.96;"><?php echo htmlspecialchars($candidateName); ?></h1>
                    <p style="margin:14px 0 0;color:var(--gsd-mist);font-size:.96rem;max-width:700px;line-height:1.7;">
                        Review the interview, score the candidate, and leave a concise hiring recommendation for the GSD team.
                    </p>
                </div>
                <div class="candidate-chip">
                    <div class="eyebrow" style="font-size:10px;">Role focus</div>
                    <div style="margin-top:6px;font-weight:900;font-size:1rem;"><?php echo htmlspecialchars($candidateTitle !== '' ? $candidateTitle : 'Candidate review'); ?></div>
                </div>
            </div>

            <div class="player-card">
                <div class="player-frame">
                    <div class="gsd-mark">
                        <span class="gsd-mark-badge">GSD</span>
                        <div>
                            <div style="font-size:10px;font-weight:900;letter-spacing:.18em;text-transform:uppercase;color:rgba(255,255,255,.5);">Candidate review</div>
                            <div style="font-size:12px;font-weight:800;">Evaluator-only workspace</div>
                        </div>
                    </div>
                    <video id="candidateVideo" controls playsinline webkit-playsinline preload="metadata">
                        <source src="<?php echo htmlspecialchars($mp4StreamUrl); ?>" type="video/mp4">
                        <source src="<?php echo htmlspecialchars($streamUrl); ?>" type="video/webm">
                        Your browser does not support video playback. Please try Chrome or update your device.
                    </video>
                </div>

                <?php if ($matchReasoning !== ''): ?>
                    <div class="insight-card">
                        <div class="eyebrow" style="font-size:10px;">GSD fit note</div>
                        <p style="margin:10px 0 0;color:var(--gsd-mist);line-height:1.72;font-size:.95rem;">
                            <?php echo htmlspecialchars($matchReasoning); ?>
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <aside class="panel-side">
            <div class="panel-inner">
                <div class="panel-card">
                    <span class="meta-pill"><i class="fa-regular fa-star"></i> Evaluation form</span>
                    <h2 style="margin:16px 0 6px;font-size:2.05rem;line-height:1;">Share your recommendation</h2>
                    <p style="margin:0;color:var(--gsd-mist);font-size:.94rem;line-height:1.72;">
                        Your feedback helps GSD Associates compare finalists, surface strengths, and decide next-step interviews faster.
                    </p>
                </div>

                <div class="panel-card">
                    <form id="candidateFeedbackForm" class="space-y-5">
                        <input type="hidden" name="candidate_id" value="<?php echo htmlspecialchars((string) $candidate['id']); ?>">
                        <input type="hidden" name="client_token" value="<?php echo htmlspecialchars($clientRef); ?>">

                        <label class="field-label">Overall rating</label>
                        <div class="rating-row" style="margin-bottom:18px;">
                            <input type="radio" id="s5" name="rating" value="5" required><label for="s5"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="s4" name="rating" value="4"><label for="s4"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="s3" name="rating" value="3"><label for="s3"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="s2" name="rating" value="2"><label for="s2"><i class="fa-solid fa-star"></i></label>
                            <input type="radio" id="s1" name="rating" value="1"><label for="s1"><i class="fa-solid fa-star"></i></label>
                        </div>

                        <div style="margin-bottom:16px;">
                            <label class="field-label" for="evaluator-name">Evaluator name</label>
                            <input
                                id="evaluator-name"
                                class="field-input"
                                type="text"
                                name="evaluator"
                                value="<?php echo htmlspecialchars($preEvaluator); ?>"
                                placeholder="Hiring manager, recruiter, or client reviewer"
                                required
                            >
                        </div>

                        <div style="margin-bottom:20px;">
                            <label class="field-label" for="comment">Evaluator feedback</label>
                            <textarea
                                id="comment"
                                class="field-area"
                                name="comment"
                                placeholder="Summarize communication, role fit, confidence, and whether this candidate should move forward."
                            ></textarea>
                        </div>

                        <button type="submit" id="submitBtn" class="submit-btn">Save Feedback</button>
                    </form>
                </div>

                <div class="footer-note">Get Stuff Done Talent Network</div>
            </div>
        </aside>
    </main>

    <script>
        document.getElementById('candidateFeedbackForm').addEventListener('submit', async function (event) {
            event.preventDefault();

            const submitButton = document.getElementById('submitBtn');
            const originalLabel = submitButton.textContent;
            submitButton.textContent = 'Saving feedback';
            submitButton.disabled = true;

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
                    <div style="text-align:center;padding:28px 12px;">
                        <div style="width:84px;height:84px;margin:0 auto 18px;border-radius:999px;background:linear-gradient(135deg,#22c55e,#16a34a);display:flex;align-items:center;justify-content:center;box-shadow:0 18px 40px rgba(34,197,94,.24);">
                            <i class="fa-solid fa-check" style="font-size:2rem;color:#fff;"></i>
                        </div>
                        <div class="eyebrow" style="font-size:10px;">Feedback captured</div>
                        <h2 style="margin:10px 0 8px;font-size:1.75rem;line-height:1.05;">Thank you</h2>
                        <p style="margin:0 auto;max-width:320px;color:rgba(255,255,255,.62);line-height:1.7;font-size:.95rem;">
                            Your evaluator feedback has been saved and is now attached to this candidate review.
                        </p>
                    </div>
                `;
            } catch (error) {
                console.error(error);
                submitButton.textContent = originalLabel;
                submitButton.disabled = false;
                alert(error.message);
            }
        });
    </script>
</body>
</html>
