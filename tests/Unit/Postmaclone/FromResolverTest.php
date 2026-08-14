<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\FromResolver;
use Ngramx\Postmaclone\FromSource;
use PHPUnit\Framework\TestCase;

class FromResolverTest extends TestCase
{
    private FromResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new FromResolver();
    }

    public function test_resolves_filesystem_path(): void
    {
        $source = $this->resolver->resolve('./backups/prod.dump');
        $this->assertTrue($source->isPath());
        $this->assertSame('./backups/prod.dump', $source->value);
    }

    public function test_resolves_postgres_connection(): void
    {
        $source = $this->resolver->resolve('postgresql://u:p@localhost:5432/app');
        $this->assertTrue($source->isConnection());
        $this->assertSame('postgres', $source->engineHint);
    }

    public function test_resolves_mysql_connection(): void
    {
        $source = $this->resolver->resolve('mysql://root:secret@127.0.0.1/app');
        $this->assertTrue($source->isConnection());
        $this->assertSame('mysql', $source->engineHint);
    }

    public function test_resolves_s3_uri(): void
    {
        $source = $this->resolver->resolve('s3://bucket/path/file.dump');
        $this->assertTrue($source->isS3());
        $this->assertSame(FromSource::KIND_S3, $source->kind);
    }

    public function test_resolves_spaces_uri(): void
    {
        $source = $this->resolver->resolve('spaces://bucket/path/file.dump');
        $this->assertTrue($source->isS3());
    }

    public function test_rejects_unknown_scheme(): void
    {
        $this->expectException(PostmacloneException::class);
        $this->resolver->resolve('ftp://example.com/file.dump');
    }

    public function test_rejects_empty(): void
    {
        $this->expectException(PostmacloneException::class);
        $this->resolver->resolve('   ');
    }
}
