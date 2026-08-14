<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone;

readonly class FromSource
{
    public const KIND_PATH = 'path';
    public const KIND_CONNECTION = 'connection';
    public const KIND_S3 = 's3';

    public function __construct(
        public string $kind,
        public string $value,
        public ?string $engineHint = null,
    ) {
    }

    public function isPath(): bool
    {
        return $this->kind === self::KIND_PATH;
    }

    public function isConnection(): bool
    {
        return $this->kind === self::KIND_CONNECTION;
    }

    public function isS3(): bool
    {
        return $this->kind === self::KIND_S3;
    }
}
