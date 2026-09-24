import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import { ApiError } from '../../services/apiClient';
import {
  VIDEO_GENERATION_ASPECT_RATIOS,
  VIDEO_GENERATION_DURATIONS,
  VIDEO_RESOLUTIONS,
  fieldError,
  videoGenerationErrorMessage,
} from '../../services/videoConstants';
import { generateVideo, regenerateVideo } from '../../services/videoService';
import { listScripts } from '../../services/scriptService';
import { listImages } from '../../services/imageService';

const EMPTY = {
  title: '',
  prompt: '',
  aspect_ratio: '16:9',
  resolution: '',
  provider: 'gemini',
  model: '',
  script_id: '',
  image_id: '',
};

export default function VideoGenerateForm({ project, parentVideo = null }) {
  const navigate = useNavigate();
  const initial = useMemo(() => {
    if (!parentVideo) {
      return EMPTY;
    }

    return {
      ...EMPTY,
      title: parentVideo.title || '',
      prompt: parentVideo.generation?.prompt || parentVideo.prompt || '',
      aspect_ratio: parentVideo.aspect_ratio === '9:16' ? '9:16' : '16:9',
      resolution: parentVideo.generation?.resolution || '',
      provider: parentVideo.generation?.provider || parentVideo.provider || 'gemini',
      model: parentVideo.generation?.model || '',
      script_id: parentVideo.script_id || '',
      image_id: parentVideo.image_id || '',
    };
  }, [parentVideo]);

  const [values, setValues] = useState(initial);
  const [scripts, setScripts] = useState([]);
  const [images, setImages] = useState([]);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;

    Promise.all([listScripts(project.id), listImages(project.id)])
      .then(([scriptResult, imageResult]) => {
        if (!cancelled) {
          setScripts(scriptResult.data || []);
          setImages((imageResult.data || []).filter((image) => image.has_file));
        }
      })
      .catch(() => {
        if (!cancelled) {
          setScripts([]);
          setImages([]);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [project.id]);

  function applyScript(scriptId) {
    const script = scripts.find((item) => item.id === scriptId);
    setValues((current) => ({
      ...current,
      script_id: scriptId,
      prompt: script?.body ? script.body : current.prompt,
      title: current.title || script?.title || '',
    }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setGenerating(true);
    setError(null);

    const payload = {
      prompt: values.prompt.trim(),
      title: values.title.trim() || null,
      aspect_ratio: values.aspect_ratio,
      resolution: values.resolution || null,
      duration: 8,
      provider: values.provider || 'gemini',
      model: values.model.trim() || null,
      script_id: values.script_id || null,
      image_id: values.image_id || null,
    };

    try {
      const result = parentVideo
        ? await regenerateVideo(project.id, parentVideo.id, payload)
        : await generateVideo(project.id, payload);
      const created = result?.video || result;
      navigate(`/projects/${project.id}/videos/${created.id}`, {
        replace: true,
        state: {
          notice: parentVideo
            ? 'A new video generation was started. The previous video was left unchanged.'
            : 'Video generation started.',
        },
      });
    } catch (err) {
      const apiError = err instanceof ApiError ? err : new ApiError('Unable to generate a video.', { status: 0 });
      setError(
        new ApiError(videoGenerationErrorMessage(apiError), {
          status: apiError.status,
          errorCode: apiError.errorCode,
          errors: apiError.errors,
        }),
      );
      setGenerating(false);
    }
  }

  return (
    <div className="video-generate">
      <div className="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <Link
            to={parentVideo ? `/projects/${project.id}/videos/${parentVideo.id}` : `/projects/${project.id}/videos`}
            className="small text-decoration-none"
          >
            <i className="bi bi-arrow-left me-1" aria-hidden="true" />
            Back
          </Link>
          <h2 className="section-title mt-3 mb-1">{parentVideo ? 'Regenerate video' : 'Generate video'}</h2>
          <p className="text-secondary mb-0">
            {generating
              ? 'Sending the request to Gemini Veo…'
              : 'Starts a real Gemini Veo job. Completion is shown only after the provider finishes.'}
          </p>
        </div>
      </div>

      <form className="card border-0 shadow-sm" onSubmit={handleSubmit}>
        <div className="card-body p-4 p-md-5">
          {error?.message ? (
            <div className="mb-3" role="alert">
              <AlertMessage>{error.message}</AlertMessage>
            </div>
          ) : null}

          <div className="mb-3">
            <label className="form-label" htmlFor="video-gen-script">
              Use a script <span className="text-secondary fw-normal">(optional)</span>
            </label>
            <select
              id="video-gen-script"
              className="form-select"
              value={values.script_id}
              onChange={(event) => applyScript(event.target.value)}
              disabled={generating}
            >
              <option value="">No script</option>
              {scripts.map((script) => (
                <option key={script.id} value={script.id}>
                  {script.title}
                </option>
              ))}
            </select>
          </div>

          <div className="mb-3">
            <label className="form-label" htmlFor="video-gen-image">
              Source image <span className="text-secondary fw-normal">(optional, image-to-video)</span>
            </label>
            <select
              id="video-gen-image"
              className="form-select"
              value={values.image_id}
              onChange={(event) => setValues({ ...values, image_id: event.target.value })}
              disabled={generating}
            >
              <option value="">Text to video</option>
              {images.map((image) => (
                <option key={image.id} value={image.id}>
                  {image.title}
                </option>
              ))}
            </select>
          </div>

          <div className="mb-3">
            <label className="form-label" htmlFor="video-gen-title">
              Title <span className="text-secondary fw-normal">(optional)</span>
            </label>
            <input
              id="video-gen-title"
              className="form-control"
              value={values.title}
              onChange={(event) => setValues({ ...values, title: event.target.value })}
              maxLength={255}
              disabled={generating}
            />
          </div>

          <div className="mb-3">
            <label className="form-label" htmlFor="video-gen-prompt">
              Video prompt
            </label>
            <textarea
              id="video-gen-prompt"
              className={`form-control ${fieldError(error, 'prompt') ? 'is-invalid' : ''}`}
              value={values.prompt}
              onChange={(event) => setValues({ ...values, prompt: event.target.value })}
              rows="8"
              required
              disabled={generating}
            />
            {fieldError(error, 'prompt') ? <div className="invalid-feedback">{fieldError(error, 'prompt')}</div> : null}
          </div>

          <div className="row g-3 mb-3">
            <div className="col-md-4">
              <label className="form-label" htmlFor="video-gen-aspect">
                Aspect ratio
              </label>
              <select
                id="video-gen-aspect"
                className="form-select"
                value={values.aspect_ratio}
                onChange={(event) => setValues({ ...values, aspect_ratio: event.target.value })}
                disabled={generating}
              >
                {VIDEO_GENERATION_ASPECT_RATIOS.map((ratio) => (
                  <option key={ratio.value} value={ratio.value}>
                    {ratio.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-md-4">
              <label className="form-label" htmlFor="video-gen-duration">
                Duration
              </label>
              <select id="video-gen-duration" className="form-select" value="8" disabled>
                {VIDEO_GENERATION_DURATIONS.map((duration) => (
                  <option key={duration.value} value={duration.value}>
                    {duration.label}
                  </option>
                ))}
              </select>
              <div className="form-text">Gemini Veo generate models accept 8 seconds.</div>
            </div>
            <div className="col-md-4">
              <label className="form-label" htmlFor="video-gen-resolution">
                Resolution
              </label>
              <select
                id="video-gen-resolution"
                className="form-select"
                value={values.resolution}
                onChange={(event) => setValues({ ...values, resolution: event.target.value })}
                disabled={generating}
              >
                {VIDEO_RESOLUTIONS.map((item) => (
                  <option key={item.value || 'default'} value={item.value}>
                    {item.label}
                  </option>
                ))}
              </select>
            </div>
          </div>

          <div className="row g-3 mb-4">
            <div className="col-md-6">
              <label className="form-label" htmlFor="video-gen-provider">
                Provider
              </label>
              <select
                id="video-gen-provider"
                className="form-select"
                value={values.provider}
                onChange={(event) => setValues({ ...values, provider: event.target.value })}
                disabled={generating}
              >
                <option value="gemini">Gemini Veo</option>
              </select>
            </div>
            <div className="col-md-6">
              <label className="form-label" htmlFor="video-gen-model">
                Model <span className="text-secondary fw-normal">(optional, must be a configured Veo model)</span>
              </label>
              <input
                id="video-gen-model"
                className="form-control"
                value={values.model}
                onChange={(event) => setValues({ ...values, model: event.target.value })}
                disabled={generating}
              />
            </div>
          </div>

          <button className="btn btn-primary" type="submit" disabled={generating || values.prompt.trim() === ''}>
            {generating ? 'Starting generation…' : parentVideo ? 'Generate new version' : 'Generate video'}
          </button>
        </div>
      </form>
    </div>
  );
}
