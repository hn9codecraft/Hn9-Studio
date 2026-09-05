import { useState } from 'react';
import { useAuth } from '../../contexts/AuthContext';

export default function Header({ title }) {
  const { user, logout } = useAuth();
  const [loggingOut, setLoggingOut] = useState(false);

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
      <div className="d-flex align-items-center gap-3">
        <button
          className="btn btn-outline-secondary d-lg-none"
          type="button"
          data-bs-toggle="offcanvas"
          data-bs-target="#mobileSidebar"
          aria-controls="mobileSidebar"
        >
          <i className="bi bi-list" aria-hidden="true" />
          <span className="visually-hidden">Open navigation</span>
        </button>
        <div>
          <h1 className="h4 mb-0">{title}</h1>
        </div>
      </div>

      <div className="d-flex align-items-center gap-3">
        <div className="text-end d-none d-sm-block">
          <div className="fw-semibold">{user?.name || 'Signed in'}</div>
          <div className="small text-secondary">{user?.email}</div>
        </div>
        <button className="btn btn-outline-primary" type="button" onClick={handleLogout} disabled={loggingOut}>
          {loggingOut ? 'Signing out…' : 'Log out'}
        </button>
      </div>
    </header>
  );
}
