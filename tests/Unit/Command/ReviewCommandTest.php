<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Command;

use Ngramx\Command\ReviewCommand;
use Ngramx\Config\ConfigLoader;
use Ngramx\Config\LockFile;
use Ngramx\Config\Schema\CommandDefinition;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Docker\DockerCompose;
use Ngramx\Git\GitRepositoryService;
use Ngramx\Laravel\LaravelService;
use Ngramx\Orchestrator\CommandOrchestrator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

class ReviewCommandTest extends TestCase
{
    private ConfigLoader $configLoader;
    private DockerCompose $dockerCompose;
    private LockFile $lockFile;
    private GitRepositoryService $gitRepositoryService;
    private LaravelService $laravelService;
    private CommandOrchestrator $commandOrchestrator;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->configLoader = $this->createMock(ConfigLoader::class);
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->lockFile = $this->createMock(LockFile::class);
        $this->gitRepositoryService = $this->createMock(GitRepositoryService::class);
        $this->laravelService = $this->createMock(LaravelService::class);
        $this->commandOrchestrator = $this->createMock(CommandOrchestrator::class);

        $this->tmpDir = sys_get_temp_dir() . '/ngramx-review-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $this->dockerCompose->expects($this->any())->method('isRunning')->willReturn(true);
        $this->lockFile->expects($this->any())->method('exists')->willReturn(false);
        $this->gitRepositoryService->expects($this->any())->method('fetchFromOrigin')->willReturn(true);
        $this->gitRepositoryService->expects($this->any())->method('findBranchesContaining')->willReturn(['GIG-123-feature']);
        $this->gitRepositoryService->expects($this->any())->method('selectBranch')->willReturn('GIG-123-feature');
        $this->gitRepositoryService->expects($this->any())->method('checkoutBranch')->willReturn(true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_command_is_configured_correctly(): void
    {
        $command = $this->createCommand();

        $this->assertSame('review', $command->getName());
        $this->assertNotEmpty($command->getDescription());
        $this->assertTrue($command->getDefinition()->hasArgument('ticket'));
    }

    public function test_command_has_worktree_options(): void
    {
        $definition = $this->createCommand()->getDefinition();

        $this->assertTrue($definition->hasOption('worktree'));
        $this->assertTrue($definition->hasOption('cursor'));
        $this->assertTrue($definition->getOption('worktree')->getShortcut() === 'w');
    }

    public function test_it_fails_when_services_not_running(): void
    {
        $this->dockerCompose = $this->createMock(DockerCompose::class);
        $this->dockerCompose->expects($this->once())->method('isRunning')->willReturn(false);

        $this->setupConfigLoader($this->createMockConfig([]));

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('not running', $tester->getDisplay());
    }

    public function test_it_uses_fresh_command_when_defined(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(
                command: 'php artisan migrate:fresh --seed',
                description: 'Reset database',
            ),
        ]);

        $this->setupConfigLoader($config);

        $this->commandOrchestrator->expects($this->once())
            ->method('run')
            ->with('fresh', $config)
            ->willReturn(1.5);

