<?php

declare(strict_types=1);

namespace Ngramx\Remote;

/**
 * Runs ssh with the caller's terminal attached, so the remote shell behaves
 * like any other interactive session (job control, resizing, ctrl-c).
 */
class SshRunner
{
    public function hasTty(): bool
    {
        return function_exists('posix_isatty') && posix_isatty(STDIN);
    }

    /**
     * @param list<string> $args
     * @return int Exit code from ssh
     */
    public function run(array $args): int
    {
        $ttyAvailable = file_exists('/dev/tty') && $this->hasTty();

        if ($ttyAvailable) {
            $process = proc_open($args, [
                ['file', '/dev/tty', 'r'],
                ['file', '/dev/tty', 'w'],
                ['file', '/dev/tty', 'w'],
            ], $pipes);
        } else {
            $process = proc_open($args, [STDIN, STDOUT, STDERR], $pipes);
        }

        if (!is_resource($process)) {
            return 1;
        }

        return proc_close($process);
    }
}
