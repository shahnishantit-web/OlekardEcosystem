import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import type { ApiResponse } from '@/lib/api/client';
import { generateIdempotencyKey } from '@/lib/utils/idempotency';

// --- Audit types ---
export interface AuditEvent {
  id: string;
  action: string;
  actor_id: string | null;
  actor_type: string;
  resource_type: string | null;
  resource_id: string | null;
  outcome: 'success' | 'failure' | 'denied';
  correlation_id: string;
  occurred_at: string;
  before_state?: object | null;
  after_state?: object | null;
}

export interface AuditEventDetail extends AuditEvent {
  metadata: object;
}

export interface AuditEventCursorMeta {
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

// --- Compliance dashboard types ---
export interface ChecklistItem {
  label: string;
  status: 'ok' | 'warning' | 'critical';
  detail: string | null;
}

export interface OpenComplianceItem {
  id: string;
  description: string;
  due_date: string | null;
  urgency: 'low' | 'medium' | 'high';
  category: string;
}

export interface RetentionDeadline {
  policy_id: string;
  data_category: string;
  deadline_at: string;
  urgency: 'low' | 'medium' | 'high';
}

export interface ComplianceDashboard {
  regulatory_checklist: ChecklistItem[];
  open_items: OpenComplianceItem[];
  retention_deadlines: RetentionDeadline[];
}

// --- Retention policy types ---
export interface RetentionPolicy {
  id: string;
  data_category: string;
  minimum_retention_days: number;
  post_retention_action: 'archive' | 'purge';
  effective_from: string;
  enforcement_hold_until: string | null;
  configured_by_actor_id: string;
  updated_at: string;
}

// --- QA types ---
export interface QaRunCounts {
  passed: number;
  failed: number;
  skipped: number;
  total: number;
}

export interface QaTestRun {
  id: string;
  category: string;
  status: 'pending' | 'running' | 'completed' | 'failed';
  triggered_by: string;
  counts: QaRunCounts;
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
}

export interface QaTestResult {
  id: string;
  test_case_code: string;
  test_case_name: string;
  scenario_type: string;
  outcome: 'passed' | 'failed' | 'skipped' | 'error';
  duration_ms: number | null;
  error_message: string | null;
  evidence: Record<string, unknown> | null;
  created_at: string;
}

export interface QaTestRunDetail extends QaTestRun {
  results: QaTestResult[];
}

export interface QaCategoryRow {
  category: string;
  label: string;
  latest_run_id: string | null;
  latest_status: string | null;
  passed: number | null;
  failed: number | null;
  total: number | null;
  last_run_at: string | null;
}

export interface QaDashboard {
  summary: {
    total_categories: number;
    categories_with_run: number;
  };
  categories: QaCategoryRow[];
}

// --- Shared types ---
export interface PaginatedMeta {
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

export interface PaginatedResponse<T> {
  data: T[];
  meta: PaginatedMeta;
}

/** AdminApp collection endpoints return rows in `data` and pagination in `meta`. */
export type PaginatedApiResponse<T> = Omit<ApiResponse<T[]>, 'meta'> & { meta: PaginatedMeta };

// --- Dashboard types ---
export interface OperationalDashboard {
  active_users: number;
  active_wallets: number;
  pending_kyc_submissions: number;
  pending_agent_approvals: number;
  open_disputes: number;
  system_health: string;
  cached_at: string;
}

export interface FinancialDashboard {
  total_wallet_balance_minor_units: number;
  currency: string;
  transactions_today_count: number;
  transactions_today_volume_minor_units: number;
  pending_settlements_minor_units: number;
  failed_transactions_today: number;
  cached_at: string;
}

export interface RiskDashboard {
  active_fraud_alerts: number;
  aml_flags_today: number;
  high_risk_transactions_today: number;
  blocked_users_count: number;
  frozen_wallets_count: number;
  failed_kyc_attempts_today: number;
  suspicious_ip_count_today: number;
  open_support_disputes: number;
  velocity_breach_alerts_today: number;
  cached_at: string;
}

// --- User types ---
export interface AdminUser {
  id: string;
  email: string;
  full_name: string;
  phone_number: string | null;
  status: string;
  kyc_tier: number | null;
  wallet_count: number;
  actor_type: string;
  created_at: string;
}

// --- KYC types ---
export interface KycVerificationResult {
  provider_name: string;
  outcome: string;
  confidence_score: number | null;
  created_at?: string | undefined;
}

export interface KycSubmission {
  id: string;
  user_id: string;
  user_name: string;
  user_phone_number?: string | null | undefined;
  tier_applied: number | null;
  current_tier?: number | null | undefined;
  status: string;
  document_type: string;
  submitted_at: string | null;
  document_urls: string[];
  risk_score?: number | null | undefined;
  kyc_type?: string | null | undefined;
  rejection_reason?: string | null | undefined;
  reviewed_at?: string | null | undefined;
  reviewer_id?: string | null | undefined;
  personal_info?: {
    full_legal_name?: string | null | undefined;
    date_of_birth?: string | null | undefined;
    nationality?: string | null | undefined;
    address?: {
      line_1?: string | null | undefined;
      city?: string | null | undefined;
      country_code?: string | null | undefined;
    } | null | undefined;
  } | null | undefined;
  verification_results?: KycVerificationResult[] | null | undefined;
}

// --- Wallet types ---
export interface AdminWallet {
  id: string;
  user_id: string;
  currency: string;
  balance_minor_units: number;
  status: string;
  frozen_at: string | null;
  frozen_by: string | null;
  freeze_reason: string | null;
  created_at?: string | undefined;
  // E33 extended fields
  wallet_number?: string | null | undefined;
  owner_name?: string | null | undefined;
  currency_code?: string | null | undefined;
  owner_user_id?: string | null | undefined;
  available_balance_minor_units?: number | null | undefined;
  spending_limit_minor_units?: number | null | undefined;
}

export interface WalletLedgerEntry {
  entry_id: string;
  ledger_account: string;
  direction: 'debit' | 'credit';
  amount_minor_units: number;
  running_balance_minor_units?: number | null | undefined;
  type: string;
  reference?: string | null | undefined;
  posted_at: string;
  description?: string | null | undefined;
}

// --- Transaction types ---
export interface AdminTransaction {
  // Core fields (used in both list and detail)
  id: string;
  transaction_id?: string | null | undefined;
  reference_number?: string | null | undefined;
  type: string;
  status: string;
  amount_minor_units: number;
  currency: string;
  currency_code?: string | null | undefined;
  fee_minor_units?: number | null | undefined;
  // Wallet references
  wallet_id: string;
  source_wallet_id?: string | null | undefined;
  destination_wallet_id?: string | null | undefined;
  // Initiator
  initiator_user_id?: string | null | undefined;
  initiator_name?: string | null | undefined;
  // State
  reversal_eligible: boolean;
  reversal_id?: string | null | undefined;
  fraud_flags?: string[] | null | undefined;
  idempotency_key?: string | null | undefined;
  // Timestamps
  created_at: string;
  completed_at?: string | null | undefined;
  failed_at?: string | null | undefined;
  // Failure info (for failed transactions)
  failure_reason?: string | null | undefined;
  retry_count?: number | null | undefined;
}

export interface TransactionLedgerEntry {
  entry_id: string;
  ledger_account: string;
  amount_minor_units: number;
  direction: 'debit' | 'credit';
  posted_at: string;
  description?: string | null | undefined;
}

export interface TransactionFraudAssessment {
  risk_score: number;
  flags: string[];
  assessed_at: string;
}

export interface AdminTransactionDetail extends AdminTransaction {
  ledger_entries?: TransactionLedgerEntry[] | null | undefined;
  fraud_assessment?: TransactionFraudAssessment | null | undefined;
  correlation_id?: string | null | undefined;
  provider_response?: Record<string, unknown> | null | undefined;
}

// --- Float types ---
export interface AdminFloatRecord {
  agent_id: string;
  full_name: string;
  float_balance_minor_units: number;
  float_currency: string;
  float_limit_minor_units: number;
  utilization_pct?: number | null | undefined;
  last_top_up_at?: string | null | undefined;
  // optional extended fields
  phone_number?: string | null | undefined;
  business_name?: string | null | undefined;
}

// --- Agent types ---
export interface AdminAgent {
  // Core (always present)
  id: string;
  user_id: string;
  full_name: string;
  status: string;
  float_balance_minor_units: number;
  float_limit_minor_units: number;
  commission_rate: number;
  created_at: string;
  // E33 extended fields
  agent_id?: string | null | undefined;
  agent_status?: string | null | undefined;
  phone_number?: string | null | undefined;
  business_name?: string | null | undefined;
  approved_at?: string | null | undefined;
  approved_by?: string | null | undefined;
  float_currency?: string | null | undefined;
  total_cash_in_today_minor_units?: number | null | undefined;
  total_cash_out_today_minor_units?: number | null | undefined;
  location?: string | null | undefined;
  // Detail-only fields
  rejection_reason?: string | null | undefined;
  suspended_at?: string | null | undefined;
  suspension_reason?: string | null | undefined;
  // 30-day metrics (returned in detail)
  transactions_30d?: number | null | undefined;
  commission_earned_30d_minor_units?: number | null | undefined;
  cash_in_30d_minor_units?: number | null | undefined;
  cash_out_30d_minor_units?: number | null | undefined;
}

// --- Role types ---
export interface AdminRole {
  role_id: string;
  role_name: string;
  permission_slugs: string[];
  user_count: number;
  created_at: string | null;
}

export const SYSTEM_ROLE_NAMES = ['admin', 'operations', 'finance', 'compliance'] as const;

export const ALL_PERMISSION_SLUGS: { slug: string; label: string; group: string }[] = [
  {slug:'security.config.read',label:'View security policies',group:'Security'},
  {slug:'security.config.write',label:'Propose security policies',group:'Security'},
  {slug:'security.events.read',label:'View security events',group:'Security'},
  {slug:'security.events.export',label:'Export security events',group:'Security'},
  { slug: 'multi_currency.manage_currencies', label: 'Manage currencies', group: 'Multi-Currency' },
  { slug: 'multi_currency.configure_rate', label: 'Configure exchange rates', group: 'Multi-Currency' },
  { slug: 'configuration.approve', label: 'Approve configuration changes', group: 'Configuration' },
  { slug: 'support.investigate', label: 'support investigate', group: 'Support' },
  { slug: 'support.assign', label: 'support assign', group: 'Support' },
  { slug: 'support.resolve', label: 'support resolve', group: 'Support' },
  { slug: 'dispute.approve', label: 'dispute approve', group: 'Support' },
  { slug: 'dispute.reject', label: 'dispute reject', group: 'Support' },
  { slug: 'dispute.refund', label: 'dispute refund', group: 'Support' },
  { slug: 'dispute.escalate', label: 'dispute escalate', group: 'Support' },
  {slug:'integrations.manage',label:'Manage integrations and API credentials',group:'Integrations'},
  { slug: 'notifications.templates.read', label: 'templates read notifications', group: 'Notifications' },
  { slug: 'notifications.templates.write', label: 'templates write notifications', group: 'Notifications' },
  { slug: 'notifications.log.read', label: 'log read notifications', group: 'Notifications' },
  { slug: 'notifications.retry', label: 'retry notifications', group: 'Notifications' },
  { slug: 'notifications.rules.read', label: 'rules read notifications', group: 'Notifications' },
  { slug: 'notifications.rules.write', label: 'rules write notifications', group: 'Notifications' },
  { slug: 'risk.manage_alerts', label: 'Manage risk investigations', group: 'Security' },
  { slug: 'risk.freeze_account', label: 'Request and approve AML account freezes', group: 'Security' },
  { slug: 'security.alerts.read', label: 'View risk alert investigations', group: 'Security' },
  { slug: 'security.findings.read', label: 'Read security findings', group: 'Security' },
  { slug: 'security.findings.write', label: 'Manage security findings', group: 'Security' },
  { slug: 'adminapp.dashboard.operational', label: 'View Operational Dashboard', group: 'Dashboard' },
  { slug: 'adminapp.dashboard.financial',   label: 'View Financial Dashboard',   group: 'Dashboard' },
  { slug: 'adminapp.dashboard.risk',        label: 'View Risk Dashboard',        group: 'Dashboard' },
  { slug: 'adminapp.users.read',            label: 'Read Users',                 group: 'Users' },
  { slug: 'adminapp.users.write',           label: 'Manage Users',               group: 'Users' },
  { slug: 'adminapp.roles.manage',          label: 'Manage Roles',               group: 'Roles' },
  { slug: 'adminapp.kyc.review',            label: 'Review KYC',                 group: 'KYC' },
  { slug: 'adminapp.kyc.approve',           label: 'Approve KYC',                group: 'KYC' },
  { slug: 'adminapp.kyc.reject',            label: 'Reject KYC',                 group: 'KYC' },
  { slug: 'adminapp.wallets.read',          label: 'Read Wallets',               group: 'Wallets' },
  { slug: 'adminapp.wallets.freeze',        label: 'Freeze Wallets',             group: 'Wallets' },
  { slug: 'adminapp.wallets.unfreeze',      label: 'Unfreeze Wallets',           group: 'Wallets' },
  { slug: 'adminapp.transactions.read',        label: 'Read Transactions',        group: 'Transactions' },
  { slug: 'adminapp.transactions.investigate', label: 'Investigate Transactions', group: 'Transactions' },
  { slug: 'adminapp.transactions.reverse',     label: 'Reverse Transactions',     group: 'Transactions' },
  { slug: 'adminapp.agents.approve',        label: 'Approve Agents',             group: 'Agents' },
  { slug: 'adminapp.agents.manage',         label: 'Manage Agents',              group: 'Agents' },
  { slug: 'adminapp.float.manage',          label: 'Manage Agent Float',         group: 'Float' },
  { slug: 'adminapp.reconciliation.read',   label: 'View Reconciliation',        group: 'Reconciliation' },
  { slug: 'adminapp.settlement.read',       label: 'View Settlement',            group: 'Settlement' },
  { slug: 'adminapp.audit.read',            label: 'View Audit Log',             group: 'Audit' },
  { slug: 'compliance.read',       label: 'View Compliance Dashboard',  group: 'Compliance' },
  { slug: 'compliance.config',     label: 'Configure Compliance',       group: 'Compliance' },
  { slug: 'adminapp.reports.read',          label: 'View Reports',               group: 'Reports' },
  { slug: 'admin.read',      label: 'Generate Reports',           group: 'Reports' },
  { slug: 'reports.schedule',      label: 'Schedule Reports',           group: 'Reports' },
  { slug: 'configuration.policy.view',           label: 'View Configuration',         group: 'Configuration' },
  { slug: 'configuration.policy.update',          label: 'Submit Config Changes',      group: 'Configuration' },
];

// --- Pending action type ---
export interface PendingAction {
  approval_id: string;
  action_type: string;
  status: string;
  expires_at: string;
}

// --- Report types ---
export interface ReportJob {
  report_id: string;
  report_type: string;
  status: string;
  download_url: string | null;
  created_at: string;
}

// --- Reconciliation types ---
export interface ReconciliationBatch {
  batch_id: string;
  period_start: string;
  period_end: string;
  status: string;
  total_transactions: number;
  matched_count: number;
  unmatched_count: number;
  discrepancy_amount_minor_units: number;
  currency_code: string;
  run_at: string | null;
  completed_at: string | null;
  // detail-only
  unmatched_transaction_ids?: string[] | null | undefined;
}

export interface SettlementRecord {
  settlement_id: string;
  settlement_date: string;
  status: string;
  total_amount_minor_units: number;
  currency_code: string;
  net_position_minor_units: number;
  provider: string | null;
  reference: string | null;
}

export class AdminAppClient {
  private static readonly BASE = '/v1/admin-app';

