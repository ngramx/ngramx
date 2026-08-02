<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Postmaclone\Exception\PostmacloneException;

readonly class S3ObjectLocator
{
    public function __construct(
        public string $bucket,
        public string $key,
        public ?string $region,
        public ?string $endpoint,
        public bool $pathStyle,
    ) {
    }

    public static function parse(
        string $path,
        ?string $region,
        ?string $endpoint,
        ?bool $pathStyle,
    ): self {
        $path = trim($path);
        $bucket = null;
        $key = null;

        if (preg_match('#^(?:s3|spaces)://([^/]+)/(.+)$#', $path, $m) === 1) {
            $bucket = $m[1];
            $key = $m[2];
        } elseif (str_contains($path, '/') && $endpoint !== null) {
            [$bucket, $key] = explode('/', $path, 2);
        } else {
            throw new PostmacloneException(
                "Invalid S3 backup path '{$path}'. Use s3://bucket/key or spaces://bucket/key"
            );
        }

        if ($region === null || $region === '') {
            throw new PostmacloneException('postmaclone.backup.region is required for S3 sources');
        }

        $usePathStyle = $pathStyle ?? ($endpoint !== null && $endpoint !== '');

        return new self($bucket, $key, $region, $endpoint, $usePathStyle);
    }
}
