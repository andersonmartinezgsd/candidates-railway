<?php
declare(strict_types=1);

require_once __DIR__.'/../config/runtime.php';
require_once __DIR__.'/ai_functions.php';

if (! function_exists('gsdCandidateAiAnalyzeAndPersist')) {
    function gsdCandidateAiAnalyzeAndPersist(PDO $db, array $candidate, array $analysisPayload = []): array
    {
        $transcript = trim((string) ($analysisPayload['transcript'] ?? $candidate['transcript'] ?? ''));
        if (str_word_count($transcript) < 8) {
            $transcription = gsdCandidateAiTranscribeMedia($candidate);
            if (is_array($transcription) && trim((string) ($transcription['text'] ?? '')) !== '') {
                $transcript = trim((string) $transcription['text']);
                $analysisPayload['language'] = $analysisPayload['language'] ?? ($transcription['language_code'] ?? null);
                $analysisPayload['_transcription_provider'] = $transcription['provider'] ?? 'openai-audio';
            }
        }

        $cvText = trim((string) ($candidate['cv_text'] ?? $candidate['cv_text_preview'] ?? ''));
        $position = trim((string) ($candidate['position_interest'] ?? $candidate['professional_title'] ?? ''));
        $answers = gsdCandidateAiDecodeJson($candidate['answers_all'] ?? null);
        $skills = gsdCandidateAiNormalizeList($candidate['skills_json'] ?? null);
        $biometric = gsdCandidateAiDecodeJson($candidate['biometric_json'] ?? null);

        if ($analysisPayload !== []) {
            $biometric = array_replace_recursive($biometric, $analysisPayload);
        }

        $roleScores = gsdCandidateAiRoleScores($candidate, $transcript, $cvText, $skills, $answers);
        $sentiment = gsdCandidateAiSentimentSummary($transcript, $analysisPayload, $candidate);
        $english = gsdCandidateAiEnglishSummary($candidate, $transcript, $cvText);
        $visual = gsdCandidateAiVisualSummary($analysisPayload, $candidate, $sentiment);
        $spontaneity = gsdCandidateAiSpontaneitySummary($transcript, $cvText);
        $transcriptSummary = gsdCandidateAiTranscriptSummary($transcript, $analysisPayload, $candidate);
        $bestRole = $roleScores[0] ?? ['code' => gsdCandidateAiInferRoleCode($position), 'label' => $position !== '' ? $position : 'Open role', 'score' => 55, 'reason' => 'Initial default match'];

        $fallback = [
            'provider' => 'heuristic',
            'overall_score' => (int) round(($bestRole['score'] + $sentiment['score'] + $visual['score'] + $spontaneity['score']) / 4),
            'match_score' => (int) $bestRole['score'],
            'match_reasoning' => sprintf(
                'Best aligned with %s based on transcript, CV, technical answers and communication signals.',
                $bestRole['label']
            ),
            'best_position' => $bestRole['label'],
            'best_position_code' => $bestRole['code'],
            'role_scores' => $roleScores,
            'sentiment_analysis' => $sentiment,
            'gesture_word_alignment' => [
                'score' => $visual['alignment_score'],
                'summary' => $visual['alignment_summary'],
            ],
            'english_level' => $english['level'],
            'english_score' => $english['score'],
            'english_analysis' => $english,
            'expression_alignment' => [
                'score' => $visual['score'],
                'summary' => $visual['summary'],
            ],
            'transcript_analysis' => $transcriptSummary,
            'spontaneity_analysis' => $spontaneity,
            'visual_analysis' => $visual,
            'behavioral_analysis' => [
                'communication' => $transcriptSummary['summary'],
                'spontaneity' => $spontaneity,
                'role_scores' => $roleScores,
                'candidate_summary' => [
                    'strengths' => gsdCandidateAiStrengths($skills, $roleScores, $english, $visual),
                    'risks' => gsdCandidateAiRisks($transcriptSummary, $spontaneity, $english),
                    'summary' => 'Candidate shows a balanced mix of role fit, communication evidence and behavioral indicators.',
                ],
            ],
        ];

        $aiResult = gsdCandidateAiCallProvider($candidate, $analysisPayload, $fallback, $db);
        $result = gsdCandidateAiMergeResult($fallback, $aiResult);
        $result['ai_analysis'] = gsdCandidateAiHtmlSummary($candidate, $result);

        gsdCandidateAiPersist($db, $candidate, $analysisPayload, $result);

        return $result;
    }
}

if (! function_exists('gsdCandidateAiCallProvider')) {
    function gsdCandidateAiCallProvider(array $candidate, array $analysisPayload, array $fallback, PDO $db): array
    {
        $providers = gsdCandidateAiProviders();
        if ($providers === []) {
            return ['provider' => 'heuristic'];
        }

        $prompt = gsdCandidateAiPrompt($candidate, $analysisPayload, $fallback, $db);

        foreach ($providers as $provider) {
            try {
                $response = match ($provider['name']) {
                    'gemini' => gsdCandidateAiCallGemini($provider['key'], $prompt),
                    'openai' => gsdCandidateAiCallOpenAi($provider['key'], $prompt),
                    'claude' => gsdCandidateAiCallClaude($provider['key'], $prompt),
                    'groq' => gsdCandidateAiCallGroq($provider['key'], $prompt),
                    'openrouter' => gsdCandidateAiCallOpenRouter($provider['key'], $prompt),
                    default => null,
                };

                if (is_array($response) && $response !== []) {
                    $response['provider'] = $provider['name'];
                    return $response;
                }
            } catch (Throwable $exception) {
                error_log('[candidate-ai] '.$provider['name'].' failed: '.$exception->getMessage());
            }
        }

        return ['provider' => 'heuristic'];
    }
}

