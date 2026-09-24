import { Link } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';
import { formatProjectDate } from '../../services/projectConstants';
import { assetSourceLabel, assetStatusLabel, assetTypeLabel } from '../../services/assetConstants';
import AssetCard from './AssetCard';

export default function AssetList({ projectId, assets, loading, error, meta }) {
  if (loading) {
    return <LoadingSpinner label="Loading assets…" />;
  }

  return (
    <div className="asset-list">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <p className="section-kicker mb-1">Asset Studio</p>
          <h2 className="section-title mb-1">Assets</h2>
          <p className="text-secondary mb-0">Catalog files and outputs for this project. Upload and AI generation are not configured yet.</p>
        </div>
        <Link className="btn btn-primary" to={`/projects/${projectId}/assets/new`}>
          <i className="bi bi-plus-lg me-2" aria-hidden="true" />
          New Asset
        </Link>
      </div>

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {!error && assets.length === 0 ? (
        <EmptyState
          icon="bi-folder"
          title="No assets yet"
          description="Create an asset record to track a file or output for this project. File upload and AI generation are not configured yet."
        >
          <Link className="btn btn-primary" to={`/projects/${projectId}/assets/new`}>
            New Asset
          </Link>
        </EmptyState>
      ) : null}

      {assets.length > 0 ? (
        <>
          <div className="d-none d-md-block">
            <div className="card border-0 shadow-sm studio-table-card">
              <div className="table-responsive">
                <table className="table studio-table mb-0 align-middle">
                  <thead>
                    <tr>
                      <th scope="col">Title</th>
                      <th scope="col">Type</th>
                      <th scope="col">Source</th>
                      <th scope="col">Status</th>
                      <th scope="col">Updated</th>
                      <th scope="col">
                        <span className="visually-hidden">Actions</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    {assets.map((asset) => (
                      <tr key={asset.id}>
                        <td>
                          <Link to={`/projects/${projectId}/assets/${asset.id}`} className="fw-semibold text-decoration-none">
                            {asset.title}
                          </Link>
                        </td>
                        <td className="text-secondary">{assetTypeLabel(asset.type)}</td>
                        <td className="text-secondary">{assetSourceLabel(asset.source)}</td>
                        <td>
                          <span className={`status-pill status-${asset.status || 'draft'}`}>
                            {assetStatusLabel(asset.status)}
                          </span>
                        </td>
                        <td className="text-secondary">{formatProjectDate(asset.updated_at)}</td>
                        <td className="text-end">
                          <Link className="btn btn-sm btn-outline-primary" to={`/projects/${projectId}/assets/${asset.id}`}>
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
            {assets.map((asset) => (
              <div className="col-12" key={asset.id}>
                <AssetCard projectId={projectId} asset={asset} />
              </div>
            ))}
          </div>

          {meta?.total ? (
            <p className="small text-secondary mt-3 mb-0">
              Showing {assets.length} of {meta.total} asset{meta.total === 1 ? '' : 's'}
            </p>
          ) : null}
        </>
      ) : null}
    </div>
  );
}
