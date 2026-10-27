// T094: Typed API client hooks for P12 bank integration configuration.
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type { ApiResponse } from '@/lib/api/client';

export type BankLifecycleStatus = 'draft' | 'active' | 'suspended' | 'retired';
export type AccountLifecycleStatus =
  | 'draft'
  | 'validated'
  | 'sandbox_testing'
  | 'evidence_review'
  | 'approved'
  | 'active'
  | 'suspended'
  | 'retired';

export interface Bank {
  id: string;
  code: string;
  display_name: string;
  bank_lifecycle_status: BankLifecycleStatus;
  supported_countries: string[];
  description: string | null;
  logo_reference: string | null;
  metadata_version: number;
  created_at: string;
  updated_at: string | null;
}

export interface IntegrationAccount {
  id: string;
  provider_id: string;
  merchant_account_reference: string | null;
  account_lifecycle_status: AccountLifecycleStatus;
  active_config_version_id: string | null;
  display_label: string | null;
  display_sort_order: number;
  supported_currencies: string[];
  supported_payment_methods: string[];
  callback_secret_reference: { key: string; masked: string } | null;
  config_version: number;
  submitted_by_actor_id: string | null;
  approved_by_actor_id: string | null;
  approved_at: string | null;
  activated_at: string | null;
  suspended_at: string | null;
  retired_at: string | null;
  rejection_reason: string | null;
  created_at: string;
  updated_at: string | null;
}

export interface IntegrationProfile {
  id: string;
  profile_identifier: string;
  version: string;
  display_name: string;
  description: string | null;
  supported_auth_mechanisms: string[];
  supported_capabilities: string[];
}

export interface ApiOperationConfig {
  id: string;
  account_id: string;
  operation_type: string;
  base_url: string;
  endpoint_path: string;
  http_method: 'GET' | 'POST' | 'PUT' | 'PATCH';
  content_type: string;
  timeout_ms: number;
  secret_key_references: Record<string, { key: string; masked: string }>;
  is_payout: boolean;
}

export interface CertificationEvidence {
  id: string;
  account_id: string;
  evidence_type: string;
  description: string | null;
  reference_url: string | null;
  created_at: string;
}

export interface SandboxTestResult {
  result: 'pass' | 'fail';
  message: string;
  duration_ms: number;
  evidence_id: string;
}

export interface CredentialRotationLog {
  rotation_log_id: string;
  secret_role: string;
  effective_from: string;
  rotated_at: string;
}

export const buildBanksApi = (client: AuthenticatedApiClient) => ({
  listBanks: (params?: Record<string, string>): Promise<ApiResponse<Bank[]>> =>
    client.get(`/api/v1/admin/payments/banks${params ? '?' + new URLSearchParams(params).toString() : ''}`),

  getBank: (bankId: string): Promise<ApiResponse<Bank>> =>
    client.get(`/api/v1/admin/payments/banks/${bankId}`),

  createBank: (data: Partial<Bank>, idempotencyKey: string): Promise<ApiResponse<Bank>> =>
    client.post('/api/v1/admin/payments/banks', data, {
      idempotencyKey,
    }),

  updateBank: (bankId: string, data: Partial<Bank>): Promise<ApiResponse<Bank>> =>
    client.patch(`/api/v1/admin/payments/banks/${bankId}`, data),

  listAccounts: (bankId: string): Promise<ApiResponse<IntegrationAccount[]>> =>
    client.get(`/api/v1/admin/payments/banks/${bankId}/accounts`),

  getAccount: (bankId: string, accountId: string): Promise<ApiResponse<IntegrationAccount>> =>
    client.get(`/api/v1/admin/payments/banks/${bankId}/accounts/${accountId}`),

  createAccount: (bankId: string, data: Partial<IntegrationAccount>, idempotencyKey: string): Promise<ApiResponse<IntegrationAccount>> =>
    client.post(`/api/v1/admin/payments/banks/${bankId}/accounts`, data, {
      idempotencyKey,
    }),

  updateAccount: (bankId: string, accountId: string, data: Partial<IntegrationAccount>): Promise<ApiResponse<IntegrationAccount & { draft_created: boolean }>> =>
    client.patch(`/api/v1/admin/payments/banks/${bankId}/accounts/${accountId}`, data),

  listProfiles: (): Promise<ApiResponse<IntegrationProfile[]>> =>
    client.get('/api/v1/admin/payments/integration-profiles'),

  listOperations: (bankId: string, accountId: string): Promise<ApiResponse<ApiOperationConfig[]>> =>
    client.get(`/api/v1/admin/payments/banks/${bankId}/accounts/${accountId}/operations`),

  saveOperations: (bankId: string, accountId: string, data: Partial<ApiOperationConfig>[], idempotencyKey: string): Promise<ApiResponse<ApiOperationConfig[]>> =>
    client.put(`/api/v1/admin/payments/banks/${bankId}/accounts/${accountId}/operations`, { operations: data }, {
      idempotencyKey,
    }),

  validateOperations: (bankId: string, accountId: string, data: Partial<ApiOperationConfig>[]): Promise<ApiResponse<{ errors: Record<string, string[]> }>> =>
    client.post(`/api/v1/admin/payments/banks/${bankId}/accounts/${accountId}/operations/validate`, { operations: data }),

  transition: (bankId: string, accountId: string, action: string, payload: Record<string, unknown>, idempotencyKey: string): Promise<ApiResponse<{ new_status: AccountLifecycleStatus }>> =>
    client.post(`/api/v1/admin/payments/banks/${bankId}/accounts/${accountId}/lifecycle`, { action, ...payload }, {
      idempotencyKey,
    }),

  listEvidence: (bankId: string, accountId: string): Promise<ApiResponse<CertificationEvidence[]>> =>
    client.get(`/api/v1/admin/payments/banks/${bankId}/accounts/${accountId}/evidence`),

  addEvidence: (bankId: string, accountId: string, data: Partial<CertificationEvidence>, idempotencyKey: string): Promise<ApiResponse<CertificationEvidence>> =>
    client.post(`/api/v1/admin/payments/banks/${bankId}/accounts/${accountId}/evidence`, data, {
      idempotencyKey,
    }),

  runSandboxTest: (bankId: string, accountId: string, idempotencyKey: string): Promise<ApiResponse<SandboxTestResult>> =>
    client.post(`/api/v1/admin/payments/banks/${bankId}/accounts/${accountId}/sandbox-test`, {}, {
      idempotencyKey,
    }),

  rotateCredential: (bankId: string, accountId: string, data: { secret_role: string; new_key_name: string; reason?: string }, idempotencyKey: string): Promise<ApiResponse<CredentialRotationLog>> =>
    client.post(`/api/v1/admin/payments/banks/${bankId}/accounts/${accountId}/rotate-credential`, data, {
      idempotencyKey,
    }),
});

export type BanksApi = ReturnType<typeof buildBanksApi>;
