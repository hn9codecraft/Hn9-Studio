import { useEffect, useMemo, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import {
  createStoryPlan,
  estimateStorySceneCount,
  generateStoryPlan,
  getStoryBible,
  getStoryPlan,
  getStoryStyle,
  listStoryCharacters,
  listStoryPlanVersions,
  listStoryPlans,
  materializeStoryPlanVersion,
  regenerateStoryPlan,
} from '../../services/storyService';

function formatTimestamp(seconds) {
  const total = Math.max(0, Number(seconds) || 0);
  const m = Math.floor(total / 60);
  const s = total % 60;
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

export default function StoryPlannerPanel({ projectId, onMaterialized = null }) {
  const [idea, setIdea] = useState('');
  const [title, setTitle] = useState('');
  const [duration, setDuration] = useState(120);
  const [plans, setPlans] = useState([]);
  const [selectedPlanId, setSelectedPlanId] = useState(null);
  const [selectedPlan, setSelectedPlan] = useState(null);
  const [versions, setVersions] = useState([]);
  const [bible, setBible] = useState(null);
  const [characters, setCharacters] = useState([]);
  const [style, setStyle] = useState(null);
  const [regenInstruction, setRegenInstruction] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');

  const sceneEstimate = useMemo(() => estimateStorySceneCount(duration), [duration]);

  async function refreshPlans(preferId = null) {
    const items = await listStoryPlans(projectId);
    setPlans(items);
    const nextId = preferId || selectedPlanId || items[0]?.id || null;
    setSelectedPlanId(nextId);
    return nextId;
  }

  async function refreshSelected(planId) {
    if (!planId) {
      setSelectedPlan(null);
      setVersions([]);
      return;
    }
    const [plan, vers] = await Promise.all([
      getStoryPlan(projectId, planId),
      listStoryPlanVersions(projectId, planId),
    ]);
    setSelectedPlan(plan);
    setVersions(vers);
  }

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');
      try {
        const [planItems, bibleData, chars, styleData] = await Promise.all([
          listStoryPlans(projectId),
          getStoryBible(projectId),
          listStoryCharacters(projectId),
          getStoryStyle(projectId),
        ]);
        if (cancelled) return;
        setPlans(planItems);
        setBible(bibleData);
        setCharacters(chars);
        setStyle(styleData);
        const first = planItems[0]?.id || null;
        setSelectedPlanId(first);
        if (first) {
          const vers = await listStoryPlanVersions(projectId, first);
          if (!cancelled) {
            setSelectedPlan(planItems[0]);
            setVersions(vers);
          }
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load Story Planner.');
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
    if (!selectedPlanId) return;
    refreshSelected(selectedPlanId).catch((err) => {
      setError(err instanceof ApiError ? err.message : 'Unable to load story plan.');
    });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedPlanId]);

  async function handleCreateAndGenerate(event) {
    event.preventDefault();
    setBusy('generate');
    setError('');
    setMessage('');
    try {
      const created = await createStoryPlan(projectId, {
        title: title || null,
        idea,
        requested_duration_seconds: Number(duration),
      });
      const generated = await generateStoryPlan(projectId, created.id);
      await refreshPlans(generated.id);
      setSelectedPlanId(generated.id);
      setSelectedPlan(generated);
      const vers = await listStoryPlanVersions(projectId, generated.id);
      setVersions(vers);
      setMessage('Story plan generated.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to generate story plan.');
    } finally {
      setBusy('');
    }
  }

  async function handleRegenerate() {
    if (!selectedPlanId) return;
    setBusy('regenerate');
    setError('');
    setMessage('');
    try {
      const generated = await regenerateStoryPlan(projectId, selectedPlanId, {
        instruction: regenInstruction || null,
      });
      setSelectedPlan(generated);
      const vers = await listStoryPlanVersions(projectId, selectedPlanId);
      setVersions(vers);
      await refreshPlans(selectedPlanId);
      setMessage('Story plan regenerated as a new version.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to regenerate story plan.');
    } finally {
      setBusy('');
    }
  }

  async function handleMaterialize() {
    const version = selectedPlan?.current_version;
    if (!selectedPlanId || !version?.id) return;
    const expectedScenes = Array.isArray(version.plan?.scenes) ? version.plan.scenes.length : 0;
    const confirmed = window.confirm(
      `Create Reels & Scenes from plan version V${version.version}?\n\nExpected scenes: ${expectedScenes}\n\nIf this version was already materialized, the existing reel will be returned (no duplicates).`,
    );
    if (!confirmed) return;

    setBusy('materialize');
    setError('');
    setMessage('');
    try {
      const result = await materializeStoryPlanVersion(projectId, selectedPlanId, version.id);
      const reelId = result?.reel?.id || null;
      if (result?.created) {
        setMessage(`Created reel with ${result.scene_count} scenes.`);
      } else {
        setMessage(`This plan version was already materialized (${result.scene_count} scenes). Opening existing reel.`);
      }
      if (typeof onMaterialized === 'function' && reelId) {
        onMaterialized(reelId);
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to materialize plan version.');
    } finally {
      setBusy('');
    }
  }

  const currentPlanPayload = selectedPlan?.current_version?.plan || null;
  const scenes = Array.isArray(currentPlanPayload?.scenes) ? currentPlanPayload.scenes : [];
  const canMaterialize =
    selectedPlan?.current_version?.status === 'completed' &&
    Array.isArray(selectedPlan?.current_version?.plan?.scenes) &&
    selectedPlan.current_version.plan.scenes.length > 0;

  if (loading) {
    return <LoadingSpinner label="Loading Story Planner…" />;
  }

  return (
    <section className="story-planner" aria-label="Story Planner">
      <div className="mb-3">
        <h2 className="h4 mb-1">Story Planner</h2>
        <p className="text-secondary mb-0">
          Turn a story idea and this project&apos;s story context into a timed scene plan. No video is generated here.
        </p>
      </div>

      {error ? <div className="mb-3"><AlertMessage>{error}</AlertMessage></div> : null}
      {message ? <div className="mb-3"><AlertMessage variant="success">{message}</AlertMessage></div> : null}

      <div className="row g-4">
        <div className="col-12 col-xl-4">
          <div className="card border-0 glass-card mb-3">
            <div className="card-body">
              <h3 className="h6">Project context</h3>
              <p className="small text-secondary mb-2">
                Story context: {bible?.configured ? 'configured' : 'empty'}
              </p>
              <p className="small text-secondary mb-2">
                Characters: {characters.length}
                {characters[0] ? ` (e.g. ${characters[0].name})` : ''}
              </p>
              <p className="small text-secondary mb-0">
                Visual style: {style?.configured ? (style.visual_style || 'configured') : 'empty'}
              </p>
            </div>
          </div>

          <form className="card border-0 glass-card" onSubmit={handleCreateAndGenerate}>
            <div className="card-body">
              <div className="mb-3">
                <label className="form-label" htmlFor="plan-title">Title (optional)</label>
                <input
                  id="plan-title"
                  className="form-control"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                />
              </div>
              <div className="mb-3">
                <label className="form-label" htmlFor="plan-idea">Story idea</label>
                <textarea
                  id="plan-idea"
                  className="form-control"
                  rows={5}
                  required
                  value={idea}
                  onChange={(e) => setIdea(e.target.value)}
                  placeholder="Continue the story with Aarav entering the mountain cave."
                />
              </div>
              <div className="mb-3">
                <label className="form-label" htmlFor="plan-duration">Total duration (seconds)</label>
                <input
                  id="plan-duration"
                  type="number"
                  min={30}
                  max={3600}
                  step={1}
                  className="form-control"
                  value={duration}
                  onChange={(e) => setDuration(e.target.value)}
                />
                <div className="form-text">
                  Estimated scenes at 30s units: <strong>{sceneEstimate}</strong>
                </div>
              </div>
              <button type="submit" className="btn btn-primary" disabled={busy === 'generate'}>
                {busy === 'generate' ? 'Generating…' : 'Generate Story Plan'}
              </button>
            </div>
          </form>
        </div>

        <div className="col-12 col-xl-8">
          {plans.length > 0 ? (
            <div className="mb-3">
              <label className="form-label" htmlFor="plan-select">Plans</label>
              <select
                id="plan-select"
                className="form-select"
                value={selectedPlanId || ''}
                onChange={(e) => setSelectedPlanId(e.target.value || null)}
              >
                {plans.map((plan) => (
                  <option key={plan.id} value={plan.id}>
                    {plan.title || 'Untitled'} · {plan.requested_duration_seconds}s · {plan.status}
                  </option>
                ))}
              </select>
            </div>
          ) : null}

          {!selectedPlan ? (
            <EmptyState
              icon="bi-journal-text"
              title="No story plan yet"
              description="Enter an idea and duration, then generate a structured plan."
            />
          ) : (
            <>
              <div className="card border-0 glass-card mb-3">
                <div className="card-body">
                  <div className="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <h3 className="h5 mb-0">{selectedPlan.title || currentPlanPayload?.title || 'Story plan'}</h3>
                    <span className="badge text-bg-secondary">
                      {selectedPlan.status}
                      {selectedPlan.current_version ? ` · V${selectedPlan.current_version.version}` : ''}
                    </span>
                  </div>
                  <p className="text-secondary mb-2">{selectedPlan.idea}</p>
                  <p className="mb-0">
                    Duration: {selectedPlan.requested_duration_seconds}s
                    {currentPlanPayload?.logline ? ` · ${currentPlanPayload.logline}` : ''}
                  </p>
                  {selectedPlan.current_version?.master_story ? (
                    <div className="mt-3">
                      <h4 className="h6">Master story</h4>
                      <p className="mb-0">{selectedPlan.current_version.master_story}</p>
                    </div>
                  ) : null}
                  {selectedPlan.current_version?.error_message ? (
                    <AlertMessage className="mt-3">{selectedPlan.current_version.error_message}</AlertMessage>
                  ) : null}
                </div>
              </div>

              <div className="card border-0 glass-card mb-3">
                <div className="card-body">
                  <h4 className="h6">Scenes</h4>
                  {scenes.length === 0 ? (
                    <p className="text-secondary mb-0">No completed scene breakdown yet.</p>
                  ) : (
                    <div className="story-plan-scenes">
                      {scenes.map((scene) => (
                        <article className="story-plan-scene mb-3" key={scene.sequence}>
                          <h5 className="h6 mb-1">
                            Scene {String(scene.sequence).padStart(2, '0')} ·{' '}
                            {formatTimestamp(scene.start_second)}–{formatTimestamp(scene.end_second)}
                          </h5>
                          <p className="fw-semibold mb-1">{scene.title}</p>
                          <p className="mb-1">{scene.story}</p>
                          <p className="small text-secondary mb-1">
                            Characters: {(scene.characters || []).join(', ') || '—'} · Location: {scene.location}
                          </p>
                          <p className="small mb-1"><strong>Visual:</strong> {scene.visual_prompt}</p>
                          <p className="small mb-1"><strong>Motion:</strong> {scene.motion_prompt}</p>
                          <p className="small mb-0"><strong>Audio:</strong> {scene.audio_direction}</p>
                        </article>
                      ))}
                    </div>
                  )}
                </div>
              </div>

              <div className="card border-0 glass-card mb-3">
                <div className="card-body">
                  <h4 className="h6">Create Reels &amp; Scenes from this Plan</h4>
                  <p className="text-secondary small mb-2">
                    Materializes the current completed plan version into runtime reels/scenes.
                    Existing scenes from this exact version are not overwritten or duplicated.
                  </p>
                  {selectedPlan.current_version ? (
                    <p className="small mb-3">
                      Version V{selectedPlan.current_version.version} · status {selectedPlan.current_version.status}
                      {scenes.length ? ` · ${scenes.length} planned scenes` : ''}
                    </p>
                  ) : (
                    <p className="small text-secondary mb-3">No completed version available yet.</p>
                  )}
                  <button
                    type="button"
                    className="btn btn-primary"
                    disabled={!canMaterialize || busy === 'materialize'}
                    onClick={handleMaterialize}
                  >
                    {busy === 'materialize' ? 'Creating…' : 'Create Reels & Scenes from this Plan'}
                  </button>
                </div>
              </div>

              <div className="card border-0 glass-card mb-3">
                <div className="card-body">
                  <h4 className="h6">Regenerate</h4>
                  <textarea
                    className="form-control mb-2"
                    rows={2}
                    value={regenInstruction}
                    onChange={(e) => setRegenInstruction(e.target.value)}
                    placeholder="Make the second half more emotional."
                  />
                  <button
                    type="button"
                    className="btn btn-outline-primary"
                    disabled={busy === 'regenerate'}
                    onClick={handleRegenerate}
                  >
                    {busy === 'regenerate' ? 'Regenerating…' : 'Regenerate as new version'}
                  </button>
                </div>
              </div>

              <div className="card border-0 glass-card">
                <div className="card-body">
                  <h4 className="h6">Version history</h4>
                  {versions.length === 0 ? (
                    <p className="text-secondary mb-0">No versions yet.</p>
                  ) : (
                    <ul className="list-unstyled mb-0">
                      {versions.map((version) => (
                        <li key={version.id} className="mb-2">
                          V{version.version} — {version.status}
                          {selectedPlan.current_version?.id === version.id ? ' · current' : ''}
                          {version.instruction ? ` · “${version.instruction}”` : ''}
                        </li>
                      ))}
                    </ul>
                  )}
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </section>
  );
}
