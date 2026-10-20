import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
export interface Template {
    id: string;
    key: string;
    category: string;
    language: string | null;
    revision: number;
    subject_template: string;
    body_template: string;
    supported_channels: string[];
    variables: string[] | Record<string, unknown>;
    active: boolean;
}
export interface Delivery {
    id: string;
    recipient: string;
    channel: string;
    event: string;
    status: string;
    timestamp: string;
    attempt_count: number;
}
export interface Rule {
    template_id: string;
    event: string;
    channel: string;
    enabled: boolean;
    mandatory: boolean;
    revision: number;
}
export interface Page<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}
export class NotificationsApi {
    constructor(private client: AuthenticatedApiClient) { }
    async templates() { return (await this.client.get<Template[]>('/v1/admin/notifications/templates')).data; }
    async edit(t: Template) { return (await this.client.patch<Template>('/v1/admin/notifications/templates', { id: t.id, revision: t.revision, subject_template: t.subject_template, body_template: t.body_template, language: t.language })).data; }
    async preview(t: Template, variables: Record<string, string>) { return (await this.client.post<{
        subject: string;
        body: string;
    }>('/v1/admin/notifications/templates/preview', { subject_template: t.subject_template, body_template: t.body_template, variables })).data; }
    async test(t: Template, variables: Record<string, string>, request_id: string) { return this.client.post('/v1/admin/notifications/templates/test', { id: t.id, variables, request_id }); }
    async log(query: URLSearchParams) { return await this.client.get<Delivery[]>('/v1/admin/notifications/log?' + query) as unknown as Page<Delivery>; }
    async retry(id: string) { return this.client.post('/v1/admin/notifications/retry/' + encodeURIComponent(id), {}); }
    async rules() { return (await this.client.get<Rule[]>('/v1/admin/notifications/rules')).data; }
    async updateRule(r: Rule) { return this.client.patch('/v1/admin/notifications/rules', { template_id: r.template_id, channel: r.channel, enabled: !r.enabled, revision: r.revision }); }
}
export { errorMessage } from '../security/api';
