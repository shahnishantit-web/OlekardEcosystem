<?php

declare(strict_types=1);

namespace App\Modules\Support\Application\Services;

use App\Modules\Identity\Domain\Contracts\IdentityModuleContract;
use App\Modules\Platform\Domain\Contracts\AuditServiceInterface;
use App\Modules\Platform\Domain\Contracts\EventPublisherInterface;
use App\Modules\Platform\Domain\ValueObjects\AuditEventData;
use App\Modules\Support\Domain\Enums\TicketStatus;
use App\Modules\Support\Domain\Events\AdminCaseEvent;
use App\Modules\Support\Infrastructure\Models\DisputeEvidenceModel;
use App\Modules\Support\Infrastructure\Models\DisputeModel;
use App\Modules\Support\Infrastructure\Models\SupportTicketModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

final class AdminCaseService
{
    public function __construct(private readonly IdentityModuleContract $identity, private readonly EventPublisherInterface $events, private readonly AuditServiceInterface $audit, private readonly SupportTicketService $tickets, private readonly SupportTicketNoteService $notes, private readonly DisputeInvestigationService $disputes) {}

    public function summary(): array
    {
        return ['open_disputes' => DB::connection('platform')->table('support.disputes')
            ->whereNotIn('status', ['refunded', 'rejected'])->count()];
    }

