<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Config\Schema\SecretsConfig;
use Ngramx\Config\Schema\SecretsProviderConfig;
use Ngramx\Output\OutputFormatter;
use Ngramx\Worktree\WorktreeComposerAuthSeeder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class WorktreeComposerAuthSeederTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ngramx-composer-auth-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_it_writes_auth_json_when_flux_secrets_are_configured(): void
    {
        file_put_contents(
            $this->tmpDir . '/.env',
            "FLUX_USERNAME=rob@example.com\nFLUX_LICENSE_KEY=license-key\n"
        );

        $secrets = new SecretsConfig(providers: [
            new SecretsProviderConfig(
                provider: SecretsProviderConfig::PROVIDER_DOTENV,
                required: ['FLUX_USERNAME', 'FLUX_LICENSE_KEY'],
            ),
        ]);

        $output = new BufferedOutput();
        $formatter = new OutputFormatter($output);

        (new WorktreeComposerAuthSeeder())->seed($this->tmpDir, $secrets, $formatter);

        $this->assertFileExists($this->tmpDir . '/auth.json');
        $decoded = json_decode((string) file_get_contents($this->tmpDir . '/auth.json'), true);
        $this->assertSame('rob@example.com', $decoded['http-basic']['composer.fluxui.dev']['username']);
        $this->assertSame('license-key', $decoded['http-basic']['composer.fluxui.dev']['password']);
        $this->assertStringContainsString('Configured Flux Pro Composer authentication', $output->fetch());
    }

    public function test_it_skips_when_flux_secrets_are_not_required(): void
    {
        file_put_contents($this->tmpDir . '/.env', "FLUX_USERNAME=rob@example.com\n");

        $secrets = new SecretsConfig(providers: [
            new SecretsProviderConfig(
                provider: SecretsProviderConfig::PROVIDER_DOTENV,
                required: ['APP_KEY'],
            ),
        ]);

        $formatter = new OutputFormatter(new BufferedOutput());

        (new WorktreeComposerAuthSeeder())->seed($this->tmpDir, $secrets, $formatter);

        $this->assertFileDoesNotExist($this->tmpDir . '/auth.json');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $item) {
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
