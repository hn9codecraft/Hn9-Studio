import { useEffect, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { STORY_ASPECT_RATIOS, STORY_LANGUAGES, storyFieldError } from '../../services/storyConstants';
import { getStoryBible, updateStoryBible } from '../../services/storyService';

const EMPTY_AUDIO = {
  voice_enabled: null,
  music_enabled: null,
  sfx_enabled: null,
  voice_style: '',
  music_mood: '',
};

function formFromBible(bible) {
  return {
    concept: bible?.concept || '',
    genre: bible?.genre || '',
    audience: bible?.audience || '',
    language: bible?.language || '',
    tone: bible?.tone || '',
    world: bible?.world || '',
    location: bible?.location || '',
    time_period: bible?.time_period || '',
    narrative_style: bible?.narrative_style || '',
    video_style: bible?.video_style || '',
    aspect_ratio: bible?.aspect_ratio || '',
    default_duration: bible?.default_duration ?? '',
    audio_defaults: {
      ...EMPTY_AUDIO,
      ...(bible?.audio_defaults || {}),
      voice_style: bible?.audio_defaults?.voice_style || '',
      music_mood: bible?.audio_defaults?.music_mood || '',
    },
  };
}

function audioChoice(value) {
  if (value === true) {
    return 'yes';
  }
  if (value === false) {
    return 'no';
  }
  return '';
}

function parseAudioChoice(value) {
  if (value === 'yes') {
    return true;
  }
  if (value === 'no') {
    return false;
  }
  return null;
}

export default function StoryBibleForm({ projectId }) {
  const [values, setValues] = useState(formFromBible(null));
  const [configured, setConfigured] = useState(false);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState('');

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError(null);
      setNotice('');

      try {
        const bible = await getStoryBible(projectId);
        if (!cancelled) {
          setValues(formFromBible(bible));
          setConfigured(Boolean(bible?.configured));
        }
      } catch (err) {
        if (!cancelled) {
          setValues(formFromBible(null));
          setConfigured(false);
          setError(err instanceof ApiError ? err : new ApiError('Unable to load the Story Bible.'));
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
  }, [projectId]);

  function setField(field, value) {
    setValues((current) => ({ ...current, [field]: value }));
  }

  function setAudio(field, value) {
    setValues((current) => ({
      ...current,
      audio_defaults: { ...current.audio_defaults, [field]: value },
    }));
  }

  async function handleSubmit(event) {
    event.preventDefault();
    setSaving(true);
    setError(null);
    setNotice('');

    const payload = {
      ...values,
      default_duration: values.default_duration === '' ? null : Number(values.default_duration),
      audio_defaults: {
        voice_enabled: values.audio_defaults.voice_enabled,
        music_enabled: values.audio_defaults.music_enabled,
        sfx_enabled: values.audio_defaults.sfx_enabled,
        voice_style: values.audio_defaults.voice_style,
        music_mood: values.audio_defaults.music_mood,
      },
    };

    try {
      const bible = await updateStoryBible(projectId, payload);
      setValues(formFromBible(bible));
      setConfigured(Boolean(bible?.configured));
      setNotice('Story Bible saved.');
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to save the Story Bible.'));
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <LoadingSpinner label="Loading Story Bible…" />;
  }

  return (
    <section className="story-bible mt-4" aria-labelledby="story-bible-heading">
      <div className="card border-0 glass-card">
        <div className="card-body">
          <h2 id="story-bible-heading" className="h4 mb-2">
            Story Bible
          </h2>
          <p className="page-lede mb-4">
            {configured
              ? 'Permanent story context for this project. Later planner and video modules will read these values.'
              : 'This Story Bible is empty. Enter the project’s permanent story context and save it.'}
          </p>

          {notice ? (
            <div className="mb-3">
              <AlertMessage variant="success">{notice}</AlertMessage>
            </div>
          ) : null}

          {error?.message ? (
            <div className="mb-3">
              <AlertMessage>{error.message}</AlertMessage>
            </div>
          ) : null}

          <form onSubmit={handleSubmit}>
            <div className="mb-3">
              <label className="form-label" htmlFor="story-concept">
                Story concept
              </label>
              <textarea
                id="story-concept"
                className={`form-control ${storyFieldError(error, 'concept') ? 'is-invalid' : ''}`}
                rows="4"
                value={values.concept}
                onChange={(event) => setField('concept', event.target.value)}
              />
              {storyFieldError(error, 'concept') ? (
                <div className="invalid-feedback">{storyFieldError(error, 'concept')}</div>
              ) : null}
            </div>

            <div className="row g-3">
              <div className="col-md-6">
                <label className="form-label" htmlFor="story-genre">
                  Genre
                </label>
                <input
                  id="story-genre"
                  className={`form-control ${storyFieldError(error, 'genre') ? 'is-invalid' : ''}`}
                  value={values.genre}
                  onChange={(event) => setField('genre', event.target.value)}
                />
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="story-audience">
                  Audience
                </label>
                <input
                  id="story-audience"
                  className={`form-control ${storyFieldError(error, 'audience') ? 'is-invalid' : ''}`}
                  value={values.audience}
                  onChange={(event) => setField('audience', event.target.value)}
                />
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
                >
                  {STORY_LANGUAGES.map((option) => (
                    <option key={option.value || 'unset'} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="story-tone">
                  Tone
                </label>
                <input
                  id="story-tone"
                  className="form-control"
                  value={values.tone}
                  onChange={(event) => setField('tone', event.target.value)}
                />
              </div>
              <div className="col-12">
                <label className="form-label" htmlFor="story-world">
                  World
                </label>
                <textarea
                  id="story-world"
                  className="form-control"
                  rows="3"
                  value={values.world}
                  onChange={(event) => setField('world', event.target.value)}
                />
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="story-location">
                  Location
                </label>
                <input
                  id="story-location"
                  className="form-control"
                  value={values.location}
                  onChange={(event) => setField('location', event.target.value)}
                />
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="story-time-period">
                  Time period
                </label>
                <input
                  id="story-time-period"
                  className="form-control"
                  value={values.time_period}
                  onChange={(event) => setField('time_period', event.target.value)}
                />
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="story-narrative-style">
                  Narrative style
                </label>
                <input
                  id="story-narrative-style"
                  className="form-control"
                  value={values.narrative_style}
                  onChange={(event) => setField('narrative_style', event.target.value)}
                />
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="story-video-style">
                  Video style
                </label>
                <input
                  id="story-video-style"
                  className="form-control"
                  value={values.video_style}
                  onChange={(event) => setField('video_style', event.target.value)}
                />
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="story-aspect-ratio">
                  Aspect ratio
                </label>
                <select
                  id="story-aspect-ratio"
                  className={`form-select ${storyFieldError(error, 'aspect_ratio') ? 'is-invalid' : ''}`}
                  value={values.aspect_ratio}
                  onChange={(event) => setField('aspect_ratio', event.target.value)}
                >
                  {STORY_ASPECT_RATIOS.map((option) => (
                    <option key={option.value || 'unset'} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
                {storyFieldError(error, 'aspect_ratio') ? (
                  <div className="invalid-feedback">{storyFieldError(error, 'aspect_ratio')}</div>
                ) : null}
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="story-default-duration">
                  Default duration (seconds)
                </label>
                <input
                  id="story-default-duration"
                  type="number"
                  min="0"
                  max="300"
                  className={`form-control ${storyFieldError(error, 'default_duration') ? 'is-invalid' : ''}`}
                  value={values.default_duration}
                  onChange={(event) => setField('default_duration', event.target.value)}
                />
                {storyFieldError(error, 'default_duration') ? (
                  <div className="invalid-feedback">{storyFieldError(error, 'default_duration')}</div>
                ) : null}
              </div>
            </div>

            <h3 className="h6 mt-4 mb-3">Audio defaults</h3>
            <div className="row g-3">
              <div className="col-md-4">
                <label className="form-label" htmlFor="story-voice-enabled">
                  Voice
                </label>
                <select
                  id="story-voice-enabled"
                  className="form-select"
                  value={audioChoice(values.audio_defaults.voice_enabled)}
                  onChange={(event) => setAudio('voice_enabled', parseAudioChoice(event.target.value))}
                >
                  <option value="">Not set</option>
                  <option value="yes">Enabled</option>
                  <option value="no">Disabled</option>
                </select>
              </div>
              <div className="col-md-4">
                <label className="form-label" htmlFor="story-music-enabled">
                  Music
                </label>
                <select
                  id="story-music-enabled"
                  className="form-select"
                  value={audioChoice(values.audio_defaults.music_enabled)}
                  onChange={(event) => setAudio('music_enabled', parseAudioChoice(event.target.value))}
                >
                  <option value="">Not set</option>
                  <option value="yes">Enabled</option>
                  <option value="no">Disabled</option>
                </select>
              </div>
              <div className="col-md-4">
                <label className="form-label" htmlFor="story-sfx-enabled">
                  SFX
                </label>
                <select
                  id="story-sfx-enabled"
                  className="form-select"
                  value={audioChoice(values.audio_defaults.sfx_enabled)}
                  onChange={(event) => setAudio('sfx_enabled', parseAudioChoice(event.target.value))}
                >
                  <option value="">Not set</option>
                  <option value="yes">Enabled</option>
                  <option value="no">Disabled</option>
                </select>
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="story-voice-style">
                  Voice style
                </label>
                <input
                  id="story-voice-style"
                  className="form-control"
                  value={values.audio_defaults.voice_style}
                  onChange={(event) => setAudio('voice_style', event.target.value)}
                />
              </div>
              <div className="col-md-6">
                <label className="form-label" htmlFor="story-music-mood">
                  Music mood
                </label>
                <input
                  id="story-music-mood"
                  className="form-control"
                  value={values.audio_defaults.music_mood}
                  onChange={(event) => setAudio('music_mood', event.target.value)}
                />
              </div>
            </div>

            <div className="mt-4">
              <button type="submit" className="btn btn-primary" disabled={saving}>
                {saving ? 'Saving…' : 'Save Story Bible'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </section>
  );
}