    public function queue(array $filters): array
    {
        $db = DB::connection('platform');
        $tickets = $db->table('support.support_tickets as t')->whereNull('t.dispute_id')->whereNotExists(function ($q): void {
            $q->selectRaw('1')->from('support.disputes as d')->whereColumn('d.ticket_id', 't.id');
        })->selectRaw("t.id,t.ticket_number as number,t.reporter_id as customer_id,'Support' as type,t.priority,t.status as raw_status,t.created_at,t.assignee_id as assigned_id,NULL::timestamptz as escalated_at");
        $disputes = $db->table('support.disputes as d')->leftJoin('support.support_tickets as t', 't.id', '=', 'd.ticket_id')->selectRaw("d.id,d.dispute_number as number,d.reporter_id as customer_id,'Dispute' as type,coalesce(t.priority,'medium') as priority,CASE WHEN d.escalated AND d.status NOT IN ('refunded','rejected') THEN 'escalated' ELSE d.status END as raw_status,d.created_at,d.investigator_id as assigned_id,d.escalated_at");
        $q = $db->query()->fromSub($tickets->unionAll($disputes), 'c')->leftJoin('support.admin_cases as a', 'a.case_id', '=', 'c.id')->selectRaw("c.*,coalesce(a.deadline,c.escalated_at + interval '24 hours') as deadline,a.senior_id, CASE WHEN c.raw_status IN ('resolved','closed','refunded','rejected') THEN 'Resolved' WHEN a.escalated_at IS NOT NULL OR c.raw_status='escalated' THEN 'Escalated' WHEN c.raw_status IN ('in_progress','under_investigation','pending_confirmation','approved') THEN 'In Progress' ELSE 'Open' END as status");
        $q = $db->query()->fromSub($q, 'cases');
        foreach (['type', 'status', 'priority'] as $field) {
            if (! empty($filters[$field])) {
                $q->where($field, $filters[$field]);
            }
        }
        if (! empty($filters['date_from'])) {
            $q->where('created_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $q->where('created_at', '<', Carbon::parse($filters['date_to'])->addDay());
        }
        $page = $q->orderByDesc('created_at')->orderByDesc('id')->paginate(20, ['*'], 'page', (int) ($filters['page'] ?? 1));
        $items = array_map(function ($row): array {
            $a = (array) $row;
            $a['overdue'] = $a['status'] !== 'Resolved' && $a['deadline'] !== null && Carbon::parse($a['deadline'])->isPast();
            $a['assigned_id'] = $a['senior_id'] ?? $a['assigned_id'];

            return $a;
        }, $page->items());

        return ['items' => $items, 'page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }

    public function model(string $id, bool $lock = false): SupportTicketModel|DisputeModel
    {
        $d = DisputeModel::query();
        $t = SupportTicketModel::query();
        if ($lock) {
            $d->lockForUpdate();
            $t->lockForUpdate();
        }

        return $d->find($id) ?? $t->findOrFail($id);
    }

    public function detail(string $id): array
    {
        $m = $this->model($id);
        $dispute = $m instanceof DisputeModel;
        $meta = DB::connection('platform')->table('support.admin_cases')->where('case_id', $id)->first();
        $terminal = in_array($m->status->value, ['closed', 'resolved', 'refunded', 'rejected'], true);
        $deadline = $meta?->deadline ?? ($dispute && $m->escalated_at ? $m->escalated_at->copy()->addHours(24)->toIso8601String() : null);
        $status = $terminal ? 'Resolved' : (($meta?->escalated_at || ($dispute && $m->escalated)) ? 'Escalated' : (in_array($m->status->value, ['in_progress', 'under_investigation', 'approved', 'pending_confirmation'], true) ? 'In Progress' : 'Open'));
        $entries = DB::connection('platform')->table('support.admin_case_entries')->where('case_id', $id)->orderBy('created_at')->orderBy('id')->get()->map(function ($row): array {
            $p = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
            unset($p['file_key']);

            return ['id' => $row->id, 'kind' => $row->kind, 'actor_id' => $row->actor_id, 'created_at' => $row->created_at, 'payload' => $p];
        })->all();
        $ticket = $dispute ? ($m->ticket_id ? SupportTicketModel::find($m->ticket_id) : SupportTicketModel::where('dispute_id', $id)->first()) : $m;
        $notes = $ticket?->notes()->orderBy('created_at')->get()->map(fn ($n) => ['id' => $n->id, 'kind' => 'note', 'actor_id' => $n->author_id, 'created_at' => $n->created_at->toIso8601String(), 'payload' => ['content' => $n->content, 'internal' => $n->is_internal]])->all() ?? [];
        $timeline = array_merge($notes, $entries);
        usort($timeline, fn ($a, $b) => Carbon::parse($a['created_at'])->getTimestampMs() <=> Carbon::parse($b['created_at'])->getTimestampMs());
        $evidence = $dispute ? DisputeEvidenceModel::where('dispute_id', $id)->get()->map(fn ($e) => ['id' => $e->id, 'filename' => $e->original_name, 'legacy' => true])->all() : [];
        foreach ($entries as $entry) {
            if ($entry['kind'] === 'attachment') {
                $evidence[] = ['id' => $entry['id'], 'filename' => $entry['payload']['filename'], 'legacy' => false];
            }
        }

        return ['id' => $id, 'number' => $dispute ? $m->dispute_number : $m->ticket_number, 'type' => $dispute ? 'Dispute' : 'Support', 'customer_id' => $m->reporter_id, 'description' => $m->description, 'priority' => $ticket?->priority->value ?? 'medium', 'status' => $status, 'raw_status' => $m->status->value, 'created_at' => $m->created_at->toIso8601String(), 'assigned_id' => $meta?->senior_id ?? ($dispute ? $m->investigator_id : $m->assignee_id), 'deadline' => $deadline, 'overdue' => ! $terminal && $deadline !== null && Carbon::parse($deadline)->isPast(), 'transaction_ids' => $dispute ? [$m->transaction_id] : [], 'timeline' => $timeline, 'evidence' => $evidence, 'refund_pending_confirmation' => $dispute && $m->refund_pending_confirmation, 'refund_initiator_id' => $dispute ? $m->refund_initiator_id : null, 'refund_amount_minor' => $dispute ? $m->refunded_amount_minor : null, 'refund_reason' => $dispute ? $m->partial_refund_justification : null];
    }

    public function entry(string $id, string $actor, string $kind, array $payload, string $correlation, ?string $key = null, ?string $hash = null): string
    {
        $entry = (string) Uuid::uuid7();
        DB::connection('platform')->table('support.admin_case_entries')->insert(['id' => $entry, 'case_id' => $id, 'actor_id' => $actor, 'kind' => $kind, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'correlation_id' => $correlation, 'request_key' => $key, 'request_hash' => $hash, 'created_at' => now()]);
        $this->audit->record(new AuditEventData(action: 'support.case.'.$kind, actorType: 'ADMIN', resourceType: 'support_case', correlationId: $correlation, outcome: 'success', actorId: $actor, resourceId: $id, afterState: ['entry_id' => $entry]));

        return $entry;
    }

    public function mutate(string $id, array $data, string $actor, string $correlation): array
    {
        return DB::connection('platform')->transaction(function () use ($id, $data, $actor, $correlation): array {
            $m = $this->model($id, true);
            $dispute = $m instanceof DisputeModel;
            $action = $data['action'];
            abort_if(in_array($m->status->value, ['closed', 'resolved', 'refunded', 'rejected'], true), 409, 'Case is terminal');
            if ($action === 'note') {
                if (! $dispute) {
                    $note = $this->notes->addNote($id, $actor, 'admin', $data['content'], true, $correlation);
                    $this->entry($id, $actor, 'note_added', ['note_id' => $note->id], $correlation);
                } else {
                    $this->entry($id, $actor, 'note', ['content' => $data['content'], 'internal' => true], $correlation);
                }
            } elseif ($action === 'link') {
                $other = $this->model($data['related_case_id']);
                abort_if($other->id === $id, 422, 'Cannot link a case to itself');
                $this->entry($id, $actor, 'link', ['related_case_id' => $other->id], $correlation);
            } elseif ($action === 'assign') {
                abort_unless($this->identity->hasPermission($data['assignee_id'], 'support.investigate'), 422, 'Assignee must be a support agent');
                if (($dispute && $m->escalated) || DB::connection('platform')->table('support.admin_cases')->where('case_id', $id)->whereNotNull('escalated_at')->exists()) {
                    abort_unless($this->identity->hasPermission($data['assignee_id'], 'support.assign'), 422, 'Escalated cases require a senior support agent');
                }
                if ($dispute) {
                    $m->investigator_id = $data['assignee_id'];
                    $m->save();
                } else {
                    $this->tickets->assignTicket($id, $data['assignee_id'], $actor, $correlation);
                }
                DB::connection('platform')->table('support.admin_cases')->where('case_id', $id)->whereNotNull('escalated_at')->update(['senior_id' => $data['assignee_id']]);
                $this->entry($id, $actor, 'assignment', ['assignee_id' => $data['assignee_id']], $correlation);
            } elseif ($action === 'investigate') {
                if ($dispute) {
                    $this->disputes->startInvestigation($id, $actor, $actor, 'admin', $correlation);
                } else {
                    $this->tickets->updateStatus($id, TicketStatus::InProgress, $actor, $correlation);
                }
                $this->entry($id, $actor, 'investigation', [], $correlation);
            } elseif ($action === 'resolve') {
                abort_if($dispute, 409, 'Disputes require their approved resolution/refund workflow');
                $this->tickets->updateStatus($id, TicketStatus::Resolved, $actor, $correlation, $data['resolution_note']);
                $this->entry($id, $actor, 'resolution', ['resolution_note' => $data['resolution_note']], $correlation);
            } elseif ($action === 'escalate') {
                abort_if(DB::connection('platform')->table('support.admin_cases')->where('case_id', $id)->whereNotNull('escalated_at')->exists() || ($dispute && $m->escalated), 409, 'Already escalated');
                $senior = $data['assignee_id'];
                abort_unless($this->identity->hasPermission($senior, 'support.assign') && $this->identity->hasPermission($senior, 'support.investigate'), 422, 'Select an eligible senior support agent');
                if ($dispute) {
                    $this->disputes->escalate($id, $actor, 'admin', $data['reason'], $correlation);
                    $m->refresh();
                    $m->investigator_id = $senior;
                    $m->save();
                } else {
                    $this->tickets->assignTicket($id, $senior, $actor, $correlation);
                }
                $deadline = now()->addHours(24);
                DB::connection('platform')->table('support.admin_cases')->updateOrInsert(['case_id' => $id], ['escalated_at' => now(), 'deadline' => $deadline, 'reason' => $data['reason'], 'senior_id' => $senior]);
                $eventId = $this->entry($id, $actor, 'escalation', ['reason' => $data['reason'], 'assignee_id' => $senior, 'deadline' => $deadline->toIso8601String()], $correlation);
                $this->events->publish(new AdminCaseEvent('SUPPORT_CASE_ESCALATED', ['id' => $eventId, 'recipient_id' => $senior, 'case_id' => $id, 'occurred_at' => now()->toIso8601String(), 'correlation_id' => $correlation]));
            }

            return $this->detail($id);
        });
    }

    public function attach(string $id, string $filename, string $encoded, string $actor, string $correlation): array
    {
        $content = base64_decode($encoded, true);
        $max = (int) config('support.evidence.max_size_mb', 10) * 1048576;
        if ($content === false || strlen($content) < 1 || strlen($content) > $max) {
            throw ValidationException::withMessages(['content_base64' => 'Invalid or oversized attachment']);
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($content);
        abort_unless(in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true), 422, 'Unsupported evidence type');
        $path = 'admin-support/'.$id.'/'.Uuid::uuid7();
        try {
            DB::connection('platform')->transaction(function () use ($id, $filename, $content, $mime, $path, $actor, $correlation): void {
                $m = $this->model($id, true);
                abort_if(in_array($m->status->value, ['closed', 'resolved', 'refunded', 'rejected'], true), 409, 'Case is terminal');
                $count = DB::connection('platform')->table('support.admin_case_entries')->where('case_id', $id)->where('kind', 'attachment')->count();
                if ($m instanceof DisputeModel) {
                    $count += DisputeEvidenceModel::where('dispute_id', $id)->count();
                }
                abort_if($count >= (int) config('support.evidence.max_files', 5), 422, 'Evidence limit reached');
                abort_unless(Storage::disk('local')->put($path, $content), 503, 'Evidence storage unavailable');
                $this->entry($id, $actor, 'attachment', ['filename' => basename($filename), 'file_key' => $path, 'mime' => $mime, 'size' => strlen($content)], $correlation);
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return $this->detail($id);
    }

    public function attachment(string $id, string $entryId): array
    {
        $m = $this->model($id);
        $entry = DB::connection('platform')->table('support.admin_case_entries')->where('case_id', $id)->where('id', $entryId)->where('kind', 'attachment')->first();
        if ($entry) {
            $p = json_decode($entry->payload, true, 512, JSON_THROW_ON_ERROR);

            return ['content' => Storage::disk('local')->get($p['file_key']), 'mime' => $p['mime'], 'filename' => $p['filename']];
        }
        abort_unless($m instanceof DisputeModel, 404);
        $e = DisputeEvidenceModel::where('dispute_id', $id)->findOrFail($entryId);

        // Authorize each download; never expose storage keys or public URLs.
        return ['content' => Storage::disk((string) config('support.evidence.disk', 'support-evidence'))->get($e->file_key), 'mime' => 'application/octet-stream', 'filename' => $e->original_name];
    }
}
