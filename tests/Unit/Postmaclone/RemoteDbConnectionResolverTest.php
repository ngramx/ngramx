<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\DbConnectionConfig;
use Ngramx\Config\Schema\Postmaclone\DbCredentialsConfig;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Postmaclone\Connection\RemoteDbConnectionResolver;
use PHPUnit\Framework\TestCase;

final class RemoteDbConnectionResolverTest extends TestCase
{
    public function test_builds_url_from_credentials_item_fields(): void
    {
        $resolver = new RemoteDbConnectionResolver();
        $url = $resolver->resolve(new DbConnectionConfig(
            database: 'earl_kendrick_core_prod_scratch',
            credentials: new DbCredentialsConfig(
                username: 'scratch_user',
                password: 's3cret!',
                host: 'cluster.example.com',
                port: '25060',
                connectionOptions: 'sslmode=require',
            ),
        ), PostmacloneConfig::ENGINE_POSTGRES);

        $this->assertSame(
            'postgres://scratch_user:s3cret%21@cluster.example.com:25060/earl_kendrick_core_prod_scratch?sslmode=require',
            $url,
        );
    }

    public function test_legacy_host_on_connection_still_works(): void
    {
        $resolver = new RemoteDbConnectionResolver();
        $url = $resolver->resolve(new DbConnectionConfig(
            host: 'cluster.example.com',
            port: 25060,
            database: 'earl_kendrick_core_prod_scratch',
            credentials: new DbCredentialsConfig(
                username: 'scratch_user',
                password: 's3cret!',
            ),
        ), PostmacloneConfig::ENGINE_POSTGRES);

        $this->assertSame(
            'postgres://scratch_user:s3cret%21@cluster.example.com:25060/earl_kendrick_core_prod_scratch?sslmode=require',
            $url,
        );
    }

    public function test_legacy_full_url_still_works(): void
    {
        $resolver = new RemoteDbConnectionResolver();
        $url = $resolver->resolve(new DbConnectionConfig(
            url: 'postgres://legacy:pass@host:5432/db',
        ), PostmacloneConfig::ENGINE_POSTGRES);

        $this->assertSame('postgres://legacy:pass@host:5432/db', $url);
    }
}
