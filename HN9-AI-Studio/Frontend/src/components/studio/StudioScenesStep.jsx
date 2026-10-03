import { useEffect, useMemo, useState } from 'react';
import { aspectRatioLabel, storyFieldError } from '../../services/storyConstants';
import {
  approveStoryScene,
  archiveStoryScene,
  createStoryReel,
  createStoryScene,
  duplicateStoryScene,
  editStorySceneVersion,
  extendStorySceneVersion,
  approveProductionUnitVersion,
  assembleProductionScene,
  getProductionPlanScene,
  getProductionSceneAssemblyFileUrl,
  getProductionUnitVersionFileUrl,
  getStorySceneVersionFileUrl,
  getStoryStyle,
  getStoryVideoFileUrl,
  getStoryVideoJob,
  listProductionPlans,
  listProductionUnitVersions,
  listStorySceneVersions,
  markStoryProjectChanged,
  regenerateStoryScene,
  requestProductionUnitVersionChanges,
  selectProductionUnitVersion,
  reorderStoryScenes,
  reworkStoryScene,
  submitStorySceneReview,
  updateStoryScene,
} from '../../services/storyService';
import {
  failureReason,
  formatDateTime,
  formatSeconds,
  friendlyError,
  isJobActive,
  jobStatusLabel,
  reviewStatusLabel,
  sceneName,
  sortedScenes,
  SOUND_ROLES,
} from '../../services/studioMessages';
import { useFeedback, useStudio } from './StudioContext';
import StudioScenePlanner from './StudioScenePlanner';
import {
  BusyButton,
  jobTone,
  MediaPreview,
  NotConnectedNote,
  reviewTone,
  StatusBadge,
  StepFooter,
  StepHeader,
} from './StudioUi';

const VIDEO_MODES = [
  { value: 'text', flag: 'text', label: 'From a description', hint: 'Describe what happens and the video is created from your words.' },
  { value: 'image', flag: 'image', label: 'From a picture', hint: 'Start the video from one of your approved pictures.' },
  { value: 'reference', flag: 'reference', label: 'From my character/style', hint: 'Keep your approved character or style pictures consistent.' },
];

export default function StudioScenesStep({ nav, focusSceneId = null, openPlanner = false }) {
  const { reels, reel, reelId, sceneStatus, connections, selectReel, project, goTo } = useStudio();
  const [editing, setEditing] = useState(null);
  const [planning, setPlanning] = useState(openPlanner);

  useEffect(() => {
    if (openPlanner) setPlanning(true);
  }, [openPlanner]);
  const scenes = sortedScenes(reel);
  const statusById = useMemo(() => Object.fromEntries(sceneStatus.map((item) => [item.scene_id, item])), [sceneStatus]);
  const videoConnected = Boolean(connections?.video && Object.values(connections.video).some(Boolean));

  useEffect(() => {
    if (!focusSceneId) return;
    document.getElementById(`scene-${focusSceneId}`)?.scrollIntoView({ block: 'center' });
  }, [focusSceneId, reelId]);

  if (editing) {
    const scene = editing === 'new' ? null : scenes.find((item) => item.id === editing);
    return (
      <div className="studio-step">
        <SceneEditor key={editing} scene={scene} nextSequence={scenes.length + 1} onClose={() => setEditing(null)} />
      </div>
    );
  }

  if (reels.length === 0 || planning) {
    return (
      <div className="studio-step">
        <StepHeader title="Scenes" purpose="Break your story into short scenes. Each scene gets its own video, sound and approval." />
        <StudioScenePlanner
          firstTime={reels.length === 0}
          onManual={() => {
            setPlanning(false);
            setEditing('new');
          }}
          onDone={(newReelId) => {
            setPlanning(false);
            if (newReelId) selectReel(newReelId);
            else if (openPlanner) goTo('scenes');
          }}
          onCancel={
            reels.length > 0
              ? () => {
                  setPlanning(false);
                  if (openPlanner) goTo('scenes');
                }
              : null
          }
        />
        <StepFooter prev={nav.prev} onNavigate={nav.onNavigate} />
      </div>
    );
  }

  const approved = scenes.filter((scene) => statusById[scene.id]?.version?.status === 'approved').length;

  return (
    <div className="studio-step">
      <StepHeader title="Scenes" purpose="Each scene goes through: details → video → sound → approval. Approved scenes appear in the Final Video automatically.">
        <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => setEditing('new')}>
          <i className="bi bi-plus-lg me-1" aria-hidden="true" />
          Add scene
        </button>
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => setPlanning(true)}>
          <i className="bi bi-journal-check me-1" aria-hidden="true" />
          Story plan
        </button>
      </StepHeader>

      {reels.length > 1 ? (
        <div className="studio-reel-bar">
          <label className="form-label mb-0 me-2" htmlFor="scenes-reel">
            Video
          </label>
          <select id="scenes-reel" className="form-select form-select-sm w-auto" value={reelId || ''} onChange={(event) => selectReel(event.target.value)}>
            {reels.map((item) => (
              <option key={item.id} value={item.id}>
                {item.title || project.name}
              </option>
            ))}
          </select>
        </div>
      ) : null}

      <p className="small text-secondary mb-3">
        {scenes.length} {scenes.length === 1 ? 'scene' : 'scenes'} · {formatSeconds(scenes.reduce((sum, scene) => sum + (scene.duration_seconds || 0), 0))} ·{' '}
        {approved} approved
      </p>

      {!videoConnected ? <NotConnectedNote area="video" className="mb-3" /> : null}

      {scenes.length === 0 ? (
        <div className="studio-empty card border-0 glass-card">
          <i className="bi bi-film" aria-hidden="true" />
          <p className="fw-semibold mb-1">This video has no scenes yet</p>
          <p className="small text-secondary mb-3">Add a scene to describe what happens first.</p>
          <button type="button" className="btn btn-primary btn-sm" onClick={() => setEditing('new')}>
            Add scene
          </button>
        </div>
      ) : (
        <ol className="studio-scene-list list-unstyled">
          {scenes.map((scene, index) => (
            <SceneCard
              key={scene.id}
              scene={scene}
              status={statusById[scene.id] || null}
              isFirst={index === 0}
              isLast={index === scenes.length - 1}
              scenes={scenes}
              highlighted={focusSceneId === scene.id}
              onEdit={() => setEditing(scene.id)}
            />
          ))}
        </ol>
      )}

      <StepFooter prev={nav.prev} next={nav.next} onNavigate={nav.onNavigate} />
    </div>
  );
}

