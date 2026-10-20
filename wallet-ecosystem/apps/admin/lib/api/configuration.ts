import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type { ApiResponse } from '@/lib/api/client';

// Domains are registered by backend modules (auth, device, ledger, and others).
export type ConfigurationDomain = string;
export type ChangeRequestStatus = 'pending_approval' | 'approved' | 'rejected' | 'withdrawn' | 'failed';
export type VersionEventType = 'submitted' | 'approved' | 'rejected' | 'withdrawn' | 'activated' | 'failed';

export interface ConfigurationKeyItem {
  config_key: string;
  domain: ConfigurationDomain;
  current_value: unknown;
  value_type: string;
  status: string;
  description: string | null;
  updated_at: string;
  has_pending_change: boolean;
}

export interface ConfigurationKeyDetail extends ConfigurationKeyItem {
  pending_change_request_id: string | null;
  requires_approval: boolean;
}

export interface ConfigurationVersionItem {
  id: string;
  config_key: string;
  domain: ConfigurationDomain;
  event_type: VersionEventType;
  value: unknown;
  actor_id: string | null;
  change_request_id: string | null;
  notes: string | null;
  occurred_at: string;
  approved_by: string | null;
  effective_from: string | null;
}

export interface ChangeRequestItem {
  id: string;
  config_key: string;
  domain: ConfigurationDomain;
  proposed_value: unknown;
  previous_value: unknown;
  maker_id: string;
  status: ChangeRequestStatus;
  rejection_reason: string | null;
  submitted_at: string;
  resolved_at: string | null;
}

export interface ChangeRequestDetail extends ChangeRequestItem {
  version_trail: ConfigurationVersionItem[];
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface SubmitChangeRequestPayload {
  config_key: string;
  proposed_value: unknown;
  idempotency_key: string;
}

export interface ApproveChangeRequestPayload {
  comments?: string;
  idempotency_key: string;
}

export interface RejectChangeRequestPayload {
  reason: string;
  idempotency_key: string;
}

export interface WithdrawChangeRequestPayload {
  idempotency_key: string;
}

export class ConfigurationApiClient {
  constructor(private readonly client: AuthenticatedApiClient) {}

  listKeys(
    params: { domain?: ConfigurationDomain; search?: string; per_page?: number; page?: number } = {},
  ): Promise<ApiResponse<PaginatedResponse<ConfigurationKeyItem>>> {
    const qs = new URLSearchParams();
    if (params.domain) qs.set('domain', params.domain);
    if (params.search) qs.set('search', params.search);
    if (params.page) qs.set('page', String(params.page));
    if (params.per_page) qs.set('per_page', String(params.per_page));
    return this.client.get(`/v1/admin/configuration/keys?${qs}`);
  }

  getKey(key: string): Promise<ApiResponse<ConfigurationKeyDetail>> {
    return this.client.get(`/v1/admin/configuration/keys/${encodeURIComponent(key)}`);
  }

  getKeyVersions(
    key: string,
    params: { per_page?: number } = {},
  ): Promise<ApiResponse<PaginatedResponse<ConfigurationVersionItem>>> {
    const qs = new URLSearchParams();
    if (params.per_page) qs.set('per_page', String(params.per_page));
    return this.client.get(`/v1/admin/configuration/keys/${encodeURIComponent(key)}/versions?${qs}`);
  }

  listChangeRequests(
    params: { status?: ChangeRequestStatus; domain?: ConfigurationDomain; config_key?: string; per_page?: number } = {},
  ): Promise<ApiResponse<PaginatedResponse<ChangeRequestItem>>> {
    const qs = new URLSearchParams();
    if (params.status) qs.set('status', params.status);
    if (params.domain) qs.set('domain', params.domain);
    if (params.config_key) qs.set('config_key', params.config_key);
    if (params.per_page) qs.set('per_page', String(params.per_page));
    return this.client.get(`/v1/admin/configuration/change-requests?${qs}`);
  }

  getChangeRequest(id: string): Promise<ApiResponse<ChangeRequestDetail>> {
    return this.client.get(`/v1/admin/configuration/change-requests/${id}`);
  }

  submitChangeRequest(
    payload: SubmitChangeRequestPayload,
  ): Promise<ApiResponse<ChangeRequestItem>> {
    return this.client.post('/v1/admin/configuration/change-requests', payload, {
      idempotencyKey: payload.idempotency_key,
    });
  }

  approveChangeRequest(
    id: string,
    payload: ApproveChangeRequestPayload,
  ): Promise<ApiResponse<ChangeRequestItem>> {
    return this.client.post(`/v1/admin/configuration/change-requests/${id}/approve`, payload, {
      idempotencyKey: payload.idempotency_key,
    });
  }

  rejectChangeRequest(
    id: string,
    payload: RejectChangeRequestPayload,
  ): Promise<ApiResponse<ChangeRequestItem>> {
    return this.client.post(`/v1/admin/configuration/change-requests/${id}/reject`, payload, {
      idempotencyKey: payload.idempotency_key,
    });
  }

  withdrawChangeRequest(
    id: string,
    payload: WithdrawChangeRequestPayload,
  ): Promise<ApiResponse<ChangeRequestItem>> {
    return this.client.post(`/v1/admin/configuration/change-requests/${id}/withdraw`, payload, {
      idempotencyKey: payload.idempotency_key,
    });
  }
}
