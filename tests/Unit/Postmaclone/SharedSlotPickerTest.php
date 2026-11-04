<?php

declare(strict_types=1);

namespace Ngramx\Tests\Unit\Postmaclone;

use Ngramx\Postmaclone\Exception\PostmacloneException;
use Ngramx\Postmaclone\SharedSlotPicker;
use PHPUnit\Framework\TestCase;

final class SharedSlotPickerTest extends TestCase
{
    public function test_bootstrap_treats_shared_database_as_live(): void
    {
        $slots = SharedSlotPicker::pick(null, 'hydra_prod_scratch', 'hydra_prod_anon');

        self::assertSame('hydra_prod_anon', $slots['live']);
        self::assertSame('hydra_prod_scratch', $slots['idle']);
    }

    public function test_flips_when_manifest_points_at_scratch(): void
    {
        $slots = SharedSlotPicker::pick(
            ['shared_database' => 'hydra_prod_scratch'],
            'hydra_prod_scratch',
            'hydra_prod_anon',
        );

        self::assertSame('hydra_prod_scratch', $slots['live']);
        self::assertSame('hydra_prod_anon', $slots['idle']);
    }

    public function test_unknown_manifest_database_falls_back_to_shared(): void
    {
        $slots = SharedSlotPicker::pick(
            ['shared_database' => 'someone_else'],
            'hydra_prod_scratch',
            'hydra_prod_anon',
        );

        self::assertSame('hydra_prod_anon', $slots['live']);
        self::assertSame('hydra_prod_scratch', $slots['idle']);
    }

    public function test_rejects_identical_slot_names(): void
    {
        $this->expectException(PostmacloneException::class);
        SharedSlotPicker::pick(null, 'hydra_prod_anon', 'hydra_prod_anon');
    }
}
