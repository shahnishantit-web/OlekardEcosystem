<?php

declare(strict_types=1);

use App\Modules\Agent\Domain\Exceptions\AgentStatusHistoryImmutableException;
use App\Modules\Agent\Infrastructure\Models\AgentStatusHistoryModel;
use Tests\Support\AgentTestCase;

uses(AgentTestCase::class);

// T063 ————————————————————————————————————————————————

test('performUpdate throws AgentStatusHistoryImmutableException when model exists', function () {
    $model         = new AgentStatusHistoryModel();
    $model->exists = true;

    $threw = false;
    try {
        $model->fill(['reason' => 'changed'])->save();
    } catch (AgentStatusHistoryImmutableException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});

test('delete throws AgentStatusHistoryImmutableException when model exists', function () {
    $model         = new AgentStatusHistoryModel();
    $model->exists = true;

    $threw = false;
    try {
        $model->delete();
    } catch (AgentStatusHistoryImmutableException) {
        $threw = true;
    }

    expect($threw)->toBeTrue();
});
