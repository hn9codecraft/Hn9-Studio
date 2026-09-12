import { Link } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';
import { formatProjectDate } from '../../services/projectConstants';
import { videoAspectRatioLabel, videoDurationLabel, videoStatusLabel } from '../../services/videoConstants';
import VideoCard from './VideoCard';

export default function VideoList({ projectId, videos, loading, error, meta }) {
  if (loading) {
    return <LoadingSpinner label="Loading video requests…" />;
  }

  return (
    <div className="video-list">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <p className="section-kicker mb-1">Video Studio</p>
          <h2 className="section-title mb-1">Video requests</h2>
          <p className="text-secondary mb-0">Save prompts and settings for this project. AI generation is not configured yet.</p>
        </div>
        <Link className="btn btn-primary" to={`/projects/${projectId}/videos/new`}>
          <i className="bi bi-plus-lg me-2" aria-hidden="true" />
          New Video Request
        </Link>
      </div>

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {!error && videos.length === 0 ? (
        <EmptyState
          icon="bi-camera-reels"
          title="No video requests yet"
          description="Create a video request to save a prompt and settings. AI video generation is not configured yet."
        >
          <Link className="btn btn-primary" to={`/projects/${projectId}/videos/new`}>
            New Video Request
          </Link>
        </EmptyState>
      ) : null}

      {videos.length > 0 ? (
        <>
          <div className="d-none d-md-block">
            <div className="card border-0 shadow-sm studio-table-card">
              <div className="table-responsive">
                <table className="table studio-table mb-0 align-middle">
                  <thead>
                    <tr>
                      <th scope="col">Title</th>
                      <th scope="col">Aspect ratio</th>
                      <th scope="col">Duration</th>
                      <th scope="col">Status</th>
                      <th scope="col">Updated</th>
                      <th scope="col">
                        <span className="visually-hidden">Actions</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {videos.map((video) => (
                      <tr key={video.id}>
                        <td>
                          <Link to={`/projects/${projectId}/videos/${video.id}`} className="fw-semibold text-decoration-none">
                            {video.title}
                          </Link>
                        </td>
                        <td className="text-secondary">{videoAspectRatioLabel(video.aspect_ratio)}</td>
                        <td className="text-secondary">{videoDurationLabel(video.duration)}</td>
                        <td>
                          <span className={`status-pill status-${video.status || 'draft'}`}>
                            {videoStatusLabel(video.status)}
                          </span>
                        </td>
                        <td className="text-secondary">{formatProjectDate(video.updated_at)}</td>
                        <td className="text-end">
                          <Link className="btn btn-sm btn-outline-primary" to={`/projects/${projectId}/videos/${video.id}`}>
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
            {videos.map((video) => (
              <div className="col-12" key={video.id}>
                <VideoCard projectId={projectId} video={video} />
              </div>
            ))}
          </div>

          {meta?.total ? (
            <p className="small text-secondary mt-3 mb-0">
              Showing {videos.length} of {meta.total} video request{meta.total === 1 ? '' : 's'}
            </p>
          ) : null}
        </>
      ) : null}
    </div>
  );
}
