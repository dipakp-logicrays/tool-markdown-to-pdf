<?php
declare(strict_types=1);

/**
 * Minimal .env loader.
 *
 * Reads simple KEY=VALUE lines from the project's `.env` file once per
 * request, cached in a static. Real environment variables (set via
 * `export`, Apache `SetEnv`, etc.) take precedence over `.env` values.
 *
 * No quoting or interpolation supported — keep `.env` simple.
 *
 * @param string      $key
 * @param string|null $default
 * @return string|null
 */
function envGet(string $key, ?string $default = null): ?string
{
    static $env = null;
    if ($env === null) {
        $env = [];
        $file = __DIR__ . '/../.env';
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $env[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
    }
    $real = getenv($key);
    if ($real !== false && $real !== '') {
        return $real;
    }
    return $env[$key] ?? $default;
}
