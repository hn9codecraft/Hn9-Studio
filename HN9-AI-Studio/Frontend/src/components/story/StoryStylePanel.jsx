import { useEffect, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { characterReferenceStatusLabel, STORY_ASPECT_RATIOS, storyFieldError } from '../../services/storyConstants';
import {
  approveStoryStyleReference,
  archiveStoryStyleReference,
  generateStoryStyleReference,
  getStoryStyle,
  getStoryStyleReferenceFileUrl,
  initializeStoryStyle,
  listStoryStyleReferences,
  rejectStoryStyleReference,
  submitStoryStyleReferenceReview,
  updateStoryStyle,
  uploadStoryStyleReference,
} from '../../services/storyService';

const EMPTY_FORM = {
  visual_style: '',
  animation_style: '',
  lighting: '',
  camera_style: '',
  color_direction: '',
  environment_style: '',
  mood: '',
  rendering_style: '',
  visual_quality: '',
  art_direction_notes: '',
  aspect_ratio: '',
};

function StyleReferencePreview({ projectId, reference }) {
  const [url, setUrl] = useState(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let active = true;
    let objectUrl = null;

    async function load() {
      try {
        objectUrl = await getStoryStyleReferenceFileUrl(projectId, reference.id);
        if (active) {
          setUrl(objectUrl);
          setFailed(false);
        }
      } catch {
        if (active) setFailed(true);
      }
    }

    load();

    return () => {
      active = false;
      if (objectUrl) URL.revokeObjectURL(objectUrl);
    };
  }, [projectId, reference.id]);

  if (failed) return <div className="story-reference-preview missing">Preview unavailable</div>;
  if (!url) return <div className="story-reference-preview loading">Loading…</div>;

  return (
    <img
      className="story-reference-preview"
      src={url}
      alt={`Style reference v${reference.version}`}
    />
  );
}