  constructor(private readonly client: AuthenticatedApiClient) {}

  // --- Dashboards ---
  getOperationalDashboard(): Promise<ApiResponse<OperationalDashboard>> {
    return this.client.get(`${AdminAppClient.BASE}/dashboard/operational`);
  }

  getFinancialDashboard(): Promise<ApiResponse<FinancialDashboard>> {
    return this.client.get(`${AdminAppClient.BASE}/dashboard/financial`);
  }

  getRiskDashboard(): Promise<ApiResponse<RiskDashboard>> {
    return this.client.get(`${AdminAppClient.BASE}/dashboard/risk`);
  }

  // --- Users ---
  listUsers(params?: Record<string, string>): Promise<ApiResponse<PaginatedResponse<AdminUser>>> {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get(`${AdminAppClient.BASE}/users${qs}`);
  }

  getUser(userId: string): Promise<ApiResponse<AdminUser>> {
    return this.client.get(`${AdminAppClient.BASE}/users/${userId}`);
  }

  updateUserStatus(
    userId: string,
    action: 'suspend' | 'reactivate',
    reason: string,
  ): Promise<ApiResponse<AdminUser>> {
    return this.client.patch(`${AdminAppClient.BASE}/users/${userId}/status`, { action, reason }, {
      idempotencyKey: generateIdempotencyKey(`user-status-${userId}-${action}`),
    });
  }

