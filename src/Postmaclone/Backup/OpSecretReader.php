<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Symfony\Component\Process\Process;

/**
 * Resolves 1Password secret references via the local `op` CLI.
 */
class OpSecretReader
{
    public function read(string $reference): string
    {
        if (!str_starts_with($reference, 'op://')) {
            throw new PostmacloneException(
                "Credential reference must start with op:// (got: {$reference})"
            );
        }

        if (!S3Credentials::isOpAvailable()) {
            throw new PostmacloneException(
                "Cannot resolve {$reference}: 1Password CLI (op) is not on PATH. "
                . 'Install from ' . S3Credentials::OP_INSTALL_URL
                . ' and unlock/integrate with the 1Password app.'
            );
        }

        $process = new Process(['op', 'read', $reference]);
        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            $message = "op read failed for {$reference}" . ($err !== '' ? ": {$err}" : '');
            $guidance = OpAuthProbe::authGuidanceForError($err);
            if ($guidance !== '') {
                $message .= "\n" . $guidance;
            } else {
                $message .= "\nUnlock 1Password / confirm vault access, then retry. See: ngramx postmaclone doctor";
            }

            throw new PostmacloneException($message);
        }

        $value = trim($process->getOutput());
        if ($value === '') {
            throw new PostmacloneException("op read returned an empty value for {$reference}");
        }

        return $value;
    }
}
