import { useMemo, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import { ApiError } from '../../services/apiClient';
import {
  fieldError,
  generationErrorMessage,
  SCRIPT_LANGUAGES,
  SCRIPT_PLATFORMS,
} from '../../services/scriptConstants';
import { generateScript, regenerateScript } from '../../services/scriptService';

const EMPTY = {
  topic: '',
  platform: 'instagram',
  language: 'en',
  goal: '',
  duration: '',
  audience: '',
  tone: '',
  cta: '',
  additional_instructions: '',
};

export default function ScriptGenerateForm({ project, parentScript = null, onCancelTo }) {
  const navigate = useNavigate();
  const cancelPath = onCancelTo || `/projects/${project.id}/scripts`;
  const initial = useMemo(() => {
    if (!parentScript) {
      return EMPTY;
    }

    return {
      ...EMPTY,
      topic: parentScript.generation?.topic || parentScript.title || '',
      platform: parentScript.generation?.platform || 'instagram',
      language: parentScript.generation?.language || 'en',
      goal: parentScript.generation?.goal || '',
      duration: parentScript.generation?.payload?.duration || '',
      audience: parentScript.generation?.payload?.audience || '',
      tone: parentScript.generation?.payload?.tone || '',
      cta: parentScript.generation?.payload?.cta || '',
      additional_instructions: parentScript.generation?.payload?.additional_instructions || '',
    };
  }, [parentScript]);

  const [values, setValues] = useState(initial);
  const [generating, setGenerating] = useState(false);
  const [error, setError] = useState(null);

  const isVariation = Boolean(parentScript);

  async function handleSubmit(event) {
    event.preventDefault();
    setGenerating(true);
    setError(null);

    const payload = {
      topic: values.topic.trim(),
      platform: values.platform,
      language: values.language,
      goal: values.goal.trim() || null,
      duration: values.duration.trim() || null,
      audience: values.audience.trim() || null,
      tone: values.tone.trim() || null,
      cta: values.cta.trim() || null,
      additional_instructions: values.additional_instructions.trim() || null,
    };

    try {
      const result = parentScript
        ? await regenerateScript(project.id, parentScript.id, payload)
        : await generateScript(project.id, payload);
      const created = result?.script || result;
      navigate(`/projects/${project.id}/scripts/${created.id}`, {
        replace: true,
        state: {
          notice: isVariation
            ? 'A new AI variation was generated. The previous script was left unchanged.'
            : 'AI script generated.',
        },
      });
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to generate a script.', { status: 0 }));
      setGenerating(false);
    }
  }

  return (
    <div className="script-generate">
      <div className="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
          <Link to={cancelPath} className="small text-decoration-none">
            <i className="bi bi-arrow-left me-1" aria-hidden="true" />
            Back to Scripts
          </Link>
          <h2 className="section-title mt-3 mb-1">{isVariation ? 'Regenerate script' : 'Generate script'}</h2>
          <p className="text-secondary mb-0">
            {isVariation
              ? 'Creates a new AI variation. The current script is not overwritten.'
              : 'Uses the project generation pipeline. Nothing is saved until the provider returns a real result.'}
          </p>
        </div>
      </div>

      {error ? (
        <div className="mb-3">
          <AlertMessage>{generationErrorMessage(error)}</AlertMessage>
        </div>
      ) : null}

      {project.status === 'archived' ? (
        <div className="mb-3">
          <AlertMessage>
            This project is archived, so it cannot accept generation requests.
          </AlertMessage>
        </div>
      ) : null}

      {fieldError(error, 'topic') ? (
        <div className="mb-3">
          <AlertMessage>{fieldError(error, 'topic')}</AlertMessage>
        </div>
      ) : null}

      <form className="card border-0 shadow-sm" onSubmit={handleSubmit}>
        <div className="card-body p-4 p-md-5">
          <div className="row g-3">
            <div className="col-12">
              <label className="form-label" htmlFor="script-topic">
                Topic / brief
              </label>
              <input
                id="script-topic"
                className={`form-control ${fieldError(error, 'topic') ? 'is-invalid' : ''}`}
                value={values.topic}
                onChange={(event) => setValues({ ...values, topic: event.target.value })}
                maxLength={255}
                required
                disabled={generating}
              />
            </div>
            <div className="col-md-6">
              <label className="form-label" htmlFor="script-platform">
                Platform
              </label>
              <select
                id="script-platform"
                className="form-select"
                value={values.platform}
                onChange={(event) => setValues({ ...values, platform: event.target.value })}
                disabled={generating}
              >
                {SCRIPT_PLATFORMS.map((item) => (
                  <option key={item.value} value={item.value}>
                    {item.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-md-6">
              <label className="form-label" htmlFor="script-language">
                Language
              </label>
              <select
                id="script-language"
                className="form-select"
                value={values.language}
                onChange={(event) => setValues({ ...values, language: event.target.value })}
                disabled={generating}
              >
                {SCRIPT_LANGUAGES.map((item) => (
                  <option key={item.value} value={item.value}>
                    {item.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-md-6">
              <label className="form-label" htmlFor="script-duration">
                Duration
              </label>
              <input
                id="script-duration"
                className="form-control"
                value={values.duration}
                onChange={(event) => setValues({ ...values, duration: event.target.value })}
                placeholder="30s"
                disabled={generating}
              />
            </div>
            <div className="col-md-6">
              <label className="form-label" htmlFor="script-tone">
                Tone
              </label>
              <input
                id="script-tone"
                className="form-control"
                value={values.tone}
                onChange={(event) => setValues({ ...values, tone: event.target.value })}
                placeholder="Direct, warm, energetic"
                disabled={generating}
              />
            </div>
            <div className="col-12">
              <label className="form-label" htmlFor="script-audience">
                Target audience
              </label>
              <input
                id="script-audience"
                className="form-control"
                value={values.audience}
                onChange={(event) => setValues({ ...values, audience: event.target.value })}
                disabled={generating}
              />
            </div>
            <div className="col-12">
              <label className="form-label" htmlFor="script-goal">
                Goal
              </label>
              <textarea
                id="script-goal"
                className="form-control"
                rows="2"
                value={values.goal}
                onChange={(event) => setValues({ ...values, goal: event.target.value })}
                disabled={generating}
              />
            </div>
            <div className="col-12">
              <label className="form-label" htmlFor="script-cta">
                CTA
              </label>
              <input
                id="script-cta"
                className="form-control"
                value={values.cta}
                onChange={(event) => setValues({ ...values, cta: event.target.value })}
                disabled={generating}
              />
            </div>
            <div className="col-12">
              <label className="form-label" htmlFor="script-notes">
                Additional instructions
              </label>
              <textarea
                id="script-notes"
                className="form-control"
                rows="3"
                value={values.additional_instructions}
                onChange={(event) => setValues({ ...values, additional_instructions: event.target.value })}
                disabled={generating}
              />
            </div>
          </div>

          <div className="d-flex flex-wrap gap-2 mt-4">
            <button
              className="btn btn-primary"
              type="submit"
              disabled={generating || !values.topic.trim() || project.status === 'archived'}
            >
              {generating ? 'Generating…' : isVariation ? 'Create variation' : 'Generate script'}
            </button>
            <Link className="btn btn-outline-secondary" to={cancelPath}>
              Cancel
            </Link>
          </div>
        </div>
      </form>
    </div>
  );
}
