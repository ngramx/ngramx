<?php

declare(strict_types=1);

namespace Ngramx\Docker;

/**
 * A running container that has zero networks attached even though its
 * compose definition declared one. Reported by {@see NetworkAttachmentChecker}
 * and (usually) auto-resolved by recreating the service.
 */
readonly class NetworkAttachmentIssue
{
    public function __construct(
        public string $service,
        public string $containerId,
        public string $declaredNetworkMode,
    ) {
    }

    public function describe(): string
    {
        return sprintf(
            'Service `%s` is running but has no networks attached '
                . '(container %s, expected network mode: %s). '
                . 'Peer services cannot resolve this hostname, so anything that depends on it will hang.',
            $this->service,
            substr($this->containerId, 0, 12),
            $this->declaredNetworkMode === '' ? '(unset)' : $this->declaredNetworkMode,
        );
    }
}
