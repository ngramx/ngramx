<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Connection\ConnectionUrlParser;
use PHPUnit\Framework\TestCase;

class ConnectionUrlParserTest extends TestCase
{
    public function test_parses_postgres_url_with_query(): void
    {
        $parsed = (new ConnectionUrlParser())->parse(
            'postgresql://user:pa%40ss@db.example.com:25060/my_anon?sslmode=require'
        );

        $this->assertSame('db.example.com', $parsed->host);
        $this->assertSame(25060, $parsed->port);
        $this->assertSame('my_anon', $parsed->database);
        $this->assertSame('user', $parsed->username);
        $this->assertSame('pa@ss', $parsed->password);
        $this->assertSame('sslmode=require', $parsed->query);
        $this->assertSame(
            'postgresql://user:pa%40ss@127.0.0.1:15432/my_anon?sslmode=require',
            $parsed->databaseUrl('127.0.0.1', 15432),
        );
    }
}