export default function StoryStylePanel({ projectId }) {
  const [style, setStyle] = useState(null);
  const [references, setReferences] = useState([]);
  const [form, setForm] = useState(EMPTY_FORM);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [fieldError, setFieldError] = useState(null);
  const [message, setMessage] = useState('');

  async function refresh() {
    const [detail, refs] = await Promise.all([
      getStoryStyle(projectId),
      listStoryStyleReferences(projectId),
    ]);
    setStyle(detail);
    setReferences(refs);
    setForm({
      visual_style: detail.visual_style || '',
      animation_style: detail.animation_style || '',
      lighting: detail.lighting || '',
      camera_style: detail.camera_style || '',
      color_direction: detail.color_direction || '',
      environment_style: detail.environment_style || '',
      mood: detail.mood || '',
      rendering_style: detail.rendering_style || '',
      visual_quality: detail.visual_quality || '',
      art_direction_notes: detail.art_direction_notes || '',
      aspect_ratio: detail.aspect_ratio || '',
    });
  }

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');
      try {
        await initializeStoryStyle(projectId);
        if (!cancelled) await refresh();
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load Style Bible.');
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

  function updateField(key, value) {
    setForm((prev) => ({ ...prev, [key]: value }));
  }

  async function handleSave(event) {
    event.preventDefault();
    setSaving(true);
    setError('');
    setFieldError(null);
    setMessage('');
    try {
      const updated = await updateStoryStyle(projectId, form);
      setStyle(updated);
      setMessage('Style Bible saved.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to save Style Bible.');
      setFieldError(err instanceof ApiError ? err : null);
    } finally {
      setSaving(false);
    }
  }

  async function handleUpload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    setBusy('upload');
    setError('');
    setMessage('');
    try {
      await uploadStoryStyleReference(projectId, file);
      await refresh();
      setMessage('Style reference uploaded as draft.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to upload style reference.');
    } finally {
      setBusy('');
    }
  }

  async function handleGenerate() {
    setBusy('generate');
    setError('');
    setMessage('');
    try {
      await generateStoryStyleReference(projectId);
      await refresh();
      setMessage('Style reference generated as draft.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to generate style reference.');
    } finally {
      setBusy('');
    }
  }

  async function runReferenceAction(action, referenceId) {
    setBusy(`${action}-${referenceId}`);
    setError('');
    setMessage('');
    try {
      if (action === 'submit') {
        await submitStoryStyleReferenceReview(projectId, referenceId);
        setMessage('Style reference submitted for review.');
      } else if (action === 'approve') {
        await approveStoryStyleReference(projectId, referenceId);
        setMessage('Style reference approved.');
      } else if (action === 'reject') {
        await rejectStoryStyleReference(projectId, referenceId, 'Needs rework');
        setMessage('Style reference rejected.');
      } else if (action === 'archive') {
        await archiveStoryStyleReference(projectId, referenceId);
        setMessage('Style reference archived.');
      }
      await refresh();
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Style reference action failed.');
    } finally {
      setBusy('');
    }
  }

  if (loading) {
    return <LoadingSpinner label="Loading Style Bible…" />;
  }

  return (
    <section className="story-style mt-4" aria-label="Style Bible">
      <div className="mb-3">
        <h2 className="h4 mb-1">Style Bible</h2>
        <p className="text-secondary mb-0">
          Persistent visual direction for later story planner and video modules.
        </p>
      </div>

      {error ? <div className="mb-3"><AlertMessage>{error}</AlertMessage></div> : null}
      {message ? <div className="mb-3"><AlertMessage variant="success">{message}</AlertMessage></div> : null}

      <form className="card border-0 glass-card mb-4" onSubmit={handleSave}>
        <div className="card-body">
          <div className="row g-3">
            {[
              ['visual_style', 'Visual style', 'text'],
              ['animation_style', 'Animation style', 'text'],
              ['lighting', 'Lighting', 'text'],
              ['camera_style', 'Camera style', 'text'],
              ['color_direction', 'Color direction', 'text'],
              ['environment_style', 'Environment style', 'text'],
              ['mood', 'Mood', 'text'],
              ['rendering_style', 'Rendering style', 'text'],
              ['visual_quality', 'Visual quality', 'text'],
              ['art_direction_notes', 'Art direction notes', 'textarea'],
            ].map(([key, label, type]) => (
              <div className={type === 'textarea' ? 'col-12' : 'col-md-6'} key={key}>
                <label className="form-label" htmlFor={`style-${key}`}>{label}</label>
                {type === 'textarea' ? (
                  <textarea
                    id={`style-${key}`}
                    className="form-control"
                    rows={3}
                    value={form[key]}
                    onChange={(e) => updateField(key, e.target.value)}
                  />
                ) : (
                  <input
                    id={`style-${key}`}
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
            <div className="col-md-6">
              <label className="form-label" htmlFor="style-aspect_ratio">Aspect ratio</label>
              <select
                id="style-aspect_ratio"
                className="form-select"
                value={form.aspect_ratio}
                onChange={(e) => updateField('aspect_ratio', e.target.value)}
              >
                {STORY_ASPECT_RATIOS.map((option) => (
                  <option key={option.value || 'none'} value={option.value}>{option.label}</option>
                ))}
              </select>
            </div>
          </div>
          <div className="mt-3">
            <button type="submit" className="btn btn-primary" disabled={saving}>
              {saving ? 'Saving…' : 'Save Style Bible'}
            </button>
          </div>
        </div>
      </form>

      <div className="card border-0 glass-card">
        <div className="card-body">
          <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h3 className="h5 mb-0">Style references</h3>
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

          {style?.approved_reference ? (
            <div className="story-approved-reference mb-4">
              <p className="text-uppercase small text-secondary mb-2">Current approved reference</p>
              <StyleReferencePreview projectId={projectId} reference={style.approved_reference} />
              <p className="mt-2 mb-0">
                V{style.approved_reference.version} — {characterReferenceStatusLabel(style.approved_reference.status)}
              </p>
            </div>
          ) : (
            <p className="text-secondary">No approved style reference yet.</p>
          )}

          <h4 className="h6">Versions</h4>
          {references.length === 0 ? (
            <EmptyState
              icon="bi-palette"
              title="No style references"
              description="Upload or generate a style reference to create V1."
            />
          ) : (
            <div className="story-reference-versions">
              {references.map((ref) => (
                <div className="story-reference-row" key={ref.id}>
                  <StyleReferencePreview projectId={projectId} reference={ref} />
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
                            onClick={() => runReferenceAction('reject', ref.id)}
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
    </section>
  );
}
