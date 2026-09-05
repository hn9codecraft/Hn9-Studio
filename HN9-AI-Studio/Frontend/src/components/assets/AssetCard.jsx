import { Link } from 'react-router-dom';
import { formatProjectDate } from '../../services/projectConstants';
import { assetSourceLabel, assetStatusLabel, assetTypeLabel } from '../../services/assetConstants';

export default function AssetCard({ projectId, asset }) {
  return (
    <Link to={`/projects/${projectId}/assets/${asset.id}`} className="project-card card border-0 shadow-sm text-decoration-none">
      <div className="card-body p-4">
        <div className="d-flex justify-content-between align-items-start gap-3 mb-2">
          <h3 className="h6 mb-0 text-body">{asset.title}</h3>
          <span className={`status-pill status-${asset.status || 'draft'}`}>{assetStatusLabel(asset.status)}</span>
        </div>
        <p className="small text-secondary mb-2">
          {assetTypeLabel(asset.type)} · {assetSourceLabel(asset.source)}
        </p>
        <p className="small text-secondary mb-0">Updated {formatProjectDate(asset.updated_at)}</p>
      </div>
    </Link>
  );
}
