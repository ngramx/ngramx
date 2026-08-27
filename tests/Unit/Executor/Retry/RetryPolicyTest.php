<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Executor\Retry;

use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Executor\Retry\RetryPolicy;
use PHPUnit\Framework\TestCase;

class RetryPolicyTest extends TestCase
{
    public function test_a_command_without_an_explicit_retry_gets_the_default_attempts(): void
    {
        $policy = new RetryPolicy();

        $this->assertSame(
            RetryPolicy::DEFAULT_RETRIES + 1,
            $policy->attemptsFor(new CommandDefinition(command: 'x', description: '')),
        );
    }

    public function test_an_explicit_retry_sets_the_attempt_count(): void
    {
        $policy = new RetryPolicy();

        $this->assertSame(6, $policy->attemptsFor(new CommandDefinition(command: 'x', description: '', retry: 5)));
    }

    public function test_retry_zero_means_a_single_attempt(): void
    {
        $policy = new RetryPolicy();
        $cmd = new CommandDefinition(command: 'x', description: '', retry: 0);

        $this->assertSame(1, $policy->attemptsFor($cmd));
        $this->assertFalse(
            $policy->shouldRetry($cmd, 1, 'connection refused'),
            'An explicit retry: 0 opts out even of transient failures'
        );
    }

    /**
     * The whole point of classifying: a failing test suite or a broken
     * migration is the command telling us the truth, and running it twice more
     * just triples the wait before the user sees it.
     */
    public function test_a_genuine_command_failure_is_not_retried(): void
    {
        $policy = new RetryPolicy();

        $this->assertFalse($policy->shouldRetry(
            new CommandDefinition(command: 'phpunit', description: ''),
            1,
            "FAILURES!\nTests: 12, Assertions: 30, Failures: 1.",
            '',
        ));
    }

    public function test_an_explicit_retry_overrides_the_classification(): void
    {
        $policy = new RetryPolicy();

        $this->assertTrue(
            $policy->shouldRetry(
                new CommandDefinition(command: 'phpunit', description: '', retry: 2),
                1,
                'Tests: 12, Failures: 1.',
            ),
            'Setting retry: explicitly is the project saying "retry this whatever the reason"'
        );
    }

    /**
     * @param list<string> $outputs
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('transientOutputs')]
    public function test_environmental_failures_are_transient(string $case, array $outputs): void
    {
        $policy = new RetryPolicy();

        $this->assertTrue($policy->isTransient(1, ...$outputs), $case);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function transientOutputs(): array
    {
        return [
            'container gone' => ['service "app" is not running', ['service "app" is not running']],
            'daemon hiccup' => ['docker daemon', ['Cannot connect to the Docker daemon at unix:///var/run/docker.sock']],
            'db not listening' => ['pg refused', ['SQLSTATE[08006] [7] connection refused']],
            'mysql not listening' => ['mysql refused', ['SQLSTATE[HY000] [2002] Connection refused']],
            'dns not ready' => ['dns', ['php_network_getaddresses: Temporary failure in name resolution']],
            'deps not installed yet' => ['autoload', ['Failed opening required /app/vendor/autoload.php']],
            'binary not there yet' => ['not found', ['bash: line 1: artisan: command not found']],
            'mount replaced' => ['stale mount', ['docker: Error while creating mount source path']],
            'cwd vanished' => [
                'empty composer path',
                ["Composer could not find a composer.json file in \nTo initialize a project, please create one"],
            ],
            'error on stderr only' => ['stderr', ['', 'shell-init: error retrieving current directory']],
        ];
    }

    /**
     * Composer prints the directory it searched. With a path present this is a
     * real missing composer.json — retrying cannot conjure one up. With nothing
     * after "in", `getcwd()` came back empty, which means the mount the
     * container is holding no longer resolves.
     */
    public function test_a_genuinely_missing_composer_json_is_not_transient(): void
    {
        $policy = new RetryPolicy();

        $this->assertFalse($policy->isTransient(
            1,
            "Composer could not find a composer.json file in /app\nTo initialize a project, please create one",
        ));
    }

    public function test_container_runtime_exit_codes_are_transient(): void
    {
        $policy = new RetryPolicy();

        $this->assertTrue($policy->isTransient(125, ''), 'docker could not run the command at all');
        $this->assertTrue($policy->isTransient(137, ''), 'SIGKILL, typically the OOM killer');
        $this->assertFalse($policy->isTransient(1, ''), 'a plain failure with no output tells us nothing');
    }

    public function test_only_container_level_failures_ask_for_a_recreate(): void
    {
        $policy = new RetryPolicy();

        $this->assertTrue($policy->needsContainerRecreate('service "app" is not running'));
        $this->assertTrue($policy->needsContainerRecreate("could not find a composer.json file in \n"));
        $this->assertFalse(
            $policy->needsContainerRecreate('SQLSTATE[08006] [7] connection refused'),
            'A database still booting is transient but the app container is fine — recreating it would be pure cost'
        );
    }

    public function test_the_backoff_grows_with_each_retry(): void
    {
        $sleeps = [];
        $policy = new RetryPolicy(static function (int $seconds) use (&$sleeps): void {
            $sleeps[] = $seconds;
        });

        $policy->pauseBeforeRetry(1);
        $policy->pauseBeforeRetry(2);
        $policy->pauseBeforeRetry(3);

        $this->assertSame([3, 6, 9], $sleeps);
    }
}
