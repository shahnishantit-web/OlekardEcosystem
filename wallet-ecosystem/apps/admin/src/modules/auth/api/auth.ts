import { apiClient } from '@/lib/api/client';
import type { ApiClient } from '@/lib/api/client';
import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export interface MfaPendingResponse {
  mfa_required: true;
  mfa_token: string;
  methods: Array<'totp' | 'fido2'>;
  expires_in: number;
}

export interface AuthenticatedTokenResponse {
  mfa_required?: false;
  mfa_enrollment_required?: boolean;
  access_token: string;
  refresh_token: string;
  token_type: 'Bearer';
  expires_in: number;
  session_id: string;
}

export type LoginApiResponse = MfaPendingResponse | AuthenticatedTokenResponse;

export interface StepUpChallenge {
  step_up_required: true;
  step_up_token: string;
  methods: Array<'totp' | 'fido2' | 'pin'>;
  expires_at: string;
}

export interface StepUpNotRequired {
  step_up_required: false;
}

export type StepUpInitiateResponse = StepUpChallenge | StepUpNotRequired;

export interface StepUpVerifyResponse {
  step_up_access_token: string;
  scope: string;
  expires_in: number;
}

function unauthClient(client?: ApiClient): ApiClient {
  return client ?? apiClient;
}

// ── Unauthenticated flows (login, MFA verify) ─────────────────────────────────

export async function loginWithPassword(
  email: string,
  password: string,
  client?: ApiClient,
): Promise<LoginApiResponse> {
  const res = await unauthClient(client).post<LoginApiResponse>('/v1/auth/login', {
    identifier: email,
    credential: password,
    credential_type: 'password',
    device_fingerprint: 'admin-web',
  });
  return res.data;
}

export async function verifyMfa(
  mfaToken: string,
  method: 'totp' | 'fido2',
  code: string,
  client?: ApiClient,
): Promise<AuthenticatedTokenResponse> {
  const res = await unauthClient(client).post<AuthenticatedTokenResponse>(
    '/v1/auth/mfa/verify',
    { mfa_token: mfaToken, method, code },
  );
  return res.data;
}

// ── Authenticated flows (step-up — requires Bearer token) ────────────────────

export async function initiateStepUp(
  scope: string,
  client: AuthenticatedApiClient,
): Promise<StepUpInitiateResponse> {
  const res = await client.post<StepUpInitiateResponse>(
    '/v1/auth/step-up/initiate',
    { scope },
  );
  return res.data;
}

export async function verifyStepUp(
  stepUpToken: string,
  method: 'totp' | 'fido2',
  credential: string,
  client: AuthenticatedApiClient,
): Promise<StepUpVerifyResponse> {
  const res = await client.post<StepUpVerifyResponse>(
    '/v1/auth/step-up/verify',
    { step_up_token: stepUpToken, method, credential },
  );
  return res.data;
}
