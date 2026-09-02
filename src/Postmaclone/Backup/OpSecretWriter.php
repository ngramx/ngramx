<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Symfony\Component\Process\Process;

/**
 * Updates 1Password item fields via the local `op` CLI (requires write access on the item).
 */
class OpSecretWriter
{
    public function write(string $reference, string $value): void
    {
        if (!str_starts_with($reference, 'op://')) {
            throw new PostmacloneException(
                "Credential reference must start with op:// (got: {$reference})"
            );
        }

        if (!S3Credentials::isOpAvailable()) {
            throw new PostmacloneException(
                "Cannot update {$reference}: 1Password CLI (op) is not on PATH."
            );
        }

        $ref = OpReference::parse($reference);
        $assignment = $ref->field . '=' . $value;
        $process = new Process([
            'op', 'item', 'edit', $ref->item,
            '--vault', $ref->vault,
            $assignment,
        ]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            $message = "op item edit failed for {$reference}" . ($err !== '' ? ": {$err}" : '');
            if (str_contains(strtolower($err), 'permission') || str_contains(strtolower($err), 'denied')) {
                $message .= "\nThe 1Password service account needs write access on this item.";
            }

            throw new PostmacloneException($message);
        }
    }
}
