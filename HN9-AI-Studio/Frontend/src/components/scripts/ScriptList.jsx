import { Link } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';
import { formatProjectDate } from '../../services/projectConstants';
import { scriptStatusLabel } from '../../services/scriptConstants';

export default function ScriptList({ projectId, scripts, loading, error, meta }) {
  if (loading) {
    return <LoadingSpinner label="Loading scripts…" />;
  }

  return (
    <div className="script-list">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <p className="section-kicker mb-1">Script Studio</p>
          <h2 className="section-title mb-1">Scripts</h2>
          <p className="text-secondary mb-0">Manual drafts for this project, stored in the database.</p>
        </div>
        <Link className="btn btn-primary" to={`/projects/${projectId}/scripts/new`}>
          <i className="bi bi-plus-lg me-2" aria-hidden="true" />
          New Script
        </Link>
      </div>

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {!error && scripts.length === 0 ? (
        <EmptyState
          icon="bi-file-text"
          title="No scripts yet"
          description="Create a script to open the studio editor. AI generation is not part of this milestone."
        >
          <Link className="btn btn-primary" to={`/projects/${projectId}/scripts/new`}>
            New Script
          </Link>
        </EmptyState>
      ) : null}

      {scripts.length > 0 ? (
        <>
          <div className="d-none d-md-block">
            <div className="card border-0 shadow-sm studio-table-card">
              <div className="table-responsive">
                <table className="table studio-table mb-0 align-middle">
                  <thead>
                    <tr>
                      <th scope="col">Title</th>
                      <th scope="col">Status</th>
                      <th scope="col">Updated</th>
                      <th scope="col">Created</th>
                      <th scope="col">
                        <span className="visually-hidden">Actions</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {scripts.map((script) => (
                      <tr key={script.id}>
                        <td>
                          <Link to={`/projects/${projectId}/scripts/${script.id}`} className="fw-semibold text-decoration-none">
                            {script.title}
                          </Link>
                        </td>
                        <td>
                          <span className={`status-pill status-${script.status || 'draft'}`}>
                            {scriptStatusLabel(script.status)}
                          </span>
                        </td>
                        <td className="text-secondary">{formatProjectDate(script.updated_at)}</td>
                        <td className="text-secondary">{formatProjectDate(script.created_at)}</td>
                        <td className="text-end">
                          <Link className="btn btn-sm btn-outline-primary" to={`/projects/${projectId}/scripts/${script.id}`}>
                            Open
                          </Link>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div className="d-md-none row g-3">
            {scripts.map((script) => (
              <div className="col-12" key={script.id}>
                <Link to={`/projects/${projectId}/scripts/${script.id}`} className="project-card card border-0 shadow-sm text-decoration-none">
                  <div className="card-body p-4">
                    <div className="d-flex justify-content-between align-items-start gap-3 mb-2">
                      <h3 className="card-heading mb-0">{script.title}</h3>
                      <span className={`status-pill status-${script.status || 'draft'}`}>{scriptStatusLabel(script.status)}</span>
                    </div>
                    <p className="small text-secondary mb-0">Updated {formatProjectDate(script.updated_at)}</p>
                  </div>
                </Link>
              </div>
            ))}
          </div>

          {meta?.total ? (
            <p className="small text-secondary mt-3 mb-0">
              Showing {scripts.length} of {meta.total} script{meta.total === 1 ? '' : 's'}
            </p>
          ) : null}
        </>
      ) : null}
    </div>
  );
}
