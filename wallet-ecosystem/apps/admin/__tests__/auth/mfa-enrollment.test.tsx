/**
 * ADM-P1-002 — MFA Enrollment & Management component tests.
 * Covers US-P1-002-001 (TOTP), US-P1-002-002 (FIDO2), US-P1-002-003 (recovery codes),
 * US-P1-002-004 (enrollment gate).
 */
import React from 'react';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';

const mockRouterReplace = jest.fn();
const mockRouterPush    = jest.fn();

jest.mock('next/navigation', () => ({
  useRouter:     () => ({ replace: mockRouterReplace, push: mockRouterPush }),
  useSearchParams: () => ({ get: jest.fn(() => null) }),
}));

jest.mock('@/src/modules/auth/api/mfa-enrollment', () => ({
  initiateTotpEnrollment:   jest.fn(),
  confirmTotpEnrollment:    jest.fn(),
  initiateFido2Enrollment:  jest.fn(),
  confirmFido2Enrollment:   jest.fn(),
  regenerateRecoveryCodes:  jest.fn(),
  createWebAuthnCredential: jest.fn(),
  isWebAuthnSupported:      jest.fn(() => true),
  base64ToBuffer:           jest.fn(() => new ArrayBuffer(8)),
  bufferToBase64:           jest.fn(() => 'base64string'),
}));

jest.mock('@/lib/auth/context', () => ({
  useAuth: jest.fn(),
}));

import { MfaEnrollmentPage } from '@/src/modules/auth/pages/mfa-enrollment';
import * as mfaApi from '@/src/modules/auth/api/mfa-enrollment';
import * as authContext from '@/lib/auth/context';

const mockInitiateTotp     = mfaApi.initiateTotpEnrollment   as jest.MockedFunction<typeof mfaApi.initiateTotpEnrollment>;
const mockConfirmTotp      = mfaApi.confirmTotpEnrollment    as jest.MockedFunction<typeof mfaApi.confirmTotpEnrollment>;
const mockInitiateFido2    = mfaApi.initiateFido2Enrollment  as jest.MockedFunction<typeof mfaApi.initiateFido2Enrollment>;
const mockConfirmFido2     = mfaApi.confirmFido2Enrollment   as jest.MockedFunction<typeof mfaApi.confirmFido2Enrollment>;
const mockCreateWebAuthn   = mfaApi.createWebAuthnCredential as jest.MockedFunction<typeof mfaApi.createWebAuthnCredential>;
const mockIsWebAuthn       = mfaApi.isWebAuthnSupported      as jest.MockedFunction<typeof mfaApi.isWebAuthnSupported>;
const mockUseAuth          = authContext.useAuth              as jest.MockedFunction<typeof authContext.useAuth>;

const MOCK_CLIENT = { post: jest.fn() } as unknown as import('@/lib/api/authenticated-client').AuthenticatedApiClient;

const TOTP_STARTED = {
  totp_uri:      'otpauth://totp/OleKard%3Aadmin-user?secret=BASE32SECRET&issuer=OleKard',
  enrollment_id: 'enroll-uuid-001',
  expires_at:    '2026-10-14T00:00:00Z',
};

const TOTP_CONFIRMED = {
  enrolled_at:  '2026-10-13T10:00:00Z',
  backup_codes: ['AA11BB22', 'CC33DD44', 'EE55FF66', 'GG77HH88', 'II99JJ00', 'KK11LL22', 'MM33NN44', 'OO55PP66'],
};

function renderPage() {
  mockUseAuth.mockReturnValue({
    authenticatedClient: MOCK_CLIENT,
    user: { id: 'user-001', email: 'admin@test.com', name: 'Admin' },
  } as ReturnType<typeof authContext.useAuth>);
  return render(<MfaEnrollmentPage />);
}

beforeEach(() => {
  jest.clearAllMocks();
  mockIsWebAuthn.mockReturnValue(true);
});

// ── Render / choose-method ────────────────────────────────────────────────────

describe('choose-method step', () => {
  it('renders the MFA setup heading and method options', () => {
    renderPage();
    expect(screen.getByText(/set up two-factor authentication/i)).toBeInTheDocument();
    expect(screen.getByText(/authenticator app/i)).toBeInTheDocument();
    expect(screen.getByText(/security key or passkey/i)).toBeInTheDocument();
  });

  it('redirects to login when not authenticated', async () => {
    mockUseAuth.mockReturnValue({
      authenticatedClient: null,
      user: null,
    } as ReturnType<typeof authContext.useAuth>);
    render(<MfaEnrollmentPage />);
    await waitFor(() => expect(mockRouterReplace).toHaveBeenCalledWith('/login'));
  });
});

