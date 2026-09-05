import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { fieldError, SCRIPT_STATUSES } from '../../services/scriptConstants';
import { createScript, deleteScript, getScript, updateScript } from '../../services/scriptService';
import DeleteScriptModal from './DeleteScriptModal';

const EMPTY = { title: '', body: '', status: 'draft' };

export default function ScriptEditor({ projectId, scriptId, creating }) {
  const navigate = useNavigate();
  const [values, setValues] = useState(EMPTY);
  const [saved, setSaved] = useState(EMPTY);
  const [loading, setLoading] = useState(!creating);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState('');
  const [aiNotice, setAiNotice] = useState('');
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [script, setScript] = useState(null);

  const dirty = useMemo(
    () => values.title !== saved.title || values.body !== saved.body || values.status !== saved.status,
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
        const data = await getScript(projectId, scriptId);
        if (!cancelled) {
          const next = toValues(data);
          setScript(data);
          setValues(next);
          setSaved(next);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err : new ApiError('Unable to load this script.', { status: 0 }));
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
  }, [creating, projectId, scriptId]);

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
      body: values.body,
      status: values.status,
    };

    try {
      if (creating) {
        const created = await createScript(projectId, payload);
        navigate(`/projects/${projectId}/scripts/${created.id}`, { replace: true });
        return;
      }

      const updated = await updateScript(projectId, scriptId, payload);
      const next = toValues(updated);
      setScript(updated);
      setValues(next);
      setSaved(next);
      setNotice('Script saved.');
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to save this script.', { status: 0 }));
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    setDeleting(true);
    setError(null);

    try {
      await deleteScript(projectId, scriptId);
      navigate(`/projects/${projectId}/scripts`, { replace: true, state: { notice: 'Script deleted.' } });
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to delete this script.', { status: 0 }));
      setDeleting(false);
      setDeleteOpen(false);
    }
  }

  if (loading) {
    return <LoadingSpinner label="Opening script…" />;
  }

  if (error && !creating && !values.title && !script) {
    return (
      <div>
        <Link to={`/projects/${projectId}/scripts`} className="small text-decoration-none">
          <i className="bi bi-arrow-left me-1" aria-hidden="true" />
          Back to Scripts
        </Link>
        <div className="mt-4">
          <AlertMessage>{error.message}</AlertMessage>
        </div>
      </div>
    );
  }

  return (
    <div className="script-editor">
      <div className="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <Link to={`/projects/${projectId}/scripts`} className="small text-decoration-none">
            <i className="bi bi-arrow-left me-1" aria-hidden="true" />
            Back to Scripts
          </Link>
          <h3 className="h4 mt-3 mb-1">{creating ? 'New Script' : 'Script editor'}</h3>
          <p className="text-secondary mb-0">
            {dirty ? 'Unsaved changes' : creating ? 'Saved when you create it.' : 'All changes are saved to the database.'}
          </p>
        </div>
        <div className="d-flex flex-wrap gap-2">
          <button
            type="button"
            className="btn btn-outline-secondary"
            onClick={() =>
              setAiNotice('AI provider integration is not configured yet. Manual writing and saving are fully available.')
            }
          >
            Generate with AI
          </button>
          {!creating ? (
            <button type="button" className="btn btn-outline-danger" onClick={() => setDeleteOpen(true)}>
              Delete
            </button>
          ) : null}
        </div>
      </div>

      {aiNotice ? (
        <div className="mb-3">
          <AlertMessage variant="warning">{aiNotice}</AlertMessage>
        </div>
      ) : null}

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
              <label className="form-label" htmlFor="script-title">
                Title
              </label>
              <input
                id="script-title"
                className={`form-control ${fieldError(error, 'title') ? 'is-invalid' : ''}`}
                value={values.title}
                onChange={(event) => setValues({ ...values, title: event.target.value })}
                maxLength={255}
                required
              />
              {fieldError(error, 'title') ? <div className="invalid-feedback">{fieldError(error, 'title')}</div> : null}
            </div>
            <div className="col-lg-4">
              <label className="form-label" htmlFor="script-status">
                Status
              </label>
              <select
                id="script-status"
                className="form-select"
                value={values.status}
                onChange={(event) => setValues({ ...values, status: event.target.value })}
              >
                {SCRIPT_STATUSES.map((status) => (
                  <option key={status.value} value={status.value}>
                    {status.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="mb-4">
            <label className="form-label" htmlFor="script-body">
              Script content
            </label>
            <textarea
              id="script-body"
              className={`form-control script-body-editor ${fieldError(error, 'body') ? 'is-invalid' : ''}`}
              value={values.body}
              onChange={(event) => setValues({ ...values, body: event.target.value })}
              rows="18"
            />
            {fieldError(error, 'body') ? <div className="invalid-feedback">{fieldError(error, 'body')}</div> : null}
          </div>

          <button className="btn btn-primary" type="submit" disabled={saving || (!creating && !dirty)}>
            {saving ? 'Saving…' : creating ? 'Create script' : 'Save'}
          </button>
        </div>
      </form>

      <DeleteScriptModal
        script={script || values}
        open={deleteOpen}
        deleting={deleting}
        onCancel={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
      />
    </div>
  );
}

function toValues(script) {
  return {
    title: script.title || '',
    body: script.body || '',
    status: script.status || 'draft',
  };
}
