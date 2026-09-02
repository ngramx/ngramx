<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\Postmaclone\BackupCredentialsConfig;
use Ngramx\Config\Schema\Postmaclone\PublishConfig;
use Ngramx\Postmaclone\Backup\S3Credentials;
use Ngramx\Postmaclone\Backup\S3ManifestReader;
use Ngramx\Postmaclone\Backup\S3ObjectLocator;
use Ngramx\Postmaclone\Backup\S3ObjectUploader;
use Ngramx\Postmaclone\CredentialRotationStateStore;
use PHPUnit\Framework\TestCase;

final class CredentialRotationStateStoreTest extends TestCase
{
    public function test_locator_uses_shared_state_object_in_publish_bucket(): void
    {
        $store = CredentialRotationStateStore::forPublish(new PublishConfig(
            path: 'spaces://anon-bucket/earl-kendrick/',
            region: 'lon1',
            endpoint: 'https://lon1.digitaloceanspaces.com',
            credentials: new BackupCredentialsConfig(
                key: 'op://Vault/write/username',
                secret: 'op://Vault/write/password',
            ),
        ));

        $reflection = new \ReflectionClass($store);
        $property = $reflection->getProperty('locator');
        $property->setAccessible(true);
        /** @var S3ObjectLocator $locator */
        $locator = $property->getValue($store);

        $this->assertSame('anon-bucket', $locator->bucket);
        $this->assertSame(CredentialRotationStateStore::STATE_OBJECT_KEY, $locator->key);
    }

    public function test_records_and_reads_credential_timestamp(): void
    {
        $locator = new S3ObjectLocator(
            'anon-bucket',
            CredentialRotationStateStore::STATE_OBJECT_KEY,
            'lon1',
            'https://lon1.digitaloceanspaces.com',
            false,
        );
        $credentials = new S3Credentials();
        $state = ['version' => 1, 'credentials' => []];

        $reader = $this->createMock(S3ManifestReader::class);
        $reader->method('read')->willReturnCallback(function () use (&$state) {
            return $state;
        });

        $uploader = $this->createMock(S3ObjectUploader::class);
        $uploader->method('putBody')->willReturnCallback(function (string $body) use (&$state): void {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $state = $decoded;
            }
        });

        $store = new CredentialRotationStateStore($locator, $credentials, $reader, $uploader);
        $credential = 'op://Tech Team Vault/postmaclone-anon/password';

        $this->assertNull($store->lastRotatedAt($credential));

        $store->recordRotatedAt($credential, '2026-09-02T04:00:00+00:00');

        $this->assertSame('2026-09-02T04:00:00+00:00', $store->lastRotatedAt($credential));
    }
}
