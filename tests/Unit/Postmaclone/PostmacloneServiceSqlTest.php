<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Config\Schema\AgentsConfig;
use Ngramx\Config\Schema\DockerConfig;
use Ngramx\Config\Schema\N8nConfig;
use Ngramx\Config\Schema\NgramxConfig;
use Ngramx\Config\Schema\Postmaclone\ColumnRule;
use Ngramx\Config\Schema\Postmaclone\PostmacloneConfig;
use Ngramx\Config\Schema\Postmaclone\TableRule;
use Ngramx\Config\Schema\SetupConfig;
use Ngramx\Postmaclone\FromResolver;
use Ngramx\Postmaclone\PostmacloneService;
use PHPUnit\Framework\TestCase;

class PostmacloneServiceSqlTest extends TestCase
{
    public function test_emit_sql_from_dump_only_touches_configured_columns(): void
    {
        $dump = dirname(__DIR__, 2) . '/fixtures/postmaclone/users.sql';
        $config = $this->config();
        $service = new PostmacloneService();
        $from = (new FromResolver())->resolve($dump);

        $result = $service->emitSql($config, dirname($dump), $from);
        $sql = $result['sql'];

        $this->assertStringContainsString('UPDATE "users"', $sql);
        $this->assertStringContainsString('"email"', $sql);
        $this->assertStringContainsString('"first_name"', $sql);
        $this->assertStringNotContainsString('"status"', $sql);
        $this->assertStringNotContainsString('alice@example.com', $sql);
    }

    private function config(): NgramxConfig
    {
        return new NgramxConfig(
            version: '1.0',
            docker: new DockerConfig(
                composeFile: dirname(__DIR__, 2) . '/fixtures/postmaclone/compose-postgres.yml',
                primaryService: 'app',
                appUrl: 'http://localhost',
            ),
            setup: new SetupConfig(),
            n8n: new N8nConfig(workflowsDir: '/tmp'),
            agents: new AgentsConfig(),
            postmaclone: new PostmacloneConfig(
                engine: 'postgres',
                seed: 42,
                tables: [
                    'users' => new TableRule(
                        table: 'users',
                        columns: [
                            'email' => new ColumnRule('email', 'safeEmail'),
                            'first_name' => new ColumnRule('first_name', 'firstName'),
                        ],
                        primaryKey: 'id',
                    ),
                ],
            ),
        );
    }
}
