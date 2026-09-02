<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Worktree;

use Ngramx\Worktree\WorktreeSiblingMounts;
use PHPUnit\Framework\TestCase;

class WorktreeSiblingMountsTest extends TestCase
{
    private const ROOT = '/repos/hydra-main/.ngramx/worktrees/gig-3054-hydra-main';
    private const COMPOSE_DIR = self::ROOT . '/docker';
    private const BASE_COMPOSE_DIR = '/repos/hydra-main/docker';

    public function test_it_repoints_a_sibling_repo_at_the_base_checkout(): void
    {
        // From the worktree this resolves to
        // /repos/hydra-main/.ngramx/worktrees/hydra-frontend, which does not
        // exist -- Docker would create it empty and the app would 500 later.
        $service = ['volumes' => ['../../hydra-frontend:/var/www/vagrant/hydra-frontend']];

        $this->assertSame(
            ['/repos/hydra-frontend:/var/www/vagrant/hydra-frontend'],
            $this->rewrite($service),
        );
    }

    public function test_it_leaves_the_checkout_itself_pointing_at_the_worktree(): void
    {
        // ".." IS the project root. In a worktree it must keep following the
        // worktree: that is how the container sees this ticket's code.
        $service = ['volumes' => ['..:/var/www/vagrant/hydra-main']];

        $this->assertSame([], $this->rewrite($service));
    }

    public function test_it_leaves_paths_inside_the_checkout_alone(): void
    {
        $service = ['volumes' => ['./config/phinx.yml:/var/www/vagrant/hydra-main/phinx.yml']];

        $this->assertSame([], $this->rewrite($service));
    }

    public function test_it_ignores_named_volumes_and_absolute_paths(): void
    {
        $service = ['volumes' => [
            'hydra-files:/var/www/vagrant/hydra-main/files',
            '/var/run/docker.sock:/var/run/docker.sock',
        ]];

        $this->assertSame([], $this->rewrite($service));
    }

    public function test_it_preserves_the_access_mode(): void
    {
        $service = ['volumes' => ['../../shared/conf.yml:/etc/conf.yml:ro']];

        $this->assertSame(
            ['/repos/shared/conf.yml:/etc/conf.yml:ro'],
            $this->rewrite($service),
        );
    }

    public function test_it_rewrites_every_escaping_mount_on_a_service(): void
    {
        $service = ['volumes' => [
            '..:/var/www/vagrant/hydra-main',
            '../../hydra-frontend:/var/www/vagrant/hydra-frontend',
            '../../hydra-customer-app:/var/www/vagrant/hydra-customer-app',
            'hydra-files:/var/www/vagrant/hydra-main/files',
        ]];

        $this->assertSame(
            [
                '/repos/hydra-frontend:/var/www/vagrant/hydra-frontend',
                '/repos/hydra-customer-app:/var/www/vagrant/hydra-customer-app',
            ],
            $this->rewrite($service),
        );
    }

    public function test_it_tolerates_a_service_without_volumes(): void
    {
        $this->assertSame([], $this->rewrite(['image' => 'redis:6-alpine']));
        $this->assertSame([], $this->rewrite('not-an-array'));
    }

    public function test_it_skips_long_syntax_mounts(): void
    {
        $service = ['volumes' => [
            ['type' => 'bind', 'source' => '../../hydra-frontend', 'target' => '/x'],
        ]];

        $this->assertSame([], $this->rewrite($service));
    }

    /**
     * @param mixed $service
     * @return list<string>
     */
    private function rewrite($service): array
    {
        return (new WorktreeSiblingMounts())->rewrite(
            $service,
            self::COMPOSE_DIR,
            self::ROOT,
            self::BASE_COMPOSE_DIR,
        );
    }
}