// ── TOTP flow ─────────────────────────────────────────────────────────────────

describe('TOTP enrollment (US-P1-002-001)', () => {
  it('shows the setup key after initiating TOTP enrollment', async () => {
    mockInitiateTotp.mockResolvedValue(TOTP_STARTED);
    renderPage();
    fireEvent.click(screen.getByText(/authenticator app/i));

    await waitFor(() => expect(screen.getByText(/BASE32SECRET/i)).toBeInTheDocument());
    expect(screen.getByText(/add to your authenticator app/i)).toBeInTheDocument();
  });

  it('shows an error if TOTP initiation fails', async () => {
    mockInitiateTotp.mockRejectedValue({ success: false, data: null, error: { code: 'MFA_ERROR', message: 'Enrollment failed', details: [] }, correlation_id: '', meta: null });
    renderPage();
    fireEvent.click(screen.getByText(/authenticator app/i));

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByText(/enrollment failed/i)).toBeInTheDocument();
  });

  it('proceeds to verify step after clicking Next', async () => {
    mockInitiateTotp.mockResolvedValue(TOTP_STARTED);
    renderPage();
    fireEvent.click(screen.getByText(/authenticator app/i));
    await waitFor(() => expect(screen.getByText(/BASE32SECRET/i)).toBeInTheDocument());

    fireEvent.click(screen.getByText(/next — enter verification code/i));
    expect(screen.getByLabelText(/verification code/i)).toBeInTheDocument();
  });

  it('confirms TOTP and shows recovery codes', async () => {
    mockInitiateTotp.mockResolvedValue(TOTP_STARTED);
    mockConfirmTotp.mockResolvedValue(TOTP_CONFIRMED);
    renderPage();

    fireEvent.click(screen.getByText(/authenticator app/i));
    await waitFor(() => screen.getByText(/next — enter verification code/i));
    fireEvent.click(screen.getByText(/next — enter verification code/i));

    const input = screen.getByLabelText(/verification code/i);
    fireEvent.change(input, { target: { value: '123456' } });
    fireEvent.click(screen.getByText(/verify and activate/i));

    await waitFor(() => expect(screen.getByText(/save your recovery codes/i)).toBeInTheDocument());
    expect(screen.getByText('AA11BB22')).toBeInTheDocument();
  });

  it('shows error on wrong TOTP code', async () => {
    mockInitiateTotp.mockResolvedValue(TOTP_STARTED);
    mockConfirmTotp.mockRejectedValue({ success: false, data: null, error: { code: 'IDENTITY_014', message: 'Invalid enrollment ID or TOTP code.', details: [] }, correlation_id: '', meta: null });
    renderPage();

    fireEvent.click(screen.getByText(/authenticator app/i));
    await waitFor(() => screen.getByText(/next — enter verification code/i));
    fireEvent.click(screen.getByText(/next — enter verification code/i));

    const input = screen.getByLabelText(/verification code/i);
    fireEvent.change(input, { target: { value: '000000' } });
    fireEvent.click(screen.getByText(/verify and activate/i));

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByText(/invalid enrollment id or totp code/i)).toBeInTheDocument();
  });
});

// ── Recovery codes (US-P1-002-003) ───────────────────────────────────────────

