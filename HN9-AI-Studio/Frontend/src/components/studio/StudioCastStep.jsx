import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { aspectRatioLabel, storyFieldError } from '../../services/storyConstants';
import {
  approveStoryCharacterReference,
  approveStoryStyleReference,
  archiveStoryCharacter,
  archiveStoryCharacterReference,
  archiveStoryStyleReference,
  createStoryCharacter,
  generateStoryCharacterReference,
  generateStoryStyleReference,
  getStoryCharacterReferenceFileUrl,
  getStoryStyle,
  getStoryStyleReferenceFileUrl,
  listStoryCharacterReferences,
  listStoryStyleReferences,
  rejectStoryCharacterReference,
  rejectStoryStyleReference,
  submitStoryCharacterReferenceReview,
  submitStoryStyleReferenceReview,
  updateStoryCharacter,
  updateStoryStyle,
  uploadStoryCharacterReference,
  uploadStoryStyleReference,
} from '../../services/storyService';
import { friendlyError } from '../../services/studioMessages';
import { useFeedback, useStudio } from './StudioContext';
import StudioReferencePictures from './StudioReferencePictures';
import { BusyButton, StatusBadge, StepFooter, StepHeader, StepSkeleton, StoredPicture } from './StudioUi';

const CHARACTER_FIELDS = [
  'name',
  'short_description',
  'appearance',
  'clothing',
  'personality',
  'age',
  'gender_presentation',
  'face_description',
  'hair',
  'voice_description',
  'special_details',
];

const STYLE_FIELDS = [
  'mood',
  'lighting',
  'color_direction',
  'camera_style',
  'animation_style',
  'environment_style',
  'rendering_style',
  'visual_quality',
  'art_direction_notes',
];

function pick(source, fields) {
  return Object.fromEntries(fields.map((field) => [field, source?.[field] || '']));
}

