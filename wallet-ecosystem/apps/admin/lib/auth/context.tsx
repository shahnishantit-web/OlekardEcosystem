'use client';

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useRef,
  useState,
  type ReactNode,
} from 'react';
import { loginWithPassword, verifyMfa } from '@/src/modules/auth/api/auth';
import type { MfaPendingResponse } from '@/src/modules/auth/api/auth';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';
import { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import { tokenCookieParts, TOKEN_COOKIE_NAMES } from '@/lib/auth/token-cookies';

export interface ActorDTO {
  id: string;
  email: string;
  name: string;
}

export type MfaMethod = 'totp' | 'fido2';

export type AuthPhase =
  | { kind: 'unauthenticated'; error?: string; errorCode?: string }
  | { kind: 'mfa_pending'; mfaToken: string; methods: MfaMethod[]; error?: string }
  | { kind: 'authenticated'; user: ActorDTO; sessionId: string };

export interface AuthContextValue {
  phase: AuthPhase['kind'];
  user: ActorDTO | null;
  mfaMethods: MfaMethod[] | null;
  isAuthenticated: boolean;
  authError: string | null;
  authErrorCode: string | null;
  authenticatedClient: AuthenticatedApiClient | null;
  permissions: string[];
  hasPermission: (slug: string) => boolean;
  login: (email: string, password: string) => Promise<void>;
  completeMfa: (method: MfaMethod, code: string) => Promise<void>;
  signOut: () => void;
}

// Decode the `permissions` claim from a JWT payload without signature verification.
// Used for client-side nav filtering only — server-side API enforces real authorization.
function decodeJwtPermissions(token: string): string[] {
  try {
    const part = token.split('.')[1];
    if (!part) return [];
    const b64 = part.replace(/-/g, '+').replace(/_/g, '/');
    const payload: unknown = JSON.parse(atob(b64));
    if (payload && typeof payload === 'object' && 'permissions' in payload) {
      const p = (payload as Record<string, unknown>).permissions;
      return Array.isArray(p) ? (p as string[]) : [];
    }
    return [];
  } catch {
    return [];
  }
}

function decodeJwtSub(token: string): string {
  try {
    const part = token.split('.')[1];
    if (!part) return '';
    const b64 = part.replace(/-/g, '+').replace(/_/g, '/');
    const payload: unknown = JSON.parse(atob(b64));
    if (payload && typeof payload === 'object' && 'sub' in payload) {
      const sub = (payload as Record<string, unknown>).sub;
      return typeof sub === 'string' ? sub : '';
    }
    return '';
  } catch {
    return '';
  }
}

// ── Session cookie helpers ──────────────────────────────────────────────────
// admin_token       — read by middleware for route protection; max-age = expires_in
// admin_user        — JSON-serialised ActorDTO; lets the server layout restore React
//                     state without a /me round-trip; max-age = expires_in
// admin_sid         — session ID used by the session-management pages
// Permissions are decoded from the same token; no duplicate permission cookie.

function writeCookie(name: string, value: string, maxAge: number): void {
  if (typeof document === 'undefined') return;
  document.cookie =
    `${name}=${encodeURIComponent(value)}; path=/; max-age=${maxAge}; SameSite=Lax`;
}

function clearCookie(name: string): void {
  if (typeof document === 'undefined') return;
  document.cookie = `${name}=; path=/; max-age=0; SameSite=Lax`;
}

function saveSession(
  token: string,
  user: ActorDTO,
  sessionId: string,
  expiresIn: number,
): void {
  const parts = tokenCookieParts(token);
  TOKEN_COOKIE_NAMES.forEach(clearCookie);
  parts.forEach(part => writeCookie(part.name, part.value, expiresIn));
  writeCookie('admin_user', JSON.stringify(user), expiresIn);
  writeCookie('admin_sid', sessionId, expiresIn);
  clearCookie('admin_permissions'); // Remove the obsolete, oversized duplicate.
}

function clearSession(): void {
  TOKEN_COOKIE_NAMES.forEach(clearCookie);
  clearCookie('admin_user');
  clearCookie('admin_sid');
  clearCookie('admin_permissions');
  clearCookie('admin_mfa_setup');
}

// ── Context default ─────────────────────────────────────────────────────────

const AuthContext = createContext<AuthContextValue>({
  phase: 'unauthenticated',
  user: null,
  mfaMethods: null,
  isAuthenticated: false,
  authError: null,
  authErrorCode: null,
  authenticatedClient: null,
  permissions: [],
  hasPermission: () => false,
  login: async () => {},
  completeMfa: async () => {},
  signOut: () => {},
});

// ── Provider ─────────────────────────────────────────────────────────────────

interface AuthProviderProps {
  children: ReactNode;
  /** Pre-populated by app/layout.tsx (server component) reading cookies via
   *  next/headers so server render and client hydration start in the same state. */
  initialAccessToken?: string;
  initialUser?: ActorDTO | null;
  initialSessionId?: string;
  /** Used by tests that need to inject an explicit starting phase. */
  initialPhase?: AuthPhase;
}

export function AuthProvider({
  children,
  initialAccessToken,
  initialUser,
  initialSessionId,
  initialPhase,
}: AuthProviderProps) {
  const [state, setState] = useState<AuthPhase>(() => {
    if (initialPhase) return initialPhase;
    if (initialAccessToken && initialUser) {
      return { kind: 'authenticated', user: initialUser, sessionId: initialSessionId ?? '' };
    }
    return { kind: 'unauthenticated' };
  });

  const [accessToken, setAccessToken] = useState<string | null>(
    initialAccessToken ?? null,
  );

  const [permissions, setPermissions] = useState<string[]>(() =>
    initialAccessToken ? decodeJwtPermissions(initialAccessToken) : [],
  );

  // Carries the email through the two-step credentials → MFA flow so that
  // completeMfa can populate user.email (the MFA verify response doesn't
  // include it).
  const pendingEmailRef = useRef('');

  const login = useCallback(async (email: string, password: string): Promise<void> => {
    try {
      const response = await loginWithPassword(email, password);
      if (response.mfa_required) {
        const mfaResponse = response as MfaPendingResponse;
        pendingEmailRef.current = email;
        setState({
          kind: 'mfa_pending',
          mfaToken: mfaResponse.mfa_token,
          methods: mfaResponse.methods,
        });
      } else {
        const userId = decodeJwtSub(response.access_token);
        const user: ActorDTO = { id: userId, email, name: email };
        const userPerms = decodeJwtPermissions(response.access_token);
        setAccessToken(response.access_token);
        setPermissions(userPerms);
        saveSession(response.access_token, user, response.session_id, response.expires_in);
        if (response.mfa_enrollment_required) {
          writeCookie('admin_mfa_setup', 'required', response.expires_in);
        }
        setState({ kind: 'authenticated', user, sessionId: response.session_id });
      }
    } catch (err) {
      const code = isApiError(err) ? (err as ApiError).error.code : undefined;
      const message = isApiError(err)
        ? (err as ApiError).error.message
        : 'Authentication failed. Please try again.';
      setState({
        kind: 'unauthenticated',
        error: message,
        ...(code !== undefined ? { errorCode: code } : {}),
      });
    }
  }, []);

  const completeMfa = useCallback(async (method: MfaMethod, code: string): Promise<void> => {
    if (state.kind !== 'mfa_pending') return;
    try {
      const response = await verifyMfa(state.mfaToken, method, code);
      const email = pendingEmailRef.current;
      const userId = decodeJwtSub(response.access_token);
      const user: ActorDTO = { id: userId, email, name: email };
      const userPerms = decodeJwtPermissions(response.access_token);
      setAccessToken(response.access_token);
      setPermissions(userPerms);
      saveSession(response.access_token, user, response.session_id, response.expires_in);
      setState({ kind: 'authenticated', user, sessionId: response.session_id });
    } catch (err) {
      const message = isApiError(err)
        ? (err as ApiError).error.message
        : 'MFA verification failed. Please check your code.';
      setState((prev) =>
        prev.kind === 'mfa_pending' ? { ...prev, error: message } : prev,
      );
    }
  }, [state]);

  const signOut = useCallback(() => {
    setAccessToken(null);
    setPermissions([]);
    clearSession();
    pendingEmailRef.current = '';
    setState({ kind: 'unauthenticated' });
  }, []);

  const authenticatedClient = useMemo(
    () => (accessToken ? new AuthenticatedApiClient(accessToken, undefined, signOut) : null),
    [accessToken, signOut],
  );

  const isAuthenticated = state.kind === 'authenticated';

  const hasPermission = useCallback(
    (slug: string): boolean =>
      isAuthenticated && (permissions.includes(slug) || permissions.includes('adminapp.*')),
    [isAuthenticated, permissions],
  );

  const value = useMemo<AuthContextValue>(
    () => ({
      phase: state.kind,
      user: isAuthenticated ? state.user : null,
      mfaMethods: state.kind === 'mfa_pending' ? state.methods : null,
      isAuthenticated,
      authError: state.kind !== 'authenticated' ? (state.error ?? null) : null,
      authErrorCode:
        state.kind === 'unauthenticated' ? (state.errorCode ?? null) : null,
      authenticatedClient,
      permissions,
      hasPermission,
      login,
      completeMfa,
      signOut,
    }),
    [state, isAuthenticated, authenticatedClient, permissions, hasPermission, login, completeMfa, signOut],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  return useContext(AuthContext);
}
