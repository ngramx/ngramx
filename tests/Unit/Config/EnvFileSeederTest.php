<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\EnvFileSeeder;
use PHPUnit\Framework\TestCase;

class EnvFileSeederTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        foreach ($this->dirs as $dir) {
            $this->rmrf($dir);
        }
    }

    private function tmp(): string
    {
        $dir = sys_get_temp_dir() . '/ngramx-envseed-' . uniqid('', true);
        mkdir($dir, 0755, true);
        $this->dirs[] = $dir;

        return $dir;
    }

    private function rmrf(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->rmrf($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    public function test_replaces_existing_keys_and_appends_missing_ones(): void
    {
        $dir = $this->tmp();
        file_put_contents($dir . '/.env', "APP_NAME=EK\nVITE_API_BASE_URL=http://localhost\nOTHER=1\n");

        $changed = (new EnvFileSeeder())->seed($dir, [
            '.env' => ['VITE_API_BASE_URL' => 'http://ek.localhost:280', 'EK_PWA_PORT' => '5373'],
        ]);

        $this->assertSame(['.env'], $changed);
        $this->assertSame(
            "APP_NAME=EK\nVITE_API_BASE_URL=http://ek.localhost:280\nOTHER=1\nEK_PWA_PORT=5373\n",
            file_get_contents($dir . '/.env'),
        );
    }

    public function test_reports_nothing_changed_when_values_already_match(): void
    {
        $dir = $this->tmp();
        file_put_contents($dir . '/.env', "A=1\n");

        $this->assertSame([], (new EnvFileSeeder())->seed($dir, ['.env' => ['A' => '1']]));
    }

    public function test_creates_missing_file_from_parent_checkout_first(): void
    {
        $parent = $this->tmp();
        $dir = $this->tmp();
        mkdir($parent . '/pwa');
        file_put_contents($parent . '/pwa/.env', "VITE_SENTRY_DSN=abc\nVITE_API_BASE_URL=http://localhost\n");

        (new EnvFileSeeder())->seed($dir, ['pwa/.env' => ['VITE_API_BASE_URL' => 'http://x.localhost']], $parent);

        $this->assertSame(
            "VITE_SENTRY_DSN=abc\nVITE_API_BASE_URL=http://x.localhost\n",
            file_get_contents($dir . '/pwa/.env'),
        );
    }

    public function test_creates_missing_file_from_example_when_no_parent_copy(): void
    {
        $dir = $this->tmp();
        mkdir($dir . '/pwa');
        file_put_contents($dir . '/pwa/.env.example', "VITE_API_BASE_URL=http://localhost\nVITE_DEMO=0\n");

        (new EnvFileSeeder())->seed($dir, ['pwa/.env' => ['VITE_API_BASE_URL' => 'http://x.localhost']]);

        $this->assertSame(
            "VITE_API_BASE_URL=http://x.localhost\nVITE_DEMO=0\n",
            file_get_contents($dir . '/pwa/.env'),
        );
    }

    public function test_creates_file_from_scratch_when_nothing_to_copy(): void
    {
        $dir = $this->tmp();

        (new EnvFileSeeder())->seed($dir, ['pwa/.env' => ['A' => 'b']]);

        $this->assertSame("A=b\n", file_get_contents($dir . '/pwa/.env'));
    }

    public function test_quotes_values_with_whitespace_and_keeps_dollar_signs_literal(): void
    {
        $this->assertSame("K=\"a b\"\n", EnvFileSeeder::patch('', 'K', 'a b'));
        $this->assertSame("K=\$1\\x\n", EnvFileSeeder::patch("K=old\n", 'K', '$1\\x'));
    }

    public function test_only_matches_whole_key(): void
    {
        $this->assertSame(
            "APP_URL_X=1\nAPP_URL=new\n",
            EnvFileSeeder::patch("APP_URL_X=1\nAPP_URL=old\n", 'APP_URL', 'new'),
        );
    }
}
