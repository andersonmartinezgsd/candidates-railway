<?php

require_once __DIR__.'/../config/runtime.php';

if (! function_exists('gsdViewerVisibleStatuses')) {
    /**
     * @return list<string>
     */
    function gsdViewerVisibleStatuses(): array
    {
        return ['completed', 'client_review', 'interviewing', 'pending', 'reviewing', 'hired'];
    }
}

if (! function_exists('gsdViewerStatusListSql')) {
    function gsdViewerStatusListSql(): string
    {
        return implode(', ', array_map(
            static fn (string $status): string => "'".str_replace("'", "''", $status)."'",
            gsdViewerVisibleStatuses()
        ));
    }
}

if (! function_exists('gsdViewerVisibleCandidateClause')) {
    function gsdViewerVisibleCandidateClause(string $alias = 'c'): string
    {
        return sprintf(
            "%s.processing_status IN (%s)
            AND (%s.name IS NULL OR %s.name <> 'Draft Candidate')
            AND (%s.email IS NULL OR %s.email NOT LIKE 'draft+%%@local.gsd')
            AND (%s.token IS NULL OR %s.token NOT LIKE 'TMP-%%')",
            $alias,
            gsdViewerStatusListSql(),
            $alias,
            $alias,
            $alias,
            $alias,
            $alias,
            $alias
        );
    }
}

if (! function_exists('gsdViewerBuildUrl')) {
    function gsdViewerBuildUrl(string $path = '', array $query = []): string
    {
        $url = gsdRecruitmentBaseUrl(ltrim($path, '/'));

        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }
}

if (! function_exists('gsdViewerNormalizeUploadPath')) {
    function gsdViewerNormalizeUploadPath(?string $rawPath): string
    {
        $path = trim((string) $rawPath);

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
}

if (! function_exists('gsdViewerPublicUploadUrl')) {
    function gsdViewerPublicUploadUrl(?string $rawPath): string
    {
        $path = gsdViewerNormalizeUploadPath($rawPath);

        if ($path === '') {
            return '';
        }

        $explicitBase = trim((string) (gsdRecruitmentUploadsBaseUrl() ?: ''));
        $portalBase = $explicitBase !== ''
            ? preg_replace('#/viewer(?:/api)?$#', '', rtrim($explicitBase, '/')) ?? rtrim($explicitBase, '/')
            : '';

        if ($portalBase === '') {
            $portalBase = gsdRecruitmentBaseUrl();
        }

        return rtrim($portalBase, '/').'/'.ltrim($path, '/');
    }
}

if (! function_exists('gsdViewerCandidateStreamUrl')) {
    function gsdViewerCandidateStreamUrl(array $candidate, ?string $format = null, string $source = 'auto'): string
    {
        $token = trim((string) ($candidate['token'] ?? ''));

        if ($token !== '') {
            $query = ['token' => $token];

            if ($format !== null && $format !== '') {
                $query['format'] = $format;
            }

            if ($source !== 'auto') {
                $query['source'] = $source;
            }

            return gsdViewerBuildUrl('apply/views/stream.php', $query);
        }

        $fallbackPath = $source === 'original'
            ? ($candidate['video_original_path'] ?? '')
            : ($candidate['video_processed_path'] ?: $candidate['video_original_path'] ?? '');

        return gsdViewerPublicUploadUrl((string) $fallbackPath);
    }
}
