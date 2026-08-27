<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit;

use Ngramx\Application;
use Ngramx\Command\N8n\ImportCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class ApplicationTest extends TestCase
{
    /**
     * Valid, but with no `clear` or `fresh` command — which is what triggers
     * the recommended-command warning banners. Most real projects are in this
     * state, which is why those banners reaching stdout mattered.
     */
    private const CONFIG_WITHOUT_RECOMMENDED_COMMANDS = <<<YAML
        version: '1.0'
        docker:
          compose_file: "docker-compose.yml"
          primary_service: "app"
          app_url: "http://localhost:80"
        YAML;


    public function test_n8n_import_command_is_registered(): void
    {
        $app = new Application();

        $this->assertTrue($app->has('n8n:import'));
        $command = $app->find('n8n:import');
        $this->assertInstanceOf(ImportCommand::class, $command);
    }

    public function test_sync_agents_command_is_registered(): void
    {
        $app = new Application();

        $this->assertTrue($app->has('sync-agents'));
    }

    public function test_init_github_actions_command_is_registered(): void
    {
        $app = new Application();

        $this->assertTrue($app->has('init-github-actions'));
    }

    /**
     * Running a read-only/utility command inside a directory that contains a ngramx.yml
     * must not create or mutate AGENTS.md. Tab completion (_complete), list, help, etc.
     * should never write to the project.
     */
    public function test_list_command_does_not_write_agents_md(): void
    {
        $originalCwd = getcwd();
        $this->assertIsString($originalCwd);

        $tmp = sys_get_temp_dir() . '/ngramx-app-test-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0o755, true);

        try {
            file_put_contents($tmp . '/ngramx.yml', "project: tmp\nservices: []\n");
            chdir($tmp);

            $app = new Application();
            $app->setAutoExit(false);
            $app->setCatchExceptions(false);

            $exit = $app->run(new ArrayInput(['command' => 'list']), new BufferedOutput());
            $this->assertSame(0, $exit);
            $this->assertFileDoesNotExist($tmp . '/AGENTS.md');
        } finally {
            chdir($originalCwd);
            @unlink($tmp . '/ngramx.yml');
            @unlink($tmp . '/AGENTS.md');
            @rmdir($tmp);
        }
    }

    /**
     * A ngramx.yml that's present but cannot be parsed used to vanish into
     * a silent catch in the Application constructor — the user would see a
     * CLI missing all their custom commands with zero explanation. We now
     * capture the error and surface it.
     */
    public function test_unparseable_ngramx_yml_is_captured_as_load_error(): void
    {
        $originalCwd = getcwd();
        $this->assertIsString($originalCwd);

        $tmp = sys_get_temp_dir() . '/ngramx-app-test-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0o755, true);

        try {
            file_put_contents($tmp . '/ngramx.yml', ": this : is : not : valid : yaml\n  bad indent\n");
            chdir($tmp);

            $app = new Application();

            $errors = $app->getConfigLoadErrors();
            $this->assertNotEmpty(
                $errors,
                'A malformed ngramx.yml must be surfaced via getConfigLoadErrors(), not silently swallowed.'
            );
            $this->assertStringContainsString('ngramx.yml', $errors[0]);
        } finally {
            chdir($originalCwd);
            @unlink($tmp . '/ngramx.yml');
            @rmdir($tmp);
        }
    }

    /**
     * The config warning banners are written to stdout, so on any project
     * missing a recommended command they would prepend two lines of prose to
     * output a caller is about to hand to a JSON parser.
     *
     * Caught only by running the real CLI: unit tests of the command mock the
     * config loader, so the banners never appear there.
     */
    public function test_json_output_is_not_polluted_by_config_warnings(): void
    {
        $originalCwd = getcwd();
        $this->assertIsString($originalCwd);

        $tmp = sys_get_temp_dir() . '/ngramx-app-test-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0o755, true);

        try {
            file_put_contents($tmp . '/ngramx.yml', self::CONFIG_WITHOUT_RECOMMENDED_COMMANDS);
            chdir($tmp);

            $app = new Application();
            $app->setAutoExit(false);

            $output = new BufferedOutput();
            $app->run(new ArrayInput(['command' => 'status', '--json' => true, '--no-cloud' => true]), $output);

            $display = $output->fetch();
            $this->assertJson(
                $display,
                'stdout must be nothing but JSON when --json is given; got: ' . substr($display, 0, 300)
            );
        } finally {
            chdir($originalCwd);
            @unlink($tmp . '/ngramx.yml');
            @rmdir($tmp);
        }
    }

    /**
     * The same warnings are still worth showing when a human is reading.
     */
    public function test_config_warnings_are_shown_without_json(): void
    {
        $originalCwd = getcwd();
        $this->assertIsString($originalCwd);

        $tmp = sys_get_temp_dir() . '/ngramx-app-test-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0o755, true);

        try {
            file_put_contents($tmp . '/ngramx.yml', self::CONFIG_WITHOUT_RECOMMENDED_COMMANDS);
            chdir($tmp);

            $app = new Application();
            $app->setAutoExit(false);

            $output = new BufferedOutput();
            $app->run(new ArrayInput(['command' => 'status', '--no-cloud' => true]), $output);

            $this->assertStringContainsString('Recommended command', $output->fetch());
        } finally {
            chdir($originalCwd);
            @unlink($tmp . '/ngramx.yml');
            @rmdir($tmp);
        }
    }

    public function test_missing_ngramx_yml_stays_silent(): void
    {
        $originalCwd = getcwd();
        $this->assertIsString($originalCwd);

        $tmp = sys_get_temp_dir() . '/ngramx-app-test-' . bin2hex(random_bytes(6));
        mkdir($tmp, 0o755, true);

        try {
            chdir($tmp);

            $app = new Application();

            $this->assertSame(
                [],
                $app->getConfigLoadErrors(),
                'Running outside any ngramx.yml-scoped project must remain silent — only PRESENT-BUT-BROKEN configs are an error.'
            );
        } finally {
            chdir($originalCwd);
            @rmdir($tmp);
        }
    }
}
