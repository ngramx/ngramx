<?php

declare(strict_types=1);

namespace Ngramx\Postmaclone\Backup;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Symfony\Component\Process\Process;

/**
 * Updates 1Password item fields via the local `op` CLI (requires write access on the item).
 *
 * GitHub Actions is a non-TTY pipe. There `op item edit field=value` fails with
 * "invalid JSON provided", and piping item JSON fails with "unable to process
 * line 1: Couldn't update the item" (stdin is parsed as item specifiers).
 * Write a template file and pass `--template` with stdin redirected from
 * /dev/null so neither assignment nor piped JSON is involved.
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
            self::toEditTemplate(self::applyFieldValue($item, $ref->field, $value)),
            JSON_THROW_ON_ERROR,
        );

        $template = $this->writeTemplateFile($payload);
        try {
            $process = $this->runDisconnected([
                'op', 'item', 'edit', $ref->item,
                '--vault', $ref->vault,
                '--template', $template,
            ]);
        } finally {
            @unlink($template);
        }

        if (!$process->isSuccessful()) {
            $err = trim($process->getErrorOutput() ?: $process->getOutput());
            $message = "op item edit failed for {$reference}" . ($err !== '' ? ": {$err}" : '');
            if ($this->looksLikeWriteDenied($err)) {
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
     * Drop empty fields that make `op item edit --template` reject the payload.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    public static function toEditTemplate(array $item): array
    {
        $fields = $item['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }

        $kept = [];
        foreach ($fields as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!array_key_exists('value', $entry) || $entry['value'] === null || $entry['value'] === '') {
                continue;
            }
            $kept[] = $entry;
        }

        $item['fields'] = $kept;

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function getItem(OpReference $ref): array
    {
        $process = $this->runDisconnected([
            'op', 'item', 'get', $ref->item,
            '--vault', $ref->vault,
            '--format', 'json',
            '--reveal',
        ]);

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

    private function writeTemplateFile(string $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'op-item-');
        if ($path === false) {
            throw new PostmacloneException('Failed to create a temporary 1Password item template');
        }
        if (file_put_contents($path, $payload) === false) {
            @unlink($path);
            throw new PostmacloneException('Failed to write the temporary 1Password item template');
        }
        chmod($path, 0600);

        return $path;
    }

    /**
     * @param list<string> $command
     */
    private function runDisconnected(array $command): Process
    {
        // Symfony Process always opens a stdin pipe. Redirect from /dev/null
        // so `op` sees a regular EOF instead of a JSON/item stream.
        $process = new Process(array_merge(
            ['bash', '-c', 'exec "$@" </dev/null', 'op-stdin'],
            $command,
        ));
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    private function looksLikeWriteDenied(string $err): bool
    {
        $lower = strtolower($err);

        return str_contains($lower, 'permission')
            || str_contains($lower, 'denied')
            || str_contains($lower, 'couldn\'t update the item');
    }
}
