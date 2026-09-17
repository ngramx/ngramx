<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Symfony\Component\Process\Process;

/**
 * Updates 1Password item fields via the local `op` CLI (requires write access on the item).
 *
 * Field-assignment `op item edit item field=value` is treated as a JSON template
 * read from stdin in GitHub Actions (non-TTY). That yields "invalid JSON provided".
 * Fetch the item, replace the field, and pipe the JSON instead.
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
        $item = $this->getItem($ref);
        $payload = json_encode(
            self::applyFieldValue($item, $ref->field, $value),
            JSON_THROW_ON_ERROR,
        );

        $process = $this->run(
            ['op', 'item', 'edit', $ref->item, '--vault', $ref->vault],
            $payload,
        );

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            $message = "op item edit failed for {$reference}" . ($err !== '' ? ": {$err}" : '');
            if (str_contains(strtolower($err), 'permission') || str_contains(strtolower($err), 'denied')) {
                $message .= "\nThe 1Password service account needs write access on this item.";
            }

            throw new PostmacloneException($message);
        }
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function applyFieldValue(array $item, string $field, string $value): array
    {
        $fields = $item['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }

        $updated = false;
        foreach ($fields as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $id = isset($entry['id']) && is_string($entry['id']) ? $entry['id'] : '';
            $label = isset($entry['label']) && is_string($entry['label']) ? $entry['label'] : '';
            if ($id !== $field && $label !== $field) {
                continue;
            }
            $fields[$index]['value'] = $value;
            $updated = true;
        }

        if (!$updated) {
            $fields[] = [
                'id' => $field,
                'label' => $field,
                'type' => 'CONCEALED',
                'value' => $value,
            ];
        }

        $item['fields'] = $fields;

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function getItem(OpReference $ref): array
    {
        $process = $this->run([
            'op', 'item', 'get', $ref->item,
            '--vault', $ref->vault,
            '--format', 'json',
            '--reveal',
        ], '');

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            throw new PostmacloneException(
                "op item get failed for {$ref->vault}/{$ref->item}" . ($err !== '' ? ": {$err}" : '')
            );
        }

        try {
            $item = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PostmacloneException(
                "op item get returned invalid JSON for {$ref->vault}/{$ref->item}: {$e->getMessage()}",
                0,
                $e,
            );
        }

        if (!is_array($item)) {
            throw new PostmacloneException(
                "op item get returned invalid JSON for {$ref->vault}/{$ref->item}"
            );
        }

        return $item;
    }

    /**
     * @param list<string> $command
     */
    private function run(array $command, string $stdin): Process
    {
        $process = new Process($command);
        $process->setTimeout(60);
        // Always bind stdin. In GitHub Actions `op item edit` otherwise treats
        // the inherited non-TTY stream as a JSON template and fails.
        $process->setInput($stdin);
        $process->run();

        return $process;
    }
}
