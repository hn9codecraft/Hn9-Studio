import { useEffect, useMemo, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import {
  archiveStoryReel,
  archiveStoryScene,
  createStoryReel,
  createStoryScene,
  duplicateStoryScene,
  listStoryReels,
  reorderStoryReels,
  reorderStoryScenes,
  approveStoryReel,
  approveStoryScene,
  commentOnStoryScene,
  editStorySceneVersion,
  extendStorySceneVersion,
  getStorySceneContinuity,
  getStoryScenePreview,
  listStorySceneVersions,
  regenerateStoryScene,
  reworkStoryScene,
  submitStoryReelReview,
  submitStorySceneReview,
  updateStoryReel,
  updateStoryScene,
} from '../../services/storyService';

function formatTimestamp(seconds) {
  const total = Math.max(0, Number(seconds) || 0);
  const m = Math.floor(total / 60);
  const s = total % 60;
  return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

const emptySceneForm = () => ({
  title: '',
  duration_seconds: 30,
  story: '',
  characters: '',
  location: '',
  narration: '',
  visual_prompt: '',
  motion_prompt: '',
  audio_direction: '',
  continuity_previous: '',
  continuity_next: '',
  continuity_character: '',
  continuity_environment: '',
});

export default function StoryReelsPanel({ projectId, focusReelId = null }) {
  const [reels, setReels] = useState([]);
  const [selectedReelId, setSelectedReelId] = useState(null);
  const [selectedSceneId, setSelectedSceneId] = useState(null);
  const [continuity, setContinuity] = useState(null);
  const [sceneVersions, setSceneVersions] = useState([]);
  const [scenePreview, setScenePreview] = useState(null);
  const [reviewComment, setReviewComment] = useState('');
  const [reelTitle, setReelTitle] = useState('');
  const [reelDescription, setReelDescription] = useState('');
  const [sceneForm, setSceneForm] = useState(emptySceneForm());
  const [editingScene, setEditingScene] = useState(false);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState('');
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');

  const selectedReel = useMemo(
    () => reels.find((reel) => reel.id === selectedReelId) || null,
    [reels, selectedReelId],
  );

  const scenes = useMemo(
    () => (Array.isArray(selectedReel?.scenes) ? selectedReel.scenes : []),
    [selectedReel],
  );

  useEffect(() => {
    if (!projectId || !selectedReelId || !selectedSceneId) {
      setContinuity(null);
      return undefined;
    }
    let cancelled = false;
    getStorySceneContinuity(projectId, selectedReelId, selectedSceneId)
      .then((payload) => {
        if (!cancelled) setContinuity(payload);
      })
      .catch(() => {
        if (!cancelled) setContinuity(null);
      });
    return () => {
      cancelled = true;
    };
  }, [projectId, selectedReelId, selectedSceneId]);

  useEffect(() => {
    if (!projectId || !selectedReelId || !selectedSceneId) {
      setSceneVersions([]);
      setScenePreview(null);
      return undefined;
    }
    let cancelled = false;
    Promise.all([
      listStorySceneVersions(projectId, selectedReelId, selectedSceneId),
      getStoryScenePreview(projectId, selectedReelId, selectedSceneId),
    ]).then(([versions, preview]) => {
      if (!cancelled) {
        setSceneVersions(versions);
        setScenePreview(preview);
      }
    }).catch(() => {
      if (!cancelled) {
        setSceneVersions([]);
        setScenePreview(null);
      }
    });
    return () => {
      cancelled = true;
    };
  }, [projectId, selectedReelId, selectedSceneId]);

  const selectedScene = useMemo(
    () => scenes.find((scene) => scene.id === selectedSceneId) || null,
    [scenes, selectedSceneId],
  );

  async function refresh(preferReelId = null) {
    const items = await listStoryReels(projectId);
    setReels(items);
    const nextId = preferReelId || focusReelId || selectedReelId || items[0]?.id || null;
    setSelectedReelId(nextId);
    const reel = items.find((item) => item.id === nextId);
    const nextSceneId = reel?.scenes?.[0]?.id || null;
    setSelectedSceneId((current) => {
      if (current && reel?.scenes?.some((scene) => scene.id === current)) {
        return current;
      }
      return nextSceneId;
    });
    return items;
  }

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError('');
      try {
        const items = await listStoryReels(projectId);
        if (cancelled) return;
        setReels(items);
        const nextId = focusReelId || items[0]?.id || null;
        setSelectedReelId(nextId);
        const reel = items.find((item) => item.id === nextId);
        setSelectedSceneId(reel?.scenes?.[0]?.id || null);
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load reels.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, [projectId, focusReelId]);

  async function handleCreateReel(event) {
    event.preventDefault();
    setBusy('create-reel');
    setError('');
    setMessage('');
    try {
      const reel = await createStoryReel(projectId, {
        title: reelTitle.trim(),
        description: reelDescription.trim() || null,
      });
      setReelTitle('');
      setReelDescription('');
      await refresh(reel.id);
      setMessage('Reel created.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to create reel.');
    } finally {
      setBusy('');
    }
  }

  async function runReview(action) {
    if (!projectId || !selectedReelId || !selectedSceneId) return;
    setError('');
    try {
      if (action === 'comment') {
        await commentOnStoryScene(projectId, selectedReelId, selectedSceneId, reviewComment);
      } else if (action === 'submit') {
        await submitStorySceneReview(projectId, selectedReelId, selectedSceneId, reviewComment || null);
      } else if (action === 'approve') {
        await approveStoryScene(projectId, selectedReelId, selectedSceneId, reviewComment || null);
      } else if (action === 'rework') {
        await reworkStoryScene(projectId, selectedReelId, selectedSceneId, reviewComment);
      } else if (action === 'regenerate') {
        await regenerateStoryScene(projectId, selectedReelId, selectedSceneId, reviewComment || null);
      } else if (action === 'reel-submit') {
        await submitStoryReelReview(projectId, selectedReelId, reviewComment || null);
      } else if (action === 'reel-approve') {
        await approveStoryReel(projectId, selectedReelId, reviewComment || null);
      } else if (action === 'edit' || action === 'extend') {
        const versionId = scenePreview?.version_id;
        if (!versionId) {
          setError('A stored scene video is required.');
          return;
        }
        if (!reviewComment.trim()) {
          setError('An edit instruction is required.');
          return;
        }
        if (action === 'edit') {
          await editStorySceneVersion(projectId, selectedReelId, selectedSceneId, versionId, reviewComment);
        } else {
          await extendStorySceneVersion(projectId, selectedReelId, selectedSceneId, versionId, reviewComment);
        }
      }
      const [versions, preview] = await Promise.all([
        listStorySceneVersions(projectId, selectedReelId, selectedSceneId),
        getStoryScenePreview(projectId, selectedReelId, selectedSceneId),
      ]);
      setSceneVersions(versions);
      setScenePreview(preview);
      setReviewComment('');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to update scene review.');
      const versions = await listStorySceneVersions(projectId, selectedReelId, selectedSceneId).catch(() => []);
      setSceneVersions(versions);
    }
  }

  async function handleArchiveReel() {
    if (!selectedReel) return;
    setBusy('archive-reel');
    setError('');
    try {
      await archiveStoryReel(projectId, selectedReel.id);
      await refresh(null);
      setMessage('Reel archived.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to archive reel.');
    } finally {
      setBusy('');
    }
  }

  async function handleRenameReel() {
    if (!selectedReel) return;
    const nextTitle = window.prompt('Reel title', selectedReel.title || '');
    if (!nextTitle || !nextTitle.trim()) return;
    setBusy('rename-reel');
    setError('');
    try {
      await updateStoryReel(projectId, selectedReel.id, { title: nextTitle.trim() });
      await refresh(selectedReel.id);
      setMessage('Reel updated.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to update reel.');
    } finally {
      setBusy('');
    }
  }

  async function handleMoveReel(direction) {
    if (!selectedReel) return;
    const index = reels.findIndex((reel) => reel.id === selectedReel.id);
    if (index < 0) return;
    const target = direction === 'up' ? index - 1 : index + 1;
    if (target < 0 || target >= reels.length) return;
    const ordered = reels.map((reel) => reel.id);
    const [moved] = ordered.splice(index, 1);
    ordered.splice(target, 0, moved);
    setBusy('reorder-reels');
    setError('');
    try {
      await reorderStoryReels(projectId, ordered);
      await refresh(selectedReel.id);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to reorder reels.');
    } finally {
      setBusy('');
    }
  }

  function openNewSceneForm() {
    setEditingScene(false);
    setSelectedSceneId(null);
    setSceneForm(emptySceneForm());
  }

  function openEditScene(scene) {
    setEditingScene(true);
    setSelectedSceneId(scene.id);
    setSceneForm({
      title: scene.title || '',
      duration_seconds: scene.duration_seconds || 30,
      story: scene.story || '',
      characters: Array.isArray(scene.characters) ? scene.characters.join(', ') : '',
      location: scene.location || '',
      narration: scene.narration || '',
      visual_prompt: scene.visual_prompt || '',
      motion_prompt: scene.motion_prompt || '',
      audio_direction: scene.audio_direction || '',
      continuity_previous: scene.continuity?.previous_scene || '',
      continuity_next: scene.continuity?.next_scene || '',
      continuity_character: scene.continuity?.character_state || '',
      continuity_environment: scene.continuity?.environment_state || '',
    });
  }

  function scenePayloadFromForm() {
    return {
      title: sceneForm.title.trim() || null,
      duration_seconds: Number(sceneForm.duration_seconds) || 30,
      story: sceneForm.story.trim() || null,
      characters: sceneForm.characters
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean),
      location: sceneForm.location.trim() || null,
      narration: sceneForm.narration.trim() || null,
      visual_prompt: sceneForm.visual_prompt.trim() || null,
      motion_prompt: sceneForm.motion_prompt.trim() || null,
      audio_direction: sceneForm.audio_direction.trim() || null,
      continuity: {
        previous_scene: sceneForm.continuity_previous.trim() || null,
        next_scene: sceneForm.continuity_next.trim() || null,
        character_state: sceneForm.continuity_character.trim() || null,
        environment_state: sceneForm.continuity_environment.trim() || null,
      },
    };
  }

  async function handleSaveScene(event) {
    event.preventDefault();
    if (!selectedReel) return;
    setBusy('save-scene');
    setError('');
    setMessage('');
    try {
      const payload = scenePayloadFromForm();
      if (editingScene && selectedSceneId) {
        await updateStoryScene(projectId, selectedReel.id, selectedSceneId, payload);
        setMessage('Scene updated.');
      } else {
        const scene = await createStoryScene(projectId, selectedReel.id, payload);
        setSelectedSceneId(scene.id);
        setMessage('Scene created.');
      }
      await refresh(selectedReel.id);
      setEditingScene(false);
      setSceneForm(emptySceneForm());
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to save scene.');
    } finally {
      setBusy('');
    }
  }

  async function handleDuplicateScene(sceneId) {
    if (!selectedReel) return;
    setBusy('duplicate-scene');
    setError('');
    try {
      const copy = await duplicateStoryScene(projectId, selectedReel.id, sceneId);
      await refresh(selectedReel.id);
      setSelectedSceneId(copy.id);
      setMessage('Scene duplicated.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to duplicate scene.');
    } finally {
      setBusy('');
    }
  }

  async function handleArchiveScene(sceneId) {
    if (!selectedReel) return;
    setBusy('archive-scene');
    setError('');
    try {
      await archiveStoryScene(projectId, selectedReel.id, sceneId);
      await refresh(selectedReel.id);
      setMessage('Scene archived.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to archive scene.');
    } finally {
      setBusy('');
    }
  }

  async function handleMoveScene(sceneId, direction) {
    if (!selectedReel) return;
    const index = scenes.findIndex((scene) => scene.id === sceneId);
    if (index < 0) return;
    const target = direction === 'up' ? index - 1 : index + 1;
    if (target < 0 || target >= scenes.length) return;
    const ordered = scenes.map((scene) => scene.id);
    const [moved] = ordered.splice(index, 1);
    ordered.splice(target, 0, moved);
    setBusy('reorder-scenes');
    setError('');
    try {
      await reorderStoryScenes(projectId, selectedReel.id, ordered);
      await refresh(selectedReel.id);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to reorder scenes.');
    } finally {
      setBusy('');
    }
  }

  if (loading) {
    return <LoadingSpinner label="Loading reels and scenes…" />;
  }

  return (
    <section className="story-reels-panel" aria-label="Reels and scenes">
      {error ? <AlertMessage className="mb-3">{error}</AlertMessage> : null}
      {message ? <p className="text-success small mb-3">{message}</p> : null}

      <div className="row g-4">
        <div className="col-12 col-lg-4">
          <div className="card border-0 glass-card mb-3">
            <div className="card-body">
              <h2 className="h5 mb-3">Create reel</h2>
              <form onSubmit={handleCreateReel}>
                <div className="mb-2">
                  <label className="form-label" htmlFor="reel-title">Title</label>
                  <input
                    id="reel-title"
                    className="form-control"
                    value={reelTitle}
                    onChange={(e) => setReelTitle(e.target.value)}
                    required
                    maxLength={200}
                  />
                </div>
                <div className="mb-3">
                  <label className="form-label" htmlFor="reel-description">Description</label>
                  <textarea
                    id="reel-description"
                    className="form-control"
                    rows={2}
                    value={reelDescription}
                    onChange={(e) => setReelDescription(e.target.value)}
                  />
                </div>
                <button type="submit" className="btn btn-primary" disabled={busy === 'create-reel'}>
                  {busy === 'create-reel' ? 'Creating…' : 'Create reel'}
                </button>
              </form>
            </div>
          </div>

          <div className="card border-0 glass-card">
            <div className="card-body">
              <h2 className="h5 mb-3">Reels</h2>
              {reels.length === 0 ? (
                <EmptyState
                  icon="bi-film"
                  title="No reels yet"
                  description="Create a reel manually or materialize one from a Story Plan version."
                />
              ) : (
                <ul className="list-group story-reel-list">
                  {reels.map((reel) => (
                    <li key={reel.id}>
                      <button
                        type="button"
                        className={`list-group-item list-group-item-action ${
                          selectedReelId === reel.id ? 'active' : ''
                        }`}
                        onClick={() => {
                          setSelectedReelId(reel.id);
                          setSelectedSceneId(reel.scenes?.[0]?.id || null);
                          setEditingScene(false);
                          setSceneForm(emptySceneForm());
                        }}
                      >
                        <div className="d-flex justify-content-between gap-2">
                          <strong>
                            Reel {String(reel.sequence).padStart(2, '0')} — {reel.title}
                          </strong>
                          <span className="small">{reel.scene_count} scenes</span>
                        </div>
                        <div className="small">
                          {formatTimestamp(0)}–{formatTimestamp(reel.total_duration_seconds)} ·{' '}
                          {reel.total_duration_seconds}s
                        </div>
                        {reel.source_plan_version ? (
                          <div className="small opacity-75">
                            From plan V{reel.source_plan_version.version}
                          </div>
                        ) : null}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </div>
        </div>

        <div className="col-12 col-lg-8">
          {!selectedReel ? (
            <EmptyState
              icon="bi-collection-play"
              title="Select a reel"
              description="Choose a reel to manage its scenes, timing, and continuity context."
            />
          ) : (
            <>
              <div className="card border-0 glass-card mb-3">
                <div className="card-body">
                  <div className="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <div>
                      <h2 className="h4 mb-1">
                        Reel {String(selectedReel.sequence).padStart(2, '0')} — {selectedReel.title}
                      </h2>
                      <p className="text-secondary mb-0">
                        {selectedReel.scene_count} scenes · {selectedReel.total_duration_seconds}s ·{' '}
                        {selectedReel.status}
                      </p>
                    </div>
                    <div className="d-flex flex-wrap gap-2">
                      <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => handleMoveReel('up')}>
                        Move up
                      </button>
                      <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => handleMoveReel('down')}>
                        Move down
                      </button>
                      <button type="button" className="btn btn-outline-primary btn-sm" onClick={handleRenameReel}>
                        Edit
                      </button>
                      <button
                        type="button"
                        className="btn btn-outline-danger btn-sm"
                        disabled={busy === 'archive-reel'}
                        onClick={handleArchiveReel}
                      >
                        Archive
                      </button>
                    </div>
                  </div>
                  {selectedReel.description ? <p className="mb-2">{selectedReel.description}</p> : null}
                  {selectedReel.source_plan ? (
                    <p className="small text-secondary mb-0">
                      Source plan: {selectedReel.source_plan.title || selectedReel.source_plan.id}
                      {selectedReel.source_plan_version
                        ? ` · version ${selectedReel.source_plan_version.version}`
                        : ''}
                    </p>
                  ) : null}
                </div>
              </div>

              {continuity ? (
                <div className="card border-0 glass-card mb-3">
                  <div className="card-body">
                    <h3 className="h5 mb-2">Continuity</h3>
                    <p className="small mb-2">
                      Ready to generate: {continuity.ready ? 'Yes' : 'No'}
                    </p>
                    <p className="small text-secondary mb-1">
                      Story bible: {continuity.story_bible?.concept || 'Missing'}
                    </p>
                    <p className="small text-secondary mb-1">
                      Characters: {(continuity.characters || []).map((item) => item.name).join(', ') || 'Missing'}
                    </p>
                    <p className="small text-secondary mb-1">
                      Style: {continuity.style_bible?.visual_style || 'Missing'}
                    </p>
                    <p className="small text-secondary mb-0">
                      Previous scene: {continuity.previous_scene?.title || 'None'}
                    </p>
                  </div>
                </div>
              ) : null}

              {selectedSceneId && selectedReelId ? (
                <div className="card border-0 glass-card mb-3">
                  <div className="card-body">
                    <h3 className="h5 mb-2">Scene review</h3>
                    <p className="small text-secondary mb-2">
                      Preview: {scenePreview?.has_file ? 'Private file stored' : 'No stored video'}
                      {sceneVersions[sceneVersions.length - 1]?.status
                        ? ` · ${sceneVersions[sceneVersions.length - 1].status}`
                        : ''}
                    </p>
                    <label className="form-label" htmlFor="scene-review-comment">Comment</label>
                    <textarea
                      id="scene-review-comment"
                      className="form-control mb-2"
                      rows={2}
                      value={reviewComment}
                      onChange={(event) => setReviewComment(event.target.value)}
                    />
                    <div className="d-flex flex-wrap gap-2 mb-3">
                      <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => runReview('comment')}>Save comment</button>
                      <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => runReview('submit')}>Submit review</button>
                      <button type="button" className="btn btn-primary btn-sm" onClick={() => runReview('approve')}>Approve</button>
                      <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => runReview('rework')}>Request rework</button>
                      <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => runReview('regenerate')}>Regenerate this scene</button>
                      {scenePreview?.has_file ? (
                        <>
                          <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => runReview('edit')}>Edit</button>
                          <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => runReview('extend')}>Extend</button>
                        </>
                      ) : null}
                      <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => runReview('reel-submit')}>Submit reel</button>
                      <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => runReview('reel-approve')}>Approve reel</button>
                    </div>
                    {sceneVersions.length === 0 ? (
                      <p className="small text-secondary mb-0">No versions yet.</p>
                    ) : (
                      <ul className="small mb-0">
                        {sceneVersions.map((version) => (
                          <li key={version.id}>
                            Version {version.version} · {version.status}
                            {version.comments?.length ? ` · ${version.comments.length} comment(s)` : ''}
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                </div>
              ) : null}

              <div className="card border-0 glass-card mb-3">
                <div className="card-body">
                  <div className="d-flex flex-wrap justify-content-between gap-2 mb-3">
                    <h3 className="h5 mb-0">Scenes</h3>
                    <button type="button" className="btn btn-primary btn-sm" onClick={openNewSceneForm}>
                      Add scene
                    </button>
                  </div>
                  {scenes.length === 0 ? (
                    <p className="text-secondary mb-0">No scenes in this reel yet.</p>
                  ) : (
                    <div className="story-scene-list">
                      {scenes.map((scene) => (
                        <article
                          key={scene.id}
                          className={`story-scene-card ${selectedSceneId === scene.id ? 'is-selected' : ''}`}
                        >
                          <div className="d-flex flex-wrap justify-content-between gap-2 mb-2">
                            <button
                              type="button"
                              className="btn btn-link text-start p-0 text-decoration-none"
                              onClick={() => openEditScene(scene)}
                            >
                              <strong>
                                Scene {String(scene.sequence).padStart(2, '0')} ·{' '}
                                {formatTimestamp(scene.start_second)}–{formatTimestamp(scene.end_second)}
                              </strong>
                              <span className="d-block">{scene.title || 'Untitled scene'}</span>
                            </button>
                            <div className="d-flex flex-wrap gap-1">
                              <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => handleMoveScene(scene.id, 'up')}>
                                Up
                              </button>
                              <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => handleMoveScene(scene.id, 'down')}>
                                Down
                              </button>
                              <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => handleDuplicateScene(scene.id)}>
                                Duplicate
                              </button>
                              <button type="button" className="btn btn-outline-danger btn-sm" onClick={() => handleArchiveScene(scene.id)}>
                                Archive
                              </button>
                            </div>
                          </div>
                          <p className="mb-1">{scene.story}</p>
                          <p className="small text-secondary mb-1">
                            Characters: {(scene.characters || []).join(', ') || '—'} · Location: {scene.location || '—'}
                          </p>
                          <p className="small mb-1"><strong>Visual:</strong> {scene.visual_prompt || '—'}</p>
                          <p className="small mb-1"><strong>Motion:</strong> {scene.motion_prompt || '—'}</p>
                          <p className="small mb-1"><strong>Audio:</strong> {scene.audio_direction || '—'}</p>
                          <p className="small mb-0">
                            Continuity: {scene.continuity?.character_state || '—'} /{' '}
                            {scene.continuity?.environment_state || '—'}
                          </p>
                        </article>
                      ))}
                    </div>
                  )}
                </div>
              </div>

              <div className="card border-0 glass-card">
                <div className="card-body">
                  <h3 className="h5 mb-3">{editingScene ? 'Edit scene' : 'New scene'}</h3>
                  <form onSubmit={handleSaveScene} className="row g-3">
                    <div className="col-md-8">
                      <label className="form-label" htmlFor="scene-title">Title</label>
                      <input
                        id="scene-title"
                        className="form-control"
                        value={sceneForm.title}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, title: e.target.value }))}
                      />
                    </div>
                    <div className="col-md-4">
                      <label className="form-label" htmlFor="scene-duration">Duration (sec)</label>
                      <input
                        id="scene-duration"
                        type="number"
                        min={1}
                        max={3600}
                        className="form-control"
                        value={sceneForm.duration_seconds}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, duration_seconds: e.target.value }))}
                      />
                    </div>
                    <div className="col-12">
                      <label className="form-label" htmlFor="scene-story">Story</label>
                      <textarea
                        id="scene-story"
                        className="form-control"
                        rows={3}
                        value={sceneForm.story}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, story: e.target.value }))}
                      />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label" htmlFor="scene-characters">Characters (comma-separated)</label>
                      <input
                        id="scene-characters"
                        className="form-control"
                        value={sceneForm.characters}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, characters: e.target.value }))}
                      />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label" htmlFor="scene-location">Location</label>
                      <input
                        id="scene-location"
                        className="form-control"
                        value={sceneForm.location}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, location: e.target.value }))}
                      />
                    </div>
                    <div className="col-12">
                      <label className="form-label" htmlFor="scene-narration">Narration</label>
                      <textarea
                        id="scene-narration"
                        className="form-control"
                        rows={2}
                        value={sceneForm.narration}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, narration: e.target.value }))}
                      />
                    </div>
                    <div className="col-md-4">
                      <label className="form-label" htmlFor="scene-visual">Visual prompt</label>
                      <textarea
                        id="scene-visual"
                        className="form-control"
                        rows={2}
                        value={sceneForm.visual_prompt}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, visual_prompt: e.target.value }))}
                      />
                    </div>
                    <div className="col-md-4">
                      <label className="form-label" htmlFor="scene-motion">Motion prompt</label>
                      <textarea
                        id="scene-motion"
                        className="form-control"
                        rows={2}
                        value={sceneForm.motion_prompt}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, motion_prompt: e.target.value }))}
                      />
                    </div>
                    <div className="col-md-4">
                      <label className="form-label" htmlFor="scene-audio">Audio direction</label>
                      <textarea
                        id="scene-audio"
                        className="form-control"
                        rows={2}
                        value={sceneForm.audio_direction}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, audio_direction: e.target.value }))}
                      />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label" htmlFor="scene-prev">Continuity · previous</label>
                      <input
                        id="scene-prev"
                        className="form-control"
                        value={sceneForm.continuity_previous}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, continuity_previous: e.target.value }))}
                      />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label" htmlFor="scene-next">Continuity · next</label>
                      <input
                        id="scene-next"
                        className="form-control"
                        value={sceneForm.continuity_next}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, continuity_next: e.target.value }))}
                      />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label" htmlFor="scene-char-state">Character state</label>
                      <input
                        id="scene-char-state"
                        className="form-control"
                        value={sceneForm.continuity_character}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, continuity_character: e.target.value }))}
                      />
                    </div>
                    <div className="col-md-6">
                      <label className="form-label" htmlFor="scene-env-state">Environment state</label>
                      <input
                        id="scene-env-state"
                        className="form-control"
                        value={sceneForm.continuity_environment}
                        onChange={(e) => setSceneForm((prev) => ({ ...prev, continuity_environment: e.target.value }))}
                      />
                    </div>
                    <div className="col-12">
                      <button type="submit" className="btn btn-primary" disabled={Boolean(busy)}>
                        {busy === 'save-scene' ? 'Saving…' : editingScene ? 'Save scene' : 'Create scene'}
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </>
          )}
        </div>
      </div>
    </section>
  );
}
