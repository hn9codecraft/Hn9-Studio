import { useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import ProjectCard from '../../components/projects/ProjectCard';
import AlertMessage from '../../components/ui/AlertMessage';
import EmptyState from '../../components/ui/EmptyState';
import LoadingSpinner from '../../components/ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { formatProjectDate, statusLabel, typeLabel } from '../../services/projectConstants';
import { listProjects } from '../../services/projectService';

export default function ProjectsPage() {
  const location = useLocation();
  const [projects, setProjects] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(location.state?.notice || '');

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');

      try {
        const result = await listProjects({ perPage: 50 });
        if (!cancelled) {
          const items = Array.isArray(result.data) ? result.data : [];
          setProjects(items);
          setMeta(result.meta);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load projects.');
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
  }, []);

  return (
    <div className="projects-page">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
          <p className="text-uppercase small text-secondary mb-1">Studio</p>
          <h2 className="h3 mb-1">Projects</h2>
          <p className="text-secondary mb-0">Your production workspaces, loaded from the live API.</p>
        </div>
        <Link className="btn btn-primary" to="/projects/new">
          <i className="bi bi-plus-lg me-2" aria-hidden="true" />
          New Project
        </Link>
      </div>

      {notice ? (
        <div className="mb-4">
          <AlertMessage variant="success">
            {notice}
            <button type="button" className="btn-close float-end" aria-label="Dismiss" onClick={() => setNotice('')} />
          </AlertMessage>
        </div>
      ) : null}

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {loading ? <LoadingSpinner label="Loading projects…" /> : null}

      {!loading && !error && projects.length === 0 ? (
        <EmptyState
          icon="bi-folder2-open"
          title="No projects yet"
          description="Create a project to open a studio workspace. Nothing is generated until later modules are built."
        >
          <Link className="btn btn-primary" to="/projects/new">
            New Project
          </Link>
        </EmptyState>
      ) : null}

      {!loading && projects.length > 0 ? (
        <>
          <div className="d-none d-md-block">
            <div className="card border-0 shadow-sm studio-table-card">
              <div className="table-responsive">
                <table className="table studio-table mb-0 align-middle">
                  <thead>
                    <tr>
                      <th>Project</th>
                      <th>Type</th>
                      <th>Status</th>
                      <th>Created</th>
                    </tr>
                  </thead>
                  <tbody>
                    {projects.map((project) => (
                      <tr key={project.id}>
                        <td>
                          <Link to={`/projects/${project.id}`} className="fw-semibold text-decoration-none">
                            {project.name}
                          </Link>
                          <div className="small text-secondary mt-1">
                            {project.description || 'No description yet.'}
                          </div>
                        </td>
                        <td>{typeLabel(project.type)}</td>
                        <td>
                          <span className={`status-pill status-${project.status || 'draft'}`}>
                            {statusLabel(project.status)}
                          </span>
                        </td>
                        <td className="text-secondary">{formatProjectDate(project.created_at)}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <div className="d-md-none row g-3">
            {projects.map((project) => (
              <div className="col-12" key={project.id}>
                <ProjectCard project={project} />
              </div>
            ))}
          </div>

          {meta?.total ? (
            <p className="small text-secondary mt-3 mb-0">
              Showing {projects.length} of {meta.total} project{meta.total === 1 ? '' : 's'}
            </p>
          ) : null}
        </>
      ) : null}
    </div>
  );
}
