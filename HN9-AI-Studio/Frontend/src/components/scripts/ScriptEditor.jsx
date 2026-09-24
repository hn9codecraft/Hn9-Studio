import { useEffect, useMemo, useState } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import {
  briefFromScript,
  fieldError,
  generationErrorMessage,
  isAiScript,
  SCRIPT_ASSIGNABLE_STATUSES,
  scriptCapabilities,
  scriptOriginLabel,
  scriptStatusClass,
  scriptStatusLabel,
  workflowErrorMessage,
} from '../../services/scriptConstants';
import {
  approveScript,
  createScript,
  deleteScript,
  getScript,
  listScriptReviewHistory,
  regenerateScript,
  requestScriptRework,
  submitScriptReview,
  updateScript,
} from '../../services/scriptService';
import DeleteScriptModal from './DeleteScriptModal';
import NeedsReworkModal from './NeedsReworkModal';
import ScriptReviewHistory from './ScriptReviewHistory';

const EMPTY = { title: '', body: '', status: 'draft' };

export default function ScriptEditor({ projectId, scriptId, creating }) {
  const navigate = useNavigate();
  const location = useLocation();
  const [values, setValues] = useState(EMPTY);
  const [saved, setSaved] = useState(EMPTY);
  const [loading, setLoading] = useState(!creating);
  const [saving, setSaving] = useState(false);
  const [regenerating, setRegenerating] = useState(false);
  const [workflowBusy, setWorkflowBusy] = useState('');
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState(location.state?.notice || '');
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [reworkOpen, setReworkOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [script, setScript] = useState(null);
  const [history, setHistory] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(false);

  const capabilities = scriptCapabilities(script);
  const canEdit = creating || capabilities.edit;
  const showAssignableStatus = creating || ['draft', 'ready', 'archived'].includes(script?.status);
  const dirty = useMemo(
    () => values.title !== saved.title || values.body !== saved.body || values.status !== saved.status,
    [values, saved],
  );

  useEffect(() => {
    if (creating) {
      setValues(EMPTY);
      setSaved(EMPTY);
      setScript(null);
      setHistory([]);
      setLoading(false);
      return undefined;
    }

    let cancelled = false;

    async function load() {
      setLoading(true);
      setError(null);

      try {
        const [data, events] = await Promise.all([
          getScript(projectId, scriptId),
          listScriptReviewHistory(projectId, scriptId),
        ]);
        if (!cancelled) {
          const next = toValues(data);
          setScript(data);
          setValues(next);
          setSaved(next);
          setHistory(Array.isArray(events) ? events : []);
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

  async function refreshHistory() {
    setHistoryLoading(true);
    try {
      const events = await listScriptReviewHistory(projectId, scriptId);
      setHistory(Array.isArray(events) ? events : []);
    } catch {
      setHistory([]);
    } finally {
      setHistoryLoading(false);
    }
  }

  function applyScript(updated, message) {
    const next = toValues(updated);
    setScript(updated);
    setValues(next);
    setSaved(next);
    setNotice(message);
    setError(null);
  }

  async function handleSave(event) {
    event.preventDefault();
    setSaving(true);
    setError(null);
    setNotice('');

    const payload = {
      title: values.title.trim(),
      body: values.body,
    };

    if (creating || showAssignableStatus) {
      payload.status = values.status;
    }

    try {
      if (creating) {
        const created = await createScript(projectId, payload);
        navigate(`/projects/${projectId}/scripts/${created.id}`, { replace: true });
        return;
      }

      const updated = await updateScript(projectId, scriptId, payload);
      applyScript(updated, script?.status === 'needs_rework' ? 'Rework saved. Resubmit when you are ready.' : 'Script saved.');
      await refreshHistory();
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(workflowErrorMessage(err, 'Unable to save this script.'), { status: 0 }));
    } finally {
      setSaving(false);
    }
  }

  async function handleRegenerate() {
    if (dirty) {
      setError(new ApiError('Save or discard your edits before creating a variation.', { status: 0 }));
      return;
    }

    setRegenerating(true);
    setError(null);
    setNotice('');

    try {
      const result = await regenerateScript(projectId, scriptId, briefFromScript(script || values));
      const created = result?.script || result;
      navigate(`/projects/${projectId}/scripts/${created.id}`, {
        replace: true,
        state: { notice: 'A new AI variation was generated. This script was left unchanged.' },
      });
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(generationErrorMessage(err), { status: 0 }));
      setRegenerating(false);
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

  async function runWorkflow(action, request, successMessage) {
    if (dirty) {
      setError(new ApiError('Save or discard your edits before taking a review action.', { status: 0 }));
      return;
    }

    setWorkflowBusy(action);
    setError(null);
    setNotice('');

    try {
      const updated = await request();
      applyScript(updated, successMessage);
      await refreshHistory();
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(workflowErrorMessage(err), { status: 0 }));
    } finally {
      setWorkflowBusy('');
    }
  }

  async function handleSubmitReview() {
    await runWorkflow(
      'submit',
      () => submitScriptReview(projectId, scriptId),
      script?.status === 'needs_rework' ? 'Script resubmitted for review.' : 'Script submitted for review.',
    );
  }

  async function handleApprove() {
    await runWorkflow('approve', () => approveScript(projectId, scriptId), 'Script approved.');
  }

  async function handleNeedsRework(comment) {
    setWorkflowBusy('rework');
    setError(null);

    try {
      const updated = await requestScriptRework(projectId, scriptId, { comment });
      applyScript(updated, 'Script sent back for rework.');
      setReworkOpen(false);
      await refreshHistory();
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(workflowErrorMessage(err), { status: 0 }));
    } finally {
      setWorkflowBusy('');
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

  const lockReason = lockExplanation(script, capabilities);

  return (
    <div className="script-editor">
      <div className="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <Link to={`/projects/${projectId}/scripts`} className="small text-decoration-none">
            <i className="bi bi-arrow-left me-1" aria-hidden="true" />
            Back to Scripts
          </Link>
          <h2 className="section-title mt-3 mb-1">{creating ? 'New Script' : 'Script editor'}</h2>
          <p className="text-secondary mb-0">
            {creating
              ? 'Manual script. Saved when you create it.'
              : dirty
                ? 'Unsaved changes'
                : `${scriptOriginLabel(script?.source)} · edits save to this script only.`}
          </p>
        </div>
        <div className="script-workflow-actions d-flex flex-wrap gap-2">
          <Link className="btn btn-outline-secondary" to={`/projects/${projectId}/scripts/generate`}>
            Generate new AI script
          </Link>
          {!creating && isAiScript(script) ? (
            <button
              type="button"
              className="btn btn-outline-primary"
              onClick={handleRegenerate}
              disabled={regenerating || !capabilities.regenerate}
              title={!capabilities.regenerate ? lockReason || 'A new variation cannot be created from this status.' : undefined}
            >
              {regenerating ? 'Generating variation…' : 'Create variation'}
            </button>
          ) : null}
          {!creating ? (
            <button type="button" className="btn btn-outline-danger" onClick={() => setDeleteOpen(true)}>
              Delete
            </button>
          ) : null}
        </div>
      </div>

      {notice ? (
        <div className="mb-3">
          <AlertMessage variant="success">{notice}</AlertMessage>
        </div>
      ) : null}

      {!creating ? (
        <div className="script-status-banner mb-3" role="status">
          <span className={`status-pill ${scriptStatusClass(script?.status)}`}>
            {scriptStatusLabel(script?.status)}
          </span>
          <span className="script-status-copy">{statusExplanation(script?.status)}</span>
        </div>
      ) : null}

      {!creating && script?.status === 'needs_rework' && script?.latest_rework?.comment ? (
        <div className="mb-3">
          <AlertMessage variant="warning">
            <strong>Rework requested</strong>
            {script.latest_rework.actor?.name ? ` by ${script.latest_rework.actor.name}` : ''}.{' '}
            {script.latest_rework.comment}
          </AlertMessage>
        </div>
      ) : null}

      {!creating && isAiScript(script) ? (
        <div className="mb-3">
          <AlertMessage variant="secondary">
            AI generated{script?.generation?.provider ? ` via ${script.generation.provider}` : ''}.
            {script?.parent_script_id ? ' This is a variation of an earlier script.' : ''}
            {' '}
            Manual edits stay on this copy. The original generation record is not overwritten.
          </AlertMessage>
        </div>
      ) : null}

      {!creating ? (
        <div className="script-workflow-actions d-flex flex-wrap gap-2 mb-4">
          {capabilities.submit ? (
            <button
              type="button"
              className="btn btn-primary"
              onClick={handleSubmitReview}
              disabled={Boolean(workflowBusy) || dirty}
              title={dirty ? 'Save or discard your edits before submitting.' : undefined}
            >
              {workflowBusy === 'submit'
                ? 'Submitting…'
                : script?.status === 'needs_rework'
                  ? 'Resubmit for review'
                  : 'Submit for review'}
            </button>
          ) : null}
          {capabilities.approve ? (
            <button type="button" className="btn btn-outline-primary" onClick={handleApprove} disabled={Boolean(workflowBusy) || dirty}>
              {workflowBusy === 'approve' ? 'Approving…' : 'Approve'}
            </button>
          ) : null}
          {capabilities.request_rework ? (
            <button
              type="button"
              className="btn btn-outline-secondary"
              onClick={() => setReworkOpen(true)}
              disabled={Boolean(workflowBusy) || dirty}
            >
              Needs rework
            </button>
          ) : null}
        </div>
      ) : null}

      <form className="card border-0 shadow-sm" onSubmit={handleSave}>
        <div className="card-body p-4 p-md-5">
          {error?.message ? (
            <div className="mb-3">
              <AlertMessage>{error.message}</AlertMessage>
            </div>
          ) : null}

          {!canEdit && lockReason ? (
            <div className="mb-3">
              <AlertMessage variant="secondary">{lockReason}</AlertMessage>
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
                disabled={!canEdit}
              />
              {fieldError(error, 'title') ? <div className="invalid-feedback">{fieldError(error, 'title')}</div> : null}
            </div>
            <div className="col-lg-4">
              <label className="form-label" htmlFor="script-status">
                Status
              </label>
              {showAssignableStatus && canEdit ? (
                <select
                  id="script-status"
                  className="form-select"
                  value={values.status}
                  onChange={(event) => setValues({ ...values, status: event.target.value })}
                >
                  {SCRIPT_ASSIGNABLE_STATUSES.map((status) => (
                    <option key={status.value} value={status.value}>
                      {status.label}
                    </option>
                  ))}
                </select>
              ) : (
                <div id="script-status" className="form-control-plaintext">
                  <span className={`status-pill ${scriptStatusClass(script?.status || values.status)}`}>
                    {scriptStatusLabel(script?.status || values.status)}
                  </span>
                </div>
              )}
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
              disabled={!canEdit}
              readOnly={!canEdit}
            />
            {fieldError(error, 'body') ? <div className="invalid-feedback">{fieldError(error, 'body')}</div> : null}
          </div>

          {canEdit ? (
            <button className="btn btn-primary" type="submit" disabled={saving || (!creating && !dirty)}>
              {saving ? 'Saving…' : creating ? 'Create script' : script?.status === 'needs_rework' ? 'Save rework' : 'Save'}
            </button>
          ) : null}
        </div>
      </form>

      {!creating ? (
        <div className="card border-0 shadow-sm mt-4">
          <div className="card-body p-4 p-md-5">
            <ScriptReviewHistory events={history} loading={historyLoading} />
          </div>
        </div>
      ) : null}

      <DeleteScriptModal
        script={script || values}
        open={deleteOpen}
        deleting={deleting}
        onCancel={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
      />
      <NeedsReworkModal
        open={reworkOpen}
        submitting={workflowBusy === 'rework'}
        error={error}
        onCancel={() => setReworkOpen(false)}
        onConfirm={handleNeedsRework}
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

function statusExplanation(status) {
  if (status === 'pending_review') {
    return 'Waiting for a reviewer decision. Content cannot be edited until a decision is recorded.';
  }

  if (status === 'needs_rework') {
    return 'A reviewer asked for changes. Edit or regenerate, then resubmit.';
  }

  if (status === 'approved') {
    return 'Approved. Normal editing is locked so this version cannot be overwritten.';
  }

  if (status === 'archived') {
    return 'Archived. This copy is kept for reference.';
  }

  if (status === 'ready') {
    return 'Marked ready. Submit it when you want a reviewer decision.';
  }

  return 'Draft. Edit, generate a variation, or submit for review.';
}

function lockExplanation(script, capabilities) {
  if (!script || capabilities.edit) {
    return '';
  }

  if (script.status === 'pending_review') {
    return 'This script is pending review, so title and body are locked until a reviewer decides.';
  }

  if (script.status === 'approved') {
    return 'This script is approved. Create a new variation if you need another draft.';
  }

  return 'This script cannot be edited in its current status.';
}
