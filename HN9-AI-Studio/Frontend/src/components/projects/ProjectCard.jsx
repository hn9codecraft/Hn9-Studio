import { Link } from 'react-router-dom';
import { formatProjectDate, statusLabel, typeLabel } from '../../services/projectConstants';

export default function ProjectCard({ project, compact = false }) {
  return (
    <Link
      to={`/projects/${project.id}`}
      className={`project-card glass-card card border-0 h-100 text-decoration-none${compact ? ' project-card--compact' : ''}`}
    >
      <div className="card-body d-flex flex-column">
        <div className="d-flex justify-content-between align-items-start gap-3 mb-2">
          <h3 className="card-heading mb-0">{project.name}</h3>
          <span className={`status-pill status-${project.status || 'draft'}`}>{statusLabel(project.status)}</span>
        </div>
        <p className={`text-secondary ${compact ? 'project-card-excerpt' : 'flex-grow-1 mb-4'}`}>
          {project.description || 'No description yet.'}
        </p>
        <div className="d-flex flex-wrap gap-3 small text-secondary mt-auto">
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
