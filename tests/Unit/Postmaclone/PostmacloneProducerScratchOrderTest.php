<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use PHPUnit\Framework\TestCase;

/**
 * Guards factory produce ordering: scratch must be released before shared anon restore.
 */
final class PostmacloneProducerScratchOrderTest extends TestCase
{
    public function test_producer_source_releases_scratch_before_shared_refresh(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/Postmaclone/PostmacloneProducer.php'
        );

        $destroyBefore = strpos($source, 'Destroying scratch database before shared refresh');
        self::assertNotFalse($destroyBefore);

        $upload = strpos($source, '$uploader->putFile($artifactLocal)');
        self::assertNotFalse($upload);
        self::assertLessThan($upload, $destroyBefore);

        $sharedRefresh = strpos(
            $source,
            '$this->sharedRefresher->refresh($engine, $dataset->shared, $artifactLocal)',
            $destroyBefore
        );
        self::assertNotFalse($sharedRefresh);
        self::assertLessThan($destroyBefore, $sharedRefresh);
        self::assertStringContainsString('if (!$scratchReleased)', $source);
    }
}