export default function StudioCastStep({ nav }) {
  const { projectId, characters } = useStudio();
  const [searchParams, setSearchParams] = useSearchParams();
  const editing = searchParams.get('character');
  const character = editing && editing !== 'new' ? characters.find((item) => item.id === editing) : null;

  function openCharacter(id) {
    const query = new URLSearchParams(searchParams);
    query.set('section', 'cast');
    if (id) query.set('character', id);
    else query.delete('character');
    setSearchParams(query);
  }

  if (editing && (editing === 'new' || character)) {
    return (
      <div className="studio-step">
        <CharacterEditor
          key={editing}
          character={character}
          onOpen={(id) => {
            const query = new URLSearchParams(searchParams);
            query.set('section', 'cast');
            query.set('character', id);
            setSearchParams(query, { replace: true });
          }}
          onClose={() => openCharacter(null)}
        />
      </div>
    );
  }

  return (
    <div className="studio-step">
      <StepHeader
        title="Cast & Look"
        purpose="Add the characters in your video and define its look. Approved pictures keep every scene consistent."
      />

      <section className="card border-0 glass-card mb-4" aria-labelledby="cast-heading">
        <div className="card-body">
          <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h3 className="h5 mb-0" id="cast-heading">
              Cast
            </h3>
            <button type="button" className="btn btn-primary btn-sm" onClick={() => openCharacter('new')}>
              <i className="bi bi-person-plus me-1" aria-hidden="true" />
              Add character
            </button>
          </div>

          {characters.length === 0 ? (
            <div className="studio-empty">
              <i className="bi bi-people" aria-hidden="true" />
              <p className="mb-1 fw-semibold">No characters yet</p>
              <p className="small text-secondary mb-3">
                Add the people, animals or mascots that appear in your video. Skip this if your video has no characters.
              </p>
              <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => openCharacter('new')}>
                Add your first character
              </button>
            </div>
          ) : (
            <ul className="studio-card-grid list-unstyled mb-0">
              {characters.map((item) => (
                <li key={item.id}>
                  <button type="button" className="studio-character-card" onClick={() => openCharacter(item.id)}>
                    {item.approved_reference ? (
                      <StoredPicture
                        pictureKey={item.approved_reference.id}
                        load={() => getStoryCharacterReferenceFileUrl(projectId, item.id, item.approved_reference.id)}
                        alt={`Picture of ${item.name}`}
                      />
                    ) : (
                      <span className="studio-picture studio-picture--placeholder" aria-hidden="true">
                        <i className="bi bi-person" />
                      </span>
                    )}
                    <span className="studio-character-card-body">
                      <span className="fw-semibold d-block">{item.name}</span>
                      {item.short_description ? (
                        <span className="small text-secondary d-block studio-clamp">{item.short_description}</span>
                      ) : null}
                      <span className="mt-2 d-inline-block">
                        {item.approved_reference ? (
                          <StatusBadge tone="success">Ready</StatusBadge>
                        ) : (
                          <StatusBadge tone="warning">Needs a picture</StatusBadge>
                        )}
                      </span>
                    </span>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </div>
      </section>

      <LookAndFeel />

      <StepFooter prev={nav.prev} next={nav.next} onNavigate={nav.onNavigate} />
    </div>
  );
}

function CharacterEditor({ character, onOpen, onClose }) {
  const { projectId, refresh } = useStudio();
  const feedback = useFeedback();
  const [saved, setSaved] = useState(() => pick(character, CHARACTER_FIELDS));
  const [values, setValues] = useState(saved);
  const [errors, setErrors] = useState(null);
  const [saving, setSaving] = useState(false);
  const [confirmRemove, setConfirmRemove] = useState(false);
  const isNew = !character;
  const dirty = JSON.stringify(values) !== JSON.stringify(saved);

  const pictureApi = useMemo(
    () =>
      character
        ? {
            key: character.id,
            list: () => listStoryCharacterReferences(projectId, character.id),
            upload: (file) => uploadStoryCharacterReference(projectId, character.id, file),
            generate: () => generateStoryCharacterReference(projectId, character.id),
            submit: (id) => submitStoryCharacterReferenceReview(projectId, character.id, id),
            approve: (id) => approveStoryCharacterReference(projectId, character.id, id),
            reject: (id, comment) => rejectStoryCharacterReference(projectId, character.id, id, comment),
            archive: (id) => archiveStoryCharacterReference(projectId, character.id, id),
            file: (id) => getStoryCharacterReferenceFileUrl(projectId, character.id, id),
          }
        : null,
    [projectId, character],
  );

  async function save(event) {
    event.preventDefault();
    if (!values.name.trim()) {
      setErrors({ errors: { name: ['Give the character a name.'] } });
      return;
    }
    setSaving(true);
    setErrors(null);
    try {
      const result = isNew
        ? await createStoryCharacter(projectId, values)
        : await updateStoryCharacter(projectId, character.id, values);
      await refresh(['characters']);
      const next = pick(result, CHARACTER_FIELDS);
      setSaved(next);
      setValues(next);
      if (isNew) {
        feedback.success(`${result.name} was added. Now add a picture so they look the same in every scene.`);
        onOpen(result.id);
      } else {
        feedback.success('Character saved.');
      }
    } catch (err) {
      setErrors(err);
      feedback.error(friendlyError(err, 'The character could not be saved. Please try again.'));
    } finally {
      setSaving(false);
    }
  }

  async function remove() {
    setSaving(true);
    try {
      await archiveStoryCharacter(projectId, character.id);
      await refresh(['characters']);
      feedback.success(`${character.name} was removed from the cast.`);
      onClose();
    } catch (err) {
      feedback.error(friendlyError(err, 'The character could not be removed. Please try again.'));
      setSaving(false);
    }
  }

  function input(name, label, { placeholder = '', textarea = false, required = false } = {}) {
    const id = `character-${name}`;
    const error = storyFieldError(errors, name);
    const Input = textarea ? 'textarea' : 'input';
    return (
      <div>
        <label className="form-label" htmlFor={id}>
          {label}
          {required ? (
            <>
              <span className="text-danger" aria-hidden="true"> *</span>
              <span className="visually-hidden"> (required)</span>
            </>
          ) : null}
        </label>
        <Input
          id={id}
          className={`form-control${error ? ' is-invalid' : ''}`}
          rows={textarea ? 2 : undefined}
          value={values[name]}
          placeholder={placeholder}
          onChange={(event) => setValues((current) => ({ ...current, [name]: event.target.value }))}
          disabled={saving}
          aria-required={required || undefined}
          autoFocus={name === 'name' && isNew}
        />
        {error ? <div className="invalid-feedback d-block">{error}</div> : null}
      </div>
    );
  }

  return (
    <>
      <button type="button" className="btn btn-link px-0 mb-2" onClick={onClose}>
        <i className="bi bi-arrow-left me-1" aria-hidden="true" />
        Back to Cast & Look
      </button>
      <StepHeader
        title={isNew ? 'New character' : character.name}
        purpose={
          isNew
            ? 'Describe the character, then save. You can add pictures after saving.'
            : 'Keep the description and an approved picture up to date; scenes use both.'
        }
      />

      <div className="row g-4">
        <div className={isNew ? 'col-12 col-xl-8' : 'col-lg-6'}>
          <form className="card border-0 glass-card" onSubmit={save} noValidate>
            <div className="card-body d-grid gap-3">
              {input('name', 'Name', { placeholder: 'e.g. Maya', required: true })}
              {input('short_description', 'Who are they?', {
                textarea: true,
                placeholder: 'e.g. The lighthouse keeper’s curious 10-year-old daughter',
              })}
              {input('appearance', 'What do they look like?', {
                textarea: true,
                placeholder: 'e.g. Freckles, big brown eyes, small for her age',
              })}
              {input('clothing', 'Clothing', { placeholder: 'e.g. Yellow raincoat and red boots' })}
              {input('personality', 'Personality', { placeholder: 'e.g. Brave but quiet, loves the sea' })}
              <details className="studio-more">
                <summary>More details (age, face, hair, voice)</summary>
                <div className="row g-3 mt-1">
                  <div className="col-md-6">{input('age', 'Age', { placeholder: 'e.g. 10' })}</div>
                  <div className="col-md-6">{input('gender_presentation', 'Gender', { placeholder: 'e.g. Girl' })}</div>
                  <div className="col-md-6">{input('face_description', 'Face', { placeholder: 'e.g. Round face, freckles' })}</div>
                  <div className="col-md-6">{input('hair', 'Hair', { placeholder: 'e.g. Short curly red hair' })}</div>
                  <div className="col-12">{input('voice_description', 'Voice', { placeholder: 'e.g. Soft and excited' })}</div>
                  <div className="col-12">
                    {input('special_details', 'Anything that must always appear', { placeholder: 'e.g. Always carries a brass compass' })}
                  </div>
                </div>
              </details>
              <div className="d-flex flex-wrap gap-2 justify-content-between">
                <div className="d-flex gap-2">
                  <BusyButton type="submit" busy={saving} busyLabel="Saving…" disabled={!isNew && !dirty}>
                    {isNew ? 'Save character' : 'Save changes'}
                  </BusyButton>
                  <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={saving}>
                    {isNew ? 'Cancel' : 'Done'}
                  </button>
                </div>
                {!isNew && !confirmRemove ? (
                  <button type="button" className="btn btn-link text-danger px-0" onClick={() => setConfirmRemove(true)} disabled={saving}>
                    Remove character
                  </button>
                ) : null}
                {!isNew && confirmRemove ? (
                  <span className="d-inline-flex align-items-center gap-2">
                    <span className="small">Remove {character.name}?</span>
                    <button type="button" className="btn btn-danger btn-sm" onClick={remove} disabled={saving}>
                      Yes, remove
                    </button>
                    <button type="button" className="btn btn-link btn-sm" onClick={() => setConfirmRemove(false)}>
                      Keep
                    </button>
                  </span>
                ) : null}
              </div>
            </div>
          </form>
        </div>

        {!isNew ? (
          <div className="col-lg-6">
            <section className="card border-0 glass-card" aria-labelledby="character-pictures-heading">
              <div className="card-body">
                <h3 className="h6 mb-1" id="character-pictures-heading">
                  Pictures of {character.name}
                </h3>
                <p className="small text-secondary">The approved picture is used whenever this character appears in a scene.</p>
                <StudioReferencePictures subject={character.name} api={pictureApi} onChanged={() => refresh(['characters'])} />
              </div>
            </section>
          </div>
        ) : null}
      </div>
    </>
  );
}

function LookAndFeel() {
  const { projectId, bible, goTo } = useStudio();
  const feedback = useFeedback();
  const [style, setStyle] = useState(null);
  const [saved, setSaved] = useState(() => pick(null, STYLE_FIELDS));
  const [values, setValues] = useState(saved);
  const [errors, setErrors] = useState(null);
  const [saving, setSaving] = useState(false);
  const dirty = JSON.stringify(values) !== JSON.stringify(saved);

  useEffect(() => {
    let cancelled = false;
    getStoryStyle(projectId)
      .then((result) => {
        if (cancelled) return;
        const next = pick(result, STYLE_FIELDS);
        setStyle(result || {});
        setSaved(next);
        setValues(next);
      })
      .catch(() => {
        if (!cancelled) setStyle({});
      });
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  const pictureApi = useMemo(
    () => ({
      key: `style-${projectId}`,
      list: () => listStoryStyleReferences(projectId),
      upload: (file) => uploadStoryStyleReference(projectId, file),
      generate: () => generateStoryStyleReference(projectId),
      submit: (id) => submitStoryStyleReferenceReview(projectId, id),
      approve: (id) => approveStoryStyleReference(projectId, id),
      reject: (id, comment) => rejectStoryStyleReference(projectId, id, comment),
      archive: (id) => archiveStoryStyleReference(projectId, id),
      file: (id) => getStoryStyleReferenceFileUrl(projectId, id),
    }),
    [projectId],
  );

  async function save(event) {
    event.preventDefault();
    setSaving(true);
    setErrors(null);
    try {
      const result = await updateStoryStyle(projectId, values);
      const next = pick(result, STYLE_FIELDS);
      setSaved(next);
      setValues(next);
      feedback.success('Look & feel saved.');
    } catch (err) {
      setErrors(err);
      feedback.error(friendlyError(err, 'The look & feel could not be saved. Please try again.'));
    } finally {
      setSaving(false);
    }
  }

  function input(name, label, placeholder, textarea = false) {
    const id = `style-${name}`;
    const error = storyFieldError(errors, name);
    const Input = textarea ? 'textarea' : 'input';
    return (
      <div>
        <label className="form-label" htmlFor={id}>
          {label}
        </label>
        <Input
          id={id}
          className={`form-control${error ? ' is-invalid' : ''}`}
          rows={textarea ? 3 : undefined}
          value={values[name]}
          placeholder={placeholder}
          onChange={(event) => setValues((current) => ({ ...current, [name]: event.target.value }))}
          disabled={saving}
        />
        {error ? <div className="invalid-feedback d-block">{error}</div> : null}
      </div>
    );
  }

  return (
    <section className="card border-0 glass-card mb-4" aria-labelledby="look-heading">
      <div className="card-body">
        <h3 className="h5 mb-1" id="look-heading">
          Look & Feel
        </h3>
        <p className="small text-secondary">Lighting, colour and camera choices applied to every scene.</p>

        <div className="studio-inherited mb-3">
          <span>
            <span className="text-secondary">Visual style:</span> <strong>{bible?.video_style || 'Not set yet'}</strong>
          </span>
          <span>
            <span className="text-secondary">Format:</span> <strong>{aspectRatioLabel(bible?.aspect_ratio)}</strong>
          </span>
          <button type="button" className="btn btn-link btn-sm px-0" onClick={() => goTo('story')}>
            Change in Story
          </button>
        </div>

        {style === null ? (
          <StepSkeleton rows={1} />
        ) : (
          <div className="row g-4">
            <div className="col-lg-6">
              <form className="d-grid gap-3" onSubmit={save} noValidate>
                {input('mood', 'Mood', 'e.g. Cosy and magical')}
                {input('lighting', 'Lighting', 'e.g. Soft golden evening light')}
                {input('color_direction', 'Colours', 'e.g. Warm oranges with deep sea blues')}
                {input('camera_style', 'Camera', 'e.g. Slow, gentle movements; wide shots')}
                <details className="studio-more">
                  <summary>More options</summary>
                  <div className="d-grid gap-3 mt-2">
                    {input('animation_style', 'Movement', 'e.g. Smooth, unhurried animation')}
                    {input('environment_style', 'Backgrounds', 'e.g. Painterly, detailed coastlines')}
                    {input('rendering_style', 'Rendering', 'e.g. Soft textures, visible brush strokes')}
                    {input('visual_quality', 'Detail level', 'e.g. Highly detailed')}
                    {input('art_direction_notes', 'Other notes', 'Anything else every scene should follow', true)}
                  </div>
                </details>
                <div>
                  <BusyButton type="submit" busy={saving} busyLabel="Saving…" disabled={!dirty}>
                    Save look & feel
                  </BusyButton>
                </div>
              </form>
            </div>
            <div className="col-lg-6">
              <h4 className="h6 mb-1">Style pictures</h4>
              <p className="small text-secondary">An approved picture shows the video service exactly how your video should look.</p>
              <StudioReferencePictures subject="the look & feel" api={pictureApi} />
            </div>
          </div>
        )}
      </div>
    </section>
  );
}
