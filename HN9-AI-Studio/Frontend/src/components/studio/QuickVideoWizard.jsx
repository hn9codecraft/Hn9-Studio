import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { STORY_ASPECT_RATIOS, STORY_LANGUAGES } from '../../services/storyConstants';
import { estimateQuickStart, listStoryTemplates, quickStartStory } from '../../services/storyService';
import { friendlyError } from '../../services/studioMessages';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton } from './StudioUi';

const LENGTHS = [
  { value: 40, label: '40 sec' },
  { value: 60, label: '1 min' },
  { value: 180, label: '3 min' },
];

function money(line) {
  if (!line || line.available === false || line.amount_usd == null) return 'not available';
  const usd = `$${Number(line.amount_usd).toFixed(2)}`;
  if (line.display_currency === 'INR' && line.amount_display != null) {
    return `₹${Number(line.amount_display).toFixed(2)} (${usd})`;
  }
  return usd;
}

export default function QuickVideoWizard({ overwrite = false, onCancel, onDone }) {
  const { projectId, refresh } = useStudio();
  const { user } = useAuth();
  const feedback = useFeedback();
  const idempotencyKey = useRef(crypto.randomUUID());
  const [templates, setTemplates] = useState([]);
  const [step, setStep] = useState(0);
  const [selected, setSelected] = useState(null);
  const [topic, setTopic] = useState('');
  const [duration, setDuration] = useState(40);
  const [aspect, setAspect] = useState('16:9');
  const [language, setLanguage] = useState('en');
  const [tone, setTone] = useState('');
  const [style, setStyle] = useState('');
  const [fields, setFields] = useState({});
  const [characters, setCharacters] = useState([]);
  const [estimate, setEstimate] = useState(null);
  const [estimating, setEstimating] = useState(false);
  const [running, setRunning] = useState(false);
  const [loadError, setLoadError] = useState('');

  useEffect(() => {
    let cancelled = false;
    listStoryTemplates()
      .then((payload) => {
        if (!cancelled) setTemplates(Array.isArray(payload?.templates) ? payload.templates : []);
      })
      .catch((error) => {
        if (!cancelled) setLoadError(friendlyError(error, 'Templates could not be loaded.'));
      });
    return () => {
      cancelled = true;
    };
  }, []);

  const template = templates.find((item) => item.key === selected) || null;

  function choose(item) {
    setSelected(item.key);
    setDuration(item.defaults?.duration_seconds || 40);
    setAspect(item.defaults?.aspect_ratio || '16:9');
    setLanguage(item.defaults?.language || '');
    setTone(item.defaults?.tone || '');
    setStyle(item.defaults?.video_style || '');
    setFields({});
    setCharacters(
      (item.characters || []).slice(0, 4).map((character) => ({
        name: character.name,
        short_description: character.short_description || '',
      })),
    );
    setStep(1);
  }

  const payload = useMemo(
    () => ({
      template: selected,
      topic: topic.trim(),
      duration_seconds: duration,
      aspect_ratio: aspect,
      language,
      tone,
      style,
      fields,
      characters: characters.filter((character) => character.name.trim()).slice(0, 4),
      overwrite,
      idempotency_key: idempotencyKey.current,
    }),
    [aspect, characters, duration, fields, language, overwrite, selected, style, tone, topic],
  );

  useEffect(() => {
    if (step !== 2 || !selected || topic.trim().length < 3) return undefined;
    let cancelled = false;
    setEstimating(true);
    estimateQuickStart(projectId, payload)
      .then((result) => {
        if (!cancelled) setEstimate(result);
      })
      .catch(() => {
        if (!cancelled) setEstimate(null);
      })
      .finally(() => {
        if (!cancelled) setEstimating(false);
      });
    return () => {
      cancelled = true;
    };
  }, [payload, projectId, selected, step, topic]);

  async function createDraft() {
    setRunning(true);
    try {
      const result = await quickStartStory(projectId, payload);
      if (result?.plan_generation === 'not_connected') {
        feedback.info('Draft saved. Connect a text AI to generate the plan');
      } else if (result?.plan_generation === 'failed') {
        feedback.error(`Draft saved. Plan generation failed: ${result.error || 'try again.'}`);
      } else {
        feedback.success('Draft ready. Review the story plan');
      }
      await refresh(['bible', 'characters']);
      onDone();
    } catch (error) {
      if (error.errorCode === 'story_budget_exceeded') {
        feedback.error(friendlyError(error));
        await refresh(['bible', 'characters']);
        onDone();
        return;
      }
      feedback.error(friendlyError(error, 'The draft could not be created.'));
    } finally {
      setRunning(false);
    }
  }

  const requiredMissing = (template?.fields || []).some(
    (field) => field.required && !String(fields[field.key] || '').trim(),
  );
  const textConnected = estimate?.text_connected !== false;

  return (
    <section className="card border-0 glass-card" aria-labelledby="quick-start-title">
      <div className="card-body d-grid gap-3">
        <div>
          <h2 className="h5 mb-1" id="quick-start-title">
            Quick start
          </h2>
          <p className="text-secondary mb-0">
            Pick a template, add the topic, and create a draft plan. Nothing is approved or produced.
          </p>
        </div>
        {loadError ? <p className="text-danger mb-0">{loadError}</p> : null}

        {step === 0 ? (
          <div className="row g-3">
            {templates.map((item) => (
              <div className="col-md-4" key={item.key}>
                <button
                  type="button"
                  className="card h-100 border-0 glass-card text-start w-100"
                  onClick={() => choose(item)}
                >
                  <span className="card-body">
                    <span className="d-block fw-semibold">{item.label}</span>
                    <span className="d-block small text-secondary mt-1">{item.description}</span>
                    <span className="d-block small mt-2">
                      {LENGTHS.find((length) => length.value === item.defaults?.duration_seconds)?.label ||
                        `${item.defaults?.duration_seconds}s`}
                      {' · '}
                      {item.defaults?.aspect_ratio}
                    </span>
                  </span>
                </button>
              </div>
            ))}
          </div>
        ) : null}

        {step === 1 && template ? (
          <div className="d-grid gap-3">
            <label className="d-grid gap-1">
              <span className="form-label mb-0">Topic</span>
              <textarea className="form-control" rows={3} value={topic} onChange={(event) => setTopic(event.target.value)} />
            </label>
            <div>
              <span className="form-label">Length</span>
              <div className="d-flex flex-wrap gap-2">
                {LENGTHS.map((length) => (
                  <button
                    key={length.value}
                    type="button"
                    className={`btn btn-sm ${duration === length.value ? 'btn-primary' : 'btn-outline-secondary'}`}
                    onClick={() => setDuration(length.value)}
                  >
                    {length.label}
                  </button>
                ))}
              </div>
            </div>
            <label className="d-grid gap-1">
              <span className="form-label mb-0">Aspect</span>
              <select className="form-select" value={aspect} onChange={(event) => setAspect(event.target.value)}>
                {STORY_ASPECT_RATIOS.map((item) => (
                  <option key={item.value} value={item.value}>
                    {item.label}
                  </option>
                ))}
              </select>
            </label>
            <label className="d-grid gap-1">
              <span className="form-label mb-0">Language</span>
              <select className="form-select" value={language} onChange={(event) => setLanguage(event.target.value)}>
                {STORY_LANGUAGES.map((item) => (
                  <option key={item.value || 'unset'} value={item.value}>
                    {item.label}
                  </option>
                ))}
              </select>
            </label>
            <label className="d-grid gap-1">
              <span className="form-label mb-0">Tone</span>
              <input className="form-control" value={tone} maxLength={100} onChange={(event) => setTone(event.target.value)} />
            </label>
            <label className="d-grid gap-1">
              <span className="form-label mb-0">Style</span>
              <input className="form-control" value={style} maxLength={255} onChange={(event) => setStyle(event.target.value)} />
            </label>
            {(template.fields || []).map((field) => (
              <label className="d-grid gap-1" key={field.key}>
                <span className="form-label mb-0">
                  {field.label}
                  {field.required ? ' *' : ''}
                </span>
                <textarea
                  className="form-control"
                  rows={field.max > 200 ? 3 : 2}
                  maxLength={field.max}
                  value={fields[field.key] || ''}
                  onChange={(event) => setFields((current) => ({ ...current, [field.key]: event.target.value }))}
                />
              </label>
            ))}
            <div className="d-grid gap-2">
              <span className="form-label mb-0">Characters</span>
              {characters.map((character, index) => (
                <div className="d-flex gap-2" key={`${character.name}-${index}`}>
                  <input
                    className="form-control"
                    value={character.name}
                    maxLength={120}
                    aria-label={`Character ${index + 1} name`}
                    onChange={(event) =>
                      setCharacters((current) =>
                        current.map((item, itemIndex) => (itemIndex === index ? { ...item, name: event.target.value } : item)),
                      )
                    }
                  />
                  <button
                    type="button"
                    className="btn btn-outline-secondary"
                    onClick={() => setCharacters((current) => current.filter((_, itemIndex) => itemIndex !== index))}
                  >
                    Remove
                  </button>
                </div>
              ))}
              {characters.length < 4 ? (
                <button
                  type="button"
                  className="btn btn-link btn-sm text-start px-0"
                  onClick={() => setCharacters((current) => [...current, { name: '', short_description: '' }])}
                >
                  Add a character
                </button>
              ) : null}
            </div>
          </div>
        ) : null}

        {step === 2 && template ? (
          <div className="d-grid gap-2">
            <p className="mb-0">
              <span className="fw-semibold">{template.label}.</span> {topic.trim()} ·{' '}
              {LENGTHS.find((length) => length.value === duration)?.label} · {aspect}
            </p>
            <p className="mb-0">
              <span className="fw-semibold">Plan step: </span>
              {estimating
                ? 'Checking the price…'
                : estimate?.plan_estimate?.available
                  ? money(estimate.plan_estimate)
                  : 'Price estimate not available'}
            </p>
            {!estimate?.plan_estimate?.available ? (
              <p className="small text-secondary mb-0">Set STORY_COST_TEXT_PER_1K_TOKENS to see this</p>
            ) : null}
            <p className="mb-0">
              <span className="fw-semibold">Rough full-video estimate: </span>
              {estimate?.full_video_preview?.available ? money({ ...estimate.full_video_preview, amount_usd: estimate.full_video_preview.total_usd, available: true }) : 'not available'}
            </p>
            <p className="small text-secondary mb-0">
              Video {money(estimate?.full_video_preview?.video)} · Voice {money(estimate?.full_video_preview?.voice)}. This rough
              price is not charged and does not change the budget.
            </p>
            <p className="small mb-0">
              Spent ${Number(estimate?.budget?.spent_total_usd || 0).toFixed(2)}
              {estimate?.budget?.remaining?.amount_usd != null
                ? ` · Remaining $${Number(estimate.budget.remaining.amount_usd).toFixed(2)}`
                : ' · No budget set'}
            </p>
            {!textConnected ? (
              <p className="mb-0">
                Text AI not connected. You can still create the draft.
                {user?.role === 'admin' ? (
                  <>
                    {' '}
                    <Link to="/providers">Connect a text AI</Link>
                  </>
                ) : null}
              </p>
            ) : null}
          </div>
        ) : null}

        <div className="d-flex flex-wrap gap-2">
          {step > 0 ? (
            <button type="button" className="btn btn-outline-secondary" onClick={() => setStep((current) => current - 1)} disabled={running}>
              Back
            </button>
          ) : null}
          {step === 1 ? (
            <button
              type="button"
              className="btn btn-primary"
              disabled={topic.trim().length < 3 || requiredMissing}
              onClick={() => setStep(2)}
            >
              Review
            </button>
          ) : null}
          {step === 2 ? (
            <BusyButton type="button" busy={running} busyLabel="Creating draft…" disabled={running || topic.trim().length < 3} onClick={createDraft}>
              {textConnected ? 'Create draft' : 'Create draft only'}
            </BusyButton>
          ) : null}
          <button type="button" className="btn btn-link" onClick={onCancel} disabled={running}>
            {overwrite ? 'Cancel' : "Skip, I'll fill it myself"}
          </button>
        </div>
      </div>
    </section>
  );
}