describe('recovery codes (US-P1-002-003)', () => {
  async function reachRecoveryCodes() {
    mockInitiateTotp.mockResolvedValue(TOTP_STARTED);
    mockConfirmTotp.mockResolvedValue(TOTP_CONFIRMED);
    renderPage();

    fireEvent.click(screen.getByText(/authenticator app/i));
    await waitFor(() => screen.getByText(/next — enter verification code/i));
    fireEvent.click(screen.getByText(/next — enter verification code/i));

    const input = screen.getByLabelText(/verification code/i);
    fireEvent.change(input, { target: { value: '123456' } });
    fireEvent.click(screen.getByText(/verify and activate/i));
    await waitFor(() => screen.getByText(/save your recovery codes/i));
  }

  it('shows all 8 recovery codes', async () => {
    await reachRecoveryCodes();
    TOTP_CONFIRMED.backup_codes.forEach((code) => {
      expect(screen.getByText(code)).toBeInTheDocument();
    });
  });

  it('has a download button', async () => {
    await reachRecoveryCodes();
    expect(screen.getByText(/download recovery codes/i)).toBeInTheDocument();
  });

  it('Continue button is disabled until the checkbox is checked', async () => {
    await reachRecoveryCodes();
    const continueBtn = screen.getByRole('button', { name: /continue/i });
    expect(continueBtn).toBeDisabled();

    fireEvent.click(screen.getByLabelText(/i have saved my recovery codes/i));
    expect(continueBtn).not.toBeDisabled();
  });

  it('proceeds to complete step after checking the checkbox and clicking Continue', async () => {
    Object.defineProperty(document, 'cookie', { writable: true, value: '' });
    await reachRecoveryCodes();

    fireEvent.click(screen.getByLabelText(/i have saved my recovery codes/i));
    fireEvent.click(screen.getByRole('button', { name: /continue/i }));
    expect(screen.getByText(/mfa is now active/i)).toBeInTheDocument();
  });

  it('shows a Regenerate button on the recovery codes screen (US-P1-002-003 AC3)', async () => {
    await reachRecoveryCodes();
    expect(screen.getByRole('button', { name: /regenerate recovery codes/i })).toBeInTheDocument();
  });

  it('regenerates codes and resets checkbox when Regenerate is clicked', async () => {
    const mockRegenerate = mfaApi.regenerateRecoveryCodes as jest.MockedFunction<typeof mfaApi.regenerateRecoveryCodes>;
    mockRegenerate.mockResolvedValue(['NEW001', 'NEW002', 'NEW003', 'NEW004', 'NEW005', 'NEW006', 'NEW007', 'NEW008']);

    await reachRecoveryCodes();
    // Check the checkbox first
    fireEvent.click(screen.getByLabelText(/i have saved my recovery codes/i));
    expect(screen.getByRole('button', { name: /continue/i })).not.toBeDisabled();

    // Click regenerate
    fireEvent.click(screen.getByRole('button', { name: /regenerate recovery codes/i }));

    // New codes appear and checkbox is reset (Continue button disabled again)
    await waitFor(() => expect(screen.getByText('NEW001')).toBeInTheDocument());
    expect(screen.getByRole('button', { name: /continue/i })).toBeDisabled();
  });

  it('shows an error when code regeneration fails', async () => {
    const mockRegenerate = mfaApi.regenerateRecoveryCodes as jest.MockedFunction<typeof mfaApi.regenerateRecoveryCodes>;
    mockRegenerate.mockRejectedValue({ success: false, data: null, error: { code: 'MFA_ERROR', message: 'Regeneration failed', details: [] }, correlation_id: '', meta: null });

    await reachRecoveryCodes();
    fireEvent.click(screen.getByRole('button', { name: /regenerate recovery codes/i }));

    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByText(/regeneration failed/i)).toBeInTheDocument();
  });
});

// ── FIDO2 / WebAuthn (US-P1-002-002) ─────────────────────────────────────────

