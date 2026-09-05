import { Link } from 'react-router-dom';
import { formatProjectDate, statusLabel, typeLabel } from '../../services/projectConstants';

export default function ProjectCard({ project }) {
  return (
    <Link to={`/projects/${project.id}`} className="project-card card border-0 shadow-sm h-100 text-decoration-none">
      <div className="card-body p-4 d-flex flex-column">
        <div className="d-flex justify-content-between align-items-start gap-3 mb-3">
          <h2 className="h5 mb-0 text-body">{project.name}</h2>
          <span className={`status-pill status-${project.status || 'draft'}`}>{statusLabel(project.status)}</span>
        </div>
        <p className="text-secondary flex-grow-1 mb-4">{project.description || 'No description yet.'}</p>
        <div className="d-flex flex-wrap gap-3 small text-secondary">
          <span>
            <i className="bi bi-layers me-1" aria-hidden="true" />
            {typeLabel(project.type)}
          </span>
          <span>
            <i className="bi bi-calendar3 me-1" aria-hidden="true" />
            {formatProjectDate(project.created_at)}
          </span>
        </div>
      </div>
    </Link>
  );
}
