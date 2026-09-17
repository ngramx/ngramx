<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Backup\OpSecretWriter;
use PHPUnit\Framework\TestCase;

final class OpSecretWriterTest extends TestCase
{
    public function test_it_replaces_a_field_matched_by_id(): void
    {
        $item = [
            'title' => 'postmaclone-anon-psql',
            'fields' => [
                ['id' => 'username', 'label' => 'username', 'value' => 'anon'],
                ['id' => 'password', 'label' => 'password', 'value' => 'old-secret'],
            ],
        ];

        $updated = OpSecretWriter::applyFieldValue($item, 'password', 'new-secret');

        $this->assertSame('anon', $updated['fields'][0]['value']);
        $this->assertSame('new-secret', $updated['fields'][1]['value']);
    }

    public function test_it_replaces_a_field_matched_by_label(): void
    {
        $item = [
            'fields' => [
                ['id' => 'xxx', 'label' => 'password', 'value' => 'old-secret'],
            ],
        ];

        $updated = OpSecretWriter::applyFieldValue($item, 'password', 'new-secret');

        $this->assertSame('new-secret', $updated['fields'][0]['value']);
    }

    public function test_it_appends_a_concealed_field_when_missing(): void
    {
        $item = [
            'fields' => [
                ['id' => 'username', 'label' => 'username', 'value' => 'anon'],
            ],
        ];

        $updated = OpSecretWriter::applyFieldValue($item, 'password', 'new-secret');

        $this->assertCount(2, $updated['fields']);
        $this->assertSame('password', $updated['fields'][1]['id']);
        $this->assertSame('CONCEALED', $updated['fields'][1]['type']);
        $this->assertSame('new-secret', $updated['fields'][1]['value']);
    }

    public function test_edit_template_drops_empty_fields(): void
    {
        $item = [
            'title' => 'postmaclone-anon-psql',
            'fields' => [
                ['id' => 'username', 'label' => 'username', 'value' => 'anon'],
                ['id' => 'notesPlain', 'label' => 'notesPlain'],
                ['id' => 'empty', 'label' => 'empty', 'value' => ''],
                ['id' => 'password', 'label' => 'password', 'value' => 'kept'],
            ],
        ];

        $template = OpSecretWriter::toEditTemplate($item);

        $this->assertCount(2, $template['fields']);
        $this->assertSame('username', $template['fields'][0]['id']);
        $this->assertSame('password', $template['fields'][1]['id']);
    }
}
