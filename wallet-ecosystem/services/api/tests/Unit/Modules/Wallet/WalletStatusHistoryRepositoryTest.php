<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Wallet;

use App\Modules\Wallet\Infrastructure\Models\WalletStatusHistoryModel;
use App\Modules\Wallet\Infrastructure\Repositories\WalletStatusHistoryRepository;
use PHPUnit\Framework\TestCase;

final class WalletStatusHistoryRepositoryTest extends TestCase
{
    /** @test */
    public function repository_has_no_update_or_delete_methods(): void
    {
        $repo = new \ReflectionClass(WalletStatusHistoryRepository::class);

        $this->assertFalse($repo->hasMethod('update'), 'Repository must not expose an update() method');
        $this->assertFalse($repo->hasMethod('delete'), 'Repository must not expose a delete() method');
    }

    /** @test */
    public function existing_model_save_throws_logic_exception(): void
    {
        $model         = new WalletStatusHistoryModel();
        $model->exists = true;

        $this->expectException(\LogicException::class);
        $model->save();
    }

    /** @test */
    public function model_delete_always_throws_logic_exception(): void
    {
        $model = new WalletStatusHistoryModel();

        $this->expectException(\LogicException::class);
        $model->delete();
    }
}
