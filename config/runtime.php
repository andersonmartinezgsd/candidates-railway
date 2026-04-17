<?php

if (! function_exists('gsdRecruitmentLoadEnv')) {
    function gsdRecruitmentNormalizeEnvValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (preg_match('/^(["\'])(.*)\1(?:\s+#.*)?$/', $value, $matches) === 1) {
            $value = $matches[2];
        } else {
            $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
        }

        return trim($value);
    }

    /**
     * Load recruitment env values from a private Hostinger location first,
     * then fall back to local development files.
     *
     * @return array<string, string>
     */
    function gsdRecruitmentLoadEnv(): array
    {
        static $env = null;

        if (is_array($env)) {
            return $env;
        }

        $root = dirname(__DIR__);
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        $isStagingHost = str_contains($host, 'staging-candidates');
        $paths = array_values(array_filter(array_unique([
            getenv('GSD_RECRUITMENT_ENV') ?: null,
            $root.'/.env.local.dev',
            dirname($root).'/.env.local.dev',
            $isStagingHost ? dirname($root, 2).'/private/staging-candidates/.env' : null,
            $isStagingHost ? dirname($root, 2).'/private/staging-candidates.env' : null,
            dirname($root, 2).'/private/candidates/.env',
            dirname($root, 2).'/private/candidates.env',
            $root.'/.env',
            dirname($root).'/.env',
        ])));

        $env = [];

        foreach ($paths as $path) {
            if (! is_readable($path)) {
                continue;
            }

            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);

                if ($line === '' || $line[0] === '#' || ! str_contains($line, '=')) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $env[trim($key)] = gsdRecruitmentNormalizeEnvValue($value);
            }

            $env['__path'] = $path;
            break;
        }

        return $env;
    }
}

if (! function_exists('gsdRecruitmentEnv')) {
    function gsdRecruitmentEnv(string $key, ?string $default = null): ?string
    {
        $env = gsdRecruitmentLoadEnv();
        $value = $env[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }
}

if (! function_exists('gsdRecruitmentBasePath')) {
    function gsdRecruitmentBasePath(): string
    {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $directory = dirname($scriptName);

        if ($directory === '.' || $directory === DIRECTORY_SEPARATOR) {
            return '';
        }

        $directory = rtrim($directory, '/');

        foreach (['/api', '/viewer', '/views'] as $suffix) {
            if ($directory === $suffix) {
                return '';
            }

            if (str_ends_with($directory, $suffix)) {
                return substr($directory, 0, -strlen($suffix));
            }
        }

        return $directory;
    }
}

if (! function_exists('gsdRecruitmentBaseUrl')) {
    function gsdRecruitmentBaseUrl(string $path = ''): string
    {
        $https = (
            (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        );

        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $url = $scheme.'://'.$host;
        $basePath = trim(gsdRecruitmentBasePath(), '/');

        if ($basePath !== '') {
            $url .= '/'.$basePath;
        }

        if ($path !== '') {
            $url .= '/'.ltrim($path, '/');
        }

        return $url;
    }
}

if (! function_exists('gsdRecruitmentUploadsBaseUrl')) {
    function gsdRecruitmentUploadsBaseUrl(): string
    {
        $explicit = gsdRecruitmentEnv('RECRUITMENT_UPLOADS_BASE_URL')
            ?? gsdRecruitmentEnv('CANDIDATES_BASE_URL')
            ?? gsdRecruitmentEnv('CANDIDATE_PORTAL_URL');

        if (is_string($explicit) && trim($explicit) !== '') {
            return rtrim(trim($explicit), '/');
        }

        return rtrim(gsdRecruitmentBaseUrl(), '/');
    }
}
