<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Output\OutputFormatter;
use Ngramx\Worktree\WorktreeCertSeeder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class WorktreeCertSeederTest extends TestCase
{
    private const SSL_PATH = 'docker/nginx/ssl';

    private string $tmpDir;
    private string $repoPath;
    private string $worktreePath;
    private BufferedOutput $output;
    private OutputFormatter $formatter;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/ngramx-cert-seeder-' . uniqid();
        $this->repoPath = $this->tmpDir . '/repo';
        $this->worktreePath = $this->tmpDir . '/worktree';
        mkdir($this->repoPath . '/' . self::SSL_PATH, 0755, true);
        mkdir($this->worktreePath, 0755, true);

        $this->output = new BufferedOutput();
        $this->formatter = new OutputFormatter($this->output);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tmpDir);
    }

    public function test_it_skips_non_https_apps(): void
    {
        $seeder = new WorktreeCertSeeder(
            mkcertAvailable: fn (): bool => throw new \LogicException('should not be called'),
        );

        $changed = $seeder->seed(
            $this->repoPath,
            $this->worktreePath,
            'http://app.localhost',
            self::SSL_PATH,
            'gig-1-app',
            $this->formatter,
        );

        $this->assertFalse($changed);
        $this->assertDirectoryDoesNotExist($this->worktreePath . '/' . self::SSL_PATH);
    }

    public function test_it_copies_a_covering_cert_from_the_parent_checkout(): void
    {
        // Parent cert already covers both the app host and the worktree subdomain.
        $pem = $this->generatePemWithSans(['app.localhost', 'gig-1-app.localhost']);
        file_put_contents($this->repoPath . '/' . self::SSL_PATH . '/app.localhost.crt', $pem);
        file_put_contents($this->repoPath . '/' . self::SSL_PATH . '/app.localhost.key', 'parent-key');

        $seeder = new WorktreeCertSeeder(
            mkcertAvailable: fn (): bool => false,
            mkcertRunner: fn (): bool => throw new \LogicException('should not be called'),
        );

        $changed = $seeder->seed(
            $this->repoPath,
            $this->worktreePath,
            'https://app.localhost',
            self::SSL_PATH,
            'gig-1-app',
            $this->formatter,
        );

        $this->assertTrue($changed);
        $this->assertSame($pem, file_get_contents($this->worktreePath . '/' . self::SSL_PATH . '/app.localhost.crt'));
        $this->assertSame('parent-key', file_get_contents($this->worktreePath . '/' . self::SSL_PATH . '/app.localhost.key'));
        $this->assertStringNotContainsString('does not cover', $this->output->fetch());
    }

    public function test_it_mints_a_multi_san_cert_when_coverage_is_missing(): void
    {
        // Parent cert only covers the app's canonical host — the typical
        // `ngramx secure` output.
        $pem = $this->generatePemWithSans(['app.localhost']);
        file_put_contents($this->repoPath . '/' . self::SSL_PATH . '/app.localhost.crt', $pem);
        file_put_contents($this->repoPath . '/' . self::SSL_PATH . '/app.localhost.key', 'parent-key');

        $mintedHostnames = null;
        $seeder = new WorktreeCertSeeder(
            mkcertAvailable: fn (): bool => true,
            mkcertRunner: function (array $hostnames, string $certFile, string $keyFile) use (&$mintedHostnames): bool {
                $mintedHostnames = $hostnames;
                file_put_contents($certFile, 'minted-cert');
                file_put_contents($keyFile, 'minted-key');
                return true;
            },
        );

        $changed = $seeder->seed(
            $this->repoPath,
            $this->worktreePath,
            'https://app.localhost',
            self::SSL_PATH,
            'gig-1-app',
            $this->formatter,
        );

        $this->assertTrue($changed);
        $this->assertSame(['app.localhost', 'gig-1-app.localhost'], $mintedHostnames);

        $sslDir = $this->worktreePath . '/' . self::SSL_PATH;
        // Written under both hostnames' file names so whichever the proxy
        // config references is covered.
        $this->assertSame('minted-cert', file_get_contents($sslDir . '/app.localhost.crt'));
        $this->assertSame('minted-key', file_get_contents($sslDir . '/app.localhost.key'));
        $this->assertSame('minted-cert', file_get_contents($sslDir . '/gig-1-app.localhost.crt'));
        $this->assertSame('minted-key', file_get_contents($sslDir . '/gig-1-app.localhost.key'));
    }

    public function test_it_warns_when_mkcert_is_unavailable_and_coverage_is_missing(): void
    {
        $pem = $this->generatePemWithSans(['app.localhost']);
        file_put_contents($this->repoPath . '/' . self::SSL_PATH . '/app.localhost.crt', $pem);
        file_put_contents($this->repoPath . '/' . self::SSL_PATH . '/app.localhost.key', 'parent-key');

        $seeder = new WorktreeCertSeeder(
            mkcertAvailable: fn (): bool => false,
            mkcertRunner: fn (): bool => throw new \LogicException('should not be called'),
        );

        $changed = $seeder->seed(
            $this->repoPath,
            $this->worktreePath,
            'https://app.localhost',
            self::SSL_PATH,
            'gig-1-app',
            $this->formatter,
        );

        // The parent copy still landed, so TLS works on the app's own host.
        $this->assertTrue($changed);
        $this->assertFileExists($this->worktreePath . '/' . self::SSL_PATH . '/app.localhost.crt');
        $this->assertStringContainsString('does not cover gig-1-app.localhost', $this->output->fetch());
    }

    public function test_it_leaves_an_already_covering_worktree_cert_alone(): void
    {
        $sslDir = $this->worktreePath . '/' . self::SSL_PATH;
        mkdir($sslDir, 0755, true);
        $pem = $this->generatePemWithSans(['app.localhost', 'gig-1-app.localhost']);
        file_put_contents($sslDir . '/app.localhost.crt', $pem);
        file_put_contents($sslDir . '/app.localhost.key', 'existing-key');

        $seeder = new WorktreeCertSeeder(
            mkcertAvailable: fn (): bool => true,
            mkcertRunner: fn (): bool => throw new \LogicException('should not be called'),
        );

        $changed = $seeder->seed(
            $this->repoPath,
            $this->worktreePath,
            'https://app.localhost',
            self::SSL_PATH,
            'gig-1-app',
            $this->formatter,
        );

        $this->assertFalse($changed);
        $this->assertSame($pem, file_get_contents($sslDir . '/app.localhost.crt'));
    }

    public function test_a_failed_mkcert_run_warns_but_does_not_throw(): void
    {
        $seeder = new WorktreeCertSeeder(
            mkcertAvailable: fn (): bool => true,
            mkcertRunner: fn (): bool => false,
        );

        $changed = $seeder->seed(
            $this->repoPath,
            $this->worktreePath,
            'https://app.localhost',
            self::SSL_PATH,
            'gig-1-app',
            $this->formatter,
        );

        $this->assertFalse($changed);
        $this->assertStringContainsString('mkcert failed', $this->output->fetch());
    }

    /**
     * @param list<string> $sans
     */
    private function generatePemWithSans(array $sans): string
    {
        $configPath = tempnam(sys_get_temp_dir(), 'ngramx-openssl-');
        $this->assertNotFalse($configPath);

        $sanLine = implode(', ', array_map(static fn (string $s): string => 'DNS:' . $s, $sans));
        file_put_contents($configPath, <<<CNF
            [req]
            distinguished_name = dn
            [dn]
            [v3_req]
            subjectAltName = $sanLine
            CNF);

        try {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $this->assertNotFalse($key);

            $csr = openssl_csr_new(['commonName' => $sans[0]], $key, ['config' => $configPath]);
            \assert($csr instanceof \OpenSSLCertificateSigningRequest);

            $cert = openssl_csr_sign($csr, null, $key, 1, [
                'config' => $configPath,
                'x509_extensions' => 'v3_req',
            ]);
            \assert($cert instanceof \OpenSSLCertificate);

            openssl_x509_export($cert, $pem);

            return $pem;
        } finally {
            @unlink($configPath);
        }
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
