import { useState } from 'react';
import { STORY_ASPECT_RATIOS, STORY_LANGUAGES, storyFieldError } from '../../services/storyConstants';
import { updateStoryBible } from '../../services/storyService';
import { friendlyError } from '../../services/studioMessages';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton, StepFooter, StepHeader } from './StudioUi';

const SUGGESTIONS = {
  genre: ['Adventure', 'Comedy', 'Drama', 'Fantasy', 'Mystery', 'Educational', 'Product story'],
  tone: ['Warm and hopeful', 'Playful', 'Suspenseful', 'Inspiring', 'Calm', 'Dramatic'],
  video_style: ['3D animation', 'Watercolor illustration', 'Anime', 'Cinematic live action', 'Flat 2D animation', 'Claymation'],
};

function formFromStory(story) {
  return {
    concept: story?.concept || '',
    genre: story?.genre || '',
    tone: story?.tone || '',
    audience: story?.audience || '',
    language: story?.language || '',
    video_style: story?.video_style || '',
    aspect_ratio: story?.aspect_ratio || '',
    world: story?.world || '',
    location: story?.location || '',
    time_period: story?.time_period || '',
    narrative_style: story?.narrative_style || '',
    voice_style: story?.audio_defaults?.voice_style || '',
    music_mood: story?.audio_defaults?.music_mood || '',
  };
}

