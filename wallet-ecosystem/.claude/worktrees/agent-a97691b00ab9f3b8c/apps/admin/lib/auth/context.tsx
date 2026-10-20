'use client';

import React, { createContext, useContext, useState } from 'react';

export interface ActorDTO {
  id: string;
  type: 'user' | 'service' | 'system';
}

export interface AuthContextValue {
  user: ActorDTO | null;
  isAuthenticated: boolean;
  signOut: () => void;
}

const AuthContext = createContext<AuthContextValue>({
  user: null,
  isAuthenticated: false,
  signOut: () => {},
});

export function AuthProvider({ children }: { children: React.ReactNode }) {
  // Stub: always returns unauthenticated. E01 wires real implementation.
  const [user] = useState<ActorDTO | null>(null);

  const signOut = () => {};

  return (
    <AuthContext.Provider value={{ user, isAuthenticated: false, signOut }}>
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthContextValue {
  return useContext(AuthContext);
}

export { AuthContext };
