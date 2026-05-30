<?php

declare(strict_types=1);

namespace Tests\Unit\Agents;

use Ngramx\Agents\AgentsMdSynchronizer;
use PHPUnit\Framework\TestCase;

class AgentsMdSynchronizerTest extends TestCase
{
    private string $projectDir;

    private string|false $originalSkipEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = sys_get_temp_dir() . '/ngramx_agents_test_' . uniqid();
        mkdir($this->projectDir, 0755, true);
        $this->originalSkipEnv = getenv('NGRAMX_SKIP_AGENTS_SYNC');
        putenv('NGRAMX_SKIP_AGENTS_SYNC');
    }

    protected function tearDown(): void
    {
        if ($this->originalSkipEnv !== false) {
            putenv('NGRAMX_SKIP_AGENTS_SYNC=' . $this->originalSkipEnv);
        } else {
            putenv('NGRAMX_SKIP_AGENTS_SYNC');
        }

        if (is_dir($this->projectDir)) {
            $this->removeDirectory($this->projectDir);
        }

        parent::tearDown();
    }

    public function testCreatesAgentsWithIntroWhenMissing(): void
    {
        $sync = new AgentsMdSynchronizer();
        $changed = $sync->sync($this->projectDir, 'BODY_V1');

        $this->assertTrue($changed);
        $path = $this->projectDir . '/AGENTS.md';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        $this->assertIsString($content);
        $this->assertStringContainsString('# Agent instructions', $content);
        $this->assertStringContainsString('BODY_V1', $content);
        $this->assertStringContainsString(AgentsMdSynchronizer::MARKER_BEGIN, $content);
        $this->assertStringContainsString(AgentsMdSynchronizer::MARKER_END, $content);
    }

    public function testNoopWhenManagedSectionUnchanged(): void
    {
        $sync = new AgentsMdSynchronizer();
        $sync->sync($this->projectDir, 'SAME');

        $changed = $sync->sync($this->projectDir, 'SAME');
        $this->assertFalse($changed);
    }

    public function testUpdatesWhenInnerBodyChanges(): void
    {
        $sync = new AgentsMdSynchronizer();
        $sync->sync($this->projectDir, 'ONE');

        $changed = $sync->sync($this->projectDir, 'TWO');
        $this->assertTrue($changed);

        $content = file_get_contents($this->projectDir . '/AGENTS.md');
        $this->assertIsString($content);
        $this->assertStringContainsString('TWO', $content);
        $this->assertStringNotContainsString('ONE', $content);
    }

    public function testAppendsManagedWhenNoMarkers(): void
    {
        file_put_contents($this->projectDir . '/AGENTS.md', "# Custom\n\nHello");

        $sync = new AgentsMdSynchronizer();
        $changed = $sync->sync($this->projectDir, 'BLOCK');

        $this->assertTrue($changed);
        $content = file_get_contents($this->projectDir . '/AGENTS.md');
        $this->assertIsString($content);
        $this->assertStringContainsString('# Custom', $content);
        $this->assertStringContainsString('Hello', $content);
        $this->assertStringContainsString('BLOCK', $content);
        $this->assertStringContainsString(AgentsMdSynchronizer::MARKER_BEGIN, $content);
    }

    public function testPreservesPrefixAndSuffixAroundManagedBlock(): void
    {
        $managed = AgentsMdSynchronizer::MARKER_BEGIN . "\n\nOLD_UNIQUE_PLACEHOLDER\n\n" . AgentsMdSynchronizer::MARKER_END;
        file_put_contents($this->projectDir . '/AGENTS.md', "PREFIX\n\n" . $managed . "\n\nSUFFIX");

        $sync = new AgentsMdSynchronizer();
        $changed = $sync->sync($this->projectDir, 'NEW_INNER');

        $this->assertTrue($changed);
        $content = file_get_contents($this->projectDir . '/AGENTS.md');
        $this->assertIsString($content);
        $this->assertStringStartsWith("PREFIX\n\n", $content);
        $this->assertStringContainsString('SUFFIX', $content);
        $this->assertStringContainsString('NEW_INNER', $content);
        $this->assertStringNotContainsString('OLD_UNIQUE_PLACEHOLDER', $content);
    }

    public function testMalformedBeginWithoutEndIsNoOp(): void
    {
        $original = "Intro\n" . AgentsMdSynchronizer::MARKER_BEGIN . "\norphan";
        $path = $this->projectDir . '/AGENTS.md';
        file_put_contents($path, $original);

        $sync = new AgentsMdSynchronizer();
        $changed = $sync->sync($this->projectDir, 'FIXED');

        $this->assertFalse($changed);
        $this->assertSame($original, file_get_contents($path));
        $this->assertTrue($sync->hasMalformedManagedMarkers($this->projectDir));
    }

    public function testInvertedMarkersDoNotGrowFile(): void
    {
        $original = "Intro\n\n"
            . AgentsMdSynchronizer::MARKER_END . "\n"
            . "stray\n"
            . AgentsMdSynchronizer::MARKER_BEGIN . "\n"
            . 'also stray';
        $path = $this->projectDir . '/AGENTS.md';
        file_put_contents($path, $original);

        $sync = new AgentsMdSynchronizer();
        for ($i = 0; $i < 3; $i++) {
            $changed = $sync->sync($this->projectDir, 'BODY_' . $i);
            $this->assertFalse($changed, "sync() should be a no-op on run $i");
        }

        $this->assertSame($original, file_get_contents($path));
        $this->assertTrue($sync->hasMalformedManagedMarkers($this->projectDir));
    }

    public function testHasMalformedManagedMarkersIsFalseForWellFormedFile(): void
    {
        $sync = new AgentsMdSynchronizer();
        $sync->sync($this->projectDir, 'BODY');

        $this->assertFalse($sync->hasMalformedManagedMarkers($this->projectDir));
    }

    public function testHasMalformedManagedMarkersIsFalseWhenFileMissing(): void
    {
        $sync = new AgentsMdSynchronizer();

        $this->assertFalse($sync->hasMalformedManagedMarkers($this->projectDir));
    }

    public function testRespectsNgramxSkipAgentsSync(): void
    {
        putenv('NGRAMX_SKIP_AGENTS_SYNC=1');

        $sync = new AgentsMdSynchronizer();
        $changed = $sync->sync($this->projectDir, 'SHOULD_NOT_APPEAR');

        $this->assertFalse($changed);
        $this->assertFileDoesNotExist($this->projectDir . '/AGENTS.md');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $directory . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
