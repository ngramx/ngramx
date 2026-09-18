<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Restore\MysqlDumpSanitizer;
use PHPUnit\Framework\TestCase;

final class MysqlDumpSanitizerTest extends TestCase
{
    public function test_strips_lone_no_auto_create_user(): void
    {
        $line = "SET sql_mode='NO_AUTO_CREATE_USER';\n";

        self::assertSame("SET sql_mode='';\n", (new MysqlDumpSanitizer())->rewriteLine($line));
    }

    public function test_strips_leading_mode_in_list(): void
    {
        $line = "SET sql_mode='NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';\n";

        self::assertSame(
            "SET sql_mode='NO_ENGINE_SUBSTITUTION';\n",
            (new MysqlDumpSanitizer())->rewriteLine($line)
        );
    }

    public function test_strips_trailing_mode_in_list(): void
    {
        $line = "SET SQL_MODE='NO_ENGINE_SUBSTITUTION,NO_AUTO_CREATE_USER';\n";

        self::assertSame(
            "SET SQL_MODE='NO_ENGINE_SUBSTITUTION';\n",
            (new MysqlDumpSanitizer())->rewriteLine($line)
        );
    }

    public function test_strips_middle_mode_and_keeps_no_auto_value_on_zero(): void
    {
        $line = "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */;\n";

        self::assertSame(
            "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO,NO_ENGINE_SUBSTITUTION' */;\n",
            (new MysqlDumpSanitizer())->rewriteLine($line)
        );
    }

    public function test_strips_routine_block_sql_mode(): void
    {
        $line = "/*!50003 SET sql_mode              = 'NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION' */ ;\n";

        self::assertSame(
            "/*!50003 SET sql_mode              = 'NO_ENGINE_SUBSTITUTION' */ ;\n",
            (new MysqlDumpSanitizer())->rewriteLine($line)
        );
    }

    public function test_leaves_insert_mentioning_the_token_alone(): void
    {
        $line = "INSERT INTO notes VALUES ('used NO_AUTO_CREATE_USER in 5.7');\n";

        self::assertSame($line, (new MysqlDumpSanitizer())->rewriteLine($line));
    }

    public function test_leaves_unrelated_sql_mode_alone(): void
    {
        $line = "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n";

        self::assertSame($line, (new MysqlDumpSanitizer())->rewriteLine($line));
    }

    public function test_stream_filter_rewrites_across_bucket_boundaries(): void
    {
        $sanitizer = new MysqlDumpSanitizer();
        $sanitizer->registerFilter();

        $dump = "SET sql_mode='NO_AUTO_CREATE_USER,STRICT_TRANS_TABLES';\n"
            . "INSERT INTO t VALUES (1);\n"
            . "/*!50003 SET sql_mode = 'NO_ENGINE_SUBSTITUTION,NO_AUTO_CREATE_USER' */ ;\n";

        $in = fopen('php://temp', 'r+');
        self::assertIsResource($in);
        fwrite($in, $dump);
        rewind($in);
        stream_set_chunk_size($in, 8);
        $sanitizer->appendFilter($in);
        $out = stream_get_contents($in);
        fclose($in);

        self::assertIsString($out);
        self::assertStringNotContainsString('NO_AUTO_CREATE_USER', $out);
        self::assertStringContainsString("SET sql_mode='STRICT_TRANS_TABLES';", $out);
        self::assertStringContainsString('INSERT INTO t VALUES (1);', $out);
        self::assertStringContainsString("sql_mode = 'NO_ENGINE_SUBSTITUTION'", $out);
    }
}
