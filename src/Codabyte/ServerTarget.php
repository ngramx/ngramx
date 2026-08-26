<?php

declare(strict_types=1);

namespace Ngramx\Codabyte;

/**
 * Where the Codabyte coding agent lives, and how to get a shell inside it.
 *
 * The agent runs as a long-lived container on a single host, so the defaults
 * here are the ones that are true in practice. Every one of them can still be
 * overridden per-invocation (CLI options) or per-machine (environment
 * variables) via {@see ServerTargetResolver}.
 */
final class ServerTarget
{
    public const DEFAULT_HOST = 'codabyte.gigabyte.software';
    public const DEFAULT_SSH_USER = 'forge';
    public const DEFAULT_CONTAINER = 'coding-agent';
    public const DEFAULT_CONTAINER_USER = 'node';
    public const DEFAULT_WORKDIR = '/workspace';

    public function __construct(
        public readonly string $host = self::DEFAULT_HOST,
        public readonly string $sshUser = self::DEFAULT_SSH_USER,
        public readonly string $container = self::DEFAULT_CONTAINER,
        public readonly string $containerUser = self::DEFAULT_CONTAINER_USER,
        public readonly string $workdir = self::DEFAULT_WORKDIR,
        public readonly ?int $port = null,
    ) {
    }

    public function sshDestination(): string
    {
        return $this->sshUser . '@' . $this->host;
    }

    /**
     * Build the argv for the ssh invocation.
     *
     * @param list<string> $command Command to run inside the container. Empty means
     *                              "give me an interactive bash shell".
     * @param bool $insideContainer False stops on the server itself, without
     *                              stepping into the container.
     * @param bool $tty Whether a terminal is available to forward.
     *
     * @return list<string>
     */
    public function sshArgs(array $command = [], bool $insideContainer = true, bool $tty = true): array
    {
        $args = ['ssh'];

        if ($tty) {
            // Force allocation: without a command ssh does this anyway, but with
            // a remote command (the `docker exec`) it will not unless asked.
            $args[] = '-t';
        }

        if ($this->port !== null) {
            $args[] = '-p';
            $args[] = (string) $this->port;
        }

        $args[] = $this->sshDestination();

        $remote = $insideContainer
            ? $this->dockerExecTokens($command, $tty)
            : $this->hostShellTokens($command);

        if ($remote !== []) {
            // ssh concatenates its trailing arguments with spaces and hands the
            // result to the remote login shell, so quoting has to survive that
            // extra round of parsing. Pre-quote here and pass a single argument.
            $args[] = implode(' ', array_map('escapeshellarg', $remote));
        }

        return $args;
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function dockerExecTokens(array $command, bool $tty): array
    {
        $tokens = ['docker', 'exec', '-i'];

        if ($tty) {
            $tokens[] = '-t';
        }

        $tokens[] = '-u';
        $tokens[] = $this->containerUser;
        $tokens[] = '-w';
        $tokens[] = $this->workdir;

        if ($command === []) {
            $tokens[] = '-e';
            $tokens[] = 'PS1=' . $this->prompt();
        }

        $tokens[] = $this->container;

        if ($command === []) {
            $tokens[] = '/bin/bash';

            return $tokens;
        }

        return array_merge($tokens, $command);
    }

    /**
     * @param list<string> $command
     * @return list<string>
     */
    private function hostShellTokens(array $command): array
    {
        return $command;
    }

    /**
     * Gigabyte brand colours, matching `ngramx shell`, so a remote shell is
     * still recognisable as one - purple host, teal path.
     */
    private function prompt(): string
    {
        $purple = '\[\033[38;2;125;85;199m\]';
        $teal = '\[\033[38;2;46;217;195m\]';
        $reset = '\[\033[0m\]';

        return $purple . $this->container . '@' . $this->host . $reset . ':' . $teal . '\w' . $reset . '\$ ';
    }
}
