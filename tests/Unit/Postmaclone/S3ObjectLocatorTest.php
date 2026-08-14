<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Backup\S3ObjectLocator;
use Ngramx\Postmaclone\Exception\PostmacloneException;
use PHPUnit\Framework\TestCase;

class S3ObjectLocatorTest extends TestCase
{
    public function test_parses_s3_uri(): void
    {
        $locator = S3ObjectLocator::parse('s3://my-bucket/path/to.dump', 'eu-west-1', null, null);
        $this->assertSame('my-bucket', $locator->bucket);
        $this->assertSame('path/to.dump', $locator->key);
        $this->assertSame('eu-west-1', $locator->region);
        $this->assertFalse($locator->pathStyle);
    }

    public function test_parses_spaces_uri_with_endpoint_defaults_path_style(): void
    {
        $locator = S3ObjectLocator::parse(
            'spaces://backups/db.dump',
            'ams3',
            'https://ams3.digitaloceanspaces.com',
            null
        );
        $this->assertSame('backups', $locator->bucket);
        $this->assertTrue($locator->pathStyle);
    }

    public function test_requires_region(): void
    {
        $this->expectException(PostmacloneException::class);
        S3ObjectLocator::parse('s3://bucket/key', null, null, null);
    }
}
