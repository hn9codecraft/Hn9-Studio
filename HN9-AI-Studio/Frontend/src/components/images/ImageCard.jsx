import { Link } from 'react-router-dom';
import { formatProjectDate } from '../../services/projectConstants';
import { imageAspectRatioLabel, imageStatusLabel } from '../../services/imageConstants';

export default function ImageCard({ projectId, image }) {
  return (
    <Link to={`/projects/${projectId}/images/${image.id}`} className="project-card card border-0 shadow-sm text-decoration-none">
      <div className="card-body p-4">
        <div className="d-flex justify-content-between align-items-start gap-3 mb-2">
          <h3 className="h6 mb-0 text-body">{image.title}</h3>
          <span className={`status-pill status-${image.status || 'draft'}`}>{imageStatusLabel(image.status)}</span>
        </div>
        <p className="small text-secondary mb-2">{imageAspectRatioLabel(image.aspect_ratio)}</p>
        <p className="small text-secondary mb-0">Updated {formatProjectDate(image.updated_at)}</p>
      </div>
    </Link>
  );
}
