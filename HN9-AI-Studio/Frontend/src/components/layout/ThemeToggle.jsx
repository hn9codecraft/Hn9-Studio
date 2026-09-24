import { useTheme } from '../../contexts/ThemeContext';

export default function ThemeToggle({ className = '' }) {
  const { isDark, toggleTheme } = useTheme();

  return (
    <button
      type="button"
      className={`theme-toggle ${className}`.trim()}
      onClick={toggleTheme}
      aria-pressed={isDark}
      aria-label={isDark ? 'Switch to light mode' : 'Switch to dark mode'}
      title={isDark ? 'Light mode' : 'Dark mode'}
    >
      <i className={`bi ${isDark ? 'bi-sun-fill' : 'bi-moon-stars-fill'}`} aria-hidden="true" />
    </button>
  );
}
