<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Auth;

use Ngramx\Auth\AuthBypassScript;
use Ngramx\Auth\AuthBypassUrl;
use PHPUnit\Framework\TestCase;

class AuthBypassTest extends TestCase
{
    public function test_it_joins_a_magic_link_path_onto_the_live_origin(): void
    {
        $this->assertSame(
            'https://gig-3741-app.localhost:8743/login/magic/abc',
            AuthBypassUrl::absolute('https://gig-3741-app.localhost:8743', '/login/magic/abc'),
        );
    }

    public function test_it_keeps_an_absolute_magic_link_the_app_already_built(): void
    {
        $absolute = 'https://app.localhost/login/magic/abc';

        $this->assertSame($absolute, AuthBypassUrl::absolute('http://localhost:8080', $absolute));
    }

    public function test_it_fills_a_configured_url_template(): void
    {
        $this->assertSame(
            'http://localhost:8080/impersonate?as=hello%40gigabyte.software',
            AuthBypassUrl::fromTemplate(
                '{url}/impersonate?as={email_query}',
                'http://localhost:8080/',
                'hello@gigabyte.software',
            ),
        );
    }

    public function test_it_parses_the_mint_script_protocol(): void
    {
        $ok = AuthBypassScript::parse("Deprecation warning\nok /login/magic/token\n");
        $this->assertSame('ok', $ok->status);
        $this->assertSame('/login/magic/token', $ok->path);

        $skip = AuthBypassScript::parse("skip identity-missing\n");
        $this->assertSame('skip', $skip->status);
        $this->assertSame('identity-missing', $skip->detail);

        $warn = AuthBypassScript::parse("warn failed QueryException\n");
        $this->assertSame('warn', $warn->status);
        $this->assertSame('failed QueryException', $warn->detail);

        $garbage = AuthBypassScript::parse("ok not a path\n");
        $this->assertSame('warn', $garbage->status);
        $this->assertNull($garbage->path);

        $silent = AuthBypassScript::parse('');
        $this->assertSame('skip', $silent->status);
    }

    public function test_the_mint_script_refuses_non_local_environments_and_does_not_send_mail(): void
    {
        $source = AuthBypassScript::source();

        $this->assertStringContainsString("environment(['local', 'testing', 'development', 'dev'])", $source);
        $this->assertStringContainsString('warn not-local', $source);
        $this->assertStringNotContainsString('Mail::', $source);
        $this->assertStringNotContainsString('sendLoginCode', $source);
    }

    public function test_the_shell_command_quotes_the_email_and_reports_the_script_status(): void
    {
        $email = "o'reilly@example.com";
        $command = AuthBypassScript::shellCommand($email, 15);

        $this->assertStringContainsString(escapeshellarg($email), $command);
        $this->assertStringContainsString('NGRAMX_BYPASS_TTL=15', $command);
        $this->assertStringContainsString('status=$?', $command);
        $this->assertStringContainsString('exit $status', $command);

        $encoded = '';
        if (preg_match("/<<'NGRAMX_BYPASS_B64'\n([A-Za-z0-9+\/=]+)\nNGRAMX_BYPASS_B64/", $command, $matches) === 1) {
            $encoded = base64_decode($matches[1], true) ?: '';
        }
        $this->assertStringContainsString('warn not-local', $encoded);
    }
}
