import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
export type Entry = {
    id: string;
    kind: string;
    actor_id: string;
    created_at: string;
    payload: Record<string, unknown>;
};
export type Case = {
    id: string;
    number: string;
    customer_id: string;
    type: 'Support' | 'Dispute';
    priority: string;
    status: string;
    raw_status: string;
    created_at: string;
    assigned_id: string | null;
    deadline: string | null;
    overdue: boolean;
    description?: string;
    transaction_ids?: string[];
    timeline?: Entry[];
    evidence?: {
        id: string;
        filename: string;
        legacy: boolean;
    }[];
    refund_pending_confirmation?: boolean;
    refund_initiator_id?: string | null;
    refund_amount_minor?: number | null;
    refund_reason?: string | null;
};
export type CasePage = {
    items: Case[];
    page: number;
    last_page: number;
    total: number;
};
export const field = 'rounded-md border border-border-primary bg-background-primary px-3 py-2 text-text-primary w-full';
export function message(e: unknown): string { const v = e as {
    error?: {
        message?: string;
    };
    message?: string;
}; return v?.error?.message ?? v?.message ?? 'The request could not be completed. Please retry.'; }
export class SupportApi {
    constructor(private readonly client: AuthenticatedApiClient) { }
    async queue(filters: Record<string, string>, page: number): Promise<CasePage> { const q = new URLSearchParams({ page: String(page) }); Object.entries(filters).forEach(([k, v]) => { if (v)
        q.set(k, v); }); return (await this.client.get<CasePage>('/v1/admin/support/cases?' + q)).data; }
    async detail(id: string): Promise<Case> { return (await this.client.get<Case>('/v1/admin/support/cases/' + id)).data; }
    async update(id: string, body: unknown): Promise<Case> { return (await this.client.patch<Case>('/v1/admin/support/cases/' + id, body)).data; }
    async resolve(id: string, resolution_note: string): Promise<Case> { return (await this.client.post<Case>('/v1/admin/support/cases/' + id + '/resolve', { resolution_note })).data; }
    async attach(id: string, file: File): Promise<Case> { if (file.size > 10 * 1048576)
        throw Error('File exceeds 10 MB.'); const content = await new Promise<string>((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result).split(',')[1] ?? ''); reader.onerror = () => reject(Error('File could not be read.')); reader.readAsDataURL(file); }); return (await this.client.post<Case>('/v1/admin/support/cases/' + id + '/attachments', { filename: file.name, content_base64: content })).data; }
    async download(id: string, entry: string): Promise<Blob> { return this.client.getBlob('/v1/admin/support/cases/' + id + '/attachments/' + entry); }
    async challenge(id: string): Promise<{
        step_up_token: string;
        methods: string[];
        step_up_required: boolean;
    }> { return (await this.client.post<{
        step_up_token: string;
        methods: string[];
        step_up_required: boolean;
    }>('/v1/auth/step-up/initiate', { scope: 'support.refund:' + id, operation_context: { risk_required: true } })).data; }
    async verify(challenge: string, method: string, credential: string): Promise<string> { return (await this.client.post<{
        step_up_access_token: string;
    }>('/v1/auth/step-up/verify', { step_up_token: challenge, method, credential })).data.step_up_access_token; }
    async refund(id: string, amount_minor: number, reason: string, step_up_token: string, key: string, confirm: boolean): Promise<Case> { return (await this.client.post<Case>('/v1/admin/support/cases/' + id + '/refund', { amount_minor, reason, step_up_token, confirm }, { idempotencyKey: key })).data; }
}
