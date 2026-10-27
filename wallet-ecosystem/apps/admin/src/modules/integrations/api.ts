import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
export interface Client {
    id: string;
    name: string;
    api_key_prefix: string;
    status: string;
    permissions: string[];
    ip_whitelist: string[];
    integration_id: string | null;
    rate_limit_per_minute: number;
    last_used_at: string | null;
}
export interface Credentials extends Client {
    api_key: string;
    raw_secret: string;
}
export interface Provider {
    id: string;
    display_name: string;
    provider_slug: string;
    provider_type: string;
    status: string;
    is_default: boolean;
    health: string;
    last_checked_at: string | null;
}
export interface Delivery {
    quarantined?: boolean;
    test_only?: boolean;
    id: string;
    event: string;
    provider_slug: string;
    endpoint: string;
    status: string;
    processing_status: string;
    attempts: number;
    last_attempt_at: string | null;
}
export interface Bucket {
    hour: string;
    samples: number;
    uptime_percent: number | null;
}
export interface Health {
    integration_id: string;
    display_name: string;
    health: string;
    last_checked_at: string | null;
    sample_count: number;
    buckets: Bucket[];
    recent_errors: {
        timestamp: string;
        status: string;
        message: string;
    }[];
}
export interface Page<T> {
    data: T[];
    meta: {
        current_page: number;
        last_page: number;
        total: number;
    };
}
export class IntegrationApi {
    constructor(private client: AuthenticatedApiClient) { }
    async clients(page: number, partner?: string, clientId?: string) { if (clientId !== undefined && clientId !== '') {
        const r = await this.client.get<Client>('/v1/admin/integrations/api-clients/' + encodeURIComponent(clientId));
        return { data: [r.data], meta: { current_page: 1, last_page: 1, total: 1 } };
    } const q = new URLSearchParams({ page: String(page) }); if (partner !== undefined && partner !== '')
        q.set('integration_id', partner); return await this.client.get<Client[]>('/v1/admin/integrations/api-clients?' + q) as unknown as Page<Client>; }
    async providers(page = 1, type = '') { return await this.client.get<Provider[]>('/v1/admin/integrations/providers?page=' + page + (type ? '&provider_type=' + encodeURIComponent(type) : '')) as unknown as Page<Provider>; }
    async create(input: object) { return (await this.client.post<Credentials>('/v1/admin/integrations/api-clients', input)).data; }
    async rotate(id: string) { return (await this.client.post<Credentials>('/v1/admin/integrations/api-clients/' + encodeURIComponent(id) + '/rotate', {})).data; }
    async revoke(id: string) { return this.client.delete('/v1/admin/integrations/api-clients/' + encodeURIComponent(id)); }
    async setStatus(id: string, status: string) { return this.client.patch('/v1/integrations/clients/' + encodeURIComponent(id) + '/status', { status }); }
    async deliveries(query: URLSearchParams) { return await this.client.get<Delivery[]>('/v1/admin/webhooks/deliveries?' + query) as unknown as Page<Delivery>; }
    async retry(id: string) { return this.client.post('/v1/admin/webhooks/deliveries/' + encodeURIComponent(id) + '/retry', {}); }
    async quarantine(id: string) { return this.client.post('/v1/admin/webhooks/deliveries/' + encodeURIComponent(id) + '/quarantine', {}); }
    async health(id: string) { return (await this.client.get<Health>('/v1/admin/integrations/providers/' + encodeURIComponent(id) + '/health')).data; }
    async providerStatus(id: string, status: string) { return this.client.patch('/v1/integrations/providers/' + encodeURIComponent(id) + '/status', { status }); }
    async setDefault(id: string) { return this.client.post('/v1/integrations/providers/' + encodeURIComponent(id) + '/set-default', {}); }
}
export { errorMessage } from '../security/api';
export const dateText = (value: string | null) => value !== null ? new Date(value).toLocaleString() : 'Not checked';
export const healthLabel = (value: string) => ({ healthy: 'Healthy', degraded: 'Degraded', down: 'Down', unknown: 'Not checked' }[value] ?? 'Not checked');
