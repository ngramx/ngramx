<?php

declare(strict_types=1);

namespace Ngramx\Docker;

use Symfony\Component\Process\Process;

/**
 * Detects a non-Docker process already listening on 127.0.0.1.
 *
 * Docker can publish a port on every interface while another process keeps the
 * loopback address. A TablePlus link to 127.0.0.1 then opens the other process.
 */
final class LoopbackHold
{
    public function owns(int $port): bool
    {
        $process = new Process([
            'lsof',
            '-nP',
            '+c',
            '0',
            '-iTCP:' . $port,
            '-sTCP:LISTEN',
            '-F',
            'cn',
        ]);
        $process->run();

        $output = $process->getOutput();
        if ($output === '') {
            return false;
        }

        return self::nonDockerOwns($output, $port);
    }

    /**
     * `lsof -F cn` field output. A command line (`c…`) applies to the name
     * lines (`n…`) that follow it.
     */
    public static function nonDockerOwns(string $fields, int $port): bool
    {
        $command = '';
        foreach (preg_split("/\r\n|\n|\r/", $fields) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            $kind = $line[0];
            $value = substr($line, 1);
            if ($kind === 'c') {
                $command = $value;
                continue;
            }

            if ($kind !== 'n' || !self::isLoopbackName($value, $port)) {
                continue;
            }

            if (!self::isDockerCommand($command)) {
                return true;
            }
        }

        return false;
    }

    private static function isLoopbackName(string $name, int $port): bool
    {
        return $name === '127.0.0.1:' . $port || $name === 'localhost:' . $port;
    }

    private static function isDockerCommand(string $command): bool
    {
        $command = strtolower($command);

        return str_contains($command, 'docker') || str_contains($command, 'vpnkit');
    }
}
