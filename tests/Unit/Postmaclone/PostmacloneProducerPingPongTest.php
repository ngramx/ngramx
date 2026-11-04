<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use PHPUnit\Framework\TestCase;

/**
 * Source guards for ping-pong produce / --from-artifact (Bugbot on GIG-4387).
 */
final class PostmacloneProducerPingPongTest extends TestCase
{
    public function test_produce_does_not_publish_incomplete_latest_json_before_dump(): void
    {
        $source = $this->producerSource();

        $anonymize = strpos($source, '$anonymizer->anonymize($pdo, $dataset->tables)');
        $dump = strpos($source, 'Dumping anonymized database');
        $firstManifest = strpos($source, '$this->writeLatestManifest(');
        $promoted = strpos($source, '$promoted = true');

        self::assertNotFalse($anonymize);
        self::assertNotFalse($dump);
        self::assertNotFalse($firstManifest);
        self::assertNotFalse($promoted);
        self::assertTrue(
            $anonymize < $promoted && $promoted < $dump,
            'idle slot must be marked promoted before dump so finally does not destroy it',
        );
        self::assertTrue(
            $firstManifest > $dump,
            'latest.json must not be written until after the anonymized dump exists',
        );
        self::assertStringNotContainsString("0,\n                        '',", $source);
    }

    public function test_from_artifact_ping_pong_keeps_existing_live_without_restore(): void
    {
        $source = $this->producerSource();

        self::assertStringContainsString('SharedSlotPicker::manifestLive($previousManifest)', $source);
        self::assertStringContainsString('Ping-pong keeping live', $source);
        self::assertStringContainsString('skipping idle restore', $source);

        $keep = strpos($source, 'Ping-pong keeping live');
        $loadIdle = strpos($source, 'Ping-pong live {$slots[\'live\']}; loading idle {$slots[\'idle\']}');
        self::assertNotFalse($keep);
        self::assertNotFalse($loadIdle);
        self::assertTrue($keep < $loadIdle, 'existing shared_database must short-circuit before idle restore');
    }

    public function test_from_artifact_idle_refresh_copies_remote_connection(): void
    {
        $source = $this->producerSource();

        self::assertStringContainsString('url: $remote->url', $source);
        self::assertStringContainsString('host: $remote->host', $source);
        self::assertStringContainsString('port: $remote->port', $source);
        self::assertStringContainsString('credentials: $remote->credentials', $source);
    }

    private function producerSource(): string
    {
        return (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Postmaclone/PostmacloneProducer.php'
        );
    }
}
