<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Backup\MysqlDumpFlags;
use PHPUnit\Framework\TestCase;

class MysqlDumpFlagsTest extends TestCase
{
    public function test_restricted_source_avoids_lock_tables_and_tablespaces(): void
    {
        $flags = MysqlDumpFlags::forRestrictedSource();

        $this->assertContains('--single-transaction', $flags);
        $this->assertContains('--no-tablespaces', $flags);
        $this->assertNotContains('--routines', $flags);
        $this->assertNotContains('--triggers', $flags);
    }

    public function test_scratch_database_includes_routines_and_triggers(): void
    {
        $flags = MysqlDumpFlags::forScratchDatabase();

        $this->assertContains('--single-transaction', $flags);
        $this->assertContains('--no-tablespaces', $flags);
        $this->assertContains('--routines', $flags);
        $this->assertContains('--triggers', $flags);
    }
}
