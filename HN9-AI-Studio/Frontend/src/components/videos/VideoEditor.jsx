import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import {
  fieldError,
  VIDEO_ASPECT_RATIOS,
  VIDEO_ASSIGNABLE_STATUSES,
  VIDEO_DURATIONS,
  videoCapabilities,
  videoDurationLabel,
  videoStatusClass,
  videoStatusLabel,
} from '../../services/videoConstants';
import {
  approveVideo,
  createVideo,
  deleteVideo,
  getVideo,
  getVideoStatus,
  listVideoReviewHistory,
  requestVideoRework,
  submitVideoReview,
  updateVideo,
} from '../../services/videoService';
import DeleteVideoModal from './DeleteVideoModal';
import GeneratedVideo from './GeneratedVideo';

const EMPTY = {
  title: '',
  prompt: '',
  negative_prompt: '',
  aspect_ratio: '16:9',
  duration: 5,
  status: 'draft',
};

export default function VideoEditor({ projectId, videoId, creating }) {
  const navigate = useNavigate();
  const [values, setValues] = useState(EMPTY);
  const [saved, setSaved] = useState(EMPTY);
  const [loading, setLoading] = useState(!creating);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState('');
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [video, setVideo] = useState(null);
  const [history, setHistory] = useState([]);
  const [workflowBusy, setWorkflowBusy] = useState('');
  const [reworkOpen, setReworkOpen] = useState(false);
  const [reworkComment, setReworkComment] = useState('');
  const capabilities = videoCapabilities(video);
  const canEdit = creating || capabilities.edit || !video;
  const inFlight = video?.status === 'pending' || video?.status === 'processing';

  const dirty = useMemo(
    () =>
      values.title !== saved.title ||
      values.prompt !== saved.prompt ||
      values.negative_prompt !== saved.negative_prompt ||
      values.aspect_ratio !== saved.aspect_ratio ||
      Number(values.duration) !== Number(saved.duration) ||
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
        const data = await getVideo(projectId, videoId);
        if (!cancelled) {
          applyVideo(data);
          listVideoReviewHistory(projectId, data.id)
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
          setError(err instanceof ApiError ? err : new ApiError('Unable to load this video request.', { status: 0 }));
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
  }, [creating, projectId, videoId]);

  useEffect(() => {
    if (creating || !inFlight) {
      return undefined;
    }

    let cancelled = false;
    const timer = window.setInterval(async () => {
      try {
        const data = await getVideoStatus(projectId, videoId);
        if (!cancelled) {
          applyVideo(data);
        }
      } catch {
        // Keep the last known honest status.
      }
    }, 5000);

    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [creating, inFlight, projectId, videoId]);

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

  function applyVideo(data) {
    const next = toValues(data);
    setVideo(data);
    setValues(next);
    setSaved(next);
  }

  async function refreshHistory(current) {
    try {
      setHistory(await listVideoReviewHistory(projectId, current.id));
    } catch {
      setHistory([]);
    }
  }

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
      duration: Number(values.duration),
      status: values.status,
    };

    try {
      if (creating) {
        const created = await createVideo(projectId, payload);
        navigate(`/projects/${projectId}/videos/${created.id}`, { replace: true });
        return;
      }

      const updated = await updateVideo(projectId, videoId, payload);
      applyVideo(updated);
      setNotice('Video request saved.');
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to save this video request.', { status: 0 }));
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    setDeleting(true);
    setError(null);

    try {
      await deleteVideo(projectId, videoId);
      navigate(`/projects/${projectId}/videos`, { replace: true, state: { notice: 'Video request deleted.' } });
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to delete this video request.', { status: 0 }));
      setDeleting(false);
      setDeleteOpen(false);
    }
  }

  async function runWorkflow(action) {
    setWorkflowBusy(action);
    setError(null);

    try {
      const updated =
        action === 'submit'
          ? await submitVideoReview(projectId, videoId)
          : await approveVideo(projectId, videoId);
      applyVideo(updated);
      setNotice(action === 'submit' ? 'Video submitted for review.' : 'Video approved.');
      await refreshHistory(updated);
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to update review status.', { status: 0 }));
    } finally {
      setWorkflowBusy('');
    }
  }

  async function submitRework(event) {
    event.preventDefault();
    setWorkflowBusy('rework');
    setError(null);

    try {
      const updated = await requestVideoRework(projectId, videoId, { comment: reworkComment.trim() });
      applyVideo(updated);
      setReworkOpen(false);
      setReworkComment('');
      setNotice('Video sent back for rework.');
      await refreshHistory(updated);
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to request rework.', { status: 0 }));
    } finally {
      setWorkflowBusy('');
    }
  }

  if (loading) {
    return <LoadingSpinner label="Opening video request…" />;
  }

  if (error && !creating && !values.title && !video) {
    return (
      <div>
        <Link to={`/projects/${projectId}/videos`} className="small text-decoration-none">
          <i className="bi bi-arrow-left me-1" aria-hidden="true" />
          Back to Videos
        </Link>
        <div className="mt-4">
          <AlertMessage>{error.message}</AlertMessage>
        </div>
      </div>
    );
  }

  return (
    <div className="video-editor">
      <div className="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <Link to={`/projects/${projectId}/videos`} className="small text-decoration-none">
            <i className="bi bi-arrow-left me-1" aria-hidden="true" />
            Back to Videos
          </Link>
          <h2 className="section-title mt-3 mb-1">{creating ? 'New Video Request' : 'Video request'}</h2>
          <p className="text-secondary mb-0">
            {dirty ? 'Unsaved changes' : creating ? 'Saved when you create it.' : 'All changes are saved to the database.'}
          </p>
        </div>
        <div className="d-flex flex-wrap gap-2">
          <Link className="btn btn-outline-secondary" to={`/projects/${projectId}/videos/generate`}>
            Generate video
          </Link>
          {!creating && capabilities.regenerate ? (
            <Link className="btn btn-outline-primary" to={`/projects/${projectId}/videos/${videoId}/regenerate`}>
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
              <label className="form-label" htmlFor="video-title">
                Title
              </label>
              <input
                id="video-title"
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
              <label className="form-label" htmlFor="video-status">
                Status
              </label>
              <select
                id="video-status"
                className="form-select"
                value={VIDEO_ASSIGNABLE_STATUSES.some((item) => item.value === values.status) ? values.status : 'draft'}
                onChange={(event) => setValues({ ...values, status: event.target.value })}
                disabled={!canEdit}
              >
                {VIDEO_ASSIGNABLE_STATUSES.map((status) => (
                  <option key={status.value} value={status.value}>
                    {status.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="mb-3">
            <label className="form-label" htmlFor="video-prompt">
              Prompt
            </label>
            <textarea
              id="video-prompt"
              className={`form-control video-prompt-editor ${fieldError(error, 'prompt') ? 'is-invalid' : ''}`}
              value={values.prompt}
              onChange={(event) => setValues({ ...values, prompt: event.target.value })}
              disabled={!canEdit}
              rows="8"
              required
            />
            {fieldError(error, 'prompt') ? <div className="invalid-feedback">{fieldError(error, 'prompt')}</div> : null}
          </div>

          <div className="mb-3">
            <label className="form-label" htmlFor="video-negative-prompt">
              Negative prompt <span className="text-secondary fw-normal">(optional)</span>
            </label>
            <textarea
              id="video-negative-prompt"
              className={`form-control ${fieldError(error, 'negative_prompt') ? 'is-invalid' : ''}`}
              value={values.negative_prompt}
              onChange={(event) => setValues({ ...values, negative_prompt: event.target.value })}
              disabled={!canEdit}
              rows="3"
            />
            {fieldError(error, 'negative_prompt') ? (
              <div className="invalid-feedback">{fieldError(error, 'negative_prompt')}</div>
            ) : null}
          </div>

          <div className="row g-3 mb-4">
            <div className="col-md-6">
              <label className="form-label" htmlFor="video-aspect-ratio">
                Aspect ratio
              </label>
              <select
                id="video-aspect-ratio"
                className="form-select"
                value={values.aspect_ratio}
                onChange={(event) => setValues({ ...values, aspect_ratio: event.target.value })}
                disabled={!canEdit}
              >
                {VIDEO_ASPECT_RATIOS.map((ratio) => (
                  <option key={ratio.value} value={ratio.value}>
                    {ratio.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-md-6">
              <label className="form-label" htmlFor="video-duration">
                Duration
              </label>
              <select
                id="video-duration"
                className="form-select"
                value={String(values.duration)}
                onChange={(event) => setValues({ ...values, duration: Number(event.target.value) })}
                disabled={!canEdit}
              >
                {VIDEO_DURATIONS.map((duration) => (
                  <option key={duration.value} value={duration.value}>
                    {duration.label}
                  </option>
                ))}
              </select>
              {video?.source === 'ai' ? (
                <div className="form-text">Generated videos are 8 seconds. Manual requests keep 5 / 10 / 15 / 30.</div>
              ) : null}
            </div>
          </div>

          <button className="btn btn-primary" type="submit" disabled={!canEdit || saving || (!creating && !dirty)}>
            {saving ? 'Saving…' : creating ? 'Create video request' : 'Save'}
          </button>
        </div>
      </form>

      <div className="card border-0 shadow-sm mt-4">
        <div className="card-body p-4 p-md-5">
          <h3 className="card-heading mb-3">Generated video</h3>
          {!creating && video?.status === 'needs_rework' && video?.latest_rework?.comment ? (
            <div className="mb-3">
              <AlertMessage variant="warning">
                <strong>Rework requested</strong>
                {video.latest_rework.actor?.name ? ` by ${video.latest_rework.actor.name}` : ''}. {video.latest_rework.comment}
              </AlertMessage>
            </div>
          ) : null}
          {!creating ? (
            <div className="mb-3" role="status" aria-live="polite">
              <span className={`status-pill ${videoStatusClass(video?.status)}`}>{videoStatusLabel(video?.status)}</span>
              {inFlight ? <span className="visually-hidden"> Generating. Status updates when the provider reports a change.</span> : null}
            </div>
          ) : null}
          <GeneratedVideo projectId={projectId} video={video} />
          <dl className="row mb-0 mt-4">
            <dt className="col-sm-3">Provider</dt>
            <dd className="col-sm-9">{video?.provider || 'Not generated'}</dd>
            <dt className="col-sm-3">Model</dt>
            <dd className="col-sm-9">{video?.generation?.model || '—'}</dd>
            <dt className="col-sm-3">Duration</dt>
            <dd className="col-sm-9">{videoDurationLabel(video?.duration)}</dd>
            <dt className="col-sm-3">File</dt>
            <dd className="col-sm-9">{video?.file?.mime_type || 'No stored file'}</dd>
          </dl>
          {!creating ? (
            <div className="d-flex flex-wrap gap-2 mt-4">
              {capabilities.submit ? (
                <button type="button" className="btn btn-primary" disabled={Boolean(workflowBusy)} onClick={() => runWorkflow('submit')}>
                  {workflowBusy === 'submit' ? 'Submitting…' : video?.status === 'needs_rework' ? 'Resubmit for review' : 'Submit for review'}
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
              <label className="form-label" htmlFor="video-rework-comment">
                Rework comment
              </label>
              <textarea
                id="video-rework-comment"
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

      <DeleteVideoModal
        video={video || values}
        open={deleteOpen}
        deleting={deleting}
        onCancel={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
      />
    </div>
  );
}

function toValues(video) {
  return {
    title: video.title || '',
    prompt: video.prompt || '',
    negative_prompt: video.negative_prompt || '',
    aspect_ratio: video.aspect_ratio || '16:9',
    duration: Number(video.duration) || 5,
    status: video.status || 'draft',
  };
}
