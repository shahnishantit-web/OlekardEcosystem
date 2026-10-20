import type { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export interface TotpEnrollmentStarted {
  totp_uri: string;
  enrollment_id: string;
  expires_at: string;
}

export interface TotpEnrollmentConfirmed {
  enrolled_at: string;
  backup_codes: string[];
}

export interface Fido2EnrollmentStarted {
  enrollment_id: string;
  challenge: string;
  rp_id: string;
  expires_at: string;
}

export async function initiateTotpEnrollment(
  client: AuthenticatedApiClient,
): Promise<TotpEnrollmentStarted> {
  const res = await client.post<TotpEnrollmentStarted>('/v1/auth/mfa/enroll/totp', {});
  return res.data;
}

export async function confirmTotpEnrollment(
  client: AuthenticatedApiClient,
  enrollmentId: string,
  totpCode: string,
): Promise<TotpEnrollmentConfirmed> {
  const res = await client.post<TotpEnrollmentConfirmed>('/v1/auth/mfa/enroll/totp/confirm', {
    enrollment_id: enrollmentId,
    totp_code: totpCode,
  });
  return res.data;
}

export async function initiateFido2Enrollment(
  client: AuthenticatedApiClient,
): Promise<Fido2EnrollmentStarted> {
  const res = await client.post<Fido2EnrollmentStarted>('/v1/auth/mfa/enroll/fido2', {});
  return res.data;
}

export async function confirmFido2Enrollment(
  client: AuthenticatedApiClient,
  enrollmentId: string,
  credentialId: string,
  publicKey: string,
  signCount: number,
): Promise<void> {
  await client.post('/v1/auth/mfa/enroll/fido2/confirm', {
    enrollment_id: enrollmentId,
    credential_id: credentialId,
    public_key:    publicKey,
    sign_count:    signCount,
  });
}

export async function regenerateRecoveryCodes(
  client: AuthenticatedApiClient,
): Promise<string[]> {
  const res = await client.post<{ recovery_codes: string[] }>('/v1/auth/mfa/recovery-codes', {});
  return res.data.recovery_codes;
}

// ── WebAuthn helpers ──────────────────────────────────────────────────────────

export function isWebAuthnSupported(): boolean {
  return (
    typeof window !== 'undefined' &&
    typeof window.PublicKeyCredential === 'function'
  );
}

/** Convert a base64 string from the server challenge into a Uint8Array */
export function base64ToBuffer(b64: string): ArrayBuffer {
  const binary = atob(b64);
  const buf    = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) {
    buf[i] = binary.charCodeAt(i);
  }
  return buf.buffer;
}

/** Convert an ArrayBuffer back to a base64 string for transmission */
export function bufferToBase64(buffer: ArrayBuffer): string {
  return btoa(String.fromCharCode(...new Uint8Array(buffer)));
}

/** Thin wrapper around navigator.credentials.create so the component can be tested without jsdom WebAuthn stubs. */
export async function createWebAuthnCredential(
  options: CredentialCreationOptions,
): Promise<Credential | null> {
  return navigator.credentials.create(options);
}
