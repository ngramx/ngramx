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

    public function test_covers_host_matches_san_entries_case_insensitively(): void
    {
        $info = $this->infoWithSans(['app.localhost', 'gig-2460-app.localhost']);

        $this->assertTrue($info->coversHost('app.localhost'));
        $this->assertTrue($info->coversHost('GIG-2460-APP.localhost'));
        $this->assertFalse($info->coversHost('other.localhost'));
    }

    public function test_covers_host_honours_single_label_wildcards(): void
    {
        $info = $this->infoWithSans(['*.localhost']);

        $this->assertTrue($info->coversHost('anything.localhost'));
        $this->assertFalse($info->coversHost('a.b.localhost'), 'wildcards cover a single label only');
        $this->assertFalse($info->coversHost('localhost'));
    }

    public function test_covers_host_falls_back_to_subject_cn_without_sans(): void
    {
        $info = new CertInfo(
            path: null,
            subjectCn: 'app.localhost',
            issuerCn: 'app.localhost',
            issuerOrg: null,
            isSelfSigned: true,
            isMkcert: false,
        );

        $this->assertTrue($info->coversHost('app.localhost'));
        $this->assertFalse($info->coversHost('sub.app.localhost'));
    }

    /**
     * @param list<string> $sans
     */
    private function infoWithSans(array $sans): CertInfo
    {
        return new CertInfo(
            path: null,
            subjectCn: null,
            issuerCn: 'mkcert me@host',
            issuerOrg: 'mkcert development CA',
            isSelfSigned: false,
            isMkcert: true,
            subjectAltNames: $sans,
        );
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