  // --- KYC ---
  listKyc(params?: Record<string, string>): Promise<ApiResponse<PaginatedResponse<KycSubmission>>> {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get(`${AdminAppClient.BASE}/kyc${qs}`);
  }

  getKyc(submissionId: string): Promise<ApiResponse<KycSubmission>> {
    return this.client.get(`${AdminAppClient.BASE}/kyc/${submissionId}`);
  }

  approveKyc(submissionId: string, idempotencyKey: string): Promise<ApiResponse<PendingAction>> {
    return this.client.post(`${AdminAppClient.BASE}/kyc/${submissionId}/approve`, {}, { idempotencyKey });
  }

  rejectKyc(
    submissionId: string,
    reason: string,
    idempotencyKey: string,
  ): Promise<ApiResponse<PendingAction>> {
    return this.client.post(`${AdminAppClient.BASE}/kyc/${submissionId}/reject`, { reason }, { idempotencyKey });
  }

  // --- Wallets ---
  listWallets(params?: Record<string, string>): Promise<ApiResponse<AdminWallet[]>> {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get(`${AdminAppClient.BASE}/wallets${qs}`);
  }

  getWallet(walletId: string): Promise<ApiResponse<AdminWallet>> {
    return this.client.get(`${AdminAppClient.BASE}/wallets/${walletId}`);
  }

