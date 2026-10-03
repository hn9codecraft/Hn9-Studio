import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import { ApiError } from '../../services/apiClient';
import { IMAGE_ASPECT_RATIOS, fieldError, imageGenerationErrorMessage } from '../../services/imageConstants';
import { generateImage, regenerateImage } from '../../services/imageService';
import { listScripts } from '../../services/scriptService';

const EMPTY = {
  title: '',
  prompt: '',
  aspect_ratio: '1:1',
  size: '',
  provider: '',
  model: '',
  script_id: '',
};

export default function ImageGenerateForm({ project, parentImage = null, basePath = `/projects/${project.id}/images` }) {
  const navigate = useNavigate();
  const initial = useMemo(() => {
    if (!parentImage) {
      return EMPTY;
    }

    return {
      ...EMPTY,
      title: parentImage.title || '',
      prompt: parentImage.generation?.prompt || parentImage.prompt || '',
      aspect_ratio: parentImage.aspect_ratio || '1:1',
      size: parentImage.generation?.size || '',
      provider: parentImage.generation?.provider || parentImage.provider || '',
      model: parentImage.generation?.model || '',
      script_id: parentImage.script_id || '',
    };
  }, [parentImage]);

  const [values, setValues] = useState(initial);
  const [scripts, setScripts] = useState([]);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState(null);

  useEffect(() => {
    let cancelled = false;

    listScripts(project.id)
      .then((result) => {
        if (!cancelled) {
          setScripts(result.data || []);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setScripts([]);
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
      size: values.size.trim() || null,
      provider: values.provider || null,
      model: values.model.trim() || null,
      script_id: values.script_id || null,
    };

    try {
      const result = parentImage
        ? await regenerateImage(project.id, parentImage.id, payload)
        : await generateImage(project.id, payload);
      const created = result?.image || result;
      navigate(`${basePath}/${created.id}`, {
        replace: true,
        state: {
          notice: parentImage
            ? 'A new image was generated. The previous image was left unchanged.'
            : 'Image generated.',
        },
      });
    } catch (err) {
      const apiError = err instanceof ApiError ? err : new ApiError('Unable to generate an image.', { status: 0 });
      setError(new ApiError(imageGenerationErrorMessage(apiError), {
        status: apiError.status,
        errorCode: apiError.errorCode,
        errors: apiError.errors,
      }));
      setGenerating(false);
    }
  }

  return (
    <div className="image-generate">
      <div className="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <Link to={parentImage ? `${basePath}/${parentImage.id}` : `${basePath}`} className="small text-decoration-none">
            <i className="bi bi-arrow-left me-1" aria-hidden="true" />
            Back
          </Link>
          <h2 className="section-title mt-3 mb-1">{parentImage ? 'Regenerate image' : 'Generate image'}</h2>
          <p className="text-secondary mb-0">
            {generating
              ? 'Waiting for the provider…'
              : 'Sends the prompt to a configured image provider. This does not replace an existing image.'}
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
            <label className="form-label" htmlFor="image-gen-script">
              Use a script <span className="text-secondary fw-normal">(optional)</span>
            </label>
            <select
              id="image-gen-script"
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
            <label className="form-label" htmlFor="image-gen-title">
              Title <span className="text-secondary fw-normal">(optional)</span>
            </label>
            <input
              id="image-gen-title"
              className="form-control"
              value={values.title}
              onChange={(event) => setValues({ ...values, title: event.target.value })}
              maxLength={255}
              disabled={generating}
            />
          </div>

          <div className="mb-3">
            <label className="form-label" htmlFor="image-gen-prompt">
              Image prompt
            </label>
            <textarea
              id="image-gen-prompt"
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
              <label className="form-label" htmlFor="image-gen-aspect">
                Aspect ratio
              </label>
              <select
                id="image-gen-aspect"
                className="form-select"
                value={values.aspect_ratio}
                onChange={(event) => setValues({ ...values, aspect_ratio: event.target.value })}
                disabled={generating}
              >
                {IMAGE_ASPECT_RATIOS.map((ratio) => (
                  <option key={ratio.value} value={ratio.value}>
                    {ratio.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-md-4">
              <label className="form-label" htmlFor="image-gen-size">
                Size <span className="text-secondary fw-normal">(optional)</span>
              </label>
              <input
                id="image-gen-size"
                className="form-control"
                value={values.size}
                placeholder="1024x1024"
                onChange={(event) => setValues({ ...values, size: event.target.value })}
                disabled={generating}
              />
            </div>
            <div className="col-md-4">
              <label className="form-label" htmlFor="image-gen-provider">
                Provider
              </label>
              <select
                id="image-gen-provider"
                className="form-select"
                value={values.provider}
                onChange={(event) => setValues({ ...values, provider: event.target.value })}
                disabled={generating}
              >
                <option value="">Image-capable provider</option>
                <option value="openai">OpenAI</option>
                <option value="gemini">Gemini</option>
              </select>
            </div>
          </div>

          <div className="mb-4">
            <label className="form-label" htmlFor="image-gen-model">
              Model <span className="text-secondary fw-normal">(optional, must be an image model)</span>
            </label>
            <input
              id="image-gen-model"
              className="form-control"
              value={values.model}
              onChange={(event) => setValues({ ...values, model: event.target.value })}
              disabled={generating}
            />
          </div>

          <button className="btn btn-primary" type="submit" disabled={generating || values.prompt.trim() === ''}>
            {generating ? 'Generating…' : parentImage ? 'Generate new version' : 'Generate image'}
          </button>
        </div>
      </form>
    </div>
  );
}