function SceneCard({ scene, status, isFirst, isLast, scenes, highlighted, onEdit }) {
  const { projectId, reelId, connections, canApprove, refresh, goTo } = useStudio();
  const feedback = useFeedback();
  const [panel, setPanel] = useState(null);
  const [busy, setBusy] = useState('');
  const [note, setNote] = useState('');
  const version = status?.version || null;
  const video = status?.video || null;
  const sounds = (status?.sounds || []).filter((sound) => sound.has_file);
  const review = version?.status || null;
  const videoReady = Boolean(video?.has_file && video.status === 'completed');
  const videoBusy = video && isJobActive(video.status);
  const videoConnected = Boolean(connections?.video && Object.values(connections.video).some(Boolean));
  const name = sceneName(scene);

  async function act(key, action, success) {
    setBusy(key);
    try {
      await action();
      await refresh(['status']);
      if (success) feedback.success(success);
      setPanel(null);
      setNote('');
      return true;
    } catch (err) {
      feedback.error(friendlyError(err, 'That did not work. Please try again.', 'video'));
      return false;
    } finally {
      setBusy('');
    }
  }

  async function structural(key, action, success) {
    setBusy(key);
    try {
      await action();
      await refresh(['reels', 'status']);
      feedback.success(success);
    } catch (err) {
      feedback.error(friendlyError(err, 'That did not work. Please try again.'));
    } finally {
      setBusy('');
    }
  }

  function move(offset) {
    const ids = scenes.map((item) => item.id);
    const from = ids.indexOf(scene.id);
    const [moved] = ids.splice(from, 1);
    ids.splice(from + offset, 0, moved);
    structural('move', () => reorderStoryScenes(projectId, reelId, ids), `${name} moved ${offset < 0 ? 'up' : 'down'}.`);
  }

  async function checkProgress() {
    setBusy('check');
    try {
      await getStoryVideoJob(projectId, video.job_id);
      markStoryProjectChanged(projectId);
      await refresh(['status']);
      feedback.info(`${name}: progress updated.`);
    } catch (err) {
      feedback.error(friendlyError(err, 'Progress could not be checked. Please try again.', 'video'));
    } finally {
      setBusy('');
    }
  }

  let videoText = videoConnected ? 'Not created yet' : 'Not connected';
  if (video) videoText = video.status === 'failed' ? `Failed: ${failureReason(video.error_code)}` : jobStatusLabel(video.status);

  const actions = [];
  if (review === 'pending_review' && canApprove) {
    actions.push(
      <BusyButton
        key="approve"
        className="btn btn-success btn-sm"
        busy={busy === 'approve'}
        busyLabel="Approving…"
        disabled={Boolean(busy)}
        onClick={() =>
          act(
            'approve',
            () => approveStoryScene(projectId, reelId, scene.id),
            videoReady ? `${name} approved and added to the Final Video timeline.` : `${name} approved.`,
          )
        }
      >
        Approve
      </BusyButton>,
      <button key="rework" type="button" className="btn btn-outline-secondary btn-sm" disabled={Boolean(busy)} onClick={() => setPanel('rework')}>
        Request changes
      </button>,
    );
  } else if (review === 'pending_review') {
    actions.push(
      <span key="waiting" className="small text-secondary">
        Waiting for the project owner to review.
      </span>,
    );
  } else if (videoBusy) {
    actions.push(
      <BusyButton key="check" className="btn btn-outline-primary btn-sm" busy={busy === 'check'} busyLabel="Checking…" onClick={checkProgress}>
        Check progress
      </BusyButton>,
    );
  } else if (videoReady && (review === 'draft' || review === 'needs_rework')) {
    actions.push(
      <BusyButton
        key="submit"
        className="btn btn-primary btn-sm"
        busy={busy === 'submit'}
        busyLabel="Sending…"
        disabled={Boolean(busy)}
        onClick={() => act('submit', () => submitStorySceneReview(projectId, reelId, scene.id), `${name} sent for review.`)}
      >
        Send for review
      </BusyButton>,
    );
  } else if (review === 'approved') {
    actions.push(
      <button key="sound" type="button" className="btn btn-outline-primary btn-sm" onClick={() => goTo('sound', { scene: scene.id })}>
        <i className="bi bi-soundwave me-1" aria-hidden="true" />
        Add sound
      </button>,
    );
  }

  const canCreateVideo = videoConnected && !videoBusy && review !== 'pending_review';
  if (canCreateVideo && (!videoReady || review === 'needs_rework')) {
    actions.unshift(
      <button key="video" type="button" className="btn btn-primary btn-sm" disabled={Boolean(busy)} onClick={() => setPanel('video')}>
        <i className="bi bi-camera-reels me-1" aria-hidden="true" />
        {video?.status === 'failed' ? 'Try again' : review === 'needs_rework' ? 'Create new version' : 'Create video'}
      </button>,
    );
  }
  const canChange = videoReady && version && !videoBusy && review !== 'pending_review';
  if (canChange && connections?.video?.edit) {
    actions.push(
      <button key="change" type="button" className="btn btn-outline-primary btn-sm" disabled={Boolean(busy)} onClick={() => setPanel('change')}>
        <i className="bi bi-magic me-1" aria-hidden="true" />
        Change this video
      </button>,
    );
  }
  if (canChange && connections?.video?.extend) {
    actions.push(
      <button key="extend" type="button" className="btn btn-outline-primary btn-sm" disabled={Boolean(busy)} onClick={() => setPanel('extend')}>
        <i className="bi bi-arrows-angle-expand me-1" aria-hidden="true" />
        Make it longer
      </button>,
    );
  }

  return (
    <li id={`scene-${scene.id}`} className={`studio-scene-card card border-0 glass-card${highlighted ? ' is-highlighted' : ''}`}>
      <div className="card-body">
        <div className="studio-scene-head">
          <span className="studio-scene-number" aria-hidden="true">
            {scene.sequence}
          </span>
          <div className="flex-grow-1 min-w-0">
            <h3 className="h6 mb-1">
              <span className="visually-hidden">Scene {scene.sequence}: </span>
              {scene.title || `Scene ${scene.sequence}`}
            </h3>
            <p className="small text-secondary mb-0">
              {formatSeconds(scene.duration_seconds)}
              {scene.characters?.length ? ` · ${scene.characters.join(', ')}` : ''}
              {scene.location ? ` · ${scene.location}` : ''}
            </p>
          </div>
          <StatusBadge tone={reviewTone(review)}>{version ? reviewStatusLabel(review) : 'Not started'}</StatusBadge>
        </div>

        {scene.story ? <p className="small mb-2 mt-2 studio-clamp">{scene.story}</p> : null}

        <dl className="studio-scene-facts">
          <div>
            <dt>Version</dt>
            <dd>{version ? `Version ${version.version}` : 'None yet'}</dd>
          </div>
          <div>
            <dt>Video</dt>
            <dd>
              <StatusBadge tone={video ? jobTone(video.status) : videoConnected ? 'neutral' : 'warning'}>{videoText}</StatusBadge>
            </dd>
          </div>
          <div>
            <dt>Sound</dt>
            <dd>
              {sounds.length
                ? sounds
                    .map((sound) => {
                      const name = SOUND_ROLES.find((role) => role.value === sound.role)?.label || 'Sound';
                      if (sound.approved_label) return `${name} (${sound.approved_label} approved)`;
                      return sound.review_status === 'pending_review' ? `${name} (ready for review)` : name;
                    })
                    .join(', ')
                : 'None yet'}
            </dd>
          </div>
        </dl>

        {version?.status === 'needs_rework' && version.review_comment ? (
          <p className="studio-note studio-note--warning small mb-2">
            <i className="bi bi-chat-left-text" aria-hidden="true" />
            <span>Changes requested: “{version.review_comment}”</span>
          </p>
        ) : null}

        {videoReady ? (
          <div className="mb-2">
            <MediaPreview load={() => getStoryVideoFileUrl(projectId, video.job_id)} label={`${name} video`} />
          </div>
        ) : null}

        <div className="studio-scene-actions">
          {actions}
          <button type="button" className="btn btn-outline-secondary btn-sm" onClick={onEdit} disabled={Boolean(busy)}>
            Edit details
          </button>
          <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => setPanel(panel === 'units' ? null : 'units')}>
            Generation units
          </button>
          <details className="studio-menu">
            <summary className="btn btn-link btn-sm">More</summary>
            <div className="studio-menu-items" onClick={(event) => event.currentTarget.closest('details')?.removeAttribute('open')}>
              {!isFirst ? (
                <button type="button" className="dropdown-item" onClick={() => move(-1)} disabled={Boolean(busy)}>
                  Move up
                </button>
              ) : null}
              {!isLast ? (
                <button type="button" className="dropdown-item" onClick={() => move(1)} disabled={Boolean(busy)}>
                  Move down
                </button>
              ) : null}
              {version ? (
                <button type="button" className="dropdown-item" onClick={() => setPanel('history')}>
                  Version history
                </button>
              ) : null}
              <button type="button" className="dropdown-item" onClick={() => setPanel('units')}>
                Generation units
              </button>
              <button
                type="button"
                className="dropdown-item"
                disabled={Boolean(busy)}
                onClick={() => structural('duplicate', () => duplicateStoryScene(projectId, reelId, scene.id), `${name} duplicated.`)}
              >
                Duplicate
              </button>
              <button type="button" className="dropdown-item text-danger" disabled={Boolean(busy)} onClick={() => setPanel('remove')}>
                Remove
              </button>
            </div>
          </details>
        </div>

        {panel === 'video' ? <CreateVideoPanel scene={scene} onClose={() => setPanel(null)} onCreated={() => refresh(['status'])} /> : null}

        {panel === 'change' || panel === 'extend' ? (
          <form
            className="studio-inline-panel"
            onSubmit={(event) => {
              event.preventDefault();
              const instruction = note.trim();
              const extend = panel === 'extend';
              act(
                panel,
                () =>
                  extend
                    ? extendStorySceneVersion(projectId, reelId, scene.id, version.id, instruction)
                    : editStorySceneVersion(projectId, reelId, scene.id, version.id, instruction),
                extend
                  ? `${name}: a longer version is being created. It appears here as a new version when ready.`
                  : `${name}: the changed version is being created. It appears here as a new version when ready.`,
              ).then((ok) => ok && markStoryProjectChanged(projectId));
            }}
          >
            <label className="form-label" htmlFor={`${panel}-${scene.id}`}>
              {panel === 'extend' ? 'What happens next?' : 'What should change in this video?'}
            </label>
            <textarea
              id={`${panel}-${scene.id}`}
              className="form-control"
              rows={2}
              value={note}
              onChange={(event) => setNote(event.target.value)}
              placeholder={panel === 'extend' ? 'e.g. The whale dives and the girl waves goodbye.' : 'e.g. Make it night time with a full moon.'}
              aria-describedby={`${panel}-${scene.id}-help`}
              required
            />
            <div className="form-text" id={`${panel}-${scene.id}-help`}>
              The current version stays as it is. A new version is created, and you can compare them in Version history.
            </div>
            <div className="d-flex gap-2 mt-2">
              <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy === panel} busyLabel="Sending…" disabled={!note.trim()}>
                {panel === 'extend' ? 'Make it longer' : 'Create changed version'}
              </BusyButton>
              <button type="button" className="btn btn-link btn-sm" onClick={() => setPanel(null)}>
                Cancel
              </button>
            </div>
          </form>
        ) : null}

        {panel === 'history' ? <SceneVersionHistory scene={scene} onClose={() => setPanel(null)} /> : null}
        {panel === 'units' ? <UnitVersionsPanel scene={scene} onClose={() => setPanel(null)} /> : null}

        {panel === 'rework' ? (
          <form
            className="studio-inline-panel"
            onSubmit={(event) => {
              event.preventDefault();
              act('rework', () => reworkStoryScene(projectId, reelId, scene.id, note.trim()), `Changes requested for ${name}.`);
            }}
          >
            <label className="form-label" htmlFor={`rework-${scene.id}`}>
              What should change?
            </label>
            <textarea
              id={`rework-${scene.id}`}
              className="form-control"
              rows={2}
              value={note}
              onChange={(event) => setNote(event.target.value)}
              placeholder="e.g. Make the sky darker and slow down the camera."
              required
            />
            <div className="d-flex gap-2 mt-2">
              <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy === 'rework'} disabled={!note.trim()}>
                Request changes
              </BusyButton>
              <button type="button" className="btn btn-link btn-sm" onClick={() => setPanel(null)}>
                Cancel
              </button>
            </div>
          </form>
        ) : null}

        {panel === 'remove' ? (
          <div className="studio-inline-panel d-flex flex-wrap align-items-center gap-2">
            <span>Remove {name}? Its videos and sound stay in History.</span>
            <BusyButton
              className="btn btn-danger btn-sm"
              busy={busy === 'remove'}
              onClick={() => structural('remove', () => archiveStoryScene(projectId, reelId, scene.id), `${name} removed.`)}
            >
              Yes, remove
            </BusyButton>
            <button type="button" className="btn btn-link btn-sm" onClick={() => setPanel(null)}>
              Keep
            </button>
          </div>
        ) : null}
      </div>
    </li>
  );
}

