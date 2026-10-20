import { apiClient } from '@/lib/api/client';
import type { ApiClient } from '@/lib/api/client';

function unauthClient(client?: ApiClient): ApiClient {
  return client ?? apiClient;
}

// ── Forgot-password ──────────────────────────────────────────────────────────

export interface ForgotPasswordResponse {
  message: string;
}

export async function requestPasswordReset(
  email: string,
  client?: ApiClient,
): Promise<ForgotPasswordResponse> {
  const res = await unauthClient(client).post<ForgotPasswordResponse>(
    '/v1/admin/auth/forgot-password',
    { email },
  );
  return res.data;
}

// ── Reset-password ───────────────────────────────────────────────────────────

export interface ResetPasswordResponse {
  message: string;
}

export async function resetPassword(
  token: string,
  password: string,
  client?: ApiClient,
): Promise<ResetPasswordResponse> {
  const res = await unauthClient(client).post<ResetPasswordResponse>(
    '/v1/admin/auth/reset-password',
    { token, password, password_confirmation: password },
  );
  return res.data;
}

// ── Email verification ───────────────────────────────────────────────────────

export interface VerifyEmailResponse {
  message: string;
}

export async function verifyEmail(
  token: string,
  client?: ApiClient,
): Promise<VerifyEmailResponse> {
  const res = await unauthClient(client).post<VerifyEmailResponse>(
    '/v1/admin/auth/verify-email',
    { token },
  );
  return res.data;
}

export async function resendVerification(
  email: string,
  client?: ApiClient,
): Promise<void> {
  await unauthClient(client).post<ForgotPasswordResponse>(
    '/v1/admin/auth/resend-verification',
    { email },
  );
}
