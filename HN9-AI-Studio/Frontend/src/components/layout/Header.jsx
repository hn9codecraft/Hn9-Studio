import { useState } from 'react';
import { useAuth } from '../../contexts/AuthContext';
import ThemeToggle from './ThemeToggle';

export default function Header({ title }) {
  const { user, logout } = useAuth();
  const [loggingOut, setLoggingOut] = useState(false);
  const initials = userInitials(user?.name);

  async function handleLogout() {
    setLoggingOut(true);
    try {
      await logout();
    } finally {
      setLoggingOut(false);
    }
  }

  return (
    <header className="app-header d-flex align-items-center justify-content-between gap-3">
      <div className="d-flex align-items-center gap-3 min-w-0">
        <button
          className="btn btn-ghost d-lg-none"
          type="button"
          data-bs-toggle="offcanvas"
          data-bs-target="#mobileSidebar"
          aria-controls="mobileSidebar"
        >
          <i className="bi bi-list" aria-hidden="true" />
          <span className="visually-hidden">Open navigation</span>
        </button>
        <div className="min-w-0">
          <p className="header-title mb-0" aria-hidden="true">
            {title}
          </p>
        </div>
      </div>

      <div className="d-flex align-items-center gap-2 gap-md-3">
        <ThemeToggle />
        <div className="header-user d-none d-sm-flex align-items-center gap-2">
          <span className="header-avatar" aria-hidden="true">
            {initials}
          </span>
          <div className="text-end">
            <div className="header-user-name">{user?.name || 'Signed in'}</div>
            <div className="header-user-email">{user?.email}</div>
          </div>
        </div>
        <button className="btn btn-ghost" type="button" onClick={handleLogout} disabled={loggingOut} aria-label={loggingOut ? 'Signing out' : 'Log out'}>
          {loggingOut ? 'Signing out…' : 'Log out'}
        </button>
      </div>
    </header>
  );
}

function userInitials(name) {
  if (!name) {
    return 'HN';
  }

  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() || '')
    .join('');
}
