<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Restore\PlainSqlDumpSanitizer;
use PHPUnit\Framework\TestCase;

final class PlainSqlDumpSanitizerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pm-sanitize-' . uniqid('', true);
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testStripsAclsAndRewritesOwnershipToCurrentUser(): void
    {
        $path = $this->dir . '/dump.sql';
        file_put_contents($path, <<<'SQL'
\restrict AbCdEf123456
\connect earl_kendrick_prod
CREATE DATABASE earl_kendrick_prod WITH TEMPLATE = template0;
CREATE ROLE earl_kendrick_prod;
SET ROLE earl_kendrick_prod;
ALTER TABLE public.users OWNER TO earl_kendrick_prod;
GRANT SELECT ON TABLE public.users TO earl_kendrick_prod_readonly;
GRANT CONNECT ON DATABASE earl_kendrick_prod TO earl_kendrick_prod_readonly;
ALTER DEFAULT PRIVILEGES FOR ROLE earl_kendrick_prod GRANT SELECT ON TABLES TO earl_kendrick_prod_readonly;
CREATE POLICY p ON public.users FOR SELECT TO earl_kendrick_prod_readonly USING (true);
\unrestrict AbCdEf123456
SQL);

        $out = (new PlainSqlDumpSanitizer())->forPsql($path);
        $body = file_get_contents($out);
        self::assertIsString($body);
        self::assertStringNotContainsString('\\restrict', $body);
        self::assertStringNotContainsString('\\connect', $body);
        self::assertStringNotContainsString('CREATE DATABASE', $body);
        self::assertStringNotContainsString('CREATE ROLE', $body);
        self::assertStringNotContainsString('GRANT ', $body);
        self::assertStringNotContainsString('ALTER DEFAULT PRIVILEGES', $body);
        self::assertStringNotContainsString('CREATE POLICY', $body);
        self::assertStringNotContainsString('earl_kendrick_prod', $body);
        self::assertStringContainsString('OWNER TO CURRENT_USER', $body);
    }

    public function testStripsSchemaAdminStatements(): void
    {
        $path = $this->dir . '/schema.sql';
        file_put_contents($path, <<<'SQL'
DROP SCHEMA public;
CREATE SCHEMA public;
ALTER SCHEMA public OWNER TO doadmin;
ALTER TABLE public.users OWNER TO earl_kendrick_prod;
SQL);

        $out = (new PlainSqlDumpSanitizer())->forPsql($path);
        $body = file_get_contents($out);
        self::assertIsString($body);
        self::assertStringNotContainsString('DROP SCHEMA', $body);
        self::assertStringNotContainsString('CREATE SCHEMA', $body);
        self::assertStringNotContainsString('ALTER SCHEMA', $body);
        self::assertStringContainsString('OWNER TO CURRENT_USER', $body);
    }
}