function UnitVersionsPanel({ scene, onClose }) {
  const { projectId, canApprove } = useStudio();
  const feedback = useFeedback();
  const [state, setState] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState('');
  const [noteFor, setNoteFor] = useState(null);
  const [note, setNote] = useState('');
  const [assembly, setAssembly] = useState(null);
  const [building, setBuilding] = useState(false);

  async function load() {
    const plans = await listProductionPlans(projectId);
    const current = plans.find((plan) => plan.is_current) || null;
    if (!current) {
      return { planId: null, units: [], versions: {} };
    }
    const planScene = await getProductionPlanScene(projectId, current.id, scene.id);
    const units = Array.isArray(planScene?.units) ? planScene.units : [];
    const versions = {};
    await Promise.all(
      units.map(async (unit) => {
        versions[unit.id] = await listProductionUnitVersions(projectId, current.id, unit.id);
      }),
    );
    return { planId: current.id, units, versions };
  }

  useEffect(() => {
    let cancelled = false;
    load()
      .then((result) => {
        if (!cancelled) setState(result);
      })
      .catch((err) => {
        if (cancelled) return;
        if (Number(err?.status) === 404) {
          setState({ planId: null, units: [], versions: {} });
          return;
        }
        setState({ planId: null, units: [], versions: {} });
        setError(friendlyError(err, 'Unit videos could not be loaded.'));
      });
    return () => {
      cancelled = true;
    };
    // Reload only when the scene changes. Actions call load() themselves.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId, scene.id]);

  async function run(key, action, success) {
    setBusy(key);
    setError('');
    try {
      await action();
      setState(await load());
      setNoteFor(null);
      setNote('');
      feedback.success(success);
    } catch (err) {
      setError(friendlyError(err, 'That could not be saved. Please try again.'));
    } finally {
      setBusy('');
    }
  }

  return (
    <section className="studio-inline-panel" aria-labelledby={`units-${scene.id}`}>
      <div className="d-flex align-items-center justify-content-between mb-2">
        <h4 className="h6 mb-0" id={`units-${scene.id}`}>
          Generation units
        </h4>
        <button type="button" className="btn btn-link btn-sm" onClick={onClose}>
          Close
        </button>
      </div>
      <p className="small text-secondary">Each unit keeps every video. Choose one approved video, then build them into one scene video.</p>
      {state?.planId && canApprove ? (
        <div className="mb-3">
          <BusyButton
            className="btn btn-primary btn-sm"
            busy={building}
            busyLabel="Building…"
            onClick={() => {
              setBuilding(true);
              setError('');
              assembleProductionScene(projectId, state.planId, scene.id)
                .then((result) => setAssembly(result?.assembly || null))
                .catch((err) => setError(friendlyError(err, 'The scene video could not be built.')))
                .finally(() => setBuilding(false));
            }}
          >
            Build scene video
          </BusyButton>
          {assembly ? (
            <div className="mt-2">
              <StatusBadge tone={assembly.status === 'completed' ? 'success' : assembly.status === 'failed' ? 'danger' : 'progress'}>
                {assembly.status_label}
              </StatusBadge>
              {assembly.label ? <span className="small ms-2">{assembly.label}</span> : null}
              {assembly.error_message ? <p className="small text-danger mt-2 mb-0">{assembly.error_message}</p> : null}
              {assembly.output_available ? (
                <div className="mt-2">
                  <MediaPreview
                    load={() => getProductionSceneAssemblyFileUrl(projectId, state.planId, scene.id, assembly.id)}
                    label={`${sceneName(scene)} scene video`}
                  />
                </div>
              ) : null}
            </div>
          ) : null}
        </div>
      ) : null}
      {error ? <p className="text-danger small">{error}</p> : null}
      {state === null ? <p className="small text-secondary mb-0">Loading units…</p> : null}
      {state && !state.planId ? <p className="small text-secondary mb-0">No video versions yet.</p> : null}
      {state?.units?.map((unit) => {
        const pack = state.versions[unit.id] || { versions: [], message: null };
        const items = Array.isArray(pack.versions) ? pack.versions : [];
        const unitName = `Unit ${String(unit.sequence).padStart(2, '0')}`;
        return (
          <div key={unit.id} className="mb-3">
            <p className="fw-semibold mb-1">
              {unitName}
              <span className="fw-normal text-secondary"> · {formatSeconds(unit.duration_seconds)}</span>
            </p>
            {items.length === 0 ? <p className="small text-secondary mb-2">{pack.message || 'No video versions yet.'}</p> : null}
            <div className="d-grid gap-2">
              {items.map((item) => (
                <article key={item.id} className={`border rounded p-2 ${item.selected ? 'border-primary' : ''}`}>
                  <div className="d-flex flex-wrap align-items-center gap-2">
                    <span className="fw-semibold">{item.label}</span>
                    <StatusBadge tone={reviewTone(item.status)}>{item.status_label}</StatusBadge>
                    {item.selected ? <StatusBadge tone="success">{item.selected_label}</StatusBadge> : null}
                  </div>
                  {item.preview_available ? (
                    <div className="mt-2">
                      <MediaPreview
                        load={() => getProductionUnitVersionFileUrl(projectId, state.planId, unit.id, item.id)}
                        label={`${unitName} ${item.label}`}
                      />
                    </div>
                  ) : null}
                  {item.comment ? <p className="small mt-2 mb-0">“{item.comment}”</p> : null}
                  {canApprove ? (
                    <div className="d-flex flex-wrap gap-2 mt-2">
                      {item.status === 'pending_review' && item.preview_available ? (
                        <BusyButton
                          className="btn btn-success btn-sm"
                          busy={busy === `approve-${item.id}`}
                          busyLabel="Approving…"
                          disabled={Boolean(busy)}
                          onClick={() =>
                            run(`approve-${item.id}`, () => approveProductionUnitVersion(projectId, state.planId, unit.id, item.id), `${item.label} approved.`)
                          }
                        >
                          Approve
                        </BusyButton>
                      ) : null}
                      {item.status === 'pending_review' && item.preview_available ? (
                        <button type="button" className="btn btn-outline-secondary btn-sm" disabled={Boolean(busy)} onClick={() => setNoteFor(item.id)}>
                          Request changes
                        </button>
                      ) : null}
                      {item.approved && !item.selected ? (
                        <BusyButton
                          className="btn btn-primary btn-sm"
                          busy={busy === `select-${item.id}`}
                          busyLabel="Selecting…"
                          disabled={Boolean(busy)}
                          onClick={() =>
                            run(
                              `select-${item.id}`,
                              () => selectProductionUnitVersion(projectId, state.planId, unit.id, item.id),
                              `${item.label} selected for the final scene.`,
                            )
                          }
                        >
                          Select for the final scene
                        </BusyButton>
                      ) : null}
                    </div>
                  ) : null}
                  {noteFor === item.id ? (
                    <form
                      className="mt-2"
                      onSubmit={(event) => {
                        event.preventDefault();
                        const comment = note.trim();
                        if (!comment) return;
                        run(
                          `changes-${item.id}`,
                          () => requestProductionUnitVersionChanges(projectId, state.planId, unit.id, item.id, comment),
                          `Changes requested for ${item.label}.`,
                        );
                      }}
                    >
                      <label className="form-label" htmlFor={`unit-note-${item.id}`}>
                        What should change?
                      </label>
                      <textarea
                        id={`unit-note-${item.id}`}
                        className="form-control"
                        rows={2}
                        value={note}
                        onChange={(event) => setNote(event.target.value)}
                        required
                      />
                      <div className="d-flex gap-2 mt-2">
                        <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy === `changes-${item.id}`} busyLabel="Sending…" disabled={!note.trim()}>
                          Request changes
                        </BusyButton>
                        <button type="button" className="btn btn-link btn-sm" onClick={() => setNoteFor(null)}>
                          Cancel
                        </button>
                      </div>
                    </form>
                  ) : null}
                </article>
              ))}
            </div>
          </div>
        );
      })}
    </section>
  );
}

function SceneVersionHistory({ scene, onClose }) {
  const { projectId, reelId } = useStudio();
  const [versions, setVersions] = useState(null);
  const [error, setError] = useState('');
  const [open, setOpen] = useState(null);

  useEffect(() => {
    let cancelled = false;
    listStorySceneVersions(projectId, reelId, scene.id)
      .then((items) => {
        if (!cancelled) setVersions([...items].reverse());
      })
      .catch((err) => {
        if (!cancelled) {
          setVersions([]);
          setError(friendlyError(err, 'Version history could not be loaded.'));
        }
      });
    return () => {
      cancelled = true;
    };
  }, [projectId, reelId, scene.id]);

  return (
    <section className="studio-inline-panel" aria-labelledby={`history-${scene.id}`}>
      <div className="d-flex align-items-center justify-content-between mb-2">
        <h4 className="h6 mb-0" id={`history-${scene.id}`}>
          Version history
        </h4>
        <button type="button" className="btn btn-link btn-sm" onClick={onClose}>
          Close
        </button>
      </div>
      {error ? <p className="text-danger small">{error}</p> : null}
      {versions === null ? <p className="small text-secondary mb-0">Loading versions…</p> : null}
      {versions?.length === 0 && !error ? <p className="small text-secondary mb-0">No versions yet.</p> : null}
      {versions?.length ? (
        <ol className="list-unstyled d-grid gap-2 mb-0" reversed>
          {versions.map((item) => (
            <li key={item.id} className="border rounded p-2">
              <div className="d-flex flex-wrap align-items-center gap-2">
                <span className="fw-semibold">Version {item.version}</span>
                <StatusBadge tone={reviewTone(item.status)}>{reviewStatusLabel(item.status)}</StatusBadge>
                {item.created_at ? <span className="small text-secondary">{formatDateTime(item.created_at)}</span> : null}
                {item.has_file ? (
                  <button
                    type="button"
                    className="btn btn-link btn-sm p-0 ms-auto"
                    aria-expanded={open === item.id}
                    onClick={() => setOpen(open === item.id ? null : item.id)}
                  >
                    {open === item.id ? 'Hide video' : 'Watch'}
                  </button>
                ) : (
                  <span className="small text-secondary ms-auto">No video</span>
                )}
              </div>
              {item.review_comment ? <p className="small mb-0 mt-1">Feedback: “{item.review_comment}”</p> : null}
              {open === item.id ? (
                <div className="mt-2">
                  <MediaPreview
                    load={() => getStorySceneVersionFileUrl(projectId, reelId, scene.id, item.id)}
                    label={`${sceneName(scene)} version ${item.version}`}
                  />
                </div>
              ) : null}
            </li>
          ))}
        </ol>
      ) : null}
    </section>
  );
}

function CreateVideoPanel({ scene, onClose, onCreated }) {
  const { projectId, reelId, connections, characters, bible } = useStudio();
  const feedback = useFeedback();
  const modes = VIDEO_MODES.filter((mode) => connections?.video?.[mode.flag]);
  const [mode, setMode] = useState(modes[0]?.value || 'text');
  const [prompt, setPrompt] = useState(scene.visual_prompt || scene.story || '');
  const [referenceId, setReferenceId] = useState('');
  const [stylePicture, setStylePicture] = useState(null);
  const [busy, setBusy] = useState(false);
  const needsPicture = mode !== 'text';

  useEffect(() => {
    if (!needsPicture) return undefined;
    let cancelled = false;
    getStoryStyle(projectId)
      .then((style) => {
        if (!cancelled && style?.approved_reference) setStylePicture(style.approved_reference);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [needsPicture, projectId]);

  const pictures = [
    ...characters.filter((item) => item.approved_reference).map((item) => ({ id: item.approved_reference.id, label: item.name })),
    ...(stylePicture ? [{ id: stylePicture.id, label: 'Look & feel picture' }] : []),
  ];

  async function submit(event) {
    event.preventDefault();
    setBusy(true);
    try {
      await regenerateStoryScene(projectId, reelId, scene.id, {
        mode,
        prompt: prompt.trim() || null,
        referenceId: needsPicture ? referenceId : null,
      });
      await onCreated();
      feedback.success(`Video requested for ${sceneName(scene)}. It can take a few minutes; use “Check progress” to update it.`);
      onClose();
    } catch (err) {
      feedback.error(friendlyError(err, 'The video could not be requested. Please try again.', 'video'));
    } finally {
      setBusy(false);
    }
  }

  if (modes.length === 0) {
    return <NotConnectedNote area="video" className="mt-3" />;
  }

  return (
    <form className="studio-inline-panel" onSubmit={submit}>
      <h4 className="h6">Create the video for {sceneName(scene)}</h4>
      <fieldset className="mb-3">
        <legend className="form-label mb-1">How should it be made?</legend>
        <div className="studio-choice-grid">
          {modes.map((option) => (
            <label key={option.value} className={`studio-choice${mode === option.value ? ' is-selected' : ''}`}>
              <input
                type="radio"
                className="form-check-input"
                name={`video-mode-${scene.id}`}
                value={option.value}
                checked={mode === option.value}
                onChange={() => setMode(option.value)}
              />
              <span>
                <span className="d-block fw-semibold">{option.label}</span>
                <span className="small text-secondary">{option.hint}</span>
              </span>
            </label>
          ))}
        </div>
      </fieldset>

      <label className="form-label" htmlFor={`video-prompt-${scene.id}`}>
        What should the video show?
      </label>
      <textarea
        id={`video-prompt-${scene.id}`}
        className="form-control mb-3"
        rows={3}
        value={prompt}
        onChange={(event) => setPrompt(event.target.value)}
        placeholder="e.g. Maya runs along the cliff at sunset as a whale breaches below."
      />

      {needsPicture ? (
        <fieldset className="mb-3">
          <legend className="form-label mb-1">Which picture?</legend>
          {pictures.length === 0 ? (
            <p className="small text-secondary mb-0">
              No approved pictures yet. Approve a character or look & feel picture in Cast & Look first.
            </p>
          ) : (
            <div className="d-flex flex-wrap gap-2">
              {pictures.map((picture) => (
                <label key={picture.id} className={`studio-choice studio-choice--compact${referenceId === picture.id ? ' is-selected' : ''}`}>
                  <input
                    type="radio"
                    className="form-check-input"
                    name={`video-picture-${scene.id}`}
                    checked={referenceId === picture.id}
                    onChange={() => setReferenceId(picture.id)}
                  />
                  <span>{picture.label}</span>
                </label>
              ))}
            </div>
          )}
        </fieldset>
      ) : null}

      <p className="small text-secondary mb-3">
        Length: {formatSeconds(scene.duration_seconds)} (from the scene) · Format: {aspectRatioLabel(bible?.aspect_ratio)} (from Story)
      </p>

      <div className="d-flex gap-2">
        <BusyButton type="submit" busy={busy} busyLabel="Requesting…" disabled={needsPicture && !referenceId}>
          Create video
        </BusyButton>
        <button type="button" className="btn btn-link" onClick={onClose} disabled={busy}>
          Cancel
        </button>
      </div>
    </form>
  );
}

const SCENE_FIELDS = ['title', 'story', 'location', 'narration', 'visual_prompt', 'motion_prompt', 'audio_direction'];

function SceneEditor({ scene, nextSequence, onClose }) {
  const { projectId, project, reelId, characters, refresh, selectReel } = useStudio();
  const feedback = useFeedback();
  const isNew = !scene;
  const [values, setValues] = useState(() => ({
    ...Object.fromEntries(SCENE_FIELDS.map((field) => [field, scene?.[field] || ''])),
    duration_seconds: scene?.duration_seconds || 30,
    characters: scene?.characters || [],
  }));
  const [errors, setErrors] = useState(null);
  const [saving, setSaving] = useState(false);
  const castNames = characters.map((item) => item.name);
  const extraNames = values.characters.filter((nameValue) => !castNames.includes(nameValue));

  function set(field, value) {
    setValues((current) => ({ ...current, [field]: value }));
  }

  function toggleCharacter(nameValue) {
    set(
      'characters',
      values.characters.includes(nameValue) ? values.characters.filter((item) => item !== nameValue) : [...values.characters, nameValue],
    );
  }

  async function save(event) {
    event.preventDefault();
    setSaving(true);
    setErrors(null);
    const payload = { ...values, duration_seconds: Number(values.duration_seconds) || 30 };
    try {
      if (isNew) {
        let targetReel = reelId;
        if (!targetReel) {
          const created = await createStoryReel(projectId, { title: project.name || 'My video' });
          targetReel = created.id;
        }
        await createStoryScene(projectId, targetReel, { ...payload, sequence: nextSequence });
        await refresh(['reels', 'status']);
        if (targetReel !== reelId) selectReel(targetReel);
        feedback.success('Scene added.');
      } else {
        await updateStoryScene(projectId, reelId, scene.id, payload);
        await refresh(['reels']);
        feedback.success('Scene saved.');
      }
      onClose();
    } catch (err) {
      setErrors(err);
      feedback.error(friendlyError(err, 'The scene could not be saved. Please try again.'));
    } finally {
      setSaving(false);
    }
  }

  function input(name, label, { placeholder = '', textarea = false, rows = 3, helper = '' } = {}) {
    const id = `scene-field-${name}`;
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
          rows={textarea ? rows : undefined}
          value={values[name]}
          placeholder={placeholder}
          onChange={(event) => set(name, event.target.value)}
          disabled={saving}
          aria-describedby={helper ? `${id}-help` : undefined}
        />
        {helper ? (
          <div className="form-text" id={`${id}-help`}>
            {helper}
          </div>
        ) : null}
        {error ? <div className="invalid-feedback d-block">{error}</div> : null}
      </div>
    );
  }

  return (
    <>
      <button type="button" className="btn btn-link px-0 mb-2" onClick={onClose}>
        <i className="bi bi-arrow-left me-1" aria-hidden="true" />
        Back to scenes
      </button>
      <StepHeader title={isNew ? 'New scene' : sceneName(scene)} purpose="Describe what happens. The video and sound for this scene start from these details." />
      <form className="card border-0 glass-card" onSubmit={save} noValidate>
        <div className="card-body d-grid gap-3">
          <div className="row g-3">
            <div className="col-md-8">{input('title', 'Scene title', { placeholder: 'e.g. The whale appears' })}</div>
            <div className="col-md-4">
              <label className="form-label" htmlFor="scene-field-duration">
                Length (seconds)
              </label>
              <input
                id="scene-field-duration"
                type="number"
                min={1}
                max={3600}
                className={`form-control${storyFieldError(errors, 'duration_seconds') ? ' is-invalid' : ''}`}
                value={values.duration_seconds}
                onChange={(event) => set('duration_seconds', event.target.value)}
                disabled={saving}
                aria-describedby="scene-field-duration-help"
              />
              <div className="form-text" id="scene-field-duration-help">
                A scene can be any length, from 1 second up to an hour.
              </div>
              {storyFieldError(errors, 'duration_seconds') ? (
                <div className="invalid-feedback d-block">{storyFieldError(errors, 'duration_seconds')}</div>
              ) : null}
            </div>
          </div>
          {input('story', 'What happens?', {
            textarea: true,
            placeholder: 'e.g. Maya spots a huge shadow in the water and climbs down the rocks to look closer.',
          })}
          <fieldset>
            <legend className="form-label mb-1">Who is in this scene?</legend>
            {castNames.length === 0 && extraNames.length === 0 ? (
              <p className="small text-secondary mb-0">No characters yet. Add them in Cast & Look to pick them here.</p>
            ) : (
              <div className="d-flex flex-wrap gap-2">
                {[...castNames, ...extraNames].map((nameValue) => (
                  <label key={nameValue} className={`studio-choice studio-choice--compact${values.characters.includes(nameValue) ? ' is-selected' : ''}`}>
                    <input
                      type="checkbox"
                      className="form-check-input"
                      checked={values.characters.includes(nameValue)}
                      onChange={() => toggleCharacter(nameValue)}
                      disabled={saving}
                    />
                    <span>{nameValue}</span>
                  </label>
                ))}
              </div>
            )}
          </fieldset>
          {input('location', 'Where does it happen?', { placeholder: 'e.g. The rocks below the lighthouse' })}
          {input('narration', 'Narration', {
            textarea: true,
            rows: 2,
            placeholder: 'What the narrator says during this scene (optional).',
          })}
          <details className="studio-more">
            <summary>More options (camera, movement, music notes)</summary>
            <div className="d-grid gap-3 mt-2">
              {input('visual_prompt', 'What the camera sees', {
                textarea: true,
                helper: 'Used as the starting description when you create this scene’s video.',
              })}
              {input('motion_prompt', 'How things move', { textarea: true, rows: 2 })}
              {input('audio_direction', 'Music and sound notes', { textarea: true, rows: 2 })}
            </div>
          </details>
          <div className="d-flex gap-2">
            <BusyButton type="submit" busy={saving} busyLabel="Saving…">
              {isNew ? 'Add scene' : 'Save scene'}
            </BusyButton>
            <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={saving}>
              Cancel
            </button>
          </div>
        </div>
      </form>
    </>
  );
}
