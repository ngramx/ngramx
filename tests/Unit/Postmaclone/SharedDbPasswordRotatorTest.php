<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\DbConnectionConfig;
use Ngramx\Config\Schema\Postmaclone\SharedDbConfig;
use Ngramx\Postmaclone\Backup\OpReference;
use Ngramx\Postmaclone\Connection\DatabaseConnectionUrl;
use Ngramx\Postmaclone\SharedDbPasswordRotator;
use PHPUnit\Framework\TestCase;

final class OpReferenceTest extends TestCase
{
    public function test_parses_vault_item_and_field(): void
    {
        $ref = OpReference::parse('op://Tech Team Vault/postmaclone-shared-earl-kendrick/database_url');
        $this->assertSame('Tech Team Vault', $ref->vault);
        $this->assertSame('postmaclone-shared-earl-kendrick', $ref->item);
        $this->assertSame('database_url', $ref->field);
    }
}

final class DatabaseConnectionUrlTest extends TestCase
{
    public function test_round_trip_with_new_password(): void
    {
        $original = 'postgresql://app_user:old%21pass@db.example.com:25060/earl_kendrick_core_prod_anon';
        $parsed = DatabaseConnectionUrl::parse($original);
        $this->assertSame('app_user', $parsed->username);
        $this->assertSame('old!pass', $parsed->password);
        $this->assertSame('earl_kendrick_core_prod_anon', $parsed->database);

        $updated = $parsed->withPassword('N3wP4ss')->toUrl();
        $this->assertSame(
            'postgres://app_user:N3wP4ss@db.example.com:25060/earl_kendrick_core_prod_anon',
            $updated,
        );
    }

    public function test_round_trip_preserves_ssl_query(): void
    {
        $original = 'postgresql://app_user:old%21pass@db.example.com:25060/earl_kendrick_core_prod_anon?sslmode=require';
        $parsed = DatabaseConnectionUrl::parse($original);
        $this->assertSame('sslmode=require', $parsed->query);

        $updated = $parsed->withPassword('N3wP4ss')->toUrl();
        $this->assertSame(
            'postgres://app_user:N3wP4ss@db.example.com:25060/earl_kendrick_core_prod_anon?sslmode=require',
            $updated,
        );
    }
}

final class SharedDbPasswordRotatorTest extends TestCase
{
    public function test_disabled_rotation_is_no_op(): void
    {
        $rotator = new SharedDbPasswordRotator();
        $result = $rotator->rotateIfDue('postgres', new SharedDbConfig(
            connection: new DbConnectionConfig(url: 'op://Vault/item/database_url'),
            passwordRotationDays: 0,
        ));

        $this->assertFalse($result['rotated']);
        $this->assertNull($result['rotated_at']);
        $this->assertSame('op://Vault/item/database_url', $result['credential_key']);
    }

    public function test_first_run_sets_baseline_without_rotating(): void
    {
        $rotator = new SharedDbPasswordRotator();
        $result = $rotator->rotateIfDue('postgres', new SharedDbConfig(
            connection: new DbConnectionConfig(url: 'op://Vault/item/database_url'),
            passwordRotationDays: 7,
        ));

        $this->assertFalse($result['rotated']);
        $this->assertNotNull($result['rotated_at']);
    }

    public function test_credential_key_uses_password_op_ref(): void
    {
        $rotator = new SharedDbPasswordRotator();
        $key = $rotator->credentialKey(new SharedDbConfig(
            connection: new DbConnectionConfig(
                host: 'cluster.example.com',
                database: 'demo_anon',
                credentials: new \Ngramx\Config\Schema\Postmaclone\DbCredentialsConfig(
                    username: 'op://Vault/postmaclone-anon/username',
                    password: 'op://Vault/postmaclone-anon/password',
                ),
            ),
        ));

        $this->assertSame('op://Vault/postmaclone-anon/password', $key);
    }

    public function test_recent_rotation_is_skipped(): void
    {
        $rotator = new SharedDbPasswordRotator();
        $result = $rotator->rotateIfDue('postgres', new SharedDbConfig(
            connection: new DbConnectionConfig(url: 'op://Vault/item/database_url'),
            passwordRotationDays: 7,
        ), (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify('-2 days')
            ->format('c'));

        $this->assertFalse($result['rotated']);
    }
}
