<?php

declare(strict_types=1);

namespace App\Modules\Audit\Infrastructure\Http\Controllers;

use App\Modules\Audit\Infrastructure\Http\Resources\AuditEventDetailResource;
use App\Modules\Audit\Infrastructure\Models\AuditEventModel;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

final class AuditEventDetailController extends Controller
{
    public function __invoke(string $id): AuditEventDetailResource
    {
        $event = AuditEventModel::findOrFail($id);

        if (!$event->verifyIntegrity()) {
            Log::warning('audit.integrity_check_failed', [
                'audit_event_id' => $event->id,
            ]);
        }

        return new AuditEventDetailResource($event);
    }
}