  freezeWallet(walletId: string, reason: string, idempotencyKey: string): Promise<ApiResponse<PendingAction>> {
    return this.client.post(`${AdminAppClient.BASE}/wallets/${walletId}/freeze`, { reason }, { idempotencyKey });
  }

  unfreezeWallet(walletId: string, reason: string, idempotencyKey: string): Promise<ApiResponse<PendingAction>> {
    return this.client.post(`${AdminAppClient.BASE}/wallets/${walletId}/unfreeze`, { reason }, { idempotencyKey });
  }

  getWalletLedger(walletId: string, params?: Record<string, string>): Promise<ApiResponse<PaginatedResponse<WalletLedgerEntry>>> {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get(`${AdminAppClient.BASE}/wallets/${walletId}/ledger${qs}`);
  }

  adjustWallet(
    walletId: string,
    payload: {
      direction: 'credit' | 'debit';
      amount_minor_units: number;
      currency: string;
      reason: string;
      reference: string;
    },
    idempotencyKey: string,
  ): Promise<ApiResponse<PendingAction>> {
    return this.client.post(`${AdminAppClient.BASE}/wallets/${walletId}/adjustment`, payload, { idempotencyKey });
  }

  // --- Transactions ---
  listTransactions(params?: Record<string, string>): Promise<ApiResponse<AdminTransaction[]>> {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get(`${AdminAppClient.BASE}/transactions${qs}`);
  }

