<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\LockFile;
use Ngramx\Config\LockFileData;
use PHPUnit\Framework\TestCase;

class LockFileTest extends TestCase
{
    private string $tempDir;
    private LockFile $lockFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ngramx-test-' . uniqid();
        mkdir($this->tempDir);
        $this->lockFile = new LockFile($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $lockPath = $this->tempDir . '/.ngramx.lock';
        if (file_exists($lockPath)) {
            unlink($lockPath);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function test_it_checks_if_lock_file_exists(): void
    {
        $this->assertFalse($this->lockFile->exists());
    }

    public function test_it_writes_lock_file(): void
    {
        $data = new LockFileData(
            namespace: 'test-namespace',
            portOffset: 1000,
            startedAt: '2025-11-08T10:30:00+00:00'
        );

        $this->lockFile->write($data);

        $this->assertTrue($this->lockFile->exists());
    }

    public function test_it_reads_lock_file(): void
    {
        $data = new LockFileData(
            namespace: 'test-namespace',
            portOffset: 1000,
            startedAt: '2025-11-08T10:30:00+00:00'
        );

        $this->lockFile->write($data);
        $readData = $this->lockFile->read();

        $this->assertNotNull($readData);
        $this->assertEquals('test-namespace', $readData->namespace);
        $this->assertEquals(1000, $readData->portOffset);
        $this->assertEquals('2025-11-08T10:30:00+00:00', $readData->startedAt);
    }

    public function test_it_reads_lock_file_with_null_values(): void
    {
        $data = new LockFileData(
            namespace: null,
            portOffset: null,
            startedAt: '2025-11-08T10:30:00+00:00'
        );

        $this->lockFile->write($data);
        $readData = $this->lockFile->read();

        $this->assertNotNull($readData);
        $this->assertNull($readData->namespace);
        $this->assertNull($readData->portOffset);
    }

    public function test_it_returns_null_when_reading_nonexistent_file(): void
    {
        $readData = $this->lockFile->read();
        $this->assertNull($readData);
    }

    public function test_it_deletes_lock_file(): void
    {
        $data = new LockFileData(
            namespace: 'test-namespace',
            portOffset: 1000,
            startedAt: '2025-11-08T10:30:00+00:00'
        );

        $this->lockFile->write($data);
        $this->assertTrue($this->lockFile->exists());

        $this->lockFile->delete();
        $this->assertFalse($this->lockFile->exists());
    }

    public function test_it_deletes_nonexistent_lock_file_gracefully(): void
    {
        $this->assertFalse($this->lockFile->exists());
        $this->lockFile->delete(); // Should not throw
        $this->assertFalse($this->lockFile->exists());
    }

    public function test_it_writes_json_with_proper_format(): void
    {
        $data = new LockFileData(
            namespace: 'test-namespace',
            portOffset: 1000,
            startedAt: '2025-11-08T10:30:00+00:00'
        );

        $this->lockFile->write($data);

        $content = file_get_contents($this->tempDir . '/.ngramx.lock');
        $this->assertNotFalse($content, 'Failed to read lock file');
        $decoded = json_decode($content, true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('namespace', $decoded);
        $this->assertArrayHasKey('port_offset', $decoded);
        $this->assertArrayHasKey('started_at', $decoded);
    }

    public function test_it_reads_and_writes_no_host_mapping_flag(): void
    {
        $data = new LockFileData(
            namespace: 'test-namespace',
            portOffset: null,
            startedAt: '2025-11-08T10:30:00+00:00',
            noHostMapping: true,
        );

        $this->lockFile->write($data);
        $readData = $this->lockFile->read();

        $this->assertNotNull($readData);
        $this->assertTrue($readData->noHostMapping);
    }

    public function test_it_defaults_no_host_mapping_to_false_for_legacy_lock_files(): void
    {
        // Write a lock file without noHostMapping (simulating legacy format)
        $legacyContent = json_encode([
            'namespace' => 'test-namespace',
            'port_offset' => 1000,
            'started_at' => '2025-11-08T10:30:00+00:00',
        ], JSON_PRETTY_PRINT);
        file_put_contents($this->tempDir . '/.ngramx.lock', $legacyContent);

        $readData = $this->lockFile->read();

        $this->assertNotNull($readData);
        $this->assertFalse($readData->noHostMapping);
    }

    public function test_it_reads_and_writes_caddy_stopped_flag(): void
    {
        $data = new LockFileData(
            namespace: null,
            portOffset: null,
            startedAt: '2025-11-08T10:30:00+00:00',
            caddyStopped: true,
        );

        $this->lockFile->write($data);
        $readData = $this->lockFile->read();

        $this->assertNotNull($readData);
        $this->assertTrue($readData->caddyStopped);
    }

    public function test_it_defaults_caddy_stopped_to_false_for_legacy_lock_files(): void
    {
        $legacyContent = json_encode([
            'namespace' => null,
            'port_offset' => null,
            'started_at' => '2025-11-08T10:30:00+00:00',
            'herd_stopped' => true,
        ], JSON_PRETTY_PRINT);
        file_put_contents($this->tempDir . '/.ngramx.lock', $legacyContent);

        $readData = $this->lockFile->read();

        $this->assertNotNull($readData);
        $this->assertFalse($readData->caddyStopped);
    }
}
