<?php

declare(strict_types=1);

namespace Ngramx\Config\Schema\Postmaclone;

/**
 * Remote database connection: legacy full URL, or host + database + shared credentials.
 */
readonly class DbConnectionConfig
{
    public function __construct(
        /** Full connection URL (op:// database_url field or literal). Legacy. */
        public ?string $url = null,
        public ?string $host = null,
        public ?int $port = null,
        public ?string $database = null,
        public ?DbCredentialsConfig $credentials = null,
    ) {
    }

    public function isConfigured(): bool
    {
        if ($this->url !== null && $this->url !== '') {
            return true;
        }

        return $this->database !== null && $this->database !== ''
            && $this->credentials !== null
            && ($this->hasHost() || $this->credentials->hasHost());
    }

    public function usesCredentialParts(): bool
    {
        return ($this->url === null || $this->url === '')
            && $this->database !== null && $this->database !== ''
            && $this->credentials !== null
            && ($this->hasHost() || $this->credentials->hasHost());
    }

    private function hasHost(): bool
    {
        return $this->host !== null && $this->host !== '';
    }
}
