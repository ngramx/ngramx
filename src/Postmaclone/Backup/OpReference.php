<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

/**
 * Parsed op://vault/item/field reference.
 */
readonly class OpReference
{
    public function __construct(
        public string $vault,
        public string $item,
        public string $field,
    ) {
    }

    public static function parse(string $reference): self
    {
        if (!str_starts_with($reference, 'op://')) {
            throw new \InvalidArgumentException("Not an op:// reference: {$reference}");
        }

        $path = substr($reference, 5);
        $parts = explode('/', $path, 3);
        if (count($parts) !== 3 || $parts[0] === '' || $parts[1] === '' || $parts[2] === '') {
            throw new \InvalidArgumentException("Invalid op:// reference (expected op://vault/item/field): {$reference}");
        }

        return new self(
            vault: rawurldecode($parts[0]),
            item: rawurldecode($parts[1]),
            field: rawurldecode($parts[2]),
        );
    }
}
