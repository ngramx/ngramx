<?php

declare(strict_types=1);

namespace Ngramx\Remote;

/**
 * Resolves a {@see CoderTarget} from, in order of precedence: explicit CLI
 * options, environment variables, then the built-in defaults.
 */
final class CoderTargetResolver
{
    public const ENV_HOST = 'NGRAMX_CODER_HOST';
    public const ENV_SSH_USER = 'NGRAMX_CODER_SSH_USER';
    public const ENV_CONTAINER = 'NGRAMX_CODER_CONTAINER';
    public const ENV_CONTAINER_USER = 'NGRAMX_CODER_CONTAINER_USER';
    public const ENV_WORKDIR = 'NGRAMX_CODER_WORKDIR';
    public const ENV_PORT = 'NGRAMX_CODER_PORT';

    /** @param array<string, string> $env */
    public function __construct(private readonly array $env = [])
    {
    }

    public static function fromEnvironment(): self
    {
        $keys = [
            self::ENV_HOST,
            self::ENV_SSH_USER,
            self::ENV_CONTAINER,
            self::ENV_CONTAINER_USER,
            self::ENV_WORKDIR,
            self::ENV_PORT,
        ];

        $env = [];
        foreach ($keys as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }

        return new self($env);
    }

    /**
     * @param array<string, string|null> $options Keys: host, ssh-user, container,
     *                                            container-user, workdir, port.
     */
    public function resolve(array $options = []): CoderTarget
    {
        $port = $this->pick($options, 'port', self::ENV_PORT);

        return new CoderTarget(
            host: $this->pick($options, 'host', self::ENV_HOST) ?? CoderTarget::DEFAULT_HOST,
            sshUser: $this->pick($options, 'ssh-user', self::ENV_SSH_USER) ?? CoderTarget::DEFAULT_SSH_USER,
            container: $this->pick($options, 'container', self::ENV_CONTAINER) ?? CoderTarget::DEFAULT_CONTAINER,
            containerUser: $this->pick($options, 'container-user', self::ENV_CONTAINER_USER)
                ?? CoderTarget::DEFAULT_CONTAINER_USER,
            workdir: $this->pick($options, 'workdir', self::ENV_WORKDIR) ?? CoderTarget::DEFAULT_WORKDIR,
            port: $port === null ? null : (int) $port,
        );
    }

    /**
     * @param array<string, string|null> $options
     */
    private function pick(array $options, string $option, string $envKey): ?string
    {
        $value = $options[$option] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return $this->env[$envKey] ?? null;
    }
}
