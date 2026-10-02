import { useEffect, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { characterReferenceStatusLabel, storyFieldError } from '../../services/storyConstants';
import {
  approveStoryCharacterReference,
  archiveStoryCharacter,
  archiveStoryCharacterReference,
  createStoryCharacter,
  generateStoryCharacterReference,
  getStoryCharacter,
  getStoryCharacterReferenceFileUrl,
  listStoryCharacterReferences,
  listStoryCharacters,
  rejectStoryCharacterReference,
  submitStoryCharacterReferenceReview,
  updateStoryCharacter,
  uploadStoryCharacterReference,
} from '../../services/storyService';

const EMPTY_FORM = {
  name: '',
  short_description: '',
  age: '',
  gender_presentation: '',
  appearance: '',
  face_description: '',
  hair: '',
  clothing: '',
  personality: '',
  voice_description: '',
  special_details: '',
};

function ReferencePreview({ projectId, characterId, reference }) {
  const [url, setUrl] = useState(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let active = true;
    let objectUrl = null;

    async function load() {
      try {
        objectUrl = await getStoryCharacterReferenceFileUrl(projectId, characterId, reference.id);
        if (active) {
          setUrl(objectUrl);
          setFailed(false);
        }
      } catch {
        if (active) {
          setFailed(true);
        }
      }
    }

    load();

    return () => {
      active = false;
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [projectId, characterId, reference.id]);

  if (failed) {
    return <div className="story-reference-preview missing">Preview unavailable</div>;
  }

  if (!url) {
    return <div className="story-reference-preview loading">Loading…</div>;
  }

  return (
    <img
      className="story-reference-preview"
      src={url}
      alt={`Character reference v${reference.version}`}
    />
  );
}

export default function StoryCharactersPanel({ projectId }) {
  const [characters, setCharacters] = useState([]);
  const [selectedId, setSelectedId] = useState(null);
  const [character, setCharacter] = useState(null);
  const [references, setReferences] = useState([]);
  const [form, setForm] = useState(EMPTY_FORM);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [fieldError, setFieldError] = useState(null);
  const [message, setMessage] = useState('');

  async function refreshList(preferId = null) {
    const items = await listStoryCharacters(projectId);
    setCharacters(items);
    const nextId = preferId || selectedId || items[0]?.id || null;
    setSelectedId(nextId);
    return nextId;
  }

  async function refreshDetail(characterId) {
    if (!characterId) {
      setCharacter(null);
      setReferences([]);
      setForm(EMPTY_FORM);
      return;
    }

    setDetailLoading(true);
    try {
      const [detail, refs] = await Promise.all([
        getStoryCharacter(projectId, characterId),
        listStoryCharacterReferences(projectId, characterId),
      ]);
      setCharacter(detail);
      setReferences(refs);
      setForm({
        name: detail.name || '',
        short_description: detail.short_description || '',
        age: detail.age || '',
        gender_presentation: detail.gender_presentation || '',
        appearance: detail.appearance || '',
        face_description: detail.face_description || '',
        hair: detail.hair || '',
        clothing: detail.clothing || '',
        personality: detail.personality || '',
        voice_description: detail.voice_description || '',
        special_details: detail.special_details || '',
      });
    } finally {
      setDetailLoading(false);
    }
  }

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');
      try {
        const items = await listStoryCharacters(projectId);
        if (cancelled) return;
        setCharacters(items);
        const first = items[0]?.id || null;
        setSelectedId(first);
        if (first) {
          await refreshDetail(first);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load characters.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }

    load();
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId]);

  useEffect(() => {
    if (!selectedId) return;
    refreshDetail(selectedId).catch((err) => {
      setError(err instanceof ApiError ? err.message : 'Unable to load character.');
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedId]);

  function updateField(key, value) {
    setForm((prev) => ({ ...prev, [key]: value }));
  }

  async function handleCreate() {
    setSaving(true);
    setError('');
    setFieldError(null);
    setMessage('');
    try {
      const created = await createStoryCharacter(projectId, { name: 'New character' });
      await refreshList(created.id);
      setMessage('Character created.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to create character.');
      setFieldError(err instanceof ApiError ? err : null);
    } finally {
      setSaving(false);
    }
  }

  async function handleSave(event) {
    event.preventDefault();
    if (!selectedId) return;

    setSaving(true);
    setError('');
    setFieldError(null);
    setMessage('');
    try {
      const updated = await updateStoryCharacter(projectId, selectedId, form);
      setCharacter(updated);
      await refreshList(selectedId);
      setMessage('Character saved.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to save character.');
      setFieldError(err instanceof ApiError ? err : null);
    } finally {
      setSaving(false);
    }
  }

  async function handleArchiveCharacter() {
    if (!selectedId) return;
    setBusy('archive-character');
    setError('');
    try {
      await archiveStoryCharacter(projectId, selectedId);
      const nextId = await refreshList(null);
      setSelectedId(nextId);
      setMessage('Character archived.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to archive character.');
    } finally {
      setBusy('');
    }
  }

  async function handleUpload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file || !selectedId) return;

    setBusy('upload');
    setError('');
    setMessage('');
    try {
      await uploadStoryCharacterReference(projectId, selectedId, file);
      await refreshDetail(selectedId);
      setMessage('Reference uploaded as draft.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to upload reference.');
    } finally {
      setBusy('');
    }
  }

  async function handleGenerate() {
    if (!selectedId) return;
    setBusy('generate');
    setError('');
    setMessage('');
    try {
      await generateStoryCharacterReference(projectId, selectedId);
      await refreshDetail(selectedId);
      setMessage('Reference generated as draft.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to generate reference.');
    } finally {
      setBusy('');
    }
  }

  async function runReferenceAction(action, referenceId, extra) {
    setBusy(`${action}-${referenceId}`);
    setError('');
    setMessage('');
    try {
      if (action === 'submit') {
        await submitStoryCharacterReferenceReview(projectId, selectedId, referenceId);
        setMessage('Reference submitted for review.');
      } else if (action === 'approve') {
        await approveStoryCharacterReference(projectId, selectedId, referenceId);
        setMessage('Reference approved.');
      } else if (action === 'reject') {
        await rejectStoryCharacterReference(projectId, selectedId, referenceId, extra || 'Needs rework');
        setMessage('Reference rejected.');
      } else if (action === 'archive') {
        await archiveStoryCharacterReference(projectId, selectedId, referenceId);
        setMessage('Reference archived.');
      }
      await refreshDetail(selectedId);
      await refreshList(selectedId);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Reference action failed.');
    } finally {
      setBusy('');
    }
  }

  if (loading) {
    return <LoadingSpinner label="Loading characters…" />;
  }

  return (
    <section className="story-characters" aria-label="Characters">
      <div className="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
          <h2 className="h4 mb-1">Characters</h2>
          <p className="text-secondary mb-0">
            Character context and approved reference images keep people and subjects consistent across scenes.
          </p>
        </div>
        <button type="button" className="btn btn-primary" onClick={handleCreate} disabled={saving}>
          New character
        </button>
      </div>

      {error ? <div className="mb-3"><AlertMessage>{error}</AlertMessage></div> : null}
      {message ? <div className="mb-3"><AlertMessage variant="success">{message}</AlertMessage></div> : null}

      {characters.length === 0 ? (
        <EmptyState
          icon="bi-people"
          title="No characters yet"
          description="Add a character, describe them, then upload or generate a reference image."
        />
      ) : (
        <div className="row g-4">
          <div className="col-12 col-lg-4">
            <div className="list-group story-character-list">
              {characters.map((item) => (
                <button
                  key={item.id}
                  type="button"
                  className={`list-group-item list-group-item-action ${selectedId === item.id ? 'active' : ''}`}
                  onClick={() => setSelectedId(item.id)}
                >
                  <div className="fw-semibold">{item.name}</div>
                  <div className="small opacity-75">
                    {item.approved_reference
                      ? `Approved V${item.approved_reference.version}`
                      : 'No approved reference'}
                  </div>
                </button>
              ))}
            </div>
          </div>

          <div className="col-12 col-lg-8">
            {detailLoading ? <LoadingSpinner label="Loading character…" /> : null}

            {!detailLoading && character ? (
              <>
                <form className="card border-0 glass-card mb-4" onSubmit={handleSave}>
                  <div className="card-body">
                    <div className="d-flex flex-wrap justify-content-between gap-2 mb-3">
                      <h3 className="h5 mb-0">Character Context</h3>
                      <button
                        type="button"
                        className="btn btn-outline-secondary btn-sm"
                        onClick={handleArchiveCharacter}
                        disabled={busy === 'archive-character'}
                      >
                        Archive
                      </button>
                    </div>

                    <div className="row g-3">
                      {[
                        ['name', 'Name', 'text'],
                        ['short_description', 'Short description', 'textarea'],
                        ['age', 'Age', 'text'],
                        ['gender_presentation', 'Gender / presentation', 'text'],
                        ['appearance', 'Appearance', 'textarea'],
                        ['face_description', 'Face description', 'textarea'],
                        ['hair', 'Hair', 'text'],
                        ['clothing', 'Clothing', 'textarea'],
                        ['personality', 'Personality', 'textarea'],
                        ['voice_description', 'Voice description', 'textarea'],
                        ['special_details', 'Special details', 'textarea'],
                      ].map(([key, label, type]) => (
                        <div className={type === 'textarea' ? 'col-12' : 'col-md-6'} key={key}>
                          <label className="form-label" htmlFor={`char-${key}`}>{label}</label>
                          {type === 'textarea' ? (
                            <textarea
                              id={`char-${key}`}
                              className="form-control"
                              rows={3}
                              value={form[key]}
                              onChange={(e) => updateField(key, e.target.value)}
                            />
                          ) : (
                            <input
                              id={`char-${key}`}
                              className="form-control"
                              value={form[key]}
                              onChange={(e) => updateField(key, e.target.value)}
                            />
                          )}
                          {storyFieldError(fieldError, key) ? (
                            <div className="invalid-feedback d-block">{storyFieldError(fieldError, key)}</div>
                          ) : null}
                        </div>
                      ))}
                    </div>

                    <div className="mt-3">
                      <button type="submit" className="btn btn-primary" disabled={saving}>
                        {saving ? 'Saving…' : 'Save character'}
                      </button>
                    </div>
                  </div>
                </form>

                <div className="card border-0 glass-card">
                  <div className="card-body">
                    <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                      <h3 className="h5 mb-0">Character references</h3>
                      <div className="d-flex flex-wrap gap-2">
                        <label className="btn btn-outline-primary btn-sm mb-0">
                          Upload
                          <input type="file" accept="image/png,image/jpeg,image/webp" hidden onChange={handleUpload} />
                        </label>
                        <button
                          type="button"
                          className="btn btn-primary btn-sm"
                          onClick={handleGenerate}
                          disabled={busy === 'generate'}
                        >
                          {busy === 'generate' ? 'Generating…' : 'Generate'}
                        </button>
                      </div>
                    </div>

                    {character.approved_reference ? (
                      <div className="story-approved-reference mb-4">
                        <p className="text-uppercase small text-secondary mb-2">Approved reference</p>
                        <ReferencePreview
                          projectId={projectId}
                          characterId={character.id}
                          reference={character.approved_reference}
                        />
                        <p className="mt-2 mb-0">
                          V{character.approved_reference.version} — {characterReferenceStatusLabel(character.approved_reference.status)}
                        </p>
                      </div>
                    ) : (
                      <p className="text-secondary">No approved reference yet.</p>
                    )}

                    <h4 className="h6">Versions</h4>
                    {references.length === 0 ? (
                      <p className="text-secondary mb-0">Upload or generate a reference to create V1.</p>
                    ) : (
                      <div className="story-reference-versions">
                        {references.map((ref) => (
                          <div className="story-reference-row" key={ref.id}>
                            <ReferencePreview projectId={projectId} characterId={character.id} reference={ref} />
                            <div className="flex-grow-1">
                              <div className="fw-semibold">
                                V{ref.version}
                                {ref.is_approved_current ? ' — Current approved' : ''}
                              </div>
                              <div className="small text-secondary">
                                {characterReferenceStatusLabel(ref.status)} · {ref.source}
                                {ref.width && ref.height ? ` · ${ref.width}×${ref.height}` : ''}
                              </div>
                              <div className="d-flex flex-wrap gap-2 mt-2">
                                {ref.status === 'draft' || ref.status === 'rejected' ? (
                                  <button
                                    type="button"
                                    className="btn btn-sm btn-outline-primary"
                                    disabled={Boolean(busy)}
                                    onClick={() => runReferenceAction('submit', ref.id)}
                                  >
                                    Submit review
                                  </button>
                                ) : null}
                                {ref.status === 'pending_review' ? (
                                  <>
                                    <button
                                      type="button"
                                      className="btn btn-sm btn-success"
                                      disabled={Boolean(busy)}
                                      onClick={() => runReferenceAction('approve', ref.id)}
                                    >
                                      Approve
                                    </button>
                                    <button
                                      type="button"
                                      className="btn btn-sm btn-outline-danger"
                                      disabled={Boolean(busy)}
                                      onClick={() => runReferenceAction('reject', ref.id, 'Needs rework')}
                                    >
                                      Reject
                                    </button>
                                  </>
                                ) : null}
                                {ref.status !== 'archived' && ref.status !== 'pending_review' ? (
                                  <button
                                    type="button"
                                    className="btn btn-sm btn-outline-secondary"
                                    disabled={Boolean(busy)}
                                    onClick={() => runReferenceAction('archive', ref.id)}
                                  >
                                    Archive
                                  </button>
                                ) : null}
                              </div>
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              </>
            ) : null}
          </div>
        </div>
      )}
    </section>
  );
}
