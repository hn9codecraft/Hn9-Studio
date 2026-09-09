import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { onUnauthorized } from '../services/apiClient';
import { fetchCurrentUser, login as loginRequest, logout as logoutRequest } from '../services/authService';
import { clearToken, getToken } from '../services/tokenStorage';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null);
  const [bootstrapping, setBootstrapping] = useState(true);

  const clearSession = useCallback(() => {
    clearToken();
    setUser(null);
  }, []);

  useEffect(() => {
    onUnauthorized(() => {
      setUser(null);
    });

    return () => onUnauthorized(null);
  }, []);

  useEffect(() => {
    let cancelled = false;

    async function restoreSession() {
      if (!getToken()) {
        if (!cancelled) {
          setBootstrapping(false);
        }
        return;
      }

      try {
        const currentUser = await fetchCurrentUser();
        if (!cancelled) {
          setUser(currentUser);
        }
      } catch {
        if (!cancelled) {
          clearSession();
        }
      } finally {
        if (!cancelled) {
          setBootstrapping(false);
        }
      }
    }

    restoreSession();

    return () => {
      cancelled = true;
    };
  }, [clearSession]);

  const login = useCallback(async (email, password) => {
    const data = await loginRequest(email, password);
    const currentUser = data.user || (await fetchCurrentUser());
    setUser(currentUser);
    return currentUser;
  }, []);

  const logout = useCallback(async () => {
    try {
      await logoutRequest();
    } finally {
      setUser(null);
    }
  }, []);

  const applyUser = useCallback((nextUser) => {
    setUser(nextUser);
  }, []);

  const value = useMemo(
    () => ({
      user,
      bootstrapping,
      isAuthenticated: Boolean(user),
      login,
      logout,
      applyUser,
    }),
    [user, bootstrapping, login, logout, applyUser],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }

  return context;
}