export default function StudioStoryStep({ nav }) {
  const { projectId, bible, refresh } = useStudio();
  const feedback = useFeedback();
  const [saved, setSaved] = useState(() => formFromStory(bible));
  const [values, setValues] = useState(saved);
  const [errors, setErrors] = useState(null);
  const [saving, setSaving] = useState(false);
  const dirty = JSON.stringify(values) !== JSON.stringify(saved);
  const hasIdea = values.concept.trim().length > 0;

  function setField(field, value) {
    setValues((current) => ({ ...current, [field]: value }));
  }

  async function save() {
    setSaving(true);
    setErrors(null);
    const { voice_style, music_mood, ...rest } = values;

    try {
      const story = await updateStoryBible(projectId, {
        ...rest,
        aspect_ratio: rest.aspect_ratio || null,
        audio_defaults: { ...(bible?.audio_defaults || {}), voice_style, music_mood },
      });
      const next = formFromStory(story);
      setSaved(next);
      setValues(next);
      await refresh(['bible']);
      feedback.success('Story details saved.');
      return true;
    } catch (err) {
      setErrors(err);
      feedback.error(friendlyError(err, 'Your story details could not be saved. Please try again.'));
      return false;
    } finally {
      setSaving(false);
    }
  }

  async function continueToCast() {
    if (dirty && !(await save())) return;
    nav.onNavigate(nav.next?.key || 'cast');
  }

  function field(name, label, { helper = '', placeholder = '', textarea = false, rows = 3, list = null } = {}) {
    const error = storyFieldError(errors, name);
    const id = `story-${name}`;
    const Input = textarea ? 'textarea' : 'input';
    return (
      <div>
        <label className="form-label" htmlFor={id}>
          {label}
        </label>
        <Input
          id={id}
          className={`form-control${error ? ' is-invalid' : ''}`}
          value={values[name]}
          onChange={(event) => setField(name, event.target.value)}
          placeholder={placeholder}
          rows={textarea ? rows : undefined}
          list={list ? `${id}-options` : undefined}
          aria-describedby={helper ? `${id}-help` : undefined}
          disabled={saving}
        />
        {list ? (
          <datalist id={`${id}-options`}>
            {list.map((option) => (
              <option key={option} value={option} />
            ))}
          </datalist>
        ) : null}
        {helper ? (
          <div className="form-text" id={`${id}-help`}>
            {helper}
          </div>
        ) : null}
        {error ? <div className="invalid-feedback d-block">{error}</div> : null}
      </div>
    );
  }

  const nextLabel = nav.next?.label || 'Cast & Look';

  return (
    <div className="studio-step">
      <StepHeader
        title="Story"
        purpose="Describe your video once. Every later step (characters, scenes, video and sound) uses these details, so you never type them again."
      />

      <form
        className="card border-0 glass-card"
        onSubmit={(event) => {
          event.preventDefault();
          continueToCast();
        }}
        noValidate
      >
        <div className="card-body d-grid gap-3">
          <div>
            <label className="form-label" htmlFor="story-concept">
              Your idea <span className="text-danger" aria-hidden="true">*</span>
              <span className="visually-hidden"> (required)</span>
            </label>
            <textarea
              id="story-concept"
              className={`form-control${storyFieldError(errors, 'concept') ? ' is-invalid' : ''}`}
              rows={4}
              value={values.concept}
              onChange={(event) => setField('concept', event.target.value)}
              placeholder="e.g. A shy lighthouse keeper's daughter befriends a lost whale and helps it find its family before winter."
              aria-describedby="story-concept-help"
              aria-required="true"
              disabled={saving}
            />
            <div className="form-text" id="story-concept-help">
              One or two sentences: who it is about, what happens, and how it ends. Scenes are planned from this.
            </div>
            {storyFieldError(errors, 'concept') ? (
              <div className="invalid-feedback d-block">{storyFieldError(errors, 'concept')}</div>
            ) : null}
          </div>

          <div className="row g-3">
            <div className="col-md-6">{field('genre', 'Genre', { placeholder: 'e.g. Adventure', list: SUGGESTIONS.genre })}</div>
            <div className="col-md-6">{field('tone', 'Tone', { placeholder: 'e.g. Warm and hopeful', list: SUGGESTIONS.tone })}</div>
            <div className="col-md-6">
              {field('audience', 'Who is it for?', { placeholder: 'e.g. Families with children aged 6–10' })}
            </div>
            <div className="col-md-6">
              <label className="form-label" htmlFor="story-language">
                Language
              </label>
              <select
                id="story-language"
                className="form-select"
                value={values.language}
                onChange={(event) => setField('language', event.target.value)}
                disabled={saving}
              >
                {STORY_LANGUAGES.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </div>
            <div className="col-12">
              {field('video_style', 'Visual style', {
                placeholder: 'e.g. Watercolor illustration',
                helper: 'How the whole video should look. Cast & Look and every scene video use this.',
                list: SUGGESTIONS.video_style,
              })}
            </div>
          </div>

          <fieldset>
            <legend className="form-label mb-1">Video format</legend>
            <div className="studio-choice-grid">
              {STORY_ASPECT_RATIOS.map((option) => (
                <label key={option.value} className={`studio-choice${values.aspect_ratio === option.value ? ' is-selected' : ''}`}>
                  <input
                    type="radio"
                    name="story-aspect"
                    className="form-check-input"
                    value={option.value}
                    checked={values.aspect_ratio === option.value}
                    onChange={() => setField('aspect_ratio', option.value)}
                    disabled={saving}
                  />
                  <span>
                    <span className="d-block fw-semibold">{option.label}</span>
                    <span className="small text-secondary">{option.hint}</span>
                  </span>
                </label>
              ))}
            </div>
            {storyFieldError(errors, 'aspect_ratio') ? (
              <div className="invalid-feedback d-block">{storyFieldError(errors, 'aspect_ratio')}</div>
            ) : null}
          </fieldset>

          <details className="studio-more">
            <summary>More options (world, setting, narration and music)</summary>
            <div className="row g-3 mt-1">
              <div className="col-12">
                {field('world', 'The world of the story', {
                  textarea: true,
                  placeholder: 'e.g. A rocky northern coast where the sea is always cold and the town lives by the lighthouse.',
                })}
              </div>
              <div className="col-md-6">{field('location', 'Main location', { placeholder: 'e.g. A lighthouse on a cliff' })}</div>
              <div className="col-md-6">{field('time_period', 'Time period', { placeholder: 'e.g. Early 1900s' })}</div>
              <div className="col-md-6">
                {field('narrative_style', 'Storytelling style', { placeholder: 'e.g. Told by the girl, looking back' })}
              </div>
              <div className="col-md-6">{field('voice_style', 'Narrator voice', { placeholder: 'e.g. Gentle, older woman' })}</div>
              <div className="col-md-6">{field('music_mood', 'Music mood', { placeholder: 'e.g. Soft piano and strings' })}</div>
            </div>
          </details>
        </div>
      </form>

      <StepFooter
        prev={nav.prev}
        onNavigate={nav.onNavigate}
        primary={
          <div className="d-flex flex-wrap gap-2 justify-content-end">
            {dirty ? (
              <BusyButton className="btn btn-outline-primary" busy={saving} busyLabel="Saving…" onClick={save}>
                Save
              </BusyButton>
            ) : null}
            <BusyButton busy={saving} busyLabel="Saving…" disabled={!hasIdea} onClick={continueToCast}>
              {dirty ? `Save and continue to ${nextLabel}` : `Continue to ${nextLabel}`}
              <i className="bi bi-arrow-right ms-1" aria-hidden="true" />
            </BusyButton>
          </div>
        }
      />
      {!hasIdea ? <p className="small text-secondary text-end mt-2 mb-0">Add your idea to continue.</p> : null}
    </div>
  );
}
