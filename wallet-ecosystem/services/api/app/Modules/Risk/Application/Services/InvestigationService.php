<?php

declare(strict_types=1);

namespace App\Modules\Risk\Application\Services;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Risk\Application\Commands\FreezeAccountCommand;
use App\Modules\Risk\Application\Commands\InvestigateAlertCommand;
use App\Modules\Risk\Application\Commands\ResolveAlertCommand;
use App\Modules\Risk\Domain\Models\AccountHold;
use App\Modules\Risk\Domain\Models\RiskAlert;
use App\Modules\Risk\Domain\Models\RiskEvaluation;
use App\Modules\Risk\Domain\Models\RiskEvent;
use App\Modules\Wallet\Domain\Contracts\WalletModuleContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class InvestigationService
{
    public function __construct(private readonly AlertService $alerts, private readonly AccountHoldService $holds, private readonly AuditServiceInterface $audit, private readonly WalletModuleContract $wallets, private readonly IdentityModuleContract $identity) {}

    public function queue(array $filters): array
    {
        $q = RiskAlert::query();
        if (! empty($filters['severity'])) {
            $q->where('severity', $filters['severity']);
        }
        $statuses = match ($filters['status'] ?? 'open') {
            'in_review' => ['under_investigation', 'escalated'],
            'resolved' => ['resolved_false_positive', 'resolved_true_positive', 'closed'],
            default => ['open'],
        };
        $p = $q->whereIn('status', $statuses)->orderByDesc('created_at')->orderByDesc('id')->paginate(25);

        return ['data' => $p->items(), 'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'total' => $p->total()]];
    }

    public function detail(string $id): array
    {
        $a = RiskAlert::findOrFail($id);
        $events = RiskEvent::where('subject_type', $a->subject_type)->where('subject_id', $a->subject_id)->orderByDesc('detected_at')->limit(100)->get();
        $evaluations = RiskEvaluation::query()->where(function ($query) use ($a, $events): void {
            $query->whereIn('id', $events->pluck('evaluation_id')->filter());
            $column = match ($a->subject_type) {
                'customer', 'user' => 'customer_id',
                'agent' => 'agent_id',
                'wallet' => 'wallet_id',
                default => null,
            };
            if ($column !== null) {
                $query->orWhere($column, $a->subject_id);
            }
        })->orderByDesc('evaluated_at')->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $linkedIds = DB::connection('risk')->table('risk.risk_alert_events')->where('alert_id', $id)->pluck('event_id');
        $triggerEvents = RiskEvent::whereIn('id', $linkedIds)->where('subject_type', $a->subject_type)->where('subject_id', $a->subject_id)->orderBy('detected_at')->get();
        $data = $a->toArray();
        $data['trigger'] = ['description' => $a->description, 'events' => $triggerEvents->values()];
        $data['affected_transactions'] = $triggerEvents->whereNotNull('transaction_reference')->map(fn ($e) => ['reference' => $e->transaction_reference, 'detected_at' => $e->detected_at])->unique('reference')->values();
        $data['entity_history'] = $events->map(fn ($e) => ['id' => $e->id, 'event_type' => $e->event_type, 'severity' => $e->severity, 'detected_at' => $e->detected_at])->values();
        $data['risk_timeline'] = $evaluations->map(fn ($e) => ['id' => $e->id, 'score' => $e->overall_score, 'risk_level' => $e->risk_level, 'evaluated_at' => $e->evaluated_at])->values();
        $data['notes'] = DB::connection('risk')->table('risk.alert_notes')->where('alert_id', $id)->orderBy('created_at')->orderBy('id')->get();
        $related = DB::connection('risk')->table('risk.alert_links')->where('alert_id', $id)->pluck('related_alert_id');
        $data['related_alerts'] = RiskAlert::whereIn('id', $related)->get(['id', 'title', 'status', 'severity']);
        $data['decisions'] = $a->decisions()->orderBy('decided_at')->get();
        try {
            $subject = $this->freezeSubject($a);
        } catch (HttpException) {
            $subject = null;
        }
        $data['freeze_subject'] = $subject;
        $data['active_hold'] = $subject !== null ? AccountHold::active()->where('subject_type', $subject['type'])->where('subject_id', $subject['id'])->first() : null;
        $data['freeze_requests'] = DB::connection('risk')->table('risk.alert_freeze_requests')->where('alert_id', $id)->orderByDesc('created_at')->get();

        return $data;
    }

    public function mutate(string $id, string $action, array $v, string $actor, string $correlation): array
    {
        return DB::connection('risk')->transaction(function () use ($id, $action, $v, $actor, $correlation): array {
            $a = RiskAlert::lockForUpdate()->findOrFail($id);
            if ($a->version !== $v['version']) {
                throw new HttpException(409, 'Alert changed. Reload before retrying.');
            }
            if ($a->status->isTerminal()) {
                throw new HttpException(422, 'Alert is closed.');
            }
            switch ($action) {
                case 'assign':
                    $this->alerts->investigateAlert(new InvestigateAlertCommand($id, $actor, $v['version'], $actor));
                    break;
                case 'resolve':
                    $this->alerts->resolveAlert(new ResolveAlertCommand(
                        alertId: $id, decision: $v['outcome'] === 'false_positive' ? 'clear' : 'block', rationale: $v['rationale'],
                        version: $v['version'], decidedBy: $actor
                    ));
                    break;
                case 'notes':
                    DB::connection('risk')->table('risk.alert_notes')->insert(['id' => (string) Uuid::uuid7(), 'alert_id' => $id, 'actor_id' => $actor, 'note' => trim($v['note']), 'created_at' => now()]);
                    $a->touch();
                    break;
                case 'links':
                    if ($v['related_alert_id'] === $id) {
                        throw ValidationException::withMessages(['related_alert_id' => 'Cannot link an alert to itself.']);
                    }
                    RiskAlert::findOrFail($v['related_alert_id']);
                    DB::connection('risk')->table('risk.alert_links')->insertOrIgnore(['alert_id' => $id, 'related_alert_id' => $v['related_alert_id'], 'actor_id' => $actor, 'created_at' => now()]);
                    $a->touch();
                    break;
                case 'escalate':
                    $a->update(['status' => 'escalated', 'escalated_at' => now(), 'updated_by' => $actor]);
                    break;
                case 'freeze-requests':
                    $subject = $this->freezeSubject($a);
                    if (AccountHold::active()->where('subject_type', $subject['type'])->where('subject_id', $subject['id'])->exists()) {
                        throw new HttpException(409, 'Account already frozen.');
                    }
                    if (DB::connection('risk')->table('risk.alert_freeze_requests')->where('alert_id', $id)->where('status', 'pending')->exists()) {
                        throw new HttpException(409, 'A freeze request is already pending.');
                    }
                    $request = ['id' => (string) Uuid::uuid7(), 'alert_id' => $id, 'maker_id' => $actor, 'reason' => trim($v['reason']), 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()];
                    DB::connection('risk')->table('risk.alert_freeze_requests')->insert($request);
                    $a->touch();
                    $this->record('freeze_requested', $id, $actor, $correlation);

                    return $request;
                default: throw new HttpException(404, 'Unknown action.');
            }
            $this->record($action, $id, $actor, $correlation);

            return $this->detail($id);
        });
    }

    public function approve(string $id, string $requestId, string $checker, string $correlation): array
    {
        return DB::connection('risk')->transaction(function () use ($id, $requestId, $checker, $correlation): array {
            $a = RiskAlert::lockForUpdate()->findOrFail($id);
            $q = DB::connection('risk')->table('risk.alert_freeze_requests')->where('id', $requestId)->where('alert_id', $id);
            $r = $q->lockForUpdate()->first();
            if (! $r) {
                throw new HttpException(404, 'Freeze request not found.');
            }
            if ($r->maker_id === $checker) {
                throw new HttpException(403, 'A different authorized administrator must approve this freeze.');
            }
            if ($r->status === 'approved') {
                return (array) $r;
            }
            if ($a->status->isTerminal()) {
                throw new HttpException(422, 'Alert is closed.');
            }
            $subject = $this->freezeSubject($a);
            DB::connection('risk')->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$subject['type'].':'.$subject['id']]);
            if (AccountHold::active()->where('subject_type', $subject['type'])->where('subject_id', $subject['id'])->exists()) {
                throw new HttpException(409, 'Account already frozen.');
            }
            $hold = $this->holds->placeHold(new FreezeAccountCommand(subjectType: $subject['type'], subjectId: $subject['id'], reason: $r->reason, placedBy: $r->maker_id, checkerId: $checker, walletId: $subject['wallet_id'], alertId: $id));
            $q->update(['checker_id' => $checker, 'status' => 'approved', 'hold_id' => $hold->id, 'updated_at' => now()]);
            $a->touch();
            $event = ['id' => (string) Uuid::uuid7(), 'hold_id' => $hold->id, 'alert_id' => $id, 'user_id' => $subject['id'], 'maker_id' => $r->maker_id, 'checker_id' => $checker, 'correlation_id' => $correlation, 'occurred_at' => now()->toIso8601String()];
            DB::connection('risk')->table('risk.investigation_outbox')->insert(['id' => $event['id'], 'event_name' => 'ACCOUNT_FROZEN_AML', 'payload' => json_encode($event, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $this->record('account_frozen_aml', $id, $checker, $correlation, ['hold_id' => $hold->id, 'maker_id' => $r->maker_id]);

            return (array) $q->select('*')->first();
        });
    }

    private function freezeSubject(RiskAlert $alert): array
    {
        if (in_array($alert->subject_type, ['customer', 'agent'], true)) {
            return ['type' => $alert->subject_type, 'id' => $alert->subject_id, 'wallet_id' => null];
        }
        $owner = $alert->subject_type === 'wallet' ? $this->wallets->ownerActorId($alert->subject_id) : ($alert->subject_type === 'user' ? $alert->subject_id : null);
        $contact = $owner !== null ? $this->identity->getUserContactInfo($owner) : null;
        if ($contact === null || ! in_array(strtolower($contact->actorType), ['customer', 'agent'], true)) {
            throw new HttpException(422, 'No supported account owner is available for this entity.');
        }

        return ['type' => strtolower($contact->actorType), 'id' => $owner, 'wallet_id' => $alert->subject_type === 'wallet' ? $alert->subject_id : null];
    }

    private function record(string $action, string $id, string $actor, string $correlation, array $metadata = []): void
    {
        DB::connection('risk')->table('risk.investigation_actions')->insert(['id' => (string) Uuid::uuid7(), 'alert_id' => $id, 'actor_id' => $actor, 'action' => $action, 'correlation_id' => $correlation, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        $this->audit->record(new AuditEventData(action: 'risk.investigation.'.str_replace('_', '-', $action), actorType: 'admin', resourceType: 'risk_alert', correlationId: $correlation, outcome: 'success', actorId: $actor, resourceId: $id, metadata: $metadata));
    }
}
