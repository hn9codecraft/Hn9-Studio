import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { fieldError, IMAGE_ASPECT_RATIOS, IMAGE_ASSIGNABLE_STATUSES, imageCapabilities, imageStatusClass, imageStatusLabel } from '../../services/imageConstants';
import { approveImage, createImage, deleteImage, getImage, listImageReviewHistory, requestImageRework, submitImageReview, updateImage } from '../../services/imageService';
import DeleteImageModal from './DeleteImageModal';
import GeneratedImage from './GeneratedImage';

const EMPTY = {
  title: '',
  prompt: '',
  negative_prompt: '',
  aspect_ratio: '1:1',
  status: 'draft',
};

export default function ImageEditor({ projectId, imageId, creating }) {
  const navigate = useNavigate();
  const [values, setValues] = useState(EMPTY);
  const [saved, setSaved] = useState(EMPTY);
  const [loading, setLoading] = useState(!creating);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState('');
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [image, setImage] = useState(null);
  const [history, setHistory] = useState([]);
  const [workflowBusy, setWorkflowBusy] = useState('');
  const [reworkOpen, setReworkOpen] = useState(false);
  const [reworkComment, setReworkComment] = useState('');
  const capabilities = imageCapabilities(image);
  const canEdit = creating || capabilities.edit || !image;

  const dirty = useMemo(
    () =>
      values.title !== saved.title ||
      values.prompt !== saved.prompt ||
      values.negative_prompt !== saved.negative_prompt ||
      values.aspect_ratio !== saved.aspect_ratio ||
      values.status !== saved.status,
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
        const data = await getImage(projectId, imageId);
        if (!cancelled) {
          const next = toValues(data);
          setImage(data);
          setValues(next);
          setSaved(next);
          listImageReviewHistory(projectId, data.id)
            .then((events) => {
              if (!cancelled) {
                setHistory(events);
              }
            })
            .catch(() => {
              if (!cancelled) {
                setHistory([]);
              }
            });
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err : new ApiError('Unable to load this image request.', { status: 0 }));
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
  }, [creating, projectId, imageId]);

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
      prompt: values.prompt,
      negative_prompt: values.negative_prompt,
      aspect_ratio: values.aspect_ratio,
      status: values.status,
    };

    try {
      if (creating) {
        const created = await createImage(projectId, payload);
        navigate(`/projects/${projectId}/images/${created.id}`, { replace: true });
        return;
      }

      const updated = await updateImage(projectId, imageId, payload);
      const next = toValues(updated);
      setImage(updated);
      setValues(next);
      setSaved(next);
      setNotice('Image request saved.');
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to save this image request.', { status: 0 }));
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    setDeleting(true);
    setError(null);

    try {
      await deleteImage(projectId, imageId);
      navigate(`/projects/${projectId}/images`, { replace: true, state: { notice: 'Image request deleted.' } });
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to delete this image request.', { status: 0 }));
      setDeleting(false);
      setDeleteOpen(false);
    }
  }

  async function refreshHistory(current = image) {
    if (!current?.id) {
      setHistory([]);
      return;
    }

    try {
      setHistory(await listImageReviewHistory(projectId, current.id));
    } catch {
      setHistory([]);
    }
  }

  async function runWorkflow(action) {
    setWorkflowBusy(action);
    setError(null);

    try {
      const updated = action === 'approve'
        ? await approveImage(projectId, imageId)
        : await submitImageReview(projectId, imageId);
      setImage(updated);
      const next = toValues(updated);
      setValues(next);
      setSaved(next);
      setNotice(action === 'approve' ? 'Image approved.' : 'Submitted for review.');
      await refreshHistory(updated);
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to update the review status.', { status: 0 }));
    } finally {
      setWorkflowBusy('');
    }
  }

  async function submitRework(event) {
    event.preventDefault();
    setWorkflowBusy('rework');
    setError(null);

    try {
      const updated = await requestImageRework(projectId, imageId, { comment: reworkComment.trim() });
      setImage(updated);
      const next = toValues(updated);
      setValues(next);
      setSaved(next);
      setReworkOpen(false);
      setReworkComment('');
      setNotice('Image sent back for rework.');
      await refreshHistory(updated);
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to request rework.', { status: 0 }));
    } finally {
      setWorkflowBusy('');
    }
  }

  if (loading) {
    return <LoadingSpinner label="Opening image request…" />;
  }

  if (error && !creating && !values.title && !image) {
    return (
      <div>
        <Link to={`/projects/${projectId}/images`} className="small text-decoration-none">
          <i className="bi bi-arrow-left me-1" aria-hidden="true" />
          Back to Images
        </Link>
        <div className="mt-4">
          <AlertMessage>{error.message}</AlertMessage>
        </div>
      </div>
    );
  }

  return (
    <div className="image-editor">
      <div className="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <Link to={`/projects/${projectId}/images`} className="small text-decoration-none">
            <i className="bi bi-arrow-left me-1" aria-hidden="true" />
            Back to Images
          </Link>
          <h2 className="section-title mt-3 mb-1">{creating ? 'New Image Request' : 'Image request'}</h2>
          <p className="text-secondary mb-0">
            {dirty ? 'Unsaved changes' : creating ? 'Saved when you create it.' : 'All changes are saved to the database.'}
          </p>
        </div>
        <div className="d-flex flex-wrap gap-2">
          <Link className="btn btn-outline-secondary" to={`/projects/${projectId}/images/generate`}>
            Generate image
          </Link>
          {!creating && capabilities.regenerate ? (
            <Link className="btn btn-outline-primary" to={`/projects/${projectId}/images/${imageId}/regenerate`}>
              Regenerate
            </Link>
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

      <form className="card border-0 shadow-sm" onSubmit={handleSave}>
        <div className="card-body p-4 p-md-5">
          {error?.message ? (
            <div className="mb-3">
              <AlertMessage>{error.message}</AlertMessage>
            </div>
          ) : null}

          <div className="row g-3 mb-3">
            <div className="col-lg-8">
              <label className="form-label" htmlFor="image-title">
                Title
              </label>
              <input
                id="image-title"
                className={`form-control ${fieldError(error, 'title') ? 'is-invalid' : ''}`}
                value={values.title}
                onChange={(event) => setValues({ ...values, title: event.target.value })}
                disabled={!canEdit}
                maxLength={255}
                required
              />
              {fieldError(error, 'title') ? <div className="invalid-feedback">{fieldError(error, 'title')}</div> : null}
            </div>
            <div className="col-lg-4">
              <label className="form-label" htmlFor="image-status">
                Status
              </label>
              {IMAGE_ASSIGNABLE_STATUSES.some((item) => item.value === values.status) ? (
                <select
                  id="image-status"
                  className="form-select"
                  value={values.status}
                  onChange={(event) => setValues({ ...values, status: event.target.value })}
                  disabled={!canEdit}
                >
                  {IMAGE_ASSIGNABLE_STATUSES.map((status) => (
                    <option key={status.value} value={status.value}>
                      {status.label}
                    </option>
                  ))}
                </select>
              ) : (
                <div id="image-status" className="form-control-plaintext">
                  <span className={`status-pill ${imageStatusClass(values.status)}`}>{imageStatusLabel(values.status)}</span>
                </div>
              )}
            </div>
          </div>

          <div className="mb-3">
            <label className="form-label" htmlFor="image-prompt">
              Prompt
            </label>
            <textarea
              id="image-prompt"
              className={`form-control image-prompt-editor ${fieldError(error, 'prompt') ? 'is-invalid' : ''}`}
              value={values.prompt}
              onChange={(event) => setValues({ ...values, prompt: event.target.value })}
              disabled={!canEdit}
              rows="8"
              required
            />
            {fieldError(error, 'prompt') ? <div className="invalid-feedback">{fieldError(error, 'prompt')}</div> : null}
          </div>

          <div className="mb-3">
            <label className="form-label" htmlFor="image-negative-prompt">
              Negative prompt <span className="text-secondary fw-normal">(optional)</span>
            </label>
            <textarea
              id="image-negative-prompt"
              className={`form-control ${fieldError(error, 'negative_prompt') ? 'is-invalid' : ''}`}
              value={values.negative_prompt}
              onChange={(event) => setValues({ ...values, negative_prompt: event.target.value })}
              rows="3"
            />
            {fieldError(error, 'negative_prompt') ? (
              <div className="invalid-feedback">{fieldError(error, 'negative_prompt')}</div>
            ) : null}
          </div>

          <div className="mb-4">
            <label className="form-label" htmlFor="image-aspect-ratio">
              Aspect ratio
            </label>
            <select
              id="image-aspect-ratio"
              className="form-select"
              value={values.aspect_ratio}
              onChange={(event) => setValues({ ...values, aspect_ratio: event.target.value })}
            >
              {IMAGE_ASPECT_RATIOS.map((ratio) => (
                <option key={ratio.value} value={ratio.value}>
                  {ratio.label}
                </option>
              ))}
            </select>
          </div>

          <button className="btn btn-primary" type="submit" disabled={!canEdit || saving || (!creating && !dirty)}>
            {saving ? 'Saving…' : creating ? 'Create image request' : 'Save'}
          </button>
        </div>
      </form>

      <div className="card border-0 shadow-sm mt-4">
        <div className="card-body p-4 p-md-5">
          <h3 className="card-heading mb-3">Generated image</h3>
          {!creating && image?.status === 'needs_rework' && image?.latest_rework?.comment ? (
            <div className="mb-3">
              <AlertMessage variant="warning">
                <strong>Rework requested</strong>
                {image.latest_rework.actor?.name ? ` by ${image.latest_rework.actor.name}` : ''}. {image.latest_rework.comment}
              </AlertMessage>
            </div>
          ) : null}
          {!creating ? (
            <div className="mb-3" role="status">
              <span className={`status-pill ${imageStatusClass(image?.status)}`}>{imageStatusLabel(image?.status)}</span>
            </div>
          ) : null}
          <GeneratedImage projectId={projectId} image={image} />
          <dl className="row mb-0 mt-4">
            <dt className="col-sm-3">Provider</dt>
            <dd className="col-sm-9">{image?.provider || 'Not generated'}</dd>
            <dt className="col-sm-3">Model</dt>
            <dd className="col-sm-9">{image?.generation?.model || '—'}</dd>
            <dt className="col-sm-3">File</dt>
            <dd className="col-sm-9">{image?.file?.mime_type || 'No stored file'}</dd>
          </dl>
          {!creating ? (
            <div className="d-flex flex-wrap gap-2 mt-4">
              {capabilities.submit ? (
                <button type="button" className="btn btn-primary" disabled={Boolean(workflowBusy)} onClick={() => runWorkflow('submit')}>
                  {workflowBusy === 'submit' ? 'Submitting…' : image?.status === 'needs_rework' ? 'Resubmit for review' : 'Submit for review'}
                </button>
              ) : null}
              {capabilities.approve ? (
                <button type="button" className="btn btn-outline-primary" disabled={Boolean(workflowBusy)} onClick={() => runWorkflow('approve')}>
                  {workflowBusy === 'approve' ? 'Approving…' : 'Approve'}
                </button>
              ) : null}
              {capabilities.request_rework ? (
                <button type="button" className="btn btn-outline-secondary" disabled={Boolean(workflowBusy)} onClick={() => setReworkOpen(true)}>
                  Needs rework
                </button>
              ) : null}
            </div>
          ) : null}
          {reworkOpen ? (
            <form className="mt-3" onSubmit={submitRework}>
              <label className="form-label" htmlFor="image-rework-comment">
                Rework comment
              </label>
              <textarea
                id="image-rework-comment"
                className="form-control mb-2"
                value={reworkComment}
                onChange={(event) => setReworkComment(event.target.value)}
                rows="3"
                required
                minLength={10}
              />
              <button className="btn btn-primary" type="submit" disabled={workflowBusy === 'rework'}>
                {workflowBusy === 'rework' ? 'Sending…' : 'Send back for rework'}
              </button>
            </form>
          ) : null}
          {history.length > 0 ? (
            <ul className="list-unstyled mt-4 mb-0">
              {history.map((event) => (
                <li key={event.id} className="mb-2">
                  <strong>{event.action}</strong>
                  {event.actor?.name ? ` · ${event.actor.name}` : ''}
                  {event.comment ? ` — ${event.comment}` : ''}
                </li>
              ))}
            </ul>
          ) : null}
        </div>
      </div>

      <DeleteImageModal
        image={image || values}
        open={deleteOpen}
        deleting={deleting}
        onCancel={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
      />
    </div>
  );
}

function toValues(image) {
  return {
    title: image.title || '',
    prompt: image.prompt || '',
    negative_prompt: image.negative_prompt || '',
    aspect_ratio: image.aspect_ratio || '1:1',
    status: image.status || 'draft',
  };
}
