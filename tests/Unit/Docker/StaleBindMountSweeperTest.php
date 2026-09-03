<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Docker;

use Ngramx\Docker\StaleBindMount;
use Ngramx\Docker\StaleBindMountReason;
use Ngramx\Docker\StaleBindMountSweeper;
use Ngramx\Output\OutputFormatter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;

class StaleBindMountSweeperTest extends TestCase
{
    private string $tempDir;

    /** @var list<string> */
    private array $tempPaths = [];

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/ngramx-stale-mounts-' . uniqid();
        mkdir($this->tempDir, 0o777, true);
        $this->tempPaths[] = $this->tempDir;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }

        $this->tempPaths = [];
    }

    public function test_it_finds_a_mount_pinned_to_a_deleted_inode(): void
    {
        $hostPath = '/home/dev/project/docker/php/local.ini';
        $sweeper = $this->sweeperFor([
            $this->mountLine($hostPath, deleted: true),
        ]);

        $stale = $sweeper->findAll();

        $this->assertCount(1, $stale);
        $this->assertSame($hostPath, $stale[0]->hostPath);
        $this->assertSame(StaleBindMountReason::DeletedInode, $stale[0]->reason);
        $this->assertSame(StaleBindMount::hashForHostPath($hostPath), $stale[0]->hash);
        $this->assertSame('Ubuntu', $stale[0]->distro);
    }

    public function test_it_finds_a_mount_whose_host_path_has_gone_away(): void
    {
        // A removed worktree: the staged mount is still live, but nothing is
        // at the path it stages any more.
        $hostPath = $this->tempDir . '/removed-worktree';

        $sweeper = $this->sweeperFor([
            $this->mountLine($hostPath, deleted: false),
        ]);

        $stale = $sweeper->findAll();

        $this->assertCount(1, $stale);
        $this->assertSame(StaleBindMountReason::MissingHostPath, $stale[0]->reason);
    }

    public function test_it_leaves_healthy_staged_mounts_alone(): void
    {
        $liveFile = $this->tempFile('live.ini');

        $sweeper = $this->sweeperFor([
            $this->mountLine($liveFile, deleted: false),
        ]);

        $this->assertSame([], $sweeper->findAll());
    }

    public function test_it_ignores_mounts_outside_the_staging_area(): void
    {
        $sweeper = $this->sweeperFor([
            '26 1 8:1 /home/dev/gone//deleted /var/lib/docker/overlay rw,relatime shared:1 - ext4 /dev/sda rw',
        ]);

        $this->assertSame([], $sweeper->findAll());
    }

    public function test_it_scopes_findings_to_the_given_root(): void
    {
        $sweeper = $this->sweeperFor([
            $this->mountLine('/home/dev/project/docker/php/local.ini', deleted: true),
            $this->mountLine('/home/dev/project-other/docker/php/local.ini', deleted: true),
            $this->mountLine('/home/dev/elsewhere/nginx.conf', deleted: true),
        ]);

        $stale = $sweeper->findUnder('/home/dev/project');

        $this->assertCount(1, $stale);
        $this->assertSame('/home/dev/project/docker/php/local.ini', $stale[0]->hostPath);
    }

    public function test_it_scopes_findings_to_the_root_itself(): void
    {
        $root = '/home/dev/project/.ngramx/worktrees/gig-1-project';
        $sweeper = $this->sweeperFor([
            $this->mountLine($root, deleted: true),
        ]);

        $this->assertCount(1, $sweeper->findUnder($root));
    }

    public function test_it_never_sweeps_everything_for_an_empty_root(): void
    {
        $sweeper = $this->sweeperFor([
            $this->mountLine('/home/dev/project/local.ini', deleted: true),
        ]);

        $this->assertSame([], $sweeper->findUnder(''));
        $this->assertSame([], $sweeper->findUnder('/'));
    }

    public function test_it_unescapes_octal_sequences_in_mount_paths(): void
    {
        $hostPath = '/home/dev/my project/local.ini';
        $sweeper = $this->sweeperFor([
            $this->mountLine($hostPath, deleted: true),
        ]);

        $stale = $sweeper->findAll();

        $this->assertCount(1, $stale);
        $this->assertSame($hostPath, $stale[0]->hostPath);
    }

    public function test_it_matches_the_paths_named_in_a_real_runc_failure(): void
    {
        $hostPath = '/home/rob/projects/earl-kendrick-core/docker/php/local.ini';
        $hash = StaleBindMount::hashForHostPath($hostPath);

        // Verbatim shape of the failure this class exists to recover from.
        $error = 'Error response from daemon: failed to create task for container: '
            . 'failed to create shim task: OCI runtime create failed: runc create failed: '
            . 'unable to start container process: error during container init: error mounting '
            . '"/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/' . $hash . '" to rootfs at '
            . '"/usr/local/etc/php/conf.d/local.ini": no such file or directory';

        $sweeper = $this->sweeperFor([
            $this->mountLine($hostPath, deleted: true),
            $this->mountLine('/home/rob/projects/other/nginx.conf', deleted: true),
        ]);

        $found = $sweeper->findForFailure($error);

        $this->assertCount(1, $found);
        $this->assertSame($hostPath, $found[0]->hostPath);
    }

    public function test_it_trusts_the_engine_over_its_own_heuristics(): void
    {
        // The mount looks perfectly healthy from the distro's point of view,
        // but the engine could not resolve it — the engine wins.
        $liveFile = $this->tempFile('healthy.ini');
        $hash = StaleBindMount::hashForHostPath($liveFile);

        $sweeper = $this->sweeperFor([
            $this->mountLine($liveFile, deleted: false),
        ]);

        $found = $sweeper->findForFailure(
            'error mounting "/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/' . $hash . '"'
        );

        $this->assertCount(1, $found);
        $this->assertSame(StaleBindMountReason::EngineReportedMissing, $found[0]->reason);
    }

    public function test_it_ignores_unrelated_failures(): void
    {
        $sweeper = $this->sweeperFor([
            $this->mountLine('/home/dev/project/local.ini', deleted: true),
        ]);

        $this->assertSame([], $sweeper->findForFailure('Error: port 5432 is already allocated'));
    }

    public function test_it_returns_nothing_when_the_mount_table_is_unreadable(): void
    {
        $sweeper = new StaleBindMountSweeper('/proc/definitely-not-a-mount-table-' . uniqid());

        $this->assertSame([], $sweeper->findAll());
        $this->assertSame([], $sweeper->findForFailure('docker-desktop-bind-mounts/Ubuntu/' . str_repeat('a', 64)));
    }

    public function test_sweeping_unmounts_each_stale_entry_and_says_so(): void
    {
        $sweeper = $this->recordingSweeperFor([
            $this->mountLine('/home/dev/project/docker/php/local.ini', deleted: true),
            $this->mountLine('/home/dev/project/docker/nginx.conf', deleted: true),
        ], succeeds: true);

        $output = new BufferedOutput();
        $sweeper->sweepUnder('/home/dev/project', new OutputFormatter($output));

        $this->assertCount(2, $sweeper->unmounted);
        $this->assertStringContainsString('Cleared 2 stale mount(s)', $output->fetch());
    }

    public function test_sweeping_is_silent_when_there_is_nothing_stale(): void
    {
        $sweeper = $this->recordingSweeperFor([], succeeds: true);

        $output = new BufferedOutput();
        $sweeper->sweepUnder('/home/dev/project', new OutputFormatter($output));

        $this->assertSame([], $sweeper->unmounted);
        $this->assertSame('', $output->fetch());
    }

    public function test_it_prints_the_manual_command_when_it_cannot_unmount(): void
    {
        $sweeper = $this->recordingSweeperFor([
            $this->mountLine('/home/dev/project/docker/php/local.ini', deleted: true),
        ], succeeds: false);

        $output = new BufferedOutput();
        $sweeper->sweepUnder('/home/dev/project', new OutputFormatter($output));

        $printed = $output->fetch();
        $this->assertStringContainsString('sudo umount', $printed);
        $this->assertStringContainsString('docker-desktop-bind-mounts', $printed);
    }

    public function test_recovery_reports_whether_a_retry_is_worthwhile(): void
    {
        $hostPath = '/home/dev/project/docker/php/local.ini';
        $error = 'error mounting "/run/desktop/mnt/host/wsl/docker-desktop-bind-mounts/Ubuntu/'
            . StaleBindMount::hashForHostPath($hostPath) . '": no such file or directory';

        $cleared = $this->recordingSweeperFor([$this->mountLine($hostPath, deleted: true)], succeeds: true);
        $this->assertTrue($cleared->recoverFromFailure($error, new OutputFormatter(new BufferedOutput())));

        $stuck = $this->recordingSweeperFor([$this->mountLine($hostPath, deleted: true)], succeeds: false);
        $this->assertFalse($stuck->recoverFromFailure($error, new OutputFormatter(new BufferedOutput())));

        $unrelated = $this->recordingSweeperFor([], succeeds: true);
        $this->assertFalse($unrelated->recoverFromFailure('boom', new OutputFormatter(new BufferedOutput())));
    }

    public function test_the_hash_is_the_sha256_of_the_host_path(): void
    {
        // Taken from a live Docker Desktop 4.x / WSL2 staging directory: this
        // is the naming scheme the whole class depends on.
        $this->assertSame(
            '46efeb10ec59639d6ec6cd17ac9955167685f0aaa5258d44a5ccaae1d9f6c2e2',
            StaleBindMount::hashForHostPath('/home/rob/projects/earl-kendrick-core/docker/php/local.ini'),
        );
    }

    /**
     * Build a `/proc/self/mountinfo` line for a staged Docker Desktop mount of
     * $hostPath, octal-escaping it the way the kernel does.
     */
    private function mountLine(string $hostPath, bool $deleted): string
    {
        $hash = StaleBindMount::hashForHostPath($hostPath);
        $root = $this->escape($hostPath) . ($deleted ? '//deleted' : '');
        $mountPoint = '/mnt/wsl/docker-desktop-bind-mounts/Ubuntu/' . $hash;

        return "1230 76 8:48 $root $mountPoint rw,relatime shared:496 - ext4 /dev/sdd rw,discard";
    }

    private function escape(string $path): string
    {
        return str_replace([' ', "\t"], ['\\040', '\\011'], $path);
    }

    /**
     * @param list<string> $mountLines
     */
    private function sweeperFor(array $mountLines): StaleBindMountSweeper
    {
        return new StaleBindMountSweeper($this->writeMountInfo($mountLines));
    }

    /**
     * A sweeper that records unmount attempts instead of running `umount`.
     *
     * @param list<string> $mountLines
     */
    private function recordingSweeperFor(array $mountLines, bool $succeeds): RecordingStaleBindMountSweeper
    {
        return new RecordingStaleBindMountSweeper($this->writeMountInfo($mountLines), $succeeds);
    }

    /**
     * @param list<string> $lines
     */
    private function writeMountInfo(array $lines): string
    {
        $path = $this->tempDir . '/mountinfo-' . uniqid();
        file_put_contents($path, implode("\n", $lines) . "\n");
        $this->tempPaths[] = $path;

        return $path;
    }

    private function tempFile(string $name): string
    {
        $path = $this->tempDir . '/' . $name;
        file_put_contents($path, "; live\n");
        $this->tempPaths[] = $path;

        return $path;
    }
}

/**
 * A sweeper that records the mount points it was asked to unmount rather than
 * shelling out to `umount`, so the reporting logic can be tested without root
 * (or a Docker Desktop host).
 */
class RecordingStaleBindMountSweeper extends StaleBindMountSweeper
{
    /** @var list<string> */
    public array $unmounted = [];

    public function __construct(string $mountInfoPath, private readonly bool $succeeds)
    {
        parent::__construct($mountInfoPath);
    }

    protected function unmount(StaleBindMount $mount): bool
    {
        $this->unmounted[] = $mount->mountPoint;

        return $this->succeeds;
    }
}
