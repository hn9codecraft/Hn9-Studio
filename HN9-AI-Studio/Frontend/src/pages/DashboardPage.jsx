import { useAuth } from '../contexts/AuthContext';
import { getApiBaseUrl } from '../services/apiClient';

export default function DashboardPage() {
  const { user } = useAuth();

  return (
    <div className="row g-4">
      <div className="col-12">
        <div className="card border-0 shadow-sm">
          <div className="card-body p-4">
            <p className="text-uppercase small text-secondary mb-2">Authenticated session</p>
            <h2 className="h4 mb-2">Welcome back, {user?.name || 'there'}</h2>
            <p className="text-secondary mb-0">
              You are signed in against the live Laravel API. This dashboard does not invent usage, costs, or
              generation totals.
            </p>
          </div>
        </div>
      </div>

      <div className="col-md-6">
        <div className="card border-0 shadow-sm h-100">
          <div className="card-body p-4">
            <h3 className="h6 text-secondary text-uppercase mb-3">Current user</h3>
            <dl className="row mb-0">
              <dt className="col-sm-4">Name</dt>
              <dd className="col-sm-8">{user?.name}</dd>
              <dt className="col-sm-4">Email</dt>
              <dd className="col-sm-8">{user?.email}</dd>
              <dt className="col-sm-4">Role</dt>
              <dd className="col-sm-8">{user?.role || '—'}</dd>
              <dt className="col-sm-4">Status</dt>
              <dd className="col-sm-8">{user?.status || '—'}</dd>
            </dl>
          </div>
        </div>
      </div>

      <div className="col-md-6">
        <div className="card border-0 shadow-sm h-100">
          <div className="card-body p-4">
            <h3 className="h6 text-secondary text-uppercase mb-3">API connection</h3>
            <p className="mb-2">
              <span className="badge text-bg-success">Live</span>
            </p>
            <p className="small text-secondary mb-0">
              Base URL: <code>{getApiBaseUrl()}</code>
            </p>
          </div>
        </div>
      </div>
    </div>
  );
}
