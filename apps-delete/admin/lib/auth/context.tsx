'use client';

import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { loginWithPassword, verifyMfa } from '@/src/modules/auth/api/auth';
import type { MfaPendingResponse } from '@/src/modules/auth/api/auth';
import { isApiError } from '@/lib/utils/errors';
import type { ApiError } from '@/lib/utils/errors';
import { AuthenticatedApiClient } from '@/lib/api/authenticated-client';

export interface ActorDTO {
  id: string;
  email: string;
  name: string;
}

export type MfaMethod = 'totp' | 'fido2';

export type AuthPhase =
  | { kind: 'unauthenticated'; error?: string }
  | { kind: 'mfa_pending'; mfaToken: string; methods: MfaMethod[]; error?: string }
  | { kind: 'authenticated'; user: ActorDTO; sessionId: string };

export interface AuthContextValue {
  phase: AuthPhase['kind'];
  user: ActorDTO | null;
  mfaMethods: MfaMethod[] | null;
  isAuthenticated: boolean;
  authError: string | null;
  authenticatedClient: AuthenticatedApiClient | null;
  login: (email: string, password: string) => Promise<void>;
  completeMfa: (method: MfaMethod, code: string) => Promise<void>;
  signOut: () => void;
}

function setAuthCookie(token: string | null): void {
  if (typeof document === 'undefined') return;
  if (token) {
    document.cookie = `admin_token=${token}; path=/; SameSite=Lax`;
  } else {
    document.cookie = 'admin_token=; path=/; max-age=0; SameSite=Lax';
  }
}

const AuthContext = createContext<AuthContextValue>({
  phase: 'unauthenticated',
  user: null,
  mfaMethods: null,
  isAuthenticated: false,
  authError: null,
  authenticatedClient: null,
  login: async () => {},
  completeMfa: async () => {},
  signOut: () => {},
});

interface AuthProviderProps {
  children: ReactNode;
  initialPhase?: AuthPhase;
  initialAccessToken?: string;
}

export function AuthProvider({ children, initialPhase, initialAccessToken }: AuthProviderProps) {
  const [state, setState] = useState<AuthPhase>(
    initialPhase ?? { kind: 'unauthenticated' },
  );
  const [accessToken, setAccessToken] = useState<string | null>(
    initialAccessToken ?? null,
  );

  const login = useCallback(async (email: string, password: string): Promise<void> => {
    try {
      const response = await loginWithPassword(email, password);
      if (response.mfa_required) {
        const mfaResponse = response as MfaPendingResponse;
        setState({
          kind: 'mfa_pending',
          mfaToken: mfaResponse.mfa_token,
          methods: mfaResponse.methods,
        });
      } else {
        setAccessToken(response.access_token);
        setAuthCookie(response.access_token);
        setState({
          kind: 'authenticated',
          user: { id: '', email, name: email },
          sessionId: response.session_id,
        });
      }
    } catch (err) {
      const message = isApiError(err)
        ? (err as ApiError).error.message
        : 'Authentication failed. Please try again.';
      setState({ kind: 'unauthenticated', error: message });
    }
  }, []);

  const completeMfa = useCallback(async (method: MfaMethod, code: string): Promise<void> => {
    if (state.kind !== 'mfa_pending') return;
    try {
      const response = await verifyMfa(state.mfaToken, method, code);
      setAccessToken(response.access_token);
      setAuthCookie(response.access_token);
      setState({
        kind: 'authenticated',
        user: { id: '', email: '', name: '' },
        sessionId: response.session_id,
      });
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
    setAuthCookie(null);
    setState({ kind: 'unauthenticated' });
  }, []);

  const authenticatedClient = useMemo(
    () => (accessToken ? new AuthenticatedApiClient(accessToken) : null),
    [accessToken],
  );

  const value = useMemo<AuthContextValue>(() => ({
    phase: state.kind,
    user: state.kind === 'authenticated' ? state.user : null,
    mfaMethods: state.kind === 'mfa_pending' ? state.methods : null,
    isAuthenticated: state.kind === 'authenticated',
    authError: state.kind !== 'authenticated' ? (state.error ?? null) : null,
    authenticatedClient,
    login,
    completeMfa,
    signOut,
  }), [state, authenticatedClient, login, completeMfa, signOut]);

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  return useContext(AuthContext);
}
