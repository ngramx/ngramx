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
        self::assertTrue($upload < $destroyBefore, 'artifact upload must finish before scratch is destroyed');

        $sharedRefresh = strpos(
            $source,
            '$this->sharedRefresher->refresh($engine, $dataset->shared, $artifactLocal)',
            $destroyBefore
        );
        self::assertNotFalse($sharedRefresh);
        self::assertTrue($destroyBefore < $sharedRefresh, 'scratch must be destroyed before shared anon restore');
        self::assertStringContainsString('if (!$scratchReleased)', $source);

        $restore = strpos($source, '$restorer->restore($dumpPath, $target)');
        self::assertNotFalse($restore);
        $sourceCleanup = strpos($source, '$source->cleanup(false)', $restore);
        self::assertNotFalse($sourceCleanup);
        $anonDump = strpos($source, 'Dumping anonymized database', $sourceCleanup);
        self::assertNotFalse($anonDump);
        self::assertTrue(
            $restore < $sourceCleanup && $sourceCleanup < $anonDump,
            'downloaded source dump must be removed after restore and before the anon dump'
        );
    }
}
