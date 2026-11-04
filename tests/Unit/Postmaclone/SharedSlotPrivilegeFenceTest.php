<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\SharedSlotPrivilegeFence;
use PHPUnit\Framework\TestCase;

final class SharedSlotPrivilegeFenceTest extends TestCase
{
    public function test_mysql_revoke_and_grant_sql(): void
    {
        self::assertSame(
            'REVOKE ALL PRIVILEGES ON `hydra_prod_anon`.* FROM \'postmaclone_anon\'@\'%\'',
            SharedSlotPrivilegeFence::mysqlRevokeSql('hydra_prod_anon', 'postmaclone_anon'),
        );
        self::assertSame(
            'GRANT ALL PRIVILEGES ON `hydra_prod_scratch`.* TO \'postmaclone_anon\'@\'%\'',
            SharedSlotPrivilegeFence::mysqlGrantSql('hydra_prod_scratch', 'postmaclone_anon'),
        );
    }

    public function test_rejects_unsafe_database_name(): void
    {
        $this->expectException(PostmacloneException::class);
        SharedSlotPrivilegeFence::mysqlGrantSql('hydra`; DROP TABLE users; --', 'postmaclone_anon');
    }

    public function test_rejects_unsafe_username(): void
    {
        $this->expectException(PostmacloneException::class);
        SharedSlotPrivilegeFence::mysqlRevokeSql('hydra_prod_anon', "anon' OR '1'='1");
    }
}
