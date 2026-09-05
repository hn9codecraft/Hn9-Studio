import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { fieldError, VIDEO_ASPECT_RATIOS, VIDEO_DURATIONS, VIDEO_STATUSES } from '../../services/videoConstants';
import { createVideo, deleteVideo, getVideo, updateVideo } from '../../services/videoService';
import DeleteVideoModal from './DeleteVideoModal';

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
  const [aiNotice, setAiNotice] = useState('');
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [video, setVideo] = useState(null);

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
          const next = toValues(data);
          setVideo(data);
          setValues(next);
          setSaved(next);
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
      const next = toValues(updated);
      setVideo(updated);
      setValues(next);
      setSaved(next);
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
          <h3 className="h4 mt-3 mb-1">{creating ? 'New Video Request' : 'Video request'}</h3>
          <p className="text-secondary mb-0">
            {dirty ? 'Unsaved changes' : creating ? 'Saved when you create it.' : 'All changes are saved to the database.'}
          </p>
        </div>
        <div className="d-flex flex-wrap gap-2">
          <button
            type="button"
            className="btn btn-outline-secondary"
            onClick={() => setAiNotice('AI video generation is not configured yet.')}
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
              <label className="form-label" htmlFor="video-title">
                Title
              </label>
              <input
                id="video-title"
                className={`form-control ${fieldError(error, 'title') ? 'is-invalid' : ''}`}
                value={values.title}
                onChange={(event) => setValues({ ...values, title: event.target.value })}
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
                value={values.status}
                onChange={(event) => setValues({ ...values, status: event.target.value })}
              >
                {VIDEO_STATUSES.map((status) => (
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
              >
                {VIDEO_DURATIONS.map((duration) => (
                  <option key={duration.value} value={duration.value}>
                    {duration.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <button className="btn btn-primary" type="submit" disabled={saving || (!creating && !dirty)}>
            {saving ? 'Saving…' : creating ? 'Create video request' : 'Save'}
          </button>
        </div>
      </form>

      <div className="card border-0 shadow-sm mt-4">
        <div className="card-body p-4 p-md-5">
          <h3 className="h5 mb-3">Provider and output</h3>
          <AlertMessage variant="warning">AI video generation is not configured yet.</AlertMessage>
          <dl className="row mb-0 mt-4">
            <dt className="col-sm-3">Provider</dt>
            <dd className="col-sm-9">{video?.provider || 'Not connected'}</dd>
            <dt className="col-sm-3">Provider job</dt>
            <dd className="col-sm-9">{video?.provider_job_id || '—'}</dd>
            <dt className="col-sm-3">Output</dt>
            <dd className="col-sm-9">
              {video?.output_url ? (
                <a href={video.output_url} target="_blank" rel="noreferrer">
                  {video.output_url}
                </a>
              ) : (
                'No generated video. A real provider can be connected later.'
              )}
            </dd>
          </dl>
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
    status: VIDEO_STATUSES.some((item) => item.value === video.status) ? video.status : 'draft',
  };
}