        $this->laravelService->expects($this->never())->method('clearCaches');
        $this->laravelService->expects($this->never())->method('resetDatabase');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Running fresh', $tester->getDisplay());
    }

    public function test_it_falls_back_to_laravel_when_fresh_not_defined(): void
    {
        $config = $this->createMockConfig([]);
        $this->setupConfigLoader($config);

        $this->commandOrchestrator->expects($this->never())->method('run');

        $this->laravelService->expects($this->atLeastOnce())->method('hasArtisan')->willReturn(true);
        $this->laravelService->expects($this->once())->method('clearCaches')->willReturn(true);
        $this->laravelService->expects($this->once())->method('resetDatabase')->willReturn(true);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('falling back to default Laravel reset', $tester->getDisplay());
    }

    public function test_it_falls_back_to_laravel_when_fresh_command_is_empty(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(
                command: '  ',
                description: 'Reset database',
            ),
        ]);
        $this->setupConfigLoader($config);

        $this->commandOrchestrator->expects($this->never())->method('run');

        $this->laravelService->expects($this->atLeastOnce())->method('hasArtisan')->willReturn(true);
        $this->laravelService->expects($this->once())->method('clearCaches')->willReturn(true);
        $this->laravelService->expects($this->once())->method('resetDatabase')->willReturn(true);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $this->assertSame(0, $exitCode);
    }

    public function test_it_skips_reset_when_no_fresh_and_no_artisan(): void
    {
        $config = $this->createMockConfig([]);
        $this->setupConfigLoader($config);

        $this->laravelService->expects($this->atLeastOnce())->method('hasArtisan')->willReturn(false);
        $this->laravelService->expects($this->never())->method('clearCaches');
        $this->laravelService->expects($this->never())->method('resetDatabase');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('skipping environment reset', $tester->getDisplay());
    }

    public function test_quick_option_uses_clear_command(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset database'),
            'clear' => new CommandDefinition(command: 'php artisan migrate && php artisan optimize:clear', description: 'Clear caches'),
        ]);

        $this->setupConfigLoader($config);

        $this->commandOrchestrator->expects($this->once())
            ->method('run')
            ->with('clear', $config)
            ->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123', '--quick' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Running clear', $tester->getDisplay());
    }

    public function test_quick_falls_back_to_laravel_when_clear_not_defined(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset database'),
        ]);

        $this->setupConfigLoader($config);

        $this->commandOrchestrator->expects($this->never())->method('run');

        $this->laravelService->expects($this->atLeastOnce())->method('hasArtisan')->willReturn(true);
        $this->laravelService->expects($this->once())->method('clearCaches')->willReturn(true);
        $this->laravelService->expects($this->once())->method('resetDatabase')->willReturn(true);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123', '--quick' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("'clear' is not defined", $tester->getDisplay());
    }

    public function test_it_displays_full_completion_json(): void
    {
        $ticketDir = $this->tmpDir . '/.ngramx/tickets/GIG-123';
        mkdir($ticketDir, 0755, true);
        file_put_contents($ticketDir . '/completion.json', json_encode([
            'title' => 'GIG-123: Invoice PDF Export',
            'description' => 'Adds PDF export to the invoice detail page.',
            'pr_url' => 'https://github.com/org/repo/pull/42',
            'linear_url' => 'https://linear.app/team/GIG-123',
            'test_urls' => [
                ['label' => 'Invoice list', 'url' => 'https://app.example.com/invoices'],
            ],
            'test_plan' => [
                [
                    'description' => 'PDF download works',
                    'status' => 'active',
                    'steps' => [
                        'Open an invoice',
                        'Click Download PDF',
                        'Verify the PDF content',
                    ],
                ],
            ],
        ], JSON_PRETTY_PRINT));

        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset'),
        ]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode);
        // Title in heavy rules
        $this->assertStringContainsString('━━━', $display);
        $this->assertStringContainsString('GIG-123: Invoice PDF Export', $display);
        $this->assertStringContainsString('Adds PDF export to the invoice detail page.', $display);
        // Test plan with tree lines before links
        $this->assertStringContainsString('How to Test', $display);
        $this->assertStringContainsString('PDF download works', $display);
        $this->assertStringContainsString('Open an invoice', $display);
        $this->assertStringContainsString('Click Download PDF', $display);
        $this->assertStringContainsString('Verify the PDF content', $display);
        // Links at the bottom
        $this->assertStringContainsString('https://github.com/org/repo/pull/42', $display);
        $this->assertStringContainsString('https://linear.app/team/GIG-123', $display);
        $this->assertStringContainsString('https://app.example.com/invoices', $display);
        // Test plan appears before links
        $testPlanPos = strpos($display, 'How to Test');
        $prPos = strpos($display, 'https://github.com/org/repo/pull/42');
        $this->assertNotFalse($testPlanPos);
        $this->assertNotFalse($prPos);
        $this->assertLessThan($prPos, $testPlanPos);
    }

    public function test_it_renders_stale_and_active_test_blocks(): void
    {
        $ticketDir = $this->tmpDir . '/.ngramx/tickets/GIG-123';
        mkdir($ticketDir, 0755, true);
        file_put_contents($ticketDir . '/completion.json', json_encode([
            'title' => 'GIG-123: Invoice Export',
            'description' => 'Export invoices.',
            'pr_url' => 'https://github.com/org/repo/pull/42',
            'test_urls' => [],
            'test_plan' => [
                [
                    'description' => 'Original PDF test',
                    'status' => 'stale',
                    'steps' => ['Step A', 'Step B'],
                ],
                [
                    'description' => 'New logo test',
                    'status' => 'active',
                    'steps' => ['Check the logo appears', 'Verify no stretching'],
                ],
            ],
        ], JSON_PRETTY_PRINT));

        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset'),
        ]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode);
        // Stale block headline is shown (strikethrough stripped by test output)
        $this->assertStringContainsString('Original PDF test', $display);
        // Stale block steps are NOT shown
        $this->assertStringNotContainsString('Step A', $display);
        $this->assertStringNotContainsString('Step B', $display);
        // Active block headline and steps are shown
        $this->assertStringContainsString('New logo test', $display);
        $this->assertStringContainsString('Check the logo appears', $display);
        $this->assertStringContainsString('Verify no stretching', $display);
        // Tree lines present
        $this->assertStringContainsString('├─', $display);
        $this->assertStringContainsString('└─', $display);
    }

    public function test_it_finds_completion_json_case_insensitively(): void
    {
        $ticketDir = $this->tmpDir . '/.ngramx/tickets/GIG-456';
        mkdir($ticketDir, 0755, true);
        file_put_contents($ticketDir . '/Completion.JSON', json_encode([
            'title' => 'GIG-456: Some Feature',
            'description' => 'A feature.',
            'pr_url' => 'https://github.com/org/repo/pull/56',
            'linear_url' => 'https://linear.app/team/GIG-456',
            'test_urls' => [],
            'test_plan' => [],
        ]));

        $this->gitRepositoryService = $this->createMock(GitRepositoryService::class);
        $this->gitRepositoryService->expects($this->any())->method('fetchFromOrigin')->willReturn(true);
        $this->gitRepositoryService->expects($this->any())->method('findBranchesContaining')->willReturn(['GIG-456-feature']);
        $this->gitRepositoryService->expects($this->any())->method('selectBranch')->willReturn('GIG-456-feature');
        $this->gitRepositoryService->expects($this->any())->method('checkoutBranch')->willReturn(true);

        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset'),
        ]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-456']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('https://linear.app/team/GIG-456', $tester->getDisplay());
    }

    public function test_it_finds_ticket_folder_case_insensitively(): void
    {
        $ticketDir = $this->tmpDir . '/.ngramx/tickets/gig-789';
        mkdir($ticketDir, 0755, true);
        file_put_contents($ticketDir . '/COMPLETION.md', implode("\n", [
            '- Linear: https://linear.app/team/GIG-789',
        ]));

        $this->gitRepositoryService = $this->createMock(GitRepositoryService::class);
        $this->gitRepositoryService->expects($this->any())->method('fetchFromOrigin')->willReturn(true);
        $this->gitRepositoryService->expects($this->any())->method('findBranchesContaining')->willReturn(['GIG-789-feature']);
        $this->gitRepositoryService->expects($this->any())->method('selectBranch')->willReturn('GIG-789-feature');
        $this->gitRepositoryService->expects($this->any())->method('checkoutBranch')->willReturn(true);

        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset'),
        ]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-789']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('https://linear.app/team/GIG-789', $tester->getDisplay());
    }

    public function test_it_finds_ticket_folder_by_partial_match(): void
    {
        $ticketDir = $this->tmpDir . '/.ngramx/tickets/gig-1603';
        mkdir($ticketDir, 0755, true);
        file_put_contents($ticketDir . '/completion.json', json_encode([
            'title' => 'GIG-1603: Some Feature',
            'description' => 'A feature.',
            'pr_url' => 'https://github.com/org/repo/pull/54',
            'linear_url' => 'https://linear.app/gigabyte/issue/GIG-1603',
            'test_urls' => [],
            'test_plan' => [],
        ]));

        $this->gitRepositoryService = $this->createMock(GitRepositoryService::class);
        $this->gitRepositoryService->expects($this->any())->method('fetchFromOrigin')->willReturn(true);
        $this->gitRepositoryService->expects($this->any())->method('findBranchesContaining')->willReturn(['gig-1603-feature']);
        $this->gitRepositoryService->expects($this->any())->method('selectBranch')->willReturn('gig-1603-feature');
        $this->gitRepositoryService->expects($this->any())->method('checkoutBranch')->willReturn(true);

        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset'),
        ]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => '1603']);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('https://github.com/org/repo/pull/54', $display);
        $this->assertStringContainsString('https://linear.app/gigabyte/issue/GIG-1603', $display);
    }

    public function test_it_displays_multiple_test_urls_from_json(): void
    {
        $ticketDir = $this->tmpDir . '/.ngramx/tickets/GIG-123';
        mkdir($ticketDir, 0755, true);
        file_put_contents($ticketDir . '/completion.json', json_encode([
            'title' => 'GIG-123: Dashboard Update',
            'description' => 'Updates the dashboard across web and PWA.',
            'pr_url' => 'https://github.com/org/repo/pull/99',
            'test_urls' => [
                ['label' => 'Web app', 'url' => 'https://app.localhost/dashboard'],
                ['label' => 'PWA', 'url' => 'https://pwa.localhost/dashboard'],
            ],
            'test_plan' => [
                ['description' => 'Dashboard loads', 'status' => 'active', 'steps' => ['Open dashboard']],
            ],
        ]));

        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset'),
        ]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('https://github.com/org/repo/pull/99', $display);
        $this->assertStringContainsString('https://app.localhost/dashboard', $display);
        $this->assertStringContainsString('https://pwa.localhost/dashboard', $display);
    }

    public function test_it_falls_back_to_legacy_completion_md(): void
    {
        $ticketDir = $this->tmpDir . '/.ngramx/tickets/GIG-123';
        mkdir($ticketDir, 0755, true);
        file_put_contents($ticketDir . '/completion.md', implode("\n", [
            '- PR: https://github.com/org/repo/pull/99',
            '- Linear: https://linear.app/team/GIG-123',
        ]));

        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset'),
        ]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('https://github.com/org/repo/pull/99', $display);
        $this->assertStringContainsString('https://linear.app/team/GIG-123', $display);
    }

    public function test_it_prefers_completion_json_over_completion_md(): void
    {
        $ticketDir = $this->tmpDir . '/.ngramx/tickets/GIG-123';
        mkdir($ticketDir, 0755, true);
        file_put_contents($ticketDir . '/completion.json', json_encode([
            'title' => 'GIG-123: New Feature',
            'description' => 'A new feature.',
            'pr_url' => 'https://github.com/org/repo/pull/100',
            'test_urls' => [],
            'test_plan' => [
                ['description' => 'Works', 'status' => 'active', 'steps' => ['Test it']],
            ],
        ]));
        file_put_contents($ticketDir . '/completion.md', implode("\n", [
            '- PR: https://github.com/org/repo/pull/OLD',
        ]));

        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset'),
        ]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('GIG-123: New Feature', $display);
        $this->assertStringContainsString('https://github.com/org/repo/pull/100', $display);
        $this->assertStringNotContainsString('pull/OLD', $display);
    }

    public function test_it_gracefully_skips_when_no_ticket_folder(): void
    {
        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset'),
        ]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('➜', $tester->getDisplay());
    }

    public function test_it_gracefully_skips_when_no_completion_file(): void
    {
        $ticketDir = $this->tmpDir . '/.ngramx/tickets/GIG-123';
        mkdir($ticketDir, 0755, true);
        file_put_contents($ticketDir . '/README.md', '# GIG-123');

        $config = $this->createMockConfig([
            'fresh' => new CommandDefinition(command: 'php artisan migrate:fresh --seed', description: 'Reset'),
        ]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $this->commandOrchestrator->expects($this->once())->method('run')->willReturn(1.0);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString('➜', $tester->getDisplay());
    }

    public function test_it_surfaces_git_error_when_checkout_fails(): void
    {
        $this->gitRepositoryService = $this->createMock(GitRepositoryService::class);
        $this->gitRepositoryService->expects($this->any())->method('fetchFromOrigin')->willReturn(true);
        $this->gitRepositoryService->expects($this->any())->method('findBranchesContaining')->willReturn(['GIG-123-feature']);
        $this->gitRepositoryService->expects($this->any())->method('selectBranch')->willReturn('GIG-123-feature');
        $this->gitRepositoryService->expects($this->once())->method('checkoutBranch')->willReturn(false);
        $this->gitRepositoryService->expects($this->any())->method('lastCheckoutError')->willReturn(
            "error: Your local changes to the following files would be overwritten by checkout:\n\tsrc/Foo.php"
        );

        $config = $this->createMockConfig([]);
        $this->setupConfigLoader($config);

        $this->commandOrchestrator->expects($this->never())->method('run');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to check out branch', $display);
        $this->assertStringContainsString('would be overwritten by checkout', $display);
        $this->assertStringContainsString('src/Foo.php', $display);
    }

    public function test_it_gives_a_hint_when_checkout_fails_without_git_output(): void
    {
        $this->gitRepositoryService = $this->createMock(GitRepositoryService::class);
        $this->gitRepositoryService->expects($this->any())->method('fetchFromOrigin')->willReturn(true);
        $this->gitRepositoryService->expects($this->any())->method('findBranchesContaining')->willReturn(['GIG-123-feature']);
        $this->gitRepositoryService->expects($this->any())->method('selectBranch')->willReturn('GIG-123-feature');
        $this->gitRepositoryService->expects($this->once())->method('checkoutBranch')->willReturn(false);
        $this->gitRepositoryService->expects($this->any())->method('lastCheckoutError')->willReturn('');

        $config = $this->createMockConfig([]);
        $this->setupConfigLoader($config);

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'GIG-123']);

        $display = $tester->getDisplay();
        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Failed to check out branch', $display);
        $this->assertStringContainsString('uncommitted changes', $display);
    }

    public function test_it_errors_when_no_ticket_and_not_cleanup(): void
    {
        $config = $this->createMockConfig([]);
        $this->setupConfigLoader($config);

        $this->commandOrchestrator->expects($this->never())->method('run');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute([]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('ticket number is required', $tester->getDisplay());
    }

    public function test_cleanup_without_ticket_removes_all_worktrees(): void
    {
        $worktreesDir = $this->tmpDir . '/.ngramx/worktrees';
        mkdir($worktreesDir . '/gig-1-foo', 0755, true);
        mkdir($worktreesDir . '/gig-2-bar', 0755, true);

        $config = $this->createMockConfig([]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['--cleanup' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Cleaning up all worktrees (2)', $display);
        $this->assertStringContainsString('Removed all worktrees', $display);
        $this->assertDirectoryDoesNotExist($worktreesDir . '/gig-1-foo');
        $this->assertDirectoryDoesNotExist($worktreesDir . '/gig-2-bar');
    }

    public function test_cleanup_without_ticket_when_no_worktrees(): void
    {
        $config = $this->createMockConfig([]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['--cleanup' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('nothing to clean up', $tester->getDisplay());
    }

    public function test_cleanup_with_ticket_removes_only_matching_worktree(): void
    {
        $worktreesDir = $this->tmpDir . '/.ngramx/worktrees';
        mkdir($worktreesDir . '/gig-1-foo', 0755, true);
        mkdir($worktreesDir . '/gig-2-bar', 0755, true);

        $config = $this->createMockConfig([]);
        $this->setupConfigLoader($config, $this->tmpDir . '/ngramx.yml');

        $tester = new CommandTester($this->createCommand());
        $exitCode = $tester->execute(['ticket' => 'gig-1', '--cleanup' => true]);

        $display = $tester->getDisplay();
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Removed worktree for ticket gig-1', $display);
        $this->assertDirectoryDoesNotExist($worktreesDir . '/gig-1-foo');
        $this->assertDirectoryExists($worktreesDir . '/gig-2-bar');
    }

    private function createCommand(): ReviewCommand
    {
        return new ReviewCommand(
            $this->configLoader,
            $this->dockerCompose,
            $this->lockFile,
            $this->gitRepositoryService,
            $this->laravelService,
            $this->commandOrchestrator,
        );
    }

    private function setupConfigLoader(NgramxConfig $config, string $configPath = '/path/to/ngramx.yml'): void
    {
        $this->configLoader->expects($this->once())
            ->method('findConfigFile')
            ->willReturn($configPath);

        $this->configLoader->expects($this->once())
            ->method('load')
            ->willReturn($config);
    }

    /**
     * @param array<string, CommandDefinition> $commands
     */
    private function createMockConfig(array $commands): NgramxConfig
    {
        return new NgramxConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: 'docker-compose.yml',
                primaryService: 'app',
                appUrl: 'http://localhost:80',
                waitFor: [],
            ),
            setup: new SetupConfig(preStart: [], initialize: []),
            n8n: new N8nConfig(workflowsDir: './.n8n'),
            commands: $commands,
        );
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
