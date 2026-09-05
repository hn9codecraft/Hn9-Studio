import { Link } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';
import { formatProjectDate } from '../../services/projectConstants';
import { imageAspectRatioLabel, imageStatusLabel } from '../../services/imageConstants';
import ImageCard from './ImageCard';

export default function ImageList({ projectId, images, loading, error, meta }) {
  if (loading) {
    return <LoadingSpinner label="Loading image requests…" />;
  }

  return (
    <div className="image-list">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
          <p className="text-uppercase small text-secondary mb-1">Image Studio</p>
          <h3 className="h4 mb-1">Image requests</h3>
          <p className="text-secondary mb-0">Save prompts and settings for this project. AI generation is not configured yet.</p>
        </div>
        <Link className="btn btn-primary" to={`/projects/${projectId}/images/new`}>
          <i className="bi bi-plus-lg me-2" aria-hidden="true" />
          New Image Request
        </Link>
      </div>

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {!error && images.length === 0 ? (
        <EmptyState
          icon="bi-image"
          title="No image requests yet"
          description="Create an image request to save a prompt and settings. AI image generation is not configured yet."
        >
          <Link className="btn btn-primary" to={`/projects/${projectId}/images/new`}>
            New Image Request
          </Link>
        </EmptyState>
      ) : null}

      {images.length > 0 ? (
        <>
          <div className="d-none d-md-block">
            <div className="card border-0 shadow-sm studio-table-card">
              <div className="table-responsive">
                <table className="table studio-table mb-0 align-middle">
                  <thead>
                    <tr>
                      <th>Title</th>
                      <th>Aspect ratio</th>
                      <th>Status</th>
                      <th>Updated</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {images.map((image) => (
                      <tr key={image.id}>
                        <td>
                          <Link to={`/projects/${projectId}/images/${image.id}`} className="fw-semibold text-decoration-none">
                            {image.title}
                          </Link>
                        </td>
                        <td className="text-secondary">{imageAspectRatioLabel(image.aspect_ratio)}</td>
                        <td>
                          <span className={`status-pill status-${image.status || 'draft'}`}>
                            {imageStatusLabel(image.status)}
                          </span>
                        </td>
                        <td className="text-secondary">{formatProjectDate(image.updated_at)}</td>
                        <td className="text-end">
                          <Link className="btn btn-sm btn-outline-primary" to={`/projects/${projectId}/images/${image.id}`}>
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
            {images.map((image) => (
              <div className="col-12" key={image.id}>
                <ImageCard projectId={projectId} image={image} />
              </div>
            ))}
          </div>

          {meta?.total ? (
            <p className="small text-secondary mt-3 mb-0">
              Showing {images.length} of {meta.total} image request{meta.total === 1 ? '' : 's'}
            </p>
          ) : null}
        </>
      ) : null}
    </div>
  );
}