  getTransaction(transactionId: string): Promise<ApiResponse<AdminTransactionDetail>> {
    return this.client.get(`${AdminAppClient.BASE}/transactions/${transactionId}`);
  }

  exportTransactions(
    params: Record<string, string>,
    idempotencyKey: string,
  ): Promise<ApiResponse<{ export_id: string; status: string }>> {
    return this.client.post(`${AdminAppClient.BASE}/transactions/export`, params, { idempotencyKey });
  }

  reverseTransaction(
    transactionId: string,
    reason: string,
    idempotencyKey: string,
  ): Promise<ApiResponse<PendingAction>> {
    return this.client.post(
      `${AdminAppClient.BASE}/transactions/${transactionId}/reverse`,
      { reason },
      { idempotencyKey },
    );
  }

  // --- Agents ---
  listAgents(params?: Record<string, string>): Promise<ApiResponse<PaginatedResponse<AdminAgent>>> {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get(`${AdminAppClient.BASE}/agents${qs}`);
  }

  getAgent(agentId: string): Promise<ApiResponse<AdminAgent>> {
    return this.client.get(`${AdminAppClient.BASE}/agents/${agentId}`);
  }

  approveAgent(agentId: string, idempotencyKey: string): Promise<ApiResponse<PendingAction>> {
    return this.client.post(`${AdminAppClient.BASE}/agents/${agentId}/approve`, {}, { idempotencyKey });
  }

