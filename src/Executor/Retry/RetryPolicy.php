<?php

declare(strict_types=1);

namespace Ngramx\Executor\Retry;

use Ngramx\Config\Schema\CommandDefinition;

/**
 * Decides whether a failed command should be run again, and how long to wait
 * first.
 *
 * Commands that ngramx runs inside a container fail for two very different
 * reasons, and they want opposite treatment:
 *
 *  - The command itself is unhappy — a failing test, a syntax error, a bad
 *    migration. Re-running it burns minutes and reports the same failure, so
 *    we surface it immediately.
 *  - The *environment* was not ready — the container had just been recreated,
 *    a sibling step was still installing dependencies, the database socket was
 *    not accepting connections yet, a bind mount had gone stale. These are the
 *    failures that make `fresh` look flaky, and they almost always pass on a
 *    second attempt a few seconds later.
 *
 * So retries are automatic but *classified*: only output that matches a known
 * environmental signature is retried. A project can override the judgement per
 * command with `retry:` in ngramx.yml — set it and every failure of that
 * command is retried that many times, set it to `0` to never retry.
 */
final class RetryPolicy
{
    /**
     * Retries (i.e. attempts after the first) applied to a transient failure
     * when the command has no explicit `retry:`.
     */
    public const DEFAULT_RETRIES = 2;

    /**
     * Backoff base. The pause grows linearly per retry (3s, then 6s), which is
     * long enough for an install or a database socket to finish settling
     * without noticeably slowing down a run that is going to fail anyway.
     */
    public const BASE_DELAY_SECONDS = 3;

    /**
     * Output signatures that mean "the environment was not ready", matched
     * case-insensitively against both stdout and stderr.
     *
     * @var list<string>
     */
    private const TRANSIENT_PATTERNS = [
        // Container / daemon level.
        'is not running',
        'no such container',
        'cannot connect to the docker daemon',
        'error response from daemon',
        'container is marked for removal',
        // Bind mount replaced under a running container: the working directory
        // resolves to nothing, so tools report a path they cannot print. This
        // is what a recreated worktree does to a stack that was left up.
        'error while creating mount source path',
        'bind source path does not exist',
        'invalid mount config',
        'getcwd() failed',
        'shell-init: error retrieving current directory',
        // Service not accepting connections yet.
        'connection refused',
        'connection reset by peer',
        'could not connect to server',
        'server closed the connection unexpectedly',
        'no route to host',
        'network is unreachable',
        'temporary failure in name resolution',
        'could not resolve host',
        'sqlstate[hy000] [2002]',
        'sqlstate[hy000] [2006]',
        'sqlstate[08006]',
        'lock wait timeout exceeded',
        'deadlock found when trying to get lock',
        // Dependencies not installed yet — a step that fired before (or
        // alongside) the install that provides its binaries or autoloader.
        'command not found',
        'could not open input file',
        'failed opening required',
        'vendor/autoload.php',
        // Filesystem still settling (parallel installs touching the same tree).
        'text file busy',
        'device or resource busy',
        'resource temporarily unavailable',
    ];

    /**
     * Exit codes that describe a container/runtime problem rather than a
     * command result: 125 is "docker itself could not run this", 137 is a
     * SIGKILL (typically the OOM killer).
     *
     * @var list<int>
     */
    private const TRANSIENT_EXIT_CODES = [125, 137];

    /**
     * Signatures that need more than a substring to be safe. Composer prints
     * the directory it searched, so `... file in /app` is a real missing
     * composer.json (do not retry) while `... file in` with nothing after it
     * means `getcwd()` returned empty — the mount vanished under the
     * container, which is exactly the stale-worktree case.
     *
     * @var list<string>
     */
    private const TRANSIENT_REGEXES = [
        '/could not find a composer\\.json file in\\s*$/m',
    ];

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param callable(int): void|null $sleep Test seam for the inter-attempt delay
     */
    public function __construct(?callable $sleep = null)
    {
        $this->sleep = $sleep !== null
            ? $sleep(...)
            : static function (int $seconds): void {
                sleep($seconds);
            };
    }

