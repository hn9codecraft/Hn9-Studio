import { Link } from 'react-router-dom';
import { formatProjectDate } from '../../services/projectConstants';
import { videoAspectRatioLabel, videoDurationLabel, videoStatusLabel } from '../../services/videoConstants';

export default function VideoCard({ projectId, video }) {
  return (
    <Link to={`/projects/${projectId}/videos/${video.id}`} className="project-card card border-0 shadow-sm text-decoration-none">
      <div className="card-body p-4">
        <div className="d-flex justify-content-between align-items-start gap-3 mb-2">
          <h3 className="h6 mb-0 text-body">{video.title}</h3>
          <span className={`status-pill status-${video.status || 'draft'}`}>{videoStatusLabel(video.status)}</span>
        </div>
        <p className="small text-secondary mb-2">
          {videoAspectRatioLabel(video.aspect_ratio)} · {videoDurationLabel(video.duration)}
        </p>
        <p className="small text-secondary mb-0">Updated {formatProjectDate(video.updated_at)}</p>
      </div>
    </Link>
  );
}