  rejectAgent(
    agentId: string,
    reason: string,
    idempotencyKey: string,
  ): Promise<ApiResponse<AdminAgent>> {
    return this.client.post(`${AdminAppClient.BASE}/agents/${agentId}/reject`, { reason }, { idempotencyKey });
  }

  updateAgentStatus(
    agentId: string,
    action: 'suspend' | 'reactivate',
    reason: string,
    idempotencyKey: string,
  ): Promise<ApiResponse<AdminAgent>> {
    return this.client.patch(`${AdminAppClient.BASE}/agents/${agentId}/status`, { action, reason }, { idempotencyKey });
  }

  // --- Float ---
  listFloat(params?: Record<string, string>): Promise<ApiResponse<PaginatedResponse<AdminFloatRecord>>> {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get(`${AdminAppClient.BASE}/float${qs}`);
  }

  adjustFloat(
    agentId: string,
    direction: 'credit' | 'debit',
    amountMinorUnits: number,
    reason: string,
    idempotencyKey: string,
  ): Promise<ApiResponse<{ new_balance_minor_units: number }>> {
    return this.client.post(
      `${AdminAppClient.BASE}/float/${agentId}/adjust`,
      { direction, amount_minor_units: amountMinorUnits, reason },
      { idempotencyKey },
    );
  }

  topUpFloat(
    agentId: string,
    amountMinorUnits: number,
    currency: string,
    reason: string,
    idempotencyKey: string,
  ): Promise<ApiResponse<PendingAction>> {
    return this.client.post(
      `${AdminAppClient.BASE}/float/${agentId}/top-up`,
      { amount_minor_units: amountMinorUnits, currency, reason },
      { idempotencyKey },
    );
  }

  updateFloatLimit(
    agentId: string,
    newLimitMinorUnits: number,
    reason: string,
    idempotencyKey: string,
  ): Promise<ApiResponse<{ agent_id: string; float_limit_minor_units: number; updated_at: string }>> {
    return this.client.patch(
      `${AdminAppClient.BASE}/float/${agentId}/limit`,
      { float_limit_minor_units: newLimitMinorUnits, reason },
      { idempotencyKey },
    );
  }

  // --- Roles ---
  listRoles(params?: Record<string, string>): Promise<ApiResponse<AdminRole[]>> {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get(`${AdminAppClient.BASE}/roles${qs}`);
  }

  getRole(roleId: string): Promise<ApiResponse<AdminRole>> {
    return this.client.get(`${AdminAppClient.BASE}/roles/${roleId}`);
  }

  updateRolePermissions(
    roleId: string,
    permissionSlugs: string[],
    idempotencyKey: string,
  ): Promise<ApiResponse<AdminRole>> {
    return this.client.patch(
      `${AdminAppClient.BASE}/roles/${roleId}/permissions`,
      { permission_slugs: permissionSlugs },
      { idempotencyKey },
    );
  }

  createRole(
    name: string,
    permissionSlugs: string[],
    idempotencyKey: string,
  ): Promise<ApiResponse<AdminRole>> {
    return this.client.post(
      `${AdminAppClient.BASE}/roles`,
      { role_name: name, permission_slugs: permissionSlugs },
      { idempotencyKey },
    );
  }

  assignUserRole(
    userId: string,
    roleId: string,
    idempotencyKey: string,
  ): Promise<ApiResponse<{ user_id: string; role_id: string; assigned_at: string }>> {
    return this.client.post(
      `${AdminAppClient.BASE}/users/${userId}/roles`,
      { role_id: roleId },
      { idempotencyKey },
    );
  }

