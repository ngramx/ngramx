<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\FakerMethodResolver;
use PHPUnit\Framework\TestCase;

class FakerMethodResolverTest extends TestCase
{
    public function test_seed_makes_single_generator_sequence_stable(): void
    {
        $resolver = new FakerMethodResolver('en_GB', 42);
        $resolver->faker()->seed(42);
        $first = $resolver->generate('firstName');
        $resolver->faker()->seed(42);
        $again = $resolver->generate('firstName');
        $this->assertSame($first, $again);
    }

    public function test_unique_prefix(): void
    {
        $resolver = new FakerMethodResolver('en_GB', 1);
        $email = $resolver->generate('uniqueSafeEmail');
        $this->assertIsString($email);
        $this->assertStringContainsString('@', $email);
    }

    public function test_unknown_method_throws(): void
    {
        $resolver = new FakerMethodResolver('en_GB', 1);
        $this->expectException(PostmacloneException::class);
        $resolver->assertMethodExists('notARealFakerMethodZzz');
    }

    public function test_password_method_is_recognized(): void
    {
        $resolver = new FakerMethodResolver('en_GB', 1);
        $resolver->assertMethodExists('password');
        $this->assertTrue(true);
    }

    public function test_template_chains_formatters_and_literals(): void
    {
        $resolver = new FakerMethodResolver('en_GB', 42);
        $resolver->faker()->seed(42);
        $value = $resolver->generate('{{numberBetween(1, 999)}} {{firstName}} Street');

        $this->assertMatchesRegularExpression('/^\d{1,3} [A-Za-z\'\-]+ Street$/', $value);
    }

    public function test_concat_expression_builds_address_line(): void
    {
        $resolver = new FakerMethodResolver('en_GB', 42);
        $resolver->faker()->seed(42);
        $value = $resolver->generate('numberBetween(1, 999) + " " + firstName + " Street"');

        $this->assertMatchesRegularExpression('/^\d{1,3} [A-Za-z\'\-]+ Street$/', $value);
    }

    public function test_concat_and_template_are_deterministic_with_seed(): void
    {
        $a = new FakerMethodResolver('en_GB', 7);
        $a->faker()->seed(7);
        $first = $a->generate('{{buildingNumber}} {{streetName}}');

        $b = new FakerMethodResolver('en_GB', 7);
        $b->faker()->seed(7);
        $again = $b->generate('{{buildingNumber}} {{streetName}}');

        $this->assertSame($first, $again);
    }

    public function test_unknown_method_in_template_throws(): void
    {
        $resolver = new FakerMethodResolver('en_GB', 1);
        $this->expectException(PostmacloneException::class);
        $resolver->assertMethodExists('{{notARealFakerMethodZzz}} Road');
    }

    public function test_password_inside_chain_is_rejected(): void
    {
        $resolver = new FakerMethodResolver('en_GB', 1);
        $this->expectException(PostmacloneException::class);
        $resolver->assertMethodExists('firstName + " " + password');
    }
}
