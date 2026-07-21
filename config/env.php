<?php
// config/env.php
// Minimal .env loader — no Composer, no external libraries, matching the
// rest of this project (native PHP only). Reads KEY=VALUE lines from a
// .env file in the project root (already git-ignored) into getenv().
//
// Usage: require_once 'config/env.php'; then getenv('SOME_KEY') anywhere.
//
// If .env doesn't exist (e.g. a fresh clone before setup), this silently
// does nothing — code that reads these values is expected to fall back to
// a safe local-dev default, not fail hard.

function load_env($path)
{
    if (!is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);

        // Skip comments
        if ($line === '' || $line[0] === '#') {
            continue;
        }

        if (strpos($line, '=') === false) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Strip matching surrounding quotes, e.g. KEY="some value"
        if (
            strlen($value) >= 2 &&
            (($value[0] === '"' && $value[-1] === '"') ||
                ($value[0] === "'" && $value[-1] === "'"))
        ) {
            $value = substr($value, 1, -1);
        }

        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

load_env(__DIR__ . '/../.env');
