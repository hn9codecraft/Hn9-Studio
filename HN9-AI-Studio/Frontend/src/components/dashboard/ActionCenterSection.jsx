import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { getDashboardActions } from '../../services/dashboardService';

const EMPTY = { total: 0, limit: 50, items: [] };

const MODULES = [
  { value: '', label: 'All modules' },
  { value: 'project', label: 'Project' },
  { value: 'script', label: 'Script' },
  { value: 'image', label: 'Image' },
  { value: 'video', label: 'Video' },
  { value: 'asset', label: 'Asset' },
];

const STATUSES = [
  { value: '', label: 'All statuses' },
  { value: 'failed', label: 'Failed' },
  { value: 'pending', label: 'Pending' },
  { value: 'processing', label: 'Processing' },
  { value: 'draft', label: 'Draft' },
];

export default function ActionCenterSection() {
  const [actions, setActions] = useState(EMPTY);
  const [module, setModule] = useState('');
  const [status, setStatus] = useState('');
  const [project, setProject] = useState('');
  const [applied, setApplied] = useState({ module: '', status: '', project: '' });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');

      try {
        const result = await getDashboardActions(applied);
        if (!cancelled) {
          setActions(result);
        }
      } catch (err) {
        if (!cancelled) {
          setActions(EMPTY);
          setError(err instanceof ApiError ? err.message : 'Unable to load action center.');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    load();

    return () => {
      cancelled = true;
    };
  }, [applied]);

  function applyFilters(event) {
    event.preventDefault();
    setApplied({ module, status, project: project.trim() });
  }

  return (
    <section className="page-section action-center-section">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <h2 className="section-title mb-1">Items that need attention</h2>
          <p className="page-lede mb-0">
            Built from real project and content statuses. Failed is high, pending or processing is medium, draft is
            low. Empty means nothing currently needs work.
          </p>
        </div>
        <form className="filter-bar" onSubmit={applyFilters}>
          <div>
            <label className="form-label small mb-1" htmlFor="actions-module">
              Module
            </label>
            <select id="actions-module" className="form-select" value={module} onChange={(event) => setModule(event.target.value)}>
              {MODULES.map((option) => (
                <option key={option.value || 'all'} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="form-label small mb-1" htmlFor="actions-status">
              Status
            </label>
            <select id="actions-status" className="form-select" value={status} onChange={(event) => setStatus(event.target.value)}>
              {STATUSES.map((option) => (
                <option key={option.value || 'all'} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </div>
          <div>
            <label className="form-label small mb-1" htmlFor="actions-project">
              Project UUID
            </label>
            <input
              id="actions-project"
              className="form-control"
              value={project}
              onChange={(event) => setProject(event.target.value)}
              placeholder="Optional"
            />
          </div>
          <button className="btn btn-outline-primary" type="submit">
            Apply
          </button>
        </form>
      </div>

      {error ? (
        <div className="mb-3">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {loading ? <LoadingSpinner label="Loading action center…" /> : null}

      {!loading && !error && actions.total < 1 ? (
        <EmptyState
          icon="bi-check2-circle"
          title="No items need attention right now."
          description="Failed, pending, processing, and draft studio records will appear here when they exist."
        />
      ) : null}

      {!loading && !error && actions.total > 0 ? (
        <>
          <p className="small text-secondary mb-3">
            {actions.total === 1 ? '1 item needs attention.' : `${actions.total} items need attention.`}
            {actions.total > actions.items.length
              ? ` Showing the first ${actions.items.length}.`
              : ''}
          </p>
          <div className="card border-0 attention-table-wrap">
            <table className="table attention-table studio-table align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col">Priority</th>
                  <th scope="col">Module</th>
                  <th scope="col">Item</th>
                  <th scope="col">Status</th>
                  <th scope="col">Project</th>
                  <th scope="col">Action</th>
                </tr>
              </thead>
              <tbody>
                {actions.items.map((item) => (
                  <tr key={`${item.module}-${item.id}`}>
                    <td data-label="Priority">
                      <span className={`action-priority-badge action-priority-${item.priority}`}>{item.priority}</span>
                    </td>
                    <td data-label="Module" className="text-capitalize">
                      {item.module}
                    </td>
                    <td data-label="Item">
                      <span className="attention-title">{item.title}</span>
                    </td>
                    <td data-label="Status">
                      <span className={`status-pill status-${item.status || 'draft'}`}>{item.status}</span>
                    </td>
                    <td data-label="Project">{item.project.name}</td>
                    <td data-label="Action">
                      {item.action_url ? (
                        <Link className="btn btn-sm btn-outline-primary" to={item.action_url}>
                          Open
                        </Link>
                      ) : (
                        <span className="text-secondary">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      ) : null}
    </section>
  );
}
