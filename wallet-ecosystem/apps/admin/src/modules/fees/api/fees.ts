import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export type TransactionType =
  | 'transfer'
  | 'funding'
  | 'withdrawal'
  | 'bill_payment'
  | 'qr_payment'
  | 'cash_in'
  | 'cash_out'
  | 'settlement';

export type FeeRuleStatus = 'active' | 'expired' | 'deactivated' | 'superseded';

export interface FeeRule {
  id: string;
  transaction_type: TransactionType;
  fee_type: 'fixed' | 'percentage';
  rate: number | null;
  amount_minor: number | null;
  min_fee_minor: number | null;
  max_fee_minor: number | null;
  currency: string;
  subject_type: 'global' | 'customer' | 'agent';
  subject_id: string | null;
  valid_from: string;
  valid_to: string | null;
  status: FeeRuleStatus;
  pricing_version_id: string | null;
  created_by: string;
  created_at: string;
  updated_at: string;
}

export interface FeeCalculationResult {
  fee_amount_minor: number;
  fee_type: 'fixed' | 'percentage' | null;
  rule_id: string | null;
  has_applicable_rule: boolean;
  min_fee_applied: boolean;
  max_fee_applied: boolean;
}

export interface CalculateFeeRequest {
  transaction_type: TransactionType;
  amount_minor: number;
  currency: string;
  subject_type: 'global' | 'customer' | 'agent';
  subject_id?: string;
}

export async function getFeeRule(
  client: AuthenticatedApiClient,
  id: string,
): Promise<FeeRule> {
  const res = await client.get<FeeRule>(`/v1/fees/rules/${id}`);
  return res.data;
}

export async function deactivateFeeRule(
  client: AuthenticatedApiClient,
  id: string,
): Promise<void> {
  await client.delete(`/v1/fees/rules/${id}`);
}

export async function calculateFee(
  client: AuthenticatedApiClient,
  req: CalculateFeeRequest,
): Promise<FeeCalculationResult> {
  const res = await client.post<{ data: FeeCalculationResult }>('/v1/fees/calculate', req);
  return res.data.data;
}