    /**
     * Total attempts allowed for a command, including the first one.
     */
    public function attemptsFor(CommandDefinition $cmd): int
    {
        return max(1, ($cmd->retry ?? self::DEFAULT_RETRIES) + 1);
    }

    /**
     * Should this failure be retried at all?
     *
     * An explicit `retry:` is the project stating that this command is worth
     * retrying whatever the reason; without one, only recognised environmental
     * failures qualify.
     */
    public function shouldRetry(CommandDefinition $cmd, int $exitCode, string ...$outputs): bool
    {
        if ($cmd->retry !== null) {
            return $cmd->retry > 0;
        }

        return $this->isTransient($exitCode, ...$outputs);
    }

    /**
     * True when the failure looks like the environment rather than the command.
     */
    public function isTransient(int $exitCode, string ...$outputs): bool
    {
        if (in_array($exitCode, self::TRANSIENT_EXIT_CODES, true)) {
            return true;
        }

        $haystack = strtolower(implode("\n", $outputs));
        if ($haystack === '') {
            return false;
        }

        foreach (self::TRANSIENT_PATTERNS as $pattern) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        foreach (self::TRANSIENT_REGEXES as $regex) {
            if (preg_match($regex, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the failure points at the container itself being unusable —
     * gone, or holding a mount that no longer resolves. Retrying the same
     * command inside that container is pointless; it has to be recreated
     * first.
     */
    public function needsContainerRecreate(string ...$outputs): bool
    {
        $haystack = strtolower(implode("\n", $outputs));

        foreach (
            [
                'is not running',
                'no such container',
                'container is marked for removal',
                'error while creating mount source path',
                'bind source path does not exist',
                'invalid mount config',
                'getcwd() failed',
                'shell-init: error retrieving current directory',
            ] as $pattern
        ) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        foreach (self::TRANSIENT_REGEXES as $regex) {
            if (preg_match($regex, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the failure points at a *dependency* of the primary service
     * being unreachable — the database or cache the command needs, rather than
     * the container the command runs in.
     *
     * This is a different repair from {@see needsContainerRecreate}. There the
     * container executing the command is broken; here that container is
     * perfectly healthy and something it talks to has gone away. Recreating the
     * primary service would not help, and retrying is worse than useless:
     *
     *     ERROR 2005 (HY000): Unknown MySQL server host 'mysql' (-3)
     *     SQLSTATE[HY000] [2002] php_network_getaddresses: getaddrinfo failed
     *
     * reads like a database or DNS fault but means the mysql container no
     * longer exists, so Docker's embedded DNS has nothing to resolve. On the
     * shared agent host that happens because the kernel's OOM killer takes
     * mysql — the largest process in the stack — and, absent a restart policy,
     * it stays dead. Three retries then produce three identical failures.
     *
     * Matching is deliberately narrow: only "I cannot reach a host" signatures,
     * not general connection errors that a service still warming up would also
     * produce. A wrong positive costs one restart of an already-stopped
     * service, so the bias is acceptable, but a *connection refused* against a
     * booting database is genuinely transient and better left to the retry.
     */
    public function needsDependencyRestart(string ...$outputs): bool
    {
        $haystack = strtolower(implode("\n", $outputs));

        foreach (
            [
                // mysql client: -3 is EAI_NONAME, the name does not exist.
                'unknown mysql server host',
                'unknown server host',
                'unknown host',
                // glibc / PHP resolver, reached through PDO or the CLI.
                'temporary failure in name resolution',
                'name or service not known',
                'could not resolve host',
                'php_network_getaddresses',
                // PDO's "cannot connect" state, which mysql reports for a
                // hostname it could not resolve as well as for a refused port.
                'sqlstate[hy000] [2002]',
            ] as $pattern
        ) {
            if (str_contains($haystack, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pause before the given retry ($retryNumber is 1 for the first retry).
     */
    public function pauseBeforeRetry(int $retryNumber): void
    {
        ($this->sleep)(self::BASE_DELAY_SECONDS * max(1, $retryNumber));
    }
}
