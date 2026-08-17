<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Docker\ContainerExecutor;
use Ngramx\Worktree\WorktreeVhostAliaser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The substance of this class is the shell script it runs inside the container,
 * so these tests run that script for real against fixture config trees rather
 * than asserting on a mock. NGRAMX_CONFIG_ROOT points it at a temp directory.
 */
class WorktreeVhostAliaserTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ngramx-vhost-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            exec('rm -rf ' . escapeshellarg($this->root));
        }
    }

    public function test_it_adds_a_server_alias_to_an_apache_vhost(): void
    {
        $conf = $this->apacheVhost('dev.hydra');

        $aliased = $this->aliaser()->alias('compose.yml', 'app', ['gig-2857-hydra-main.localhost']);

        $this->assertTrue($aliased);
        $this->assertStringContainsString('ServerAlias gig-2857-hydra-main.localhost', $this->read($conf));
    }

    public function test_the_alias_sits_inside_the_vhost_next_to_its_server_name(): void
    {
        // A ServerAlias outside <VirtualHost> is a config error, so placement
        // matters as much as presence.
        $conf = $this->apacheVhost('dev.hydra');

        $this->aliaser()->alias('compose.yml', 'app', ['gig-2857-hydra-main.localhost']);

        $lines = array_map('trim', explode("\n", $this->read($conf)));
        $nameAt = array_search('ServerName dev.hydra', $lines, true);
        $aliasAt = array_search('ServerAlias gig-2857-hydra-main.localhost', $lines, true);
        $closeAt = array_search('</VirtualHost>', $lines, true);

        // array_search widens to int|string|false; pin all three before comparing.
        $this->assertIsInt($nameAt);
        $this->assertIsInt($aliasAt, 'the alias line should exist');
        $this->assertIsInt($closeAt);
        $this->assertSame($nameAt + 1, $aliasAt, 'the alias should follow ServerName');
        $this->assertLessThan($closeAt, $aliasAt, 'the alias must be inside the vhost');
    }

    public function test_it_aliases_every_enabled_vhost(): void
    {
        // hydra serves four: the app, its API, the customer and supplier sites.
        $main = $this->apacheVhost('dev.hydra');
        $api = $this->apacheVhost('dev.api.hydra', 'dev.api.hydra.conf');

        $this->aliaser()->alias('compose.yml', 'app', ['gig-2857-hydra-main.localhost']);

        $this->assertStringContainsString('ServerAlias gig-2857', $this->read($main));
        $this->assertStringContainsString('ServerAlias gig-2857', $this->read($api));
    }

    public function test_running_it_twice_does_not_duplicate_the_alias(): void
    {
        // `worktree` re-runs against existing environments on every follow-up.
        $conf = $this->apacheVhost('dev.hydra');

        $this->aliaser()->alias('compose.yml', 'app', ['gig-2857-hydra-main.localhost']);
        $second = $this->aliaser()->alias('compose.yml', 'app', ['gig-2857-hydra-main.localhost']);

        $this->assertFalse($second, 'nothing changed, so it should report no reload');
        $this->assertSame(1, substr_count($this->read($conf), 'ServerAlias gig-2857-hydra-main.localhost'));
    }

    public function test_it_extends_an_nginx_server_name(): void
    {
        $conf = $this->root . '/etc/nginx/conf.d/site.conf';
        $this->write($conf, "server {\n    listen 80;\n    server_name dev.hydra;\n}\n");

        $aliased = $this->aliaser()->alias('compose.yml', 'app', ['gig-2857-hydra-main.localhost']);

        $this->assertTrue($aliased);
        $this->assertStringContainsString(
            'server_name dev.hydra gig-2857-hydra-main.localhost;',
            $this->read($conf)
        );
    }

    public function test_it_does_nothing_when_the_container_runs_neither_apache_nor_nginx(): void
    {
        // A php-fpm-only primary service is common; it must not be an error.
        mkdir($this->root, 0777, true);

        $this->assertFalse($this->aliaser()->alias('compose.yml', 'app', ['gig-2857.localhost']));
    }

    public function test_it_reports_nothing_done_for_an_empty_alias_list(): void
    {
        $this->assertFalse($this->aliaser()->alias('compose.yml', 'app', ['']));
    }

    /** Runs the generated script locally, standing in for `docker compose exec`. */
    private function aliaser(): WorktreeVhostAliaser
    {
        $executor = $this->createMock(ContainerExecutor::class);
        $executor->method('exec')->willReturnCallback(
            function (string $composeFile, string $service, string $script) {
                $process = Process::fromShellCommandline($script, null, [
                    'NGRAMX_CONFIG_ROOT' => $this->root,
                ]);
                $process->run();

                return $process;
            }
        );

        return new WorktreeVhostAliaser($executor);
    }

    private function apacheVhost(string $serverName, ?string $file = null): string
    {
        $path = $this->root . '/etc/apache2/sites-enabled/' . ($file ?? 'site.conf');
        $this->write($path, <<<CONF
        <VirtualHost *:80>
            ServerName {$serverName}
            DocumentRoot /var/www/html/web
        </VirtualHost>
        CONF);

        return $path;
    }

    /** file_get_contents() widens to string|false; assert it away once here. */
    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        $this->assertIsString($contents, "could not read {$path}");

        return $contents;
    }

    private function write(string $path, string $contents): void
    {
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $contents);
    }
}
