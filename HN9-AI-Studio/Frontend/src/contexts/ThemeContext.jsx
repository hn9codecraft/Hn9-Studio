import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

export const THEME_STORAGE_KEY = 'hn9-theme';

export function readStoredTheme() {
  try {
    return window.localStorage.getItem(THEME_STORAGE_KEY) === 'dark' ? 'dark' : 'light';
  } catch {
    return 'light';
  }
}

export function applyTheme(theme) {
  const next = theme === 'dark' ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', next);
  document.documentElement.style.colorScheme = next;
  return next;
}

const ThemeContext = createContext(null);

export function ThemeProvider({ children }) {
  const [theme, setTheme] = useState(() => applyTheme(readStoredTheme()));

  useEffect(() => {
    applyTheme(theme);
    try {
      window.localStorage.setItem(THEME_STORAGE_KEY, theme);
    } catch {
      // Persistence is best-effort; theme still applies for this session.
    }
  }, [theme]);

  const toggleTheme = useCallback(() => {
    setTheme((current) => (current === 'dark' ? 'light' : 'dark'));
  }, []);

  const value = useMemo(
    () => ({
      theme,
      isDark: theme === 'dark',
      toggleTheme,
    }),
    [theme, toggleTheme],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme() {
  const context = useContext(ThemeContext);

  if (!context) {
    throw new Error('useTheme must be used within ThemeProvider');
  }

  return context;
}
