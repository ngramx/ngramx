<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * A built-from-source Docker image that no longer matches the project tree.
 */
readonly class StaleBuildFinding
{
    public const REASON_ENTRYPOINT_CHANGED = 'entrypoint-changed';
    public const REASON_STARTUP_SCRIPT_CHANGED = 'startup-script-changed';
    public const REASON_COMPOSE_NEWER_THAN_IMAGE = 'compose-newer-than-image';

    public function __construct(
        public string $service,
        public string $image,
        public string $reason,
        public string $hostPath,
        public ?string $imagePath = null,
        /** @var list<string> */
        public array $composeInputPaths = [],
    ) {
    }

    public function describe(): string
    {
        return match ($this->reason) {
            self::REASON_ENTRYPOINT_CHANGED => sprintf(
                'The `%s` image still contains an older copy of the entrypoint script. Host: `%s`. Image: `%s`.',
                $this->service,
                $this->relativeHostPath(),
                $this->imagePath ?? 'entrypoint'
            ),
            self::REASON_STARTUP_SCRIPT_CHANGED => sprintf(
                'The `%s` image still contains an older startup script. Host: `%s`. Image: `%s`.',
                $this->service,
                $this->relativeHostPath(),
                $this->imagePath ?? 'startup script'
            ),
            self::REASON_COMPOSE_NEWER_THAN_IMAGE => sprintf(
                'The `%s` image was built before the compose stack was last changed (%s).',
                $this->service,
                $this->describeComposeInputs()
            ),
            default => sprintf(
                'The `%s` image may be stale relative to `%s`.',
                $this->service,
                $this->relativeHostPath()
            ),
        };
    }

    private function relativeHostPath(): string
    {
        $basename = basename($this->hostPath);

        return str_contains($this->hostPath, '/docker/')
            ? 'docker/' . $basename
            : $basename;
    }

    private function describeComposeInputs(): string
    {
        if ($this->composeInputPaths !== []) {
            return implode(', ', $this->composeInputPaths);
        }

        return $this->relativeHostPath();
    }
}
