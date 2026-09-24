<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Postmaclone\Exception\PostmacloneException;

/**
 * Ensures a human 1Password CLI session exists before op:// reads.
 *
 * On an interactive terminal, runs `op signin` when needed. ngramx never
 * reads or stores your 1Password password — op prompts on /dev/tty directly.
 */
final class OpSessionEnsurer
{
    private static bool $attemptedSignIn = false;

    public function __construct(
        private readonly OpAuthProbe $probe = new OpAuthProbe(),
    ) {
    }

    public function ensureSignedIn(): void
    {
        if (self::env('OP_SERVICE_ACCOUNT_TOKEN') !== '') {
            return;
        }

        if ($this->probe->isSignedIn()) {
            return;
        }

        if (self::$attemptedSignIn) {
            throw new PostmacloneException(
                '1Password CLI has no active session after a sign-in attempt. '
                . 'Run: eval $(op signin)   # then retry'
            );
        }

        self::$attemptedSignIn = true;

        if (!$this->hasTty()) {
            throw new PostmacloneException(
                '1Password CLI has no active session and this is not an interactive terminal. '
                . 'Run: eval $(op signin)   # in this shell, then retry'
            );
        }

        if (!S3Credentials::isOpAvailable()) {
            throw new PostmacloneException(
                '1Password CLI (op) is not on PATH. Install from ' . S3Credentials::OP_INSTALL_URL
            );
        }

        $account = $this->resolveAccountShorthand();

        fwrite(STDERR, "1Password sign-in required for this shell — follow the op prompt…\n");

        // Without --force, op prints "run eval $(op signin)" to stderr. On WSL we
        // still need this primer before --raw --force or sign-in fails after the
        // password prompt; discard its output so the user only sees the prompt once.
        if (HostEnvironment::isWsl()) {
            $this->runAttachedToTty(
                ['op', 'signin', '--account', $account],
                stdoutPath: '/dev/null',
                stderrPath: '/dev/null',
            );
        } else {
            $this->runInteractiveSignIn(['op', 'signin', '--account', $account, '--force']);
            if ($this->probe->isSignedIn()) {
                return;
            }
        }

        $token = $this->captureRawSessionToken($account);
        if ($token === null || $token === '') {
            throw new PostmacloneException('1Password sign-in failed or was cancelled.');
        }

        $this->applySessionToken($account, $token);

        if (!$this->probe->isSignedIn()) {
            throw new PostmacloneException(
                '1Password sign-in did not establish a session. Run: eval $(op signin)   # then retry'
            );
        }
    }

    private function captureRawSessionToken(string $account): ?string
    {
        $outputPath = tempnam(sys_get_temp_dir(), 'ngramx-op-signin-');
        if ($outputPath === false) {
            throw new PostmacloneException('Failed to prepare temporary file for 1Password sign-in.');
        }

        try {
            $ok = $this->runAttachedToTty(
                ['op', 'signin', '--account', $account, '--raw', '--force'],
                stdoutPath: $outputPath,
            );
            if (!$ok) {
                return null;
            }

            $token = file_get_contents($outputPath);

            return is_string($token) ? trim($token) : null;
        } finally {
            @unlink($outputPath);
        }
    }

    /**
     * @param list<string> $command
     */
    private function runInteractiveSignIn(array $command): bool
    {
        return $this->runAttachedToTty($command);
    }

    private function resolveAccountShorthand(): string
    {
        $fromEnv = self::env('OP_ACCOUNT');
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $accounts = $this->probe->listAccountShorthands();
        if ($accounts === []) {
            throw new PostmacloneException(
                'No op account configured. Run: op account add   # sign-in address: '
                . OpAuthProbe::SIGNIN_ADDRESS
            );
        }

        return $accounts[0];
    }

    private function applySessionToken(string $account, string $token): void
    {
        $sessionVar = 'OP_SESSION_' . $account;
        putenv('OP_ACCOUNT=' . $account);
        putenv($sessionVar . '=' . $token);
        $_ENV['OP_ACCOUNT'] = $account;
        $_ENV[$sessionVar] = $token;
    }

    /**
     * @param list<string> $command
     */
    private function runAttachedToTty(
        array $command,
        ?string $stdoutPath = null,
        ?string $stderrPath = null,
    ): bool {
        if (!file_exists('/dev/tty')) {
            throw new PostmacloneException('No /dev/tty available for interactive 1Password sign-in.');
        }

        $descriptors = [
            0 => ['file', '/dev/tty', 'r'],
            1 => ['file', $stdoutPath ?? '/dev/tty', 'w'],
            2 => ['file', $stderrPath ?? '/dev/tty', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new PostmacloneException('Failed to start 1Password sign-in.');
        }

        $exit = proc_close($process);

        return $exit === 0;
    }

    private function hasTty(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDIN);
    }

    private static function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) ? trim($value) : '';
    }
}
