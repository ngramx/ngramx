<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Tls;

use Ngramx\Tls\CertInfo;
use Ngramx\Tls\CertInspector;
use PHPUnit\Framework\TestCase;

class CertInspectorTest extends TestCase
{
    public function test_inspect_pem_returns_null_for_garbage_input(): void
    {
        $inspector = new CertInspector();

        $this->assertNull($inspector->inspectPem('not a cert'));
    }

    public function test_inspect_pem_detects_self_signed_openssl_cert(): void
    {
        $pem = $this->generateSelfSignedPem('VirginLand', 'virginland.gigabyte.localhost');
        $info = (new CertInspector())->inspectPem($pem);

        $this->assertInstanceOf(CertInfo::class, $info);
        $this->assertTrue($info->isSelfSigned);
        $this->assertFalse($info->isMkcert);
        $this->assertFalse($info->isBrowserTrusted());
        $this->assertSame('virginland.gigabyte.localhost', $info->subjectCn);
        $this->assertSame('virginland.gigabyte.localhost', $info->issuerCn);
        $this->assertSame('VirginLand', $info->issuerOrg);
    }

    public function test_inspect_pem_detects_mkcert_cert_as_browser_trusted(): void
    {
        $issuerCn = sprintf('mkcert %s@%s (%s)', 'tester', 'machine', 'TestCA');
        $pem = $this->generateCaSignedPem(
            subjectCn: 'app.localhost',
            issuerCn: $issuerCn,
            issuerOrg: 'mkcert development CA',
        );

        $info = (new CertInspector())->inspectPem($pem);

        $this->assertInstanceOf(CertInfo::class, $info);
        $this->assertFalse($info->isSelfSigned);
        $this->assertTrue($info->isMkcert);
        $this->assertTrue($info->isBrowserTrusted());
    }

    public function test_inspect_for_app_url_returns_null_when_cert_missing(): void
    {
        $tmp = sys_get_temp_dir() . '/ngramx-cert-test-' . uniqid();
        mkdir($tmp);

        try {
            $info = (new CertInspector())->inspectForAppUrl(
                'https://app.localhost',
                $tmp,
                'docker/nginx/ssl',
            );

            $this->assertNull($info);
        } finally {
            @rmdir($tmp);
        }
    }

    public function test_inspect_for_app_url_returns_info_when_cert_present(): void
    {
        $tmp = sys_get_temp_dir() . '/ngramx-cert-test-' . uniqid();
        $sslDir = $tmp . '/docker/nginx/ssl';
        mkdir($sslDir, 0700, true);

        try {
            $pem = $this->generateSelfSignedPem('VirginLand', 'app.localhost');
            file_put_contents($sslDir . '/app.localhost.crt', $pem);

            $info = (new CertInspector())->inspectForAppUrl(
                'https://app.localhost',
                $tmp,
                'docker/nginx/ssl',
            );

            $this->assertNotNull($info);
            $this->assertTrue($info->isSelfSigned);
            $this->assertSame('app.localhost', $info->subjectCn);
        } finally {
            @unlink($sslDir . '/app.localhost.crt');
            @rmdir($sslDir);
            @rmdir($tmp . '/docker/nginx');
            @rmdir($tmp . '/docker');
            @rmdir($tmp);
        }
    }

    private function generateSelfSignedPem(string $org, string $cn): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($key, 'failed to generate test key');

        $csr = openssl_csr_new([
            'commonName' => $cn,
            'organizationName' => $org,
        ], $key);
        \assert($csr instanceof \OpenSSLCertificateSigningRequest);

        $cert = openssl_csr_sign($csr, null, $key, 1);
        \assert($cert instanceof \OpenSSLCertificate);

        openssl_x509_export($cert, $pem);
        return $pem;
    }

    private function generateCaSignedPem(string $subjectCn, string $issuerCn, string $issuerOrg): string
    {
        $caKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($caKey, 'failed to generate CA key');

        $caCsr = openssl_csr_new([
            'commonName' => $issuerCn,
            'organizationName' => $issuerOrg,
        ], $caKey);
        \assert($caCsr instanceof \OpenSSLCertificateSigningRequest);

        // No v3_ca extension here on purpose — most default openssl.cnf
        // installs don't define that section and the resulting cert is
        // still a perfectly serviceable signer for these tests.
        $caCert = openssl_csr_sign($caCsr, null, $caKey, 2);
        \assert($caCert instanceof \OpenSSLCertificate);

        $leafKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $leafCsr = openssl_csr_new([
            'commonName' => $subjectCn,
            'organizationName' => 'whatever',
        ], $leafKey);
        \assert($leafCsr instanceof \OpenSSLCertificateSigningRequest);

        $leafCert = openssl_csr_sign($leafCsr, $caCert, $caKey, 1);
        \assert($leafCert instanceof \OpenSSLCertificate);

        openssl_x509_export($leafCert, $pem);
        return $pem;
    }
}
