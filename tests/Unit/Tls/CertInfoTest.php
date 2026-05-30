<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Tls;

use Ngramx\Tls\CertInfo;
use PHPUnit\Framework\TestCase;

class CertInfoTest extends TestCase
{
    public function test_browser_trusted_only_when_mkcert_signed(): void
    {
        $selfSigned = new CertInfo(
            path: '/x.crt',
            subjectCn: 'app',
            issuerCn: 'app',
            issuerOrg: 'me',
            isSelfSigned: true,
            isMkcert: false,
        );
        $mkcert = new CertInfo(
            path: '/x.crt',
            subjectCn: 'app',
            issuerCn: 'mkcert me@host',
            issuerOrg: 'mkcert development CA',
            isSelfSigned: false,
            isMkcert: true,
        );

        $this->assertFalse($selfSigned->isBrowserTrusted());
        $this->assertTrue($mkcert->isBrowserTrusted());
    }

    public function test_describe_issuer_combines_cn_and_org(): void
    {
        $info = new CertInfo(
            path: null,
            subjectCn: 'app',
            issuerCn: 'mkcert me@host',
            issuerOrg: 'mkcert development CA',
            isSelfSigned: false,
            isMkcert: true,
        );

        $this->assertSame('mkcert me@host / mkcert development CA', $info->describeIssuer());
    }

    public function test_describe_issuer_falls_back_to_unknown_when_blank(): void
    {
        $info = new CertInfo(
            path: null,
            subjectCn: null,
            issuerCn: null,
            issuerOrg: null,
            isSelfSigned: false,
            isMkcert: false,
        );

        $this->assertSame('(unknown issuer)', $info->describeIssuer());
    }
}
