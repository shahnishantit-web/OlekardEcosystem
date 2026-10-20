import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
export type RiskStatus = 'open' | 'under_investigation' | 'escalated' | 'resolved_false_positive' | 'resolved_true_positive' | 'closed';
export interface RiskAlert {
    id: string;
    title: string;
    description: string;
    severity: string;
    alert_type: string;
    subject_type: string;
    subject_id: string;
    assigned_to: string | null;
    created_at: string;
    status: RiskStatus;
    version: number;
}
export interface FreezeRequest {
    id: string;
    maker_id: string;
    reason: string;
    status: string;
    checker_id?: string;
    hold_id?: string;
}
export interface AlertDetail extends RiskAlert {
    trigger: {
        description: string;
        events: unknown[];
    };
    affected_transactions: {
        reference: string;
        detected_at: string;
    }[];
    entity_history: {
        id: string;
        event_type: string;
        severity: string;
        detected_at: string;
    }[];
    risk_timeline: {
        id: string;
        score: number;
        risk_level: string;
        evaluated_at: string;
    }[];
    notes: {
        id: string;
        actor_id: string;
        note: string;
        created_at: string;
    }[];
    related_alerts: RiskAlert[];
    freeze_requests: FreezeRequest[];
    active_hold: {
        id: string;
        placed_at: string;
    } | null;
    decisions: {
        id: string;
        decision: string;
        rationale: string;
        decided_at: string;
    }[];
}
export interface Page<T> {
    data: T[];
    meta: {
        current_page?: number;
        last_page: number;
        total?: number;
    };
}
export class SecurityApi {
    constructor(private client: AuthenticatedApiClient) { }
    async alerts(query: URLSearchParams) { return await this.client.get<RiskAlert[]>('/v1/admin/security/alerts?' + query) as unknown as Page<RiskAlert>; }
    async detail(id: string) { return (await this.client.get<AlertDetail>('/v1/admin/security/alerts/' + encodeURIComponent(id))).data; }
    async action(id: string, action: string, body: object) { return this.client.post('/v1/admin/security/alerts/' + encodeURIComponent(id) + '/' + action, body); }
}
export function errorMessage(e: unknown): string {
    if (e instanceof Error)
        return e.message;
    if (e !== null && typeof e === 'object') {
        const v = e as {
            message?: string;
            error?: {
                message?: string;
            };
        };
        return v.error?.message ?? v.message ?? 'Request failed. Please retry.';
    }
    return 'Request failed. Please retry.';
}
export const closed = (s: RiskStatus) => ['resolved_false_positive', 'resolved_true_positive', 'closed'].includes(s);
export const statusLabel = (s: string) => s === 'under_investigation' ? 'In Review' : s.replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase());
export const displayDate = (v: string) => new Date(v).toLocaleString();