describe('FIDO2 enrollment (US-P1-002-002)', () => {
  it('shows spec-compliant not-supported message when WebAuthn is unavailable', () => {
    mockIsWebAuthn.mockReturnValue(false);
    renderPage();
    fireEvent.click(screen.getByText(/security key or passkey/i));
    expect(screen.getByText(/passkeys not supported/i)).toBeInTheDocument();
    expect(screen.getByText(/your browser does not support hardware security keys\. use the authenticator app instead\./i)).toBeInTheDocument();
    expect(screen.getByText(/use authenticator app instead/i)).toBeInTheDocument();
  });

  it('shows FIDO2 prompt when WebAuthn is supported', () => {
    mockIsWebAuthn.mockReturnValue(true);
    renderPage();
    fireEvent.click(screen.getByText(/security key or passkey/i));
    expect(screen.getByText(/register your security key or passkey/i)).toBeInTheDocument();
    expect(screen.getByText(/register passkey or security key/i)).toBeInTheDocument();
  });

  it('calls confirmFido2Enrollment with correct args after WebAuthn credential creation', async () => {
    mockIsWebAuthn.mockReturnValue(true);
    mockInitiateFido2.mockResolvedValue({
      enrollment_id: 'fido2-enroll-001',
      challenge:     'dGVzdGNoYWxsZW5nZQ==',
      rp_id:         'localhost',
      expires_at:    '2026-10-14T00:00:00Z',
    });
    mockCreateWebAuthn.mockResolvedValue({
      type:     'public-key',
      rawId:    new ArrayBuffer(32),
      response: { attestationObject: new ArrayBuffer(64) },
    } as unknown as Credential);
    mockConfirmFido2.mockResolvedValue(undefined);
    Object.defineProperty(document, 'cookie', { writable: true, value: '' });

    renderPage();
    fireEvent.click(screen.getByText(/security key or passkey/i));
    fireEvent.click(screen.getByText(/register passkey or security key/i));

    await waitFor(() => expect(mockConfirmFido2).toHaveBeenCalledWith(
      MOCK_CLIENT,
      'fido2-enroll-001',
      'base64string',
      'base64string',
      0,
    ));
  });

  it('calls createWebAuthnCredential and then shows the enrollment result', async () => {
    mockIsWebAuthn.mockReturnValue(true);
    mockInitiateFido2.mockResolvedValue({
      enrollment_id: 'fido2-enroll-003',
      challenge:     'dGVzdA==',
      rp_id:         'localhost',
      expires_at:    '2026-10-14T00:00:00Z',
    });
    mockCreateWebAuthn.mockResolvedValue({
      type:     'public-key',
      rawId:    new ArrayBuffer(32),
      response: { attestationObject: new ArrayBuffer(64) },
    } as unknown as Credential);
    mockConfirmFido2.mockResolvedValue(undefined);
    Object.defineProperty(document, 'cookie', { writable: true, value: '' });

    renderPage();
    fireEvent.click(screen.getByText(/security key or passkey/i));
    fireEvent.click(screen.getByText(/register passkey or security key/i));

    // Verify the WebAuthn credential creation was triggered
    await waitFor(() => expect(mockCreateWebAuthn).toHaveBeenCalledWith(
      expect.objectContaining({ publicKey: expect.objectContaining({ attestation: 'none' }) }),
    ));
  });

  it('sets error state when WebAuthn credential creation is cancelled', async () => {
    mockIsWebAuthn.mockReturnValue(true);
    mockInitiateFido2.mockResolvedValue({
      enrollment_id: 'fido2-enroll-004',
      challenge:     'dGVzdA==',
      rp_id:         'localhost',
      expires_at:    '2026-10-14T00:00:00Z',
    });
    mockCreateWebAuthn.mockRejectedValue(new DOMException('User cancelled', 'NotAllowedError'));

    renderPage();
    fireEvent.click(screen.getByText(/security key or passkey/i));
    fireEvent.click(screen.getByText(/register passkey or security key/i));

    // Wait for createWebAuthnCredential to be called (rejection triggers error state)
    await waitFor(() => expect(mockCreateWebAuthn).toHaveBeenCalled());
    await waitFor(() => expect(screen.getByRole('alert')).toBeInTheDocument());
    expect(screen.getByText(/registration was cancelled\. please try again\./i)).toBeInTheDocument();
  });
});

// ── Complete step ─────────────────────────────────────────────────────────────

describe('complete step', () => {
  it('navigates to dashboard on completion', async () => {
    mockInitiateTotp.mockResolvedValue(TOTP_STARTED);
    mockConfirmTotp.mockResolvedValue(TOTP_CONFIRMED);

    Object.defineProperty(document, 'cookie', { writable: true, value: '' });
    renderPage();

    fireEvent.click(screen.getByText(/authenticator app/i));
    await waitFor(() => screen.getByText(/next — enter verification code/i));
    fireEvent.click(screen.getByText(/next — enter verification code/i));

    const input = screen.getByLabelText(/verification code/i);
    fireEvent.change(input, { target: { value: '123456' } });
    fireEvent.click(screen.getByText(/verify and activate/i));
    await waitFor(() => screen.getByText(/save your recovery codes/i));

    fireEvent.click(screen.getByLabelText(/i have saved my recovery codes/i));
    fireEvent.click(screen.getByRole('button', { name: /continue/i }));
    fireEvent.click(screen.getByText(/go to dashboard/i));

    expect(mockRouterReplace).toHaveBeenCalledWith('/dashboard');
  });
});
