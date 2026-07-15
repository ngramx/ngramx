<?php

declare(strict_types=1);

namespace Ngramx\Worktree;

use Ngramx\Output\OutputFormatter;
use Ngramx\Tls\CertInspector;
use Symfony\Component\Process\Process;

/**
 * Make HTTPS work inside a worktree environment.
 *
 * A worktree stack advertises a hostname the main checkout's certificate was
 * never minted for: either "<folder>.localhost" (host-agnostic apps) or the
 * app's own host on a shifted port. The cert under `docker.ssl_path` in the
 * worktree is either missing entirely (fresh checkout, cert files are
 * gitignored) or — when a pre_start hook regenerates it — only covers the
 * app's canonical hostname. Browsers then refuse the worktree URL with a
 * hostname mismatch even though the CA is trusted.
 *
 * This seeder runs before the worktree stack starts, so nginx boots with the
 * right cert already in place:
 *
 *   1. If the worktree has no cert, copy the parent checkout's (if any) so
 *      projects without a cert-generating pre_start hook still get TLS.
 *   2. If mkcert is available and the cert doesn't cover every hostname the
 *      worktree may advertise, mint one covering the app host AND the
 *      "<folder>.localhost" subdomain, written under both hostnames' file
 *      names so whichever one the proxy config references is covered.
 *
 * Everything is best-effort: a project without TLS (http app_url) is skipped,
 * and any failure degrades to the previous behaviour with a warning.
 */
class WorktreeCertSeeder
{
    /** @var callable(): bool */
    private $mkcertAvailable;

    /** @var callable(list<string>, string, string): bool */
    private $mkcertRunner;

    private readonly CertInspector $inspector;

    /**
     * @param callable(): bool|null $mkcertAvailable Overridable for tests.
     * @param callable(list<string>, string, string): bool|null $mkcertRunner
     *        (hostnames, certFile, keyFile) => success. Overridable for tests.
     */
    public function __construct(
        ?CertInspector $inspector = null,
        ?callable $mkcertAvailable = null,
        ?callable $mkcertRunner = null,
    ) {
        $this->inspector = $inspector ?? new CertInspector();
        $this->mkcertAvailable = $mkcertAvailable ?? static function (): bool {
            $probe = new Process(['which', 'mkcert']);
            $probe->run();

            return $probe->isSuccessful();
        };
        $this->mkcertRunner = $mkcertRunner ?? static function (array $hostnames, string $certFile, string $keyFile): bool {
            $process = new Process(array_merge(
                ['mkcert', '-cert-file', $certFile, '-key-file', $keyFile],
                $hostnames,
            ));
            $process->setTimeout(30);
            $process->run();

            return $process->isSuccessful();
        };
    }

    /**
     * Ensure the worktree's TLS cert covers every hostname its environment may
     * advertise. Returns true when the cert on disk was created or replaced
     * (callers use this to know a running proxy must be restarted to pick the
     * new cert up).
     */
    public function seed(
        string $repositoryPath,
        string $worktreePath,
        string $appUrl,
        string $sslPath,
        string $folderName,
        OutputFormatter $formatter,
    ): bool {
        $parts = parse_url($appUrl);
        if (!is_array($parts) || (($parts['scheme'] ?? '') !== 'https') || !isset($parts['host'])) {
            return false; // project doesn't serve TLS — nothing to seed
        }

        $appHost = strtolower((string) $parts['host']);
        $subHost = WorktreeIdentity::sanitizeSegment($folderName) . '.localhost';

        $sslDir = $worktreePath . '/' . trim($sslPath, '/');
        $certFile = $sslDir . '/' . $appHost . '.crt';
        $keyFile = $sslDir . '/' . $appHost . '.key';

        $changed = false;

        if (!is_dir($sslDir)) {
            @mkdir($sslDir, 0755, true);
        }

        // A fresh worktree checkout has no cert (the files are gitignored).
        // Copy the parent's so projects without a cert-generating pre_start
        // hook still serve TLS on the app's own host.
        if (!is_file($certFile)) {
            $changed = $this->copyFromParent($repositoryPath, $sslPath, $appHost, $certFile, $keyFile);
            if ($changed) {
                $formatter->info('Copied the TLS certificate from the parent checkout');
            }
        }

        if ($this->certCovers($certFile, $appHost, $subHost)) {
            return $changed;
        }

        if (!($this->mkcertAvailable)()) {
            $formatter->warning(
                "The TLS certificate does not cover $subHost — the worktree URL may show a browser certificate error. "
                . 'Install mkcert so worktree environments can mint one that does.'
            );

            return $changed;
        }

        $formatter->info("Generating a TLS certificate for $appHost and $subHost...");
        if (!($this->mkcertRunner)([$appHost, $subHost], $certFile, $keyFile)) {
            $formatter->warning('mkcert failed to generate the worktree certificate — HTTPS may show a hostname mismatch.');

            return $changed;
        }

        // Some proxy configs derive the cert filename from the (re-seeded)
        // APP_URL host rather than the canonical one — cover both spellings.
        @copy($certFile, $sslDir . '/' . $subHost . '.crt');
        @copy($keyFile, $sslDir . '/' . $subHost . '.key');

        return true;
    }

    /**
     * Whether the cert at $certFile covers both hostnames the worktree
     * environment may end up advertising.
     */
    private function certCovers(string $certFile, string $appHost, string $subHost): bool
    {
        if (!is_file($certFile)) {
            return false;
        }

        $info = $this->inspector->inspectPath($certFile);

        return $info !== null && $info->coversHost($appHost) && $info->coversHost($subHost);
    }

    private function copyFromParent(
        string $repositoryPath,
        string $sslPath,
        string $appHost,
        string $certFile,
        string $keyFile,
    ): bool {
        $parentDir = $repositoryPath . '/' . trim($sslPath, '/');
        $parentCert = $parentDir . '/' . $appHost . '.crt';
        $parentKey = $parentDir . '/' . $appHost . '.key';

        if (!is_file($parentCert) || !is_file($parentKey)) {
            return false;
        }

        return @copy($parentCert, $certFile) && @copy($parentKey, $keyFile);
    }
}
