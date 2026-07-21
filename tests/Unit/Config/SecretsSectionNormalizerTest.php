<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Config;

use Ngramx\Config\SecretsSectionNormalizer;
use PHPUnit\Framework\TestCase;

class SecretsSectionNormalizerTest extends TestCase
{
    public function test_it_detects_a_shorthand_providers_list(): void
    {
        $secrets = [
            [
                'provider' => '.env',
                'required' => ['FLUX_USERNAME', 'FLUX_LICENSE_KEY'],
            ],
        ];

        $this->assertTrue(SecretsSectionNormalizer::isProvidersList($secrets));
        $this->assertSame(
            ['providers' => $secrets],
            SecretsSectionNormalizer::normalize($secrets)
        );
    }

    public function test_it_leaves_explicit_providers_shape_unchanged(): void
    {
        $secrets = [
            'providers' => [
                [
                    'provider' => 'shell',
                    'required' => ['SECRET_ONE'],
                ],
            ],
        ];

        $this->assertFalse(SecretsSectionNormalizer::isProvidersList($secrets));
        $this->assertSame($secrets, SecretsSectionNormalizer::normalize($secrets));
    }

    public function test_it_leaves_legacy_provider_shape_unchanged(): void
    {
        $secrets = [
            'provider' => 'shell',
            'required' => ['SECRET_ONE'],
        ];

        $this->assertFalse(SecretsSectionNormalizer::isProvidersList($secrets));
        $this->assertSame($secrets, SecretsSectionNormalizer::normalize($secrets));
    }
}
