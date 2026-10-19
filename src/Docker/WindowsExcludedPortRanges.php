<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Symfony\Component\Process\Process;

/**
 * Host ports Windows has reserved (Hyper-V, excluded ranges). Docker Desktop's
 * WSL port proxy (`/forwards/expose`) returns HTTP 500 for these even when
 * nothing is listening — they look free inside the distro.
 */
final class WindowsExcludedPortRanges
{
    /** @var list<array{0: int, 1: int}>|null */
    private ?array $ranges = null;

    /** @var \Closure(): string */
    private readonly \Closure $outputProvider;

    /**
     * @param callable(): string|null $outputProvider Test seam: raw `netsh`
     *        excludedportrange output. Defaults to querying the Windows host.
     */
    public function __construct(?callable $outputProvider = null)
    {
        $this->outputProvider = $outputProvider !== null
            ? $outputProvider(...)
            : fn (): string => $this->queryNetsh();
    }

    public function contains(int $port): bool
    {
        foreach ($this->ranges() as [$start, $end]) {
            if ($port >= $start && $port <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function parse(string $output): array
    {
        $ranges = [];
        foreach (preg_split("/\r\n|\n|\r/", $output) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s*\*?$/', $line, $matches) !== 1) {
                continue;
            }

            $ranges[] = [(int) $matches[1], (int) $matches[2]];
        }

        return $ranges;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    private function ranges(): array
    {
        if ($this->ranges === null) {
            $this->ranges = self::parse(($this->outputProvider)());
        }

        return $this->ranges;
    }

    private function queryNetsh(): string
    {
        $binaries = [];
        if (is_file('/mnt/c/Windows/System32/netsh.exe')) {
            $binaries[] = '/mnt/c/Windows/System32/netsh.exe';
        }
        $binaries[] = 'netsh.exe';
        $binaries[] = 'netsh';

        foreach ($binaries as $binary) {
            $process = new Process([
                $binary,
                'interface',
                'ipv4',
                'show',
                'excludedportrange',
                'protocol=tcp',
            ]);
            $process->setTimeout(5);

            try {
                $process->run();
            } catch (\Throwable) {
                continue;
            }

            if ($process->isSuccessful()) {
                return $process->getOutput();
            }
        }

        return '';
    }
}
