<?php

declare(strict_types=1);

namespace Ngramx\Auth;

/**
 * PHP that runs inside the app container to mint identity magic-link
 * paths, plus the shell wrapper that delivers it and the parser for its
 * `ok|skip|warn` protocol.
 *
 * The script talks in `ok|skip|warn` lines so a failure never prints a
 * token, and so projects that do not use gigabyte/laravel-identity stay quiet.
 */
final class AuthBypassScript
{
    public static function source(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

$email = getenv('NGRAMX_BYPASS_EMAIL');
$ttl = (int) getenv('NGRAMX_BYPASS_TTL');

if (!is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    echo "warn bad-email\n";
    exit(0);
}

$email = strtolower($email);
if ($ttl < 1 || $ttl > 1440) {
    $ttl = 480;
}

$root = null;
foreach ([getcwd() ?: '', '/var/www/html', '/app'] as $candidate) {
    if ($candidate !== '' && is_file($candidate . '/artisan') && is_file($candidate . '/vendor/autoload.php')) {
        $root = $candidate;
        break;
    }
}

if ($root === null) {
    echo "skip no-laravel\n";
    exit(0);
}

chdir($root);
require $root . '/vendor/autoload.php';

/** @var Illuminate\Foundation\Application $app */
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (!$app->environment(['local', 'testing', 'development', 'dev'])) {
    echo "warn not-local\n";
    exit(0);
}

if (!class_exists(Gigabyte\Identity\Models\LoginCode::class)) {
    echo "skip identity-missing\n";
    exit(0);
}

$routeName = config('identity.magic_link_route');
if (!is_string($routeName) || $routeName === '') {
    echo "warn no-magic-route\n";
    exit(0);
}

$userModel = config('identity.user_model');
if (!is_string($userModel) || !class_exists($userModel)) {
    echo "warn user-missing\n";
    exit(0);
}

$user = $userModel::query()->where('email', $email)->first();
if ($user === null) {
    echo "warn user-missing\n";
    exit(0);
}

$redirects = [null];
$encodedRedirects = getenv('NGRAMX_BYPASS_REDIRECTS');
if (is_string($encodedRedirects) && $encodedRedirects !== '') {
    $decoded = json_decode(base64_decode($encodedRedirects, true) ?: '', true);
    if (is_array($decoded)) {
        foreach ($decoded as $candidate) {
            if (is_string($candidate) && str_starts_with($candidate, '/') && !str_starts_with($candidate, '//') && !str_contains($candidate, '://')) {
                $redirects[] = $candidate;
            }
        }
    }
}

try {
    foreach ($redirects as $redirect) {
        $token = Illuminate\Support\Str::random(64);
        Gigabyte\Identity\Models\LoginCode::query()->create([
            'email' => $email,
            'code' => str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT),
            'token' => $token,
            'expires_at' => Illuminate\Support\Carbon::now()->addMinutes($ttl),
        ]);

        $path = route($routeName, ['token' => $token], false);
        if (!is_string($path) || $path === '') {
            echo "warn no-magic-route\n";
            exit(0);
        }

        if (is_string($redirect) && $redirect !== '') {
            $path .= (str_contains($path, '?') ? '&' : '?') . 'redirect=' . rawurlencode($redirect);
        }

        echo 'ok ' . $path . "\n";
    }
} catch (Throwable $e) {
    $name = (new ReflectionClass($e))->getShortName();
    echo 'warn failed ' . $name . "\n";
}

exit(0);
PHP;
    }

    /**
     * Shell run via `docker compose exec sh -c`. The script is base64 so the
     * email and the PHP source never share a quoting context.
     *
     * @param list<string> $redirects Local paths. Each gets its own magic token.
     */
    public static function shellCommand(string $email, int $ttlMinutes, array $redirects = []): string
    {
        $tmp = '/tmp/ngramx-auth-bypass-' . bin2hex(random_bytes(8)) . '.php';
        $encoded = base64_encode(self::source());
        $writer = escapeshellarg(
            'file_put_contents(' . var_export($tmp, true) . ', base64_decode(stream_get_contents(STDIN)));'
        );

        $redirectEnv = '';
        if ($redirects !== []) {
            $json = json_encode($redirects);
            if (is_string($json)) {
                $redirectEnv = ' NGRAMX_BYPASS_REDIRECTS=' . escapeshellarg(base64_encode($json));
            }
        }

        return 'php -r ' . $writer . " <<'NGRAMX_BYPASS_B64'\n"
            . $encoded . "\n"
            . "NGRAMX_BYPASS_B64\n"
            . 'NGRAMX_BYPASS_EMAIL=' . escapeshellarg($email)
            . ' NGRAMX_BYPASS_TTL=' . $ttlMinutes
            . $redirectEnv
            . ' php ' . $tmp . "\n"
            . "status=\$?\n"
            . 'rm -f ' . $tmp . "\n"
            . "exit \$status\n";
    }

    public static function parse(string $stdout): AuthBypassAttempt
    {
        $ok = [];
        $skip = null;
        $warn = null;
        $lines = preg_split("/\r\n|\n|\r/", $stdout) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^(ok|skip|warn)\s+(\S.*)$/', $line, $matches) === 1) {
                $kind = $matches[1];
                $rest = trim($matches[2]);
            } elseif (preg_match('/^(ok|skip|warn)$/', $line, $matches) === 1) {
                $kind = $matches[1];
                $rest = '';
            } else {
                continue;
            }

            if ($kind === 'ok') {
                if (preg_match('#^(https?://\S+|/\S*)$#', $rest) === 1) {
                    $ok[] = $rest;
                } else {
                    $warn = 'failed';
                }
                continue;
            }

            if ($kind === 'skip') {
                $skip = $rest !== '' ? $rest : 'skipped';
                continue;
            }

            $warn = $rest !== '' ? $rest : 'failed';
        }

        if ($ok !== []) {
            return new AuthBypassAttempt('ok', $ok[0], null, $ok);
        }

        if ($warn !== null) {
            return new AuthBypassAttempt('warn', null, $warn);
        }

        if ($skip !== null) {
            return new AuthBypassAttempt('skip', null, $skip);
        }

        // The container never spoke the protocol (no PHP, exec failed).
        // Stay quiet — only a script that ran and refused is worth a warning.
        return new AuthBypassAttempt('skip', null, 'no-protocol');
    }
}
