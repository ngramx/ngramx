<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\Exception\ConfigException;
use Ngramx\Config\HooksConfigLoader;
use Ngramx\Config\Schema\HookEvent;
use Ngramx\Config\Validator\ConfigValidator;
use PHPUnit\Framework\TestCase;

class HooksConfigLoaderTest extends TestCase
{
    private string $home;
    private string $project;
    private HooksConfigLoader $loader;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir() . '/ngramx-hooks-home-' . uniqid('', true);
        $this->project = sys_get_temp_dir() . '/ngramx-hooks-project-' . uniqid('', true);
        mkdir($this->home, 0755, true);
        mkdir($this->project . '/.ngramx', 0755, true);

        $this->loader = new HooksConfigLoader(
            validator: new ConfigValidator(),
            homeDirectory: $this->home,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->home);
        $this->removeDirectory($this->project);
    }

    public function test_it_returns_empty_config_when_no_sources_exist(): void
    {
        $config = $this->loader->load($this->project);

        $this->assertTrue($config->isEmpty());
    }

    public function test_it_loads_user_level_hooks(): void
    {
        file_put_contents($this->home . '/.ngramx.yaml', <<<'YAML'
hooks:
  onWorktreeCreate:
    - command: "cursor --new-window {worktree_path}"
      description: "Open Cursor"
YAML);

        $config = $this->loader->load($this->project);
        $hooks = $config->for(HookEvent::WorktreeCreate);

        $this->assertCount(1, $hooks);
        $this->assertSame('cursor --new-window {worktree_path}', $hooks[0]->command);
        $this->assertSame('Open Cursor', $hooks[0]->description);
    }

    public function test_project_config_overrides_user_event_list(): void
    {
        file_put_contents($this->home . '/.ngramx.yml', <<<'YAML'
hooks:
  onWorktreeCreate:
    - "echo user"
  onEnvironmentUp:
    - "echo up-user"
YAML);

        file_put_contents($this->project . '/.ngramx/config.yaml', <<<'YAML'
hooks:
  onWorktreeCreate:
    - "echo project"
YAML);

        $config = $this->loader->load($this->project);

        $this->assertSame(['echo project'], array_map(
            static fn ($h) => $h->command,
            $config->for(HookEvent::WorktreeCreate),
        ));
        $this->assertSame(['echo up-user'], array_map(
            static fn ($h) => $h->command,
            $config->for(HookEvent::EnvironmentUp),
        ));
    }

    public function test_ngramx_yml_hooks_win_over_project_config(): void
    {
        file_put_contents($this->project . '/.ngramx/config.yml', <<<'YAML'
hooks:
  onWorktreeCreate:
    - "echo project-config"
YAML);

        file_put_contents($this->project . '/ngramx.yml', <<<'YAML'
version: "1.0"
docker:
  compose_file: docker-compose.yml
  primary_service: app
  app_url: http://localhost
hooks:
  onWorktreeCreate:
    - "echo ngramx-yml"
YAML);

        $config = $this->loader->load($this->project);

        $this->assertSame(['echo ngramx-yml'], array_map(
            static fn ($h) => $h->command,
            $config->for(HookEvent::WorktreeCreate),
        ));
    }

    public function test_it_accepts_top_level_event_keys_in_user_file(): void
    {
        file_put_contents($this->home . '/.ngramx.yaml', <<<'YAML'
onWorktreeCreate:
  - "echo bare"
YAML);

        $config = $this->loader->load($this->project);

        $this->assertSame('echo bare', $config->for(HookEvent::WorktreeCreate)[0]->command);
    }

    public function test_it_rejects_unknown_events(): void
    {
        file_put_contents($this->home . '/.ngramx.yaml', <<<'YAML'
hooks:
  onSomethingElse:
    - "echo no"
YAML);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("Unknown hooks event 'onSomethingElse'");

        $this->loader->load($this->project);
    }

    public function test_string_entries_default_ignore_failure(): void
    {
        $config = $this->loader->build([
            'onEnvironmentUp' => ['echo hi'],
        ]);

        $hook = $config->for(HookEvent::EnvironmentUp)[0];
        $this->assertTrue($hook->ignoreFailure);
        $this->assertSame(120, $hook->timeout);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
