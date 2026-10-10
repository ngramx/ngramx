<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Database;

use Ngramx\Database\TablePlusLinkResolver;
use Ngramx\Database\TablePlusUrl;
use PHPUnit\Framework\TestCase;

class TablePlusLinkResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/ngramx-tableplus-' . uniqid();
        mkdir($this->root, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_it_encodes_a_postgres_connection_and_names_it(): void
    {
        $url = TablePlusUrl::build(
            'pgsql',
            'postgres',
            'p@ss word',
            '127.0.0.1',
            5432,
            'earl_kendrick',
            'gig-3802 earl',
        );

        $this->assertSame(
            'postgresql://postgres:p%40ss%20word@127.0.0.1:5432/earl_kendrick?env=local&name=gig-3802%20earl',
            $url,
        );
    }

    public function test_it_omits_an_empty_password(): void
    {
        $this->assertSame(
            'mysql://root@127.0.0.1:3306/app?env=local&name=app',
            TablePlusUrl::build('mysql', 'root', '', '127.0.0.1', 3306, 'app', 'app'),
        );
    }

    public function test_it_prints_the_published_port_and_env_credentials(): void
    {
        $this->writeEnv("DB_CONNECTION=pgsql\nDB_HOST=db\nDB_PORT=5432\nDB_DATABASE=earl_kendrick\nDB_USERNAME=postgres\nDB_PASSWORD=postgres\n");
        $this->writeCompose(<<<'YAML'
services:
  db:
    image: postgres:17
    ports:
      - "5432:5432"
YAML);

        $link = (new TablePlusLinkResolver())->resolve($this->root, 'docker-compose.yml');

        $this->assertSame(
            'postgresql://postgres:postgres@127.0.0.1:5432/earl_kendrick?env=local&name=' . rawurlencode(basename($this->root)),
            $link->url,
        );
    }

    public function test_it_applies_a_worktree_port_offset(): void
    {
        $this->writeEnv("DB_CONNECTION=pgsql\nDB_PORT=5432\nDB_DATABASE=app\nDB_USERNAME=postgres\nDB_PASSWORD=secret\n");
        $this->writeCompose(<<<'YAML'
services:
  db:
    image: postgres:17
    ports:
      - "${EK_DB_PORT:-5432}:5432"
YAML);

        $link = (new TablePlusLinkResolver())->resolve($this->root, 'docker-compose.yml', portOffset: 8200);

        $this->assertNotNull($link->url);
        $this->assertStringContainsString('@127.0.0.1:13632/app?', $link->url);
    }

    public function test_it_keeps_an_env_overridden_host_port(): void
    {
        $this->writeEnv("EK_DB_PORT=15432\nDB_CONNECTION=pgsql\nDB_PORT=5432\nDB_DATABASE=app\nDB_USERNAME=postgres\nDB_PASSWORD=secret\n");
        $this->writeCompose(<<<'YAML'
services:
  db:
    image: postgres:17
    ports:
      - "${EK_DB_PORT:-5432}:5432"
YAML);

        $link = (new TablePlusLinkResolver())->resolve($this->root, 'docker-compose.yml', portOffset: 8200);

        $this->assertNotNull($link->url);
        $this->assertStringContainsString('@127.0.0.1:15432/app?', $link->url);
    }

    public function test_it_follows_a_recorded_port_remap(): void
    {
        $this->writeEnv("DB_CONNECTION=mysql\nDB_PORT=3306\nDB_DATABASE=app\nDB_USERNAME=root\nDB_PASSWORD=root\n");
        $this->writeCompose(<<<'YAML'
services:
  db:
    image: mysql:8.0
    ports:
      - "3306:3306"
YAML);

        $link = (new TablePlusLinkResolver())->resolve(
            $this->root,
            'docker-compose.yml',
            portMap: [3306 => 13306],
        );

        $this->assertNotNull($link->url);
        $this->assertStringStartsWith('mysql://root:root@127.0.0.1:13306/app?', $link->url);
    }

    public function test_it_uses_the_postmaclone_connect_session(): void
    {
        $this->writeEnv("DB_CONNECTION=pgsql\nDB_PORT=5432\nDB_DATABASE=local\nDB_USERNAME=postgres\nDB_PASSWORD=postgres\n");
        $this->writeCompose(<<<'YAML'
services:
  db:
    image: postgres:17
    ports:
      - "5432:5432"
YAML);
        mkdir($this->root . '/.ngramx', 0755, true);
        file_put_contents($this->root . '/.ngramx/postmaclone-connect.lock', json_encode([
            'mode' => 'tunnel',
            'engine' => 'postgres',
            'database' => 'demo_anon',
            'username' => 'anon',
            'password' => 's3cret',
            'ide_host' => '127.0.0.1',
            'ide_port' => 15432,
        ], JSON_THROW_ON_ERROR));

        $link = (new TablePlusLinkResolver())->resolve($this->root, 'docker-compose.yml', publishPorts: false);

        $this->assertNotNull($link->url);
        $this->assertStringStartsWith('postgresql://anon:s3cret@127.0.0.1:15432/demo_anon?', $link->url);
    }

    public function test_it_stays_quiet_without_a_published_database(): void
    {
        $this->writeEnv("DB_CONNECTION=pgsql\nDB_DATABASE=app\nDB_USERNAME=postgres\nDB_PASSWORD=secret\n");

        $link = (new TablePlusLinkResolver())->resolve($this->root, 'docker-compose.yml');

        $this->assertNull($link->url);
    }

    public function test_it_stays_quiet_when_host_ports_are_not_published(): void
    {
        $this->writeEnv("DB_CONNECTION=pgsql\nDB_PORT=5432\nDB_DATABASE=app\nDB_USERNAME=postgres\nDB_PASSWORD=secret\n");
        $this->writeCompose(<<<'YAML'
services:
  db:
    image: postgres:17
    ports:
      - "5432:5432"
YAML);

        $link = (new TablePlusLinkResolver())->resolve($this->root, 'docker-compose.yml', publishPorts: false);

        $this->assertNull($link->url);
    }

    public function test_it_reads_credentials_from_the_compose_service_when_env_omits_them(): void
    {
        $this->writeEnv("DB_CONNECTION=pgsql\nDB_PORT=5432\n");
        $this->writeCompose(<<<'YAML'
services:
  db:
    image: postgres:17
    environment:
      POSTGRES_DB: earl_kendrick
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: postgres
    ports:
      - "5432:5432"
YAML);

        $link = (new TablePlusLinkResolver())->resolve($this->root, 'docker-compose.yml');

        $this->assertNotNull($link->url);
        $this->assertStringStartsWith('postgresql://postgres:postgres@127.0.0.1:5432/earl_kendrick?', $link->url);
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->root . '/.env', $contents);
    }

    private function writeCompose(string $contents): void
    {
        file_put_contents($this->root . '/docker-compose.yml', $contents);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