  // --- Reconciliation ---
  listReconciliation(params?: Record<string, string>): Promise<PaginatedApiResponse<ReconciliationBatch>> {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get<ReconciliationBatch[]>(`${AdminAppClient.BASE}/reconciliation${qs}`) as unknown as Promise<PaginatedApiResponse<ReconciliationBatch>>;
  }

  getReconciliationBatch(batchId: string): Promise<ApiResponse<ReconciliationBatch & { unmatched_transaction_ids: string[] }>> {
    return this.client.get(`${AdminAppClient.BASE}/reconciliation/${batchId}`);
  }

  listSettlement(params?: Record<string, string>): Promise<PaginatedApiResponse<SettlementRecord>> {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get<SettlementRecord[]>(`${AdminAppClient.BASE}/settlement${qs}`) as unknown as Promise<PaginatedApiResponse<SettlementRecord>>;
  }

  getSettlement(settlementId: string): Promise<ApiResponse<SettlementRecord>> {
    return this.client.get(`${AdminAppClient.BASE}/settlement/${settlementId}`);
  }

  approveSettlement(
    settlementId: string,
    idempotencyKey: string,
  ): Promise<ApiResponse<SettlementRecord>> {
    return this.client.post(
      `${AdminAppClient.BASE}/settlement/${settlementId}/approve`,
      {},
      { idempotencyKey },
    );
  }

  // --- Audit ---
  listAuditEvents(params: Record<string, string>): Promise<ApiResponse<AuditEvent[]>> {
    const qs = Object.keys(params).length ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get(`${AdminAppClient.BASE}/audit${qs}`);
  }

  // --- Compliance ---
  getComplianceDashboard(): Promise<ApiResponse<ComplianceDashboard>> {
    return this.client.get('/v1/compliance/dashboard');
  }

  listRetentionPolicies(): Promise<ApiResponse<RetentionPolicy[]>> {
    return this.client.get('/v1/compliance/retention-policies');
  }

  updateRetentionPolicy(
    id: string,
    payload: { minimum_retention_days: number; post_retention_action: 'archive' | 'purge' },
  ): Promise<ApiResponse<{ data: RetentionPolicy }>> {
    return this.client.put(`/v1/compliance/retention-policies/${id}`, payload);
  }

  // --- Reports ---
  createReport(
    reportType: string,
    periodStart: string,
    periodEnd: string,
    format: string,
    idempotencyKey: string,
  ): Promise<ApiResponse<ReportJob>> {
    return this.client.post(
      `${AdminAppClient.BASE}/reports`,
      { report_type: reportType, period_start: periodStart, period_end: periodEnd, format },
      { idempotencyKey },
    );
  }

  getReport(reportId: string): Promise<ApiResponse<ReportJob>> {
    return this.client.get(`${AdminAppClient.BASE}/reports/${reportId}`);
  }

  // --- QA & Validation ---
  triggerQaRun(category: string, idempotencyKey: string): Promise<ApiResponse<{ data: QaTestRun }>> {
    return this.client.post(`/v1/qa/${category}`, {}, { idempotencyKey });
  }

  listQaRuns(params?: Record<string, string>): Promise<ApiResponse<PaginatedResponse<QaTestRun>>> {
    const qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return this.client.get(`/v1/qa/runs${qs}`);
  }

  getQaRun(id: string): Promise<ApiResponse<{ data: QaTestRunDetail }>> {
    return this.client.get(`/v1/qa/runs/${id}`);
  }

  getQaDashboard(): Promise<ApiResponse<{ data: QaDashboard }>> {
    return this.client.get('/v1/qa/dashboard');
  }

  // --- Approvals (checker flow) ---
  approveAction(approvalId: string, idempotencyKey: string): Promise<ApiResponse<{ status: string }>> {
    return this.client.post(
      `${AdminAppClient.BASE}/approvals/${approvalId}/approve`,
      {},
      { idempotencyKey },
    );
  }

  rejectAction(
    approvalId: string,
    reason: string,
    idempotencyKey: string,
  ): Promise<ApiResponse<{ status: string }>> {
    return this.client.post(
      `${AdminAppClient.BASE}/approvals/${approvalId}/reject`,
      { reason },
      { idempotencyKey },
    );
  }
}