if (! function_exists('gsdCandidateAiPersist')) {
    function gsdCandidateAiPersist(PDO $db, array $candidate, array $analysisPayload, array $result): void
    {
        $candidateTable = is_string($candidate['__table'] ?? null) && ($candidate['__table'] ?? '') !== ''
            ? (string) $candidate['__table']
            : gsdCandidateAiResolveTable($db, ['candidates', 'gsd_candidates', 'candidate_drafts', 'gsd_candidate_drafts']);
        if ($candidateTable === null) {
            return;
        }

        $biometric = gsdCandidateAiDecodeJson($candidate['biometric_json'] ?? null);
        $biometric['_ai_enrichment'] = [
            'provider' => $result['provider'] ?? 'heuristic',
            'best_position' => $result['best_position'] ?? null,
            'role_scores' => $result['role_scores'] ?? [],
            'spontaneity' => $result['spontaneity_analysis'] ?? [],
            'transcript_analysis' => $result['transcript_analysis'] ?? [],
        ];
        $biometric = array_replace_recursive($biometric, $analysisPayload);

        $update = $db->prepare(
            'UPDATE '.$candidateTable.'
             SET match_score = :match_score,
                 match_reasoning = :match_reasoning,
                 ai_analysis = :ai_analysis,
                 transcript = :transcript,
                 sentiment_score = :sentiment_score,
                 dominant_emotion = :dominant_emotion,
                 spoken_language = :spoken_language,
                 english_level = :english_level,
                 english_score = :english_score,
                 biometric_json = :biometric_json,
                 updated_at = NOW()
             WHERE id = :id'
        );

        $update->execute([
            ':match_score' => (float) ($result['match_score'] ?? 0),
            ':match_reasoning' => (string) ($result['match_reasoning'] ?? ''),
            ':ai_analysis' => (string) ($result['ai_analysis'] ?? ''),
            ':transcript' => (string) (($result['transcript_analysis']['transcript'] ?? $candidate['transcript'] ?? '') ?: null),
            ':sentiment_score' => (float) ($result['sentiment_analysis']['score'] ?? $candidate['sentiment_score'] ?? 0),
            ':dominant_emotion' => (string) (($result['visual_analysis']['dominant_emotion'] ?? $candidate['dominant_emotion'] ?? '') ?: null),
            ':spoken_language' => (string) (($result['transcript_analysis']['language_code'] ?? $candidate['spoken_language'] ?? '') ?: null),
            ':english_level' => (string) (($result['english_level'] ?? $candidate['english_level'] ?? '') ?: null),
            ':english_score' => (int) ($result['english_score'] ?? $candidate['english_score'] ?? 0),
            ':biometric_json' => json_encode($biometric, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':id' => (int) $candidate['id'],
        ]);

        gsdCandidateAiPersistInsight($db, $candidate, $result);
    }
}

if (! function_exists('gsdCandidateAiPersistInsight')) {
    function gsdCandidateAiPersistInsight(PDO $db, array $candidate, array $result): void
    {
        $insightTable = gsdCandidateAiResolveTable($db, ['candidate_ai_insights', 'gsd_candidate_ai_insights']);
        if ($insightTable === null) {
            return;
        }

        $payload = [
            ':candidate_token' => (string) $candidate['token'],
            ':original_filename' => basename((string) (($candidate['video_original_path'] ?? '') ?: ($candidate['video_processed_path'] ?? ''))),
            ':visual_analysis' => json_encode(array_filter([
                'status' => $result['visual_analysis']['status'] ?? null,
                'source' => $result['visual_analysis']['source'] ?? null,
                'dominant_emotion' => $result['visual_analysis']['dominant_emotion'] ?? null,
                'score' => $result['visual_analysis']['score'] ?? null,
                'alignment_score' => $result['visual_analysis']['alignment_score'] ?? null,
                'expression_summary' => $result['visual_analysis']['summary'] ?? null,
                'gesture_word_alignment' => $result['gesture_word_alignment'] ?? null,
                'alignment_summary' => $result['visual_analysis']['alignment_summary'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':english_analysis' => json_encode($result['english_analysis'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':behavioral_analysis' => json_encode(array_filter([
                'status' => $result['spontaneity_analysis']['status'] ?? null,
                'source' => $result['spontaneity_analysis']['source'] ?? null,
                'spontaneity_analysis' => $result['spontaneity_analysis'] ?? null,
                'role_scores' => $result['role_scores'] ?? [],
                'best_position' => $result['best_position'] ?? null,
                'transcript_analysis' => $result['transcript_analysis'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':overall_score' => (float) ($result['overall_score'] ?? $result['match_score'] ?? 0),
        ];

        $existing = $db->prepare('SELECT id FROM '.$insightTable.' WHERE candidate_token = ? ORDER BY id DESC LIMIT 1');
        $existing->execute([(string) $candidate['token']]);
        $existingId = $existing->fetchColumn();

        if ($existingId) {
            $sql = 'UPDATE '.$insightTable.'
                    SET original_filename = :original_filename,
                        visual_analysis = :visual_analysis,
                        english_analysis = :english_analysis,
                        behavioral_analysis = :behavioral_analysis,
                        overall_score = :overall_score
                    WHERE id = '.(int) $existingId;
            $update = $db->prepare($sql);
            $update->execute(array_diff_key($payload, [':candidate_token' => true]));

            return;
        }

        $insert = $db->prepare(
            'INSERT INTO '.$insightTable.'
                (candidate_token, original_filename, visual_analysis, english_analysis, behavioral_analysis, overall_score)
             VALUES
                (:candidate_token, :original_filename, :visual_analysis, :english_analysis, :behavioral_analysis, :overall_score)'
        );
        $insert->execute($payload);
    }
}

if (! function_exists('gsdCandidateAiPrompt')) {
    function gsdCandidateAiPrompt(array $candidate, array $analysisPayload, array $fallback, PDO $db): string
    {
        $roleLines = array_map(
            static fn (array $role): string => sprintf('%s (%s): %d/100 - %s', $role['label'], $role['code'], $role['score'], $role['reason']),
            array_slice($fallback['role_scores'] ?? [], 0, 5)
        );

        $jobsContext = [];
        $jobsTable = gsdCandidateAiResolveTable($db, ['jobs', 'gsd_jobs']);
        try {
            if ($jobsTable !== null) {
                $jobs = $db->query('SELECT title, description FROM '.$jobsTable.' ORDER BY id ASC LIMIT 8')->fetchAll(PDO::FETCH_ASSOC) ?: [];
                foreach ($jobs as $job) {
                    $jobsContext[] = ($job['title'] ?? 'Open role').': '.mb_substr((string) ($job['description'] ?? ''), 0, 180);
                }
            }
        } catch (Throwable) {
            $jobsContext = [];
        }

        $transcript = trim((string) ($analysisPayload['transcript'] ?? $candidate['transcript'] ?? ''));
        $cvText = trim((string) ($candidate['cv_text'] ?? $candidate['cv_text_preview'] ?? ''));

        return implode("\n", [
            'You are a senior ATS-HR analyst for GSD.',
            'Analyze the candidate from transcript, CV, facial/emotion metadata and application answers.',
            'Return ONLY a valid JSON object, no markdown.',
            'Required keys:',
            '{',
            '"overall_score": 0,',
            '"match_score": 0,',
            '"match_reasoning": "",',
            '"best_position": "",',
            '"best_position_code": "",',
            '"role_scores": [{"code":"","label":"","score":0,"reason":""}],',
            '"sentiment_analysis": {"score":0,"label":"","summary":""},',
            '"gesture_word_alignment": {"score":0,"summary":""},',
            '"english_level": "",',
            '"english_score": 0,',
            '"english_analysis": {"level":"","score":0,"summary":""},',
            '"expression_alignment": {"score":0,"summary":""},',
            '"transcript_analysis": {"summary":"","word_count":0,"language_code":"","language_label":""},',
            '"spontaneity_analysis": {"score":0,"label":"","summary":""},',
            '"visual_analysis": {"dominant_emotion":"","score":0,"alignment_score":0,"summary":"","alignment_summary":""},',
            '"behavioral_analysis": {"communication":"","candidate_summary":{"summary":"","strengths":[""],"risks":[""]}}',
            '}',
            'Candidate position interest: '.((string) ($candidate['position_interest'] ?? '')),
            'Candidate professional title: '.((string) ($candidate['professional_title'] ?? '')),
            'Transcript:',
            $transcript !== '' ? mb_substr($transcript, 0, 9000) : '[Transcript unavailable]',
            'CV content:',
            $cvText !== '' ? mb_substr($cvText, 0, 9000) : '[CV unavailable]',
            'Facial / video metadata:',
            json_encode($analysisPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Pre-scored role hypotheses:',
            implode("\n", $roleLines),
            'Known open roles:',
            implode("\n", $jobsContext),
            'Heuristic baseline:',
            json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'Be strict and operational. Mention if transcript looks read/scripted vs spontaneous.',
        ]);
    }
}

if (! function_exists('gsdCandidateAiHtmlSummary')) {
    function gsdCandidateAiHtmlSummary(array $candidate, array $result): string
    {
        $strengths = $result['behavioral_analysis']['candidate_summary']['strengths'] ?? [];
        $risks = $result['behavioral_analysis']['candidate_summary']['risks'] ?? [];
        $roleItems = array_slice($result['role_scores'] ?? [], 0, 4);
        $englishLevel = (string) (($result['english_analysis']['level'] ?? '') ?: 'Pending');
        $englishSummary = (string) (($result['english_analysis']['summary'] ?? '') ?: 'Pending');
        $spontaneityLabel = (string) (($result['spontaneity_analysis']['label'] ?? '') ?: 'Pending');
        $spontaneitySummary = (string) (($result['spontaneity_analysis']['summary'] ?? '') ?: 'Pending');
        $visualSummary = (string) (($result['visual_analysis']['summary'] ?? '') ?: 'Pending');

        $roleList = implode('', array_map(static function (array $role): string {
            return '<li><strong>'.htmlspecialchars($role['label']).'</strong>: '.(int) $role['score'].'/100 - '.htmlspecialchars((string) $role['reason']).'</li>';
        }, $roleItems));

        $strengthList = implode('', array_map(static fn (string $item): string => '<li>'.htmlspecialchars($item).'</li>', array_slice($strengths, 0, 4)));
        $riskList = implode('', array_map(static fn (string $item): string => '<li>'.htmlspecialchars($item).'</li>', array_slice($risks, 0, 4)));

        return '<h4>ATS-HR AI Overview</h4>'
            .'<p><strong>Best fit:</strong> '.htmlspecialchars((string) ($result['best_position'] ?? ($candidate['position_interest'] ?? 'Open role'))).' ('.(int) ($result['match_score'] ?? 0).'% match)</p>'
            .'<p><strong>Reasoning:</strong> '.htmlspecialchars((string) ($result['match_reasoning'] ?? 'No reasoning available.')).'</p>'
            .'<h4>Communication & English</h4>'
            .'<ul>'
            .'<li><strong>Transcript:</strong> '.htmlspecialchars((string) ($result['transcript_analysis']['summary'] ?? 'No transcript analysis available')).'</li>'
            .'<li><strong>English:</strong> '.htmlspecialchars($englishLevel.' - '.$englishSummary).'</li>'
            .'<li><strong>Spontaneity:</strong> '.htmlspecialchars($spontaneityLabel.' - '.$spontaneitySummary).'</li>'
            .'</ul>'
            .'<h4>Visual & Behavioral</h4>'
            .'<ul>'
            .'<li><strong>Emotion / expression:</strong> '.htmlspecialchars($visualSummary).'</li>'
            .'<li><strong>Gesture vs words:</strong> '.htmlspecialchars((string) ($result['gesture_word_alignment']['summary'] ?? 'Pending')).'</li>'
            .'</ul>'
            .'<h4>Role Ranking</h4>'
            .'<ul>'.$roleList.'</ul>'
            .'<h4>Strengths</h4>'
            .'<ul>'.$strengthList.'</ul>'
            .'<h4>Risks / Watchouts</h4>'
            .'<ul>'.$riskList.'</ul>';
    }
}

if (! function_exists('gsdCandidateAiMergeResult')) {
    function gsdCandidateAiMergeResult(array $fallback, array $aiResult): array
    {
        $merged = $fallback;

        foreach ($aiResult as $key => $value) {
            if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                $merged[$key] = array_replace_recursive($merged[$key], $value);
                continue;
            }

            if ($value !== null && $value !== '') {
                $merged[$key] = $value;
            }
        }

        if (empty($merged['best_position']) && ! empty($merged['role_scores'][0]['label'])) {
            $merged['best_position'] = $merged['role_scores'][0]['label'];
            $merged['best_position_code'] = $merged['role_scores'][0]['code'] ?? null;
        }

        return $merged;
    }
}

if (! function_exists('gsdCandidateAiResolveTable')) {
    function gsdCandidateAiResolveTable(PDO $db, array $candidates): ?string
    {
        foreach ($candidates as $table) {
            try {
                $check = $db->query("SHOW TABLES LIKE ".$db->quote($table));
                if ($check && $check->fetchColumn() !== false) {
                    return $table;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}

if (! function_exists('gsdCandidateAiProviders')) {
    function gsdCandidateAiParseKeys(string $raw): array
    {
        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $keys = [];

        foreach ($parts as $part) {
            $value = trim($part, " \t\n\r\0\x0B\"'");
            if ($value !== '') {
                $keys[] = $value;
            }
        }

        return array_values(array_unique($keys));
    }
}

if (! function_exists('gsdCandidateAiProviders')) {
    function gsdCandidateAiProviders(): array
    {
        $order = preg_split('/\s*,\s*/', (string) gsdRecruitmentEnv('AI_ORDER', 'gemini,claude,openai')) ?: [];
        $keys = [
            'gemini' => gsdCandidateAiParseKeys((string) gsdRecruitmentEnv('GEMINI_API_KEY', '')),
            'claude' => gsdCandidateAiParseKeys((string) gsdRecruitmentEnv('CLAUDE_API_KEY', '')),
            'openai' => gsdCandidateAiParseKeys((string) gsdRecruitmentEnv('OPENAI_API_KEY', '')),
            'groq' => gsdCandidateAiParseKeys((string) gsdRecruitmentEnv('GROQ_API_KEY', '')),
            'openrouter' => gsdCandidateAiParseKeys((string) gsdRecruitmentEnv('OPENROUTER_API_KEY', '')),
        ];

        $providers = [];
        foreach ($order as $name) {
            $name = strtolower(trim($name));
            if ($name === '' || empty($keys[$name])) {
                continue;
            }

            foreach ($keys[$name] as $key) {
                $providers[] = ['name' => $name, 'key' => $key];
            }
        }

        return $providers;
    }
}

if (! function_exists('gsdCandidateAiCallGemini')) {
    function gsdCandidateAiCallGemini(string $key, string $prompt): ?array
    {
        $payload = json_encode([
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.15, 'responseMimeType' => 'application/json'],
        ]);

        [$body, $code] = gsdCandidateAiCurlPost(
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key='.$key,
            $payload,
            ['Content-Type: application/json']
        );

        if ($code !== 200) {
            throw new RuntimeException('Gemini HTTP '.$code);
        }

        $json = json_decode($body, true);
        return gsdCandidateAiParseJson($json['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }
}

if (! function_exists('gsdCandidateAiCallOpenAi')) {
    function gsdCandidateAiCallOpenAi(string $key, string $prompt): ?array
    {
        $payload = json_encode([
            'model' => 'gpt-4o-mini',
            'temperature' => 0.15,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => 'Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ],
        ]);

        [$body, $code] = gsdCandidateAiCurlPost(
            'https://api.openai.com/v1/chat/completions',
            $payload,
            ['Content-Type: application/json', 'Authorization: Bearer '.$key]
        );

        if ($code !== 200) {
            throw new RuntimeException('OpenAI HTTP '.$code);
        }

        $json = json_decode($body, true);
        return gsdCandidateAiParseJson($json['choices'][0]['message']['content'] ?? '');
    }
}

if (! function_exists('gsdCandidateAiCallClaude')) {
    function gsdCandidateAiCallClaude(string $key, string $prompt): ?array
    {
        $models = gsdCandidateAiParseModelOrder(
            (string) gsdRecruitmentEnv('CLAUDE_MODEL_ORDER', ''),
            [
                'claude-sonnet-4-20250514',
                'claude-3-7-sonnet-20250219',
                'claude-3-5-sonnet-20241022',
                'claude-3-5-haiku-20241022',
            ]
        );

        foreach ($models as $model) {
            $payload = json_encode([
                'model' => $model,
                'max_tokens' => 2200,
                'messages' => [['role' => 'user', 'content' => $prompt]],
            ]);

            [$body, $code] = gsdCandidateAiCurlPost(
                'https://api.anthropic.com/v1/messages',
                $payload,
                [
                    'Content-Type: application/json',
                    'anthropic-version: 2023-06-01',
                    'x-api-key: '.$key,
                ]
            );

            if ($code !== 200) {
                continue;
            }

            $json = json_decode($body, true);
            $parsed = gsdCandidateAiParseJson(implode('', array_column($json['content'] ?? [], 'text')));
            if (is_array($parsed) && $parsed !== []) {
                return $parsed;
            }
        }

        throw new RuntimeException('Claude HTTP 404');
    }
}

if (! function_exists('gsdCandidateAiParseModelOrder')) {
    function gsdCandidateAiParseModelOrder(string $raw, array $defaults): array
    {
        $models = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', trim($raw)) ?: [])));
        return $models !== [] ? $models : $defaults;
    }
}

if (! function_exists('gsdCandidateAiCallGroq')) {
    function gsdCandidateAiCallGroq(string $key, string $prompt): ?array
    {
        return gsdCandidateAiCallOpenAiCompatible(
            'https://api.groq.com/openai/v1/chat/completions',
            $key,
            $prompt,
            gsdCandidateAiParseModelOrder(
                (string) gsdRecruitmentEnv('GROQ_MODEL_ORDER', ''),
                [
                    'llama-3.3-70b-versatile',
                    'openai/gpt-oss-120b',
                    'llama-3.1-8b-instant',
                ]
            )
        );
    }
}

if (! function_exists('gsdCandidateAiCallOpenRouter')) {
    function gsdCandidateAiCallOpenRouter(string $key, string $prompt): ?array
    {
        return gsdCandidateAiCallOpenAiCompatible(
            'https://openrouter.ai/api/v1/chat/completions',
            $key,
            $prompt,
            gsdCandidateAiParseModelOrder(
                (string) gsdRecruitmentEnv('OPENROUTER_MODEL_ORDER', ''),
                [
                    'openai/gpt-4o-mini',
                    'meta-llama/llama-3.3-70b-instruct',
                    'anthropic/claude-3.5-sonnet',
                ]
            ),
            [
                'HTTP-Referer: https://candidates.gsdoutsource.com',
                'X-Title: GSD Candidates ATS',
            ]
        );
    }
}

if (! function_exists('gsdCandidateAiCallOpenAiCompatible')) {
    function gsdCandidateAiCallOpenAiCompatible(string $url, string $key, string $prompt, array $models, array $extraHeaders = []): ?array
    {
        foreach ($models as $model) {
            $payload = json_encode([
                'model' => $model,
                'temperature' => 0.15,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => 'Return only valid JSON.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            [$body, $code] = gsdCandidateAiCurlPost(
                $url,
                $payload,
                array_merge([
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$key,
                ], $extraHeaders)
            );

            if ($code !== 200) {
                continue;
            }

            $json = json_decode($body, true);
            $parsed = gsdCandidateAiParseJson($json['choices'][0]['message']['content'] ?? '');
            if (is_array($parsed) && $parsed !== []) {
                return $parsed;
            }
        }

        return null;
    }
}

if (! function_exists('gsdCandidateAiCurlPost')) {
    function gsdCandidateAiCurlPost(string $url, string $payload, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'GSD-ATS-HR/1.0',
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('cURL failed: '.$error);
        }

        return [$body, $code];
    }
}

if (! function_exists('gsdCandidateAiParseJson')) {
    function gsdCandidateAiParseJson(string $text): ?array
    {
        $text = preg_replace('/^```json\s*|^```\s*|```\s*$/m', '', trim($text));
        if (preg_match('/\{[\s\S]+\}/', $text, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode($matches[0], true);
        return is_array($decoded) ? $decoded : null;
    }
}

if (! function_exists('gsdCandidateAiRoleScores')) {
    function gsdCandidateAiRoleScores(array $candidate, string $transcript, string $cvText, array $skills, array $answers): array
    {
        $profiles = gsdCandidateAiRoleProfiles();
        $corpus = mb_strtolower(implode("\n", [
            (string) ($candidate['position_interest'] ?? ''),
            (string) ($candidate['professional_title'] ?? ''),
            (string) ($candidate['languages'] ?? ''),
            (string) ($candidate['highest_education'] ?? ''),
            $transcript,
            $cvText,
            implode(', ', $skills),
            json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]));

        $inferredCode = gsdCandidateAiInferRoleCode((string) ($candidate['position_interest'] ?? $candidate['professional_title'] ?? ''));
        $scores = [];

        foreach ($profiles as $code => $profile) {
            $hits = 0;
            foreach ($profile['keywords'] as $keyword) {
                if (str_contains($corpus, mb_strtolower($keyword))) {
                    $hits++;
                }
            }

            $score = 28 + ($hits * 8);
            if ($inferredCode === $code) {
                $score += 18;
            }
            if (in_array($code, ['HVA', 'HOP'], true) && (int) ($candidate['prev_worked_healthcare'] ?? 0) === 1) {
                $score += 10;
            }
            if ($code === 'VPA' && (int) ($candidate['prev_worked_va'] ?? 0) === 1) {
                $score += 10;
            }
            if ((int) ($candidate['is_education_healthcare_relevant'] ?? 0) === 1 && in_array($code, ['HVA', 'HOP'], true)) {
                $score += 8;
            }

            $score = max(25, min(98, $score));
            $scores[] = [
                'code' => $code,
                'label' => $profile['label'],
                'score' => $score,
                'reason' => $hits > 0
                    ? sprintf('Detected %d relevant role signals across CV, transcript and application answers.', $hits)
                    : 'Limited direct signals; role kept as secondary option.',
            ];
        }

        usort($scores, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return $scores;
    }
}

if (! function_exists('gsdCandidateAiRoleProfiles')) {
    function gsdCandidateAiRoleProfiles(): array
    {
        return [
            'VPA' => ['label' => 'Virtual Personal Assistant', 'keywords' => ['calendar', 'email management', 'executive assistant', 'administrative assistant', 'scheduling', 'travel arrangements', 'inbox', 'organization']],
            'HVA' => ['label' => 'Healthcare Virtual Assistant', 'keywords' => ['hipaa', 'ehr', 'emr', 'patient', 'medical', 'clinic', 'hospital', 'insurance verification', 'prior authorization']],
            'HOP' => ['label' => 'Healthcare Operations', 'keywords' => ['claims', 'medical records', 'revenue cycle', 'medical transcription', 'icd-10', 'cpt', 'billing']],
            'MVA' => ['label' => 'Marketing Virtual Assistant', 'keywords' => ['social media', 'seo', 'lead generation', 'campaign', 'mailchimp', 'hubspot', 'content']],
            'HRO' => ['label' => 'HR Operations', 'keywords' => ['onboarding', 'offboarding', 'payroll', 'benefits', 'hris', 'compliance', 'recruitment coordination']],
            'MGR' => ['label' => 'Marketing Manager', 'keywords' => ['brand strategy', 'roi', 'go-to-market', 'google analytics', 'marketing team', 'budget']],
            'ACM' => ['label' => 'Account Manager', 'keywords' => ['client relationship', 'qbr', 'renewal', 'upsell', 'crm', 'account plan']],
            'SDR' => ['label' => 'Sales Development Representative', 'keywords' => ['prospecting', 'cold call', 'lead qualification', 'pipeline', 'outbound', 'quota']],
        ];
    }
}

if (! function_exists('gsdCandidateAiInferRoleCode')) {
    function gsdCandidateAiInferRoleCode(string $position): ?string
    {
        $position = mb_strtolower(trim($position));
        if ($position === '') {
            return null;
        }

        return match (true) {
            str_contains($position, 'healthcare virtual assistant') => 'HVA',
            str_contains($position, 'healthcare operations') => 'HOP',
            str_contains($position, 'virtual personal assistant') => 'VPA',
            str_contains($position, 'marketing virtual assistant') => 'MVA',
            str_contains($position, 'marketing manager') => 'MGR',
            str_contains($position, 'account manager') => 'ACM',
            str_contains($position, 'sales') || str_contains($position, 'sdr') => 'SDR',
            str_contains($position, 'hr') => 'HRO',
            default => null,
        };
    }
}

if (! function_exists('gsdCandidateAiSentimentSummary')) {
    function gsdCandidateAiSentimentSummary(string $transcript, array $analysisPayload, array $candidate): array
    {
        $score = (int) round((float) ($analysisPayload['sentiment']['score'] ?? $candidate['sentiment_score'] ?? 0));

        if ($score <= 0 && trim($transcript) !== '') {
            $positiveWords = ['team', 'growth', 'lead', 'organized', 'success', 'improve', 'experience', 'support', 'client'];
            $negativeWords = ['problem', 'stress', 'fail', 'difficult', 'hard', 'struggle', 'conflict'];
            $lower = mb_strtolower($transcript);
            $positiveHits = 0;
            foreach ($positiveWords as $word) {
                $positiveHits += substr_count($lower, $word);
            }
            $negativeHits = 0;
            foreach ($negativeWords as $word) {
                $negativeHits += substr_count($lower, $word);
            }
            $score = $positiveHits + $negativeHits > 0
                ? (int) round(($positiveHits / max(1, $positiveHits + $negativeHits)) * 100)
                : 58;
        }

        $label = $score >= 70 ? 'Positive' : ($score >= 45 ? 'Neutral' : 'Watch');
        return [
            'score' => max(0, min(100, $score)),
            'label' => $label,
            'summary' => $label === 'Positive'
                ? 'Speech content projects confidence and constructive tone.'
                : ($label === 'Neutral'
                    ? 'Communication is stable but not strongly persuasive yet.'
                    : 'Communication includes signals of tension, hesitation or low confidence.'),
        ];
    }
}

if (! function_exists('gsdCandidateAiEnglishSummary')) {
    function gsdCandidateAiEnglishSummary(array $candidate, string $transcript, string $cvText): array
    {
        $baseText = trim($transcript) !== '' ? $transcript : $cvText;
        $wordCount = str_word_count($baseText);
        $analysis = function_exists('getEnglishLevelAnalysis')
            ? getEnglishLevelAnalysis($baseText)
            : ['english_level' => 'N/A', 'english_score' => 0];

        $declared = trim(implode(' / ', array_filter([
            (string) ($candidate['english_reading'] ?? ''),
            (string) ($candidate['english_listening'] ?? ''),
        ])));

        if ($wordCount < 12 && $declared === '') {
            return [
                'status' => 'pending',
                'source' => 'missing_evidence',
                'level' => null,
                'score' => null,
                'summary' => 'Not enough transcript or CV evidence is available yet to evaluate English reliably.',
                'declared_assessment' => null,
            ];
        }

        $summary = 'Transcript complexity, vocabulary breadth and fluency were used to estimate English performance.';
        if ($declared !== '') {
            $summary .= ' Declared assessment: '.$declared.'.';
        }

        return [
            'status' => 'ready',
            'source' => trim($transcript) !== '' ? 'transcript' : 'cv',
            'level' => (string) ($analysis['english_level'] ?? 'N/A'),
            'score' => (int) ($analysis['english_score'] ?? 0),
            'summary' => $summary,
            'declared_assessment' => $declared !== '' ? $declared : null,
        ];
    }
}

if (! function_exists('gsdCandidateAiVisualSummary')) {
    function gsdCandidateAiVisualSummary(array $analysisPayload, array $candidate, array $sentiment): array
    {
        $face = $analysisPayload['facial_analysis'] ?? gsdCandidateAiDecodeJson($candidate['biometric_json'] ?? null);
        $dominant = (string) ($face['dominant'] ?? $candidate['dominant_emotion'] ?? '');
        $averages = is_array($face['averages'] ?? null) ? $face['averages'] : [];
        if ($averages === [] && $dominant === '') {
            return [
                'status' => 'pending',
                'source' => 'missing_evidence',
                'dominant_emotion' => null,
                'score' => null,
                'alignment_score' => null,
                'summary' => 'No FaceAPI or biometric evidence has been captured yet for this candidate.',
                'alignment_summary' => 'Run FaceAPI on the stored video to generate a current visual assessment.',
            ];
        }

        $happy = (float) ($averages['happy'] ?? 0);
        $neutral = (float) ($averages['neutral'] ?? 0);
        $negative = (float) (($averages['sad'] ?? 0) + ($averages['angry'] ?? 0) + ($averages['fearful'] ?? 0) + ($averages['disgusted'] ?? 0));
        $visualScore = (int) max(0, min(100, round(($happy * 100) + ($neutral * 60) - ($negative * 45) + 35)));
        $alignmentScore = (int) max(0, min(100, 100 - abs($visualScore - (int) $sentiment['score'])));

        return [
            'status' => 'ready',
            'source' => $averages !== [] ? 'faceapi' : 'stored_emotion',
            'dominant_emotion' => $dominant,
            'score' => $visualScore,
            'alignment_score' => $alignmentScore,
            'summary' => $alignmentScore >= 70
                ? 'Facial expression and verbal tone look aligned for most of the recorded message.'
                : 'Facial expression and verbal message show some mismatch that deserves human review.',
            'alignment_summary' => $alignmentScore >= 70
                ? 'Gestures, facial tone and spoken message point in a similar direction.'
                : 'Non-verbal signals do not fully support the spoken message.',
        ];
    }
}

if (! function_exists('gsdCandidateAiSpontaneitySummary')) {
    function gsdCandidateAiSpontaneitySummary(string $transcript, string $cvText): array
    {
        $lower = mb_strtolower($transcript);
        if (str_word_count($transcript) < 20) {
            return [
                'status' => 'pending',
                'source' => 'short_transcript',
                'score' => null,
                'label' => null,
                'summary' => 'Transcript is still too short to evaluate spontaneity or detect reading reliably.',
            ];
        }

        $readingMarkers = [
            'thank you for this opportunity',
            'i am applying for',
            'as you can see',
            'according to my resume',
            'i would like to apply',
            'my name is',
        ];
        $markerCount = 0;
        foreach ($readingMarkers as $marker) {
            $markerCount += substr_count($lower, $marker);
        }

        $words = preg_split('/\s+/', trim($lower)) ?: [];
        $wordCount = count(array_filter($words));
        $uniqueRatio = $wordCount > 0 ? (count(array_unique($words)) / $wordCount) : 0;
        $cvOverlap = 0;
        if ($cvText !== '' && $transcript !== '') {
            similar_text(mb_substr($lower, 0, 600), mb_strtolower(mb_substr($cvText, 0, 600)), $cvOverlap);
        }

        $score = (int) round(78 - ($markerCount * 15) - max(0, 35 - ($uniqueRatio * 100)) * 0.4 - max(0, $cvOverlap - 55) * 0.5);
        $score = max(15, min(95, $score));
        $label = $score >= 70 ? 'Likely spontaneous' : ($score >= 45 ? 'Mixed' : 'Likely scripted');

        return [
            'status' => 'ready',
            'source' => 'transcript',
            'score' => $score,
            'label' => $label,
            'summary' => $label === 'Likely spontaneous'
                ? 'Delivery shows natural variation and low script dependence.'
                : ($label === 'Mixed'
                    ? 'Parts of the message feel prepared, but there are still natural delivery cues.'
                    : 'Transcript contains cues of reading or highly scripted delivery.'),
        ];
    }
}

if (! function_exists('gsdCandidateAiTranscriptSummary')) {
    function gsdCandidateAiTranscriptSummary(string $transcript, array $analysisPayload, array $candidate): array
    {
        $wordCount = str_word_count($transcript);
        $languageCode = (string) ($analysisPayload['language'] ?? $candidate['spoken_language'] ?? 'en');
        $languageLabel = match (strtolower($languageCode)) {
            'es', 'es-es', 'es-co' => 'Spanish',
            'en', 'en-us', 'en-gb' => 'English',
            'pt', 'pt-br' => 'Portuguese',
            default => strtoupper($languageCode),
        };

        $summary = $wordCount >= 80
            ? 'Transcript length is strong enough to evaluate communication depth and content consistency.'
            : ($wordCount >= 30
                ? 'Transcript is usable, but deeper AI interpretation may still be limited.'
                : 'Transcript is too short; interpretation should be validated with CV and manual review.');

        return [
            'summary' => $summary,
            'word_count' => $wordCount,
            'language_code' => $languageCode,
            'language_label' => $languageLabel,
            'transcript' => $transcript,
        ];
    }
}

if (! function_exists('gsdCandidateAiTranscribeMedia')) {
    function gsdCandidateAiTranscribeMedia(array $candidate): ?array
    {
        $providers = [
            [
                'name' => 'openai',
                'keys' => gsdCandidateAiParseKeys((string) gsdRecruitmentEnv('OPENAI_API_KEY', '')),
                'url' => 'https://api.openai.com/v1/audio/transcriptions',
                'models' => ['gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1'],
                'max_size' => 25 * 1024 * 1024,
                'headers' => [],
            ],
            [
                'name' => 'groq',
                'keys' => gsdCandidateAiParseKeys((string) gsdRecruitmentEnv('GROQ_API_KEY', '')),
                'url' => 'https://api.groq.com/openai/v1/audio/transcriptions',
                'models' => gsdCandidateAiParseModelOrder(
                    (string) gsdRecruitmentEnv('GROQ_TRANSCRIBE_MODEL_ORDER', ''),
                    ['whisper-large-v3-turbo', 'whisper-large-v3']
                ),
                'max_size' => 25 * 1024 * 1024,
                'headers' => [],
            ],
        ];

        $hasAnyKey = false;
        foreach ($providers as $provider) {
            if (($provider['keys'] ?? []) !== []) {
                $hasAnyKey = true;
                break;
            }
        }

        if (! $hasAnyKey) {
            return null;
        }

        $filePaths = gsdCandidateAiResolveMediaPaths($candidate);
        if ($filePaths === []) {
            return null;
        }

        foreach ($filePaths as $filePath) {
            $fileSize = filesize($filePath);
            if (! is_int($fileSize) || $fileSize <= 0) {
                continue;
            }

            foreach ($providers as $provider) {
                if (($provider['keys'] ?? []) === []) {
                    continue;
                }

                if ($fileSize > (int) ($provider['max_size'] ?? PHP_INT_MAX)) {
                    continue;
                }

                foreach ((array) $provider['keys'] as $key) {
                    foreach ((array) $provider['models'] as $model) {
                        try {
                            $result = gsdCandidateAiTranscribeWithProvider(
                                (string) $provider['url'],
                                (string) $key,
                                $filePath,
                                (string) $model,
                                (string) $provider['name'],
                                (array) ($provider['headers'] ?? [])
                            );

                            if (is_array($result) && trim((string) ($result['text'] ?? '')) !== '') {
                                return $result;
                            }
                        } catch (Throwable $exception) {
                            error_log('[candidate-ai] transcription failed: '.$exception->getMessage());
                        }
                    }
                }
            }
        }

        return null;
    }
}

if (! function_exists('gsdCandidateAiTranscribeWithProvider')) {
    function gsdCandidateAiTranscribeWithProvider(string $url, string $key, string $filePath, string $model, string $provider, array $extraHeaders = []): ?array
    {
        $payload = [
            'file' => new CURLFile($filePath),
            'model' => $model,
            'response_format' => 'json',
        ];

        $headers = array_merge([
            'Authorization: Bearer '.$key,
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'GSD-ATS-HR/1.0',
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Transcription cURL failed: '.$error);
        }

        if ($code !== 200) {
            return null;
        }

        $json = json_decode($body, true);
        $text = trim((string) ($json['text'] ?? ''));
        if ($text === '') {
            return null;
        }

        return [
            'provider' => $provider.':'.$model,
            'text' => $text,
            'language_code' => (string) ($json['language'] ?? ''),
        ];
    }
}

if (! function_exists('gsdCandidateAiResolveMediaPaths')) {
    function gsdCandidateAiResolveMediaPaths(array $candidate): array
    {
        $relatives = array_values(array_filter(array_unique([
            trim((string) ($candidate['video_original_path'] ?? '')),
            trim((string) ($candidate['video_processed_path'] ?? '')),
        ])));

        $paths = [];
        foreach ($relatives as $relative) {
            $normalized = ltrim($relative, '/');
            foreach ([
                dirname(__DIR__).'/'.$normalized,
                dirname(__DIR__).'/apply/'.$normalized,
                dirname(__DIR__, 2).'/hostinger-release/public_html/candidates/'.$normalized,
            ] as $path) {
                if (is_file($path)) {
                    $paths[$path] = filesize($path) ?: PHP_INT_MAX;
                }
            }
        }

        asort($paths);

        return array_keys($paths);
    }
}

if (! function_exists('gsdCandidateAiStrengths')) {
    function gsdCandidateAiStrengths(array $skills, array $roleScores, array $english, array $visual): array
    {
        $strengths = [];
        if (! empty($skills)) {
            $strengths[] = 'Documented skill stack extracted from CV and questionnaire.';
        }
        if (($roleScores[0]['score'] ?? 0) >= 75) {
            $strengths[] = 'Strong alignment with the primary role family.';
        }
        if (($english['score'] ?? 0) >= 70) {
            $strengths[] = 'English communication appears solid for client-facing work.';
        }
        if (($visual['alignment_score'] ?? 0) >= 70) {
            $strengths[] = 'Non-verbal delivery supports the spoken message.';
        }
        return $strengths !== [] ? $strengths : ['Candidate has enough structured data for review, but strengths need deeper confirmation.'];
    }
}

if (! function_exists('gsdCandidateAiRisks')) {
    function gsdCandidateAiRisks(array $transcriptSummary, array $spontaneity, array $english): array
    {
        $risks = [];
        if (($transcriptSummary['word_count'] ?? 0) < 35) {
            $risks[] = 'Short transcript limits confidence in the communication assessment.';
        }
        if (($spontaneity['score'] ?? 100) < 50) {
            $risks[] = 'Delivery may be scripted or too dependent on a prepared message.';
        }
        if (($english['score'] ?? 100) > 0 && ($english['score'] ?? 100) < 60) {
            $risks[] = 'English level may be below the threshold for advanced communication roles.';
        }
        return $risks !== [] ? $risks : ['No major AI-based red flags detected from the current evidence.'];
    }
}

if (! function_exists('gsdCandidateAiDecodeJson')) {
    function gsdCandidateAiDecodeJson(mixed $value): array
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
}

if (! function_exists('gsdCandidateAiNormalizeList')) {
    function gsdCandidateAiNormalizeList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn (mixed $item): string => trim((string) $item), $value)));
        }

        $decoded = gsdCandidateAiDecodeJson($value);
        if ($decoded !== []) {
            return gsdCandidateAiNormalizeList($decoded);
        }

        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', (string) $value) ?: [])));
    }
}
