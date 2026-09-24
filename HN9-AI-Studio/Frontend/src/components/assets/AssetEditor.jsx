import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { ASSET_SOURCES, ASSET_STATUSES, ASSET_TYPES, fieldError } from '../../services/assetConstants';
import { createAsset, deleteAsset, getAsset, updateAsset } from '../../services/assetService';
import DeleteAssetModal from './DeleteAssetModal';

const EMPTY = {
  title: '',
  type: 'image',
  source: 'manual',
  status: 'draft',
  file_url: '',
  mime_type: '',
  notes: '',
};

export default function AssetEditor({ projectId, assetId, creating }) {
  const navigate = useNavigate();
  const [values, setValues] = useState(EMPTY);
  const [saved, setSaved] = useState(EMPTY);
  const [loading, setLoading] = useState(!creating);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState('');
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [asset, setAsset] = useState(null);

  const dirty = useMemo(
    () =>
      values.title !== saved.title ||
      values.type !== saved.type ||
      values.source !== saved.source ||
      values.status !== saved.status ||
      values.file_url !== saved.file_url ||
      values.mime_type !== saved.mime_type ||
      values.notes !== saved.notes,
    [values, saved],
  );

  useEffect(() => {
    if (creating) {
      setValues(EMPTY);
      setSaved(EMPTY);
      setLoading(false);
      return undefined;
    }

    let cancelled = false;

    async function load() {
      setLoading(true);
      setError(null);
      setNotice('');

      try {
        const data = await getAsset(projectId, assetId);
        if (!cancelled) {
          const next = toValues(data);
          setAsset(data);
          setValues(next);
          setSaved(next);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err : new ApiError('Unable to load this asset.', { status: 0 }));
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
  }, [creating, projectId, assetId]);

  useEffect(() => {
    function warn(event) {
      if (!dirty) {
        return;
      }

      event.preventDefault();
      event.returnValue = '';
    }

    window.addEventListener('beforeunload', warn);
    return () => window.removeEventListener('beforeunload', warn);
  }, [dirty]);

  async function handleSave(event) {
    event.preventDefault();
    setSaving(true);
    setError(null);
    setNotice('');

    const payload = {
      title: values.title.trim(),
      type: values.type,
      source: values.source,
      status: values.status,
      file_url: values.file_url.trim() || null,
      mime_type: values.mime_type.trim() || null,
      notes: values.notes,
    };

    try {
      if (creating) {
        const created = await createAsset(projectId, payload);
        navigate(`/projects/${projectId}/assets/${created.id}`, { replace: true });
        return;
      }

      const updated = await updateAsset(projectId, assetId, payload);
      const next = toValues(updated);
      setAsset(updated);
      setValues(next);
      setSaved(next);
      setNotice('Asset saved.');
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to save this asset.', { status: 0 }));
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    setDeleting(true);
    setError(null);

    try {
      await deleteAsset(projectId, assetId);
      navigate(`/projects/${projectId}/assets`, { replace: true, state: { notice: 'Asset deleted.' } });
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to delete this asset.', { status: 0 }));
      setDeleting(false);
      setDeleteOpen(false);
    }
  }

  if (loading) {
    return <LoadingSpinner label="Opening asset…" />;
  }

  if (error && !creating && !values.title && !asset) {
    return (
      <div>
        <Link to={`/projects/${projectId}/assets`} className="small text-decoration-none">
          <i className="bi bi-arrow-left me-1" aria-hidden="true" />
          Back to Assets
        </Link>
        <div className="mt-4">
          <AlertMessage>{error.message}</AlertMessage>
        </div>
      </div>
    );
  }

  return (
    <div className="asset-editor">
      <div className="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <Link to={`/projects/${projectId}/assets`} className="small text-decoration-none">
            <i className="bi bi-arrow-left me-1" aria-hidden="true" />
            Back to Assets
          </Link>
          <h2 className="section-title mt-3 mb-1">{creating ? 'New Asset' : 'Asset'}</h2>
          <p className="text-secondary mb-0">
            {dirty ? 'Unsaved changes' : creating ? 'Saved when you create it.' : 'All changes are saved to the database.'}
          </p>
        </div>
        {!creating ? (
          <button type="button" className="btn btn-outline-danger" onClick={() => setDeleteOpen(true)}>
            Delete
          </button>
        ) : null}
      </div>

      {notice ? (
        <div className="mb-3">
          <AlertMessage variant="success">{notice}</AlertMessage>
        </div>
      ) : null}

      <form className="card border-0 shadow-sm" onSubmit={handleSave}>
        <div className="card-body p-4 p-md-5">
          {error?.message ? (
            <div className="mb-3">
              <AlertMessage>{error.message}</AlertMessage>
            </div>
          ) : null}

          <div className="row g-3 mb-3">
            <div className="col-lg-8">
              <label className="form-label" htmlFor="asset-title">
                Title
              </label>
              <input
                id="asset-title"
                className={`form-control ${fieldError(error, 'title') ? 'is-invalid' : ''}`}
                value={values.title}
                onChange={(event) => setValues({ ...values, title: event.target.value })}
                maxLength={255}
                required
              />
              {fieldError(error, 'title') ? <div className="invalid-feedback">{fieldError(error, 'title')}</div> : null}
            </div>
            <div className="col-lg-4">
              <label className="form-label" htmlFor="asset-status">
                Status
              </label>
              <select
                id="asset-status"
                className="form-select"
                value={values.status}
                onChange={(event) => setValues({ ...values, status: event.target.value })}
              >
                {ASSET_STATUSES.map((status) => (
                  <option key={status.value} value={status.value}>
                    {status.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="row g-3 mb-3">
            <div className="col-md-6">
              <label className="form-label" htmlFor="asset-type">
                Type
              </label>
              <select
                id="asset-type"
                className={`form-select ${fieldError(error, 'type') ? 'is-invalid' : ''}`}
                value={values.type}
                onChange={(event) => setValues({ ...values, type: event.target.value })}
                required
              >
                {ASSET_TYPES.map((type) => (
                  <option key={type.value} value={type.value}>
                    {type.label}
                  </option>
                ))}
              </select>
              {fieldError(error, 'type') ? <div className="invalid-feedback">{fieldError(error, 'type')}</div> : null}
            </div>
            <div className="col-md-6">
              <label className="form-label" htmlFor="asset-source">
                Source
              </label>
              <select
                id="asset-source"
                className="form-select"
                value={values.source}
                onChange={(event) => setValues({ ...values, source: event.target.value })}
              >
                {ASSET_SOURCES.map((source) => (
                  <option key={source.value} value={source.value}>
                    {source.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="mb-3">
            <label className="form-label" htmlFor="asset-file-url">
              File URL <span className="text-secondary fw-normal">(optional)</span>
            </label>
            <input
              id="asset-file-url"
              className={`form-control ${fieldError(error, 'file_url') ? 'is-invalid' : ''}`}
              type="url"
              value={values.file_url}
              onChange={(event) => setValues({ ...values, file_url: event.target.value })}
              placeholder="https://"
            />
            {fieldError(error, 'file_url') ? <div className="invalid-feedback">{fieldError(error, 'file_url')}</div> : null}
            <div className="form-text">Paste a real URL if you already have a file. This studio does not upload or generate files.</div>
          </div>

          <div className="mb-3">
            <label className="form-label" htmlFor="asset-mime-type">
              MIME type <span className="text-secondary fw-normal">(optional)</span>
            </label>
            <input
              id="asset-mime-type"
              className={`form-control ${fieldError(error, 'mime_type') ? 'is-invalid' : ''}`}
              value={values.mime_type}
              onChange={(event) => setValues({ ...values, mime_type: event.target.value })}
              placeholder="image/png"
              maxLength={255}
            />
            {fieldError(error, 'mime_type') ? <div className="invalid-feedback">{fieldError(error, 'mime_type')}</div> : null}
          </div>

          <div className="mb-4">
            <label className="form-label" htmlFor="asset-notes">
              Notes <span className="text-secondary fw-normal">(optional)</span>
            </label>
            <textarea
              id="asset-notes"
              className={`form-control ${fieldError(error, 'notes') ? 'is-invalid' : ''}`}
              value={values.notes}
              onChange={(event) => setValues({ ...values, notes: event.target.value })}
              rows="4"
            />
            {fieldError(error, 'notes') ? <div className="invalid-feedback">{fieldError(error, 'notes')}</div> : null}
          </div>

          <button className="btn btn-primary" type="submit" disabled={saving || (!creating && !dirty)}>
            {saving ? 'Saving…' : creating ? 'Create asset' : 'Save'}
          </button>
        </div>
      </form>

      <div className="card border-0 shadow-sm mt-4">
        <div className="card-body p-4 p-md-5">
          <h3 className="card-heading mb-3">File and output</h3>
          <AlertMessage variant="warning">File upload and AI generation are not configured yet.</AlertMessage>
          <dl className="row mb-0 mt-4">
            <dt className="col-sm-3">File</dt>
            <dd className="col-sm-9">
              {asset?.file_url || values.file_url ? (
                <a href={asset?.file_url || values.file_url} target="_blank" rel="noreferrer">
                  {asset?.file_url || values.file_url}
                </a>
              ) : (
                'No file is attached. A real upload or provider can be connected later.'
              )}
            </dd>
            <dt className="col-sm-3">MIME type</dt>
            <dd className="col-sm-9">{asset?.mime_type || values.mime_type || '—'}</dd>
          </dl>
        </div>
      </div>

      <DeleteAssetModal
        asset={asset || values}
        open={deleteOpen}
        deleting={deleting}
        onCancel={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
      />
    </div>
  );
}

function toValues(asset) {
  return {
    title: asset.title || '',
    type: ASSET_TYPES.some((item) => item.value === asset.type) ? asset.type : 'other',
    source: ASSET_SOURCES.some((item) => item.value === asset.source) ? asset.source : 'manual',
    status: ASSET_STATUSES.some((item) => item.value === asset.status) ? asset.status : 'draft',
    file_url: asset.file_url || '',
    mime_type: asset.mime_type || '',
    notes: asset.notes || '',
  };
}
