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

    public function test_strips_backticked_definer_from_view(): void
    {
        $line = "CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v` AS SELECT 1;\n";

        self::assertSame(
            "CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `v` AS SELECT 1;\n",
            (new MysqlDumpSanitizer())->rewriteLine($line)
        );
    }

    public function test_strips_50017_definer_from_trigger(): void
    {
        $line = "/*!50003 CREATE*/ /*!50017 DEFINER=`app`@`%`*/ /*!50003 TRIGGER t BEFORE INSERT ON u FOR EACH ROW SET NEW.id = 1 */;;\n";

        self::assertSame(
            "/*!50003 CREATE*/ /*!50017*/ /*!50003 TRIGGER t BEFORE INSERT ON u FOR EACH ROW SET NEW.id = 1 */;;\n",
            (new MysqlDumpSanitizer())->rewriteLine($line)
        );
    }

    public function test_strips_quoted_definer_from_procedure(): void
    {
        $line = "CREATE DEFINER='hydra'@'10.%' PROCEDURE `p`() BEGIN SELECT 1; END;\n";

        self::assertSame(
            "CREATE PROCEDURE `p`() BEGIN SELECT 1; END;\n",
            (new MysqlDumpSanitizer())->rewriteLine($line)
        );
    }

    public function test_leaves_insert_mentioning_definer_alone(): void
    {
        $line = "INSERT INTO notes VALUES ('CREATE VIEW DEFINER=`root`@`localhost`');\n";

        self::assertSame($line, (new MysqlDumpSanitizer())->rewriteLine($line));
    }

    public function test_leaves_sql_security_definer_after_strip(): void
    {
        $line = "CREATE DEFINER=`x`@`y` SQL SECURITY DEFINER FUNCTION `f`() RETURNS INT RETURN 1;\n";

        $out = (new MysqlDumpSanitizer())->rewriteLine($line);
        self::assertStringNotContainsString('DEFINER=`x`', $out);
        self::assertStringContainsString('SQL SECURITY DEFINER FUNCTION', $out);
    }

    public function test_comments_sql_log_bin(): void
    {
        $line = "SET @@SESSION.SQL_LOG_BIN= 0;\n";

        self::assertSame(
            "-- ngramx: stripped SQL_LOG_BIN\n",
            (new MysqlDumpSanitizer())->rewriteLine($line)
        );
    }

    public function test_comments_gtid_purged(): void
    {
        $line = "SET @@GLOBAL.GTID_PURGED=/*!80000 '+'*/ 'aaaa-bbbb:1-9';\n";

        self::assertSame(
            "-- ngramx: stripped GTID assignment\n",
            (new MysqlDumpSanitizer())->rewriteLine($line)
        );
    }

    public function test_comments_versioned_sql_log_bin(): void
    {
        $line = "/*!40000 SET @@SESSION.SQL_LOG_BIN=0 */;\n";

        self::assertSame(
            "-- ngramx: stripped SQL_LOG_BIN\n",
            (new MysqlDumpSanitizer())->rewriteLine($line)
        );
    }

    public function test_leaves_insert_mentioning_sql_log_bin_without_set(): void
    {
        $line = "INSERT INTO notes VALUES ('forgot SQL_LOG_BIN');\n";

        self::assertSame($line, (new MysqlDumpSanitizer())->rewriteLine($line));
    }

    public function test_leaves_insert_set_row_mentioning_sql_log_bin(): void
    {
        $line = "INSERT INTO notes SET body='SET @@SESSION.SQL_LOG_BIN=0';\n";

        self::assertSame($line, (new MysqlDumpSanitizer())->rewriteLine($line));
    }

    public function test_leaves_insert_values_containing_set_sql_log_bin(): void
    {
        $line = "INSERT INTO notes VALUES ('SET @@SESSION.SQL_LOG_BIN=0');\n";

        self::assertSame($line, (new MysqlDumpSanitizer())->rewriteLine($line));
    }

    public function test_stream_filter_strips_definer_across_bucket_boundaries(): void
    {
        $sanitizer = new MysqlDumpSanitizer();
        $sanitizer->registerFilter();

        $dump = "CREATE DEFINER=`root`@`localhost` VIEW `v` AS SELECT 1;\n"
            . "SET @@SESSION.SQL_LOG_BIN=0;\n"
            . "INSERT INTO t VALUES (1);\n";

        $in = fopen('php://temp', 'r+');
        self::assertIsResource($in);
        fwrite($in, $dump);
        rewind($in);
        stream_set_chunk_size($in, 8);
        $sanitizer->appendFilter($in);
        $out = stream_get_contents($in);
        fclose($in);

        self::assertIsString($out);
        self::assertStringNotContainsString('DEFINER=', $out);
        self::assertStringContainsString('CREATE VIEW `v` AS SELECT 1;', $out);
        self::assertStringContainsString('-- ngramx: stripped SQL_LOG_BIN', $out);
        self::assertStringContainsString('INSERT INTO t VALUES (1);', $out);
    }
}
