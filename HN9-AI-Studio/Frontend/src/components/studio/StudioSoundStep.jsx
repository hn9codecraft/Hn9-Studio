import { useCallback, useEffect, useState } from 'react';
import {
  approveStorySceneAudio,
  createStorySceneAudio,
  getStorySceneAudio,
  getStorySceneAudioFileUrl,
  listStoryReelAudio,
  markStoryProjectChanged,
  requestStorySceneAudioChanges,
  reworkStorySceneAudio,
  selectStorySceneAudio,
} from '../../services/storyService';
import {
  formatSeconds,
  friendlyError,
  isJobActive,
  reviewStatusLabel,
  sceneName,
  sortedScenes,
  SOUND_MESSAGES,
  SOUND_ROLES,
  soundFailure,
  soundState,
} from '../../services/studioMessages';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton, MediaPreview, NotConnectedNote, reviewTone, StatusBadge, StepFooter, StepHeader, StepSkeleton } from './StudioUi';

function defaultPrompt(role, scene, bible) {
  if (role === 'narration') return scene.narration || '';
  if (role === 'music') return scene.audio_direction || bible?.audio_defaults?.music_mood || '';
  if (role === 'dialogue') {
    return (scene.dialogue || [])
      .map((line) => (typeof line === 'string' ? line : [line?.character || line?.speaker, line?.line || line?.text].filter(Boolean).join(': ')))
      .filter(Boolean)
      .join('\n');
  }
  return '';
}

function byNewestVersion(a, b) {
  return (b.version_number || 0) - (a.version_number || 0);
}

export default function StudioSoundStep({ nav, focusSceneId = null }) {
  const { projectId, reel, reelId, reels, connections, sceneStatus, selectReel, goTo, project } = useStudio();
  const [audio, setAudio] = useState(null);
  const scenes = sortedScenes(reel);
  const roles = connections?.sound?.roles || [];

  const load = useCallback(() => {
    if (!reelId) {
      setAudio([]);
      return Promise.resolve();
    }
    return listStoryReelAudio(projectId, reelId)
      .then(setAudio)
      .catch(() => setAudio([]));
  }, [projectId, reelId]);

  useEffect(() => {
    load();
  }, [load]);

  useEffect(() => {
    if (focusSceneId && audio) document.getElementById(`sound-${focusSceneId}`)?.scrollIntoView({ block: 'center' });
  }, [focusSceneId, audio]);

  const statusById = Object.fromEntries(sceneStatus.map((item) => [item.scene_id, item]));

  return (
    <div className="studio-step">
      <StepHeader
        title="Sound"
        purpose="Give each scene narration or dialogue, listen to it, then approve the version you want in the video. Sound is optional; skip any scene that doesn’t need it."
      />

      {reels.length > 1 ? (
        <div className="studio-reel-bar">
          <label className="form-label mb-0 me-2" htmlFor="sound-reel">
            Video
          </label>
          <select id="sound-reel" className="form-select form-select-sm w-auto" value={reelId || ''} onChange={(event) => selectReel(event.target.value)}>
            {reels.map((item) => (
              <option key={item.id} value={item.id}>
                {item.title || project.name}
              </option>
            ))}
          </select>
        </div>
      ) : null}

      {roles.length === 0 ? <NotConnectedNote area="sound" className="mb-3" /> : null}

      {scenes.length === 0 ? (
        <div className="studio-empty card border-0 glass-card">
          <i className="bi bi-soundwave" aria-hidden="true" />
          <p className="fw-semibold mb-1">No scenes yet</p>
          <p className="small text-secondary mb-3">Sound is added per scene. Create your scenes first.</p>
          <button type="button" className="btn btn-primary btn-sm" onClick={() => goTo('scenes')}>
            Go to Scenes
          </button>
        </div>
      ) : audio === null ? (
        <StepSkeleton rows={2} />
      ) : (
        <ol className="studio-scene-list list-unstyled">
          {scenes.map((scene) => (
            <SceneSound
              key={scene.id}
              scene={scene}
              review={statusById[scene.id]?.version?.status || null}
              items={audio.filter((item) => item.scene_id === scene.id)}
              roles={roles}
              highlighted={focusSceneId === scene.id}
              onChanged={load}
            />
          ))}
        </ol>
      )}

      <StepFooter prev={nav.prev} next={nav.next} onNavigate={nav.onNavigate} />
    </div>
  );
}

function SceneSound({ scene, review, items, roles, highlighted, onChanged }) {
  return (
    <li id={`sound-${scene.id}`} className={`studio-scene-card card border-0 glass-card${highlighted ? ' is-highlighted' : ''}`}>
      <div className="card-body">
        <div className="studio-scene-head mb-2">
          <span className="studio-scene-number" aria-hidden="true">
            {scene.sequence}
          </span>
          <div className="flex-grow-1 min-w-0">
            <h3 className="h6 mb-0">{scene.title || `Scene ${scene.sequence}`}</h3>
            <p className="small text-secondary mb-0">{formatSeconds(scene.duration_seconds)}</p>
          </div>
          <StatusBadge tone={reviewTone(review)}>{review ? reviewStatusLabel(review) : 'Not started'}</StatusBadge>
        </div>
        <ul className="studio-sound-rows list-unstyled mb-0">
          {SOUND_ROLES.map((role) => (
            <SoundRow
              key={role.value}
              scene={scene}
              role={role}
              versions={items.filter((item) => item.role === role.value).sort(byNewestVersion)}
              connected={roles.includes(role.value)}
              anyConnected={roles.length > 0}
              onChanged={onChanged}
            />
          ))}
        </ul>
      </div>
    </li>
  );
}

function SoundRow({ scene, role, versions, connected, anyConnected, onChanged }) {
  const { projectId, reelId, bible, refresh, connections } = useStudio();
  const feedback = useFeedback();
  const [panel, setPanel] = useState('');
  const [source, setSource] = useState(null);
  const [prompt, setPrompt] = useState('');
  const [voice, setVoice] = useState('');
  const [comment, setComment] = useState('');
  const [showVersions, setShowVersions] = useState(false);
  const [busy, setBusy] = useState('');
  const voices = connections?.sound?.voices || [];
  const latest = versions[0] || null;
  const inVideo = versions.find((item) => item.selected) || null;
  const active = latest && isJobActive(latest.status);
  const label = `${role.label} for ${sceneName(scene)}`;
  const state = connected || latest ? soundState(latest) : { label: anyConnected ? 'Not available yet' : 'None yet', tone: 'neutral' };
  const inputId = `sound-${scene.id}-${role.value}`;

  async function run(key, action, onDone, fallback = SOUND_MESSAGES.failed) {
    setBusy(key);
    let announce = null;
    try {
      const result = await action();
      announce = () => onDone(result);
      setPanel('');
    } catch (err) {
      feedback.error(friendlyError(err, fallback, 'sound'));
    } finally {
      await Promise.all([onChanged(), refresh(['status'])]);
      setBusy('');
      announce?.();
    }
  }

  function generated(result) {
    if (result?.status === 'completed' && result?.has_file) feedback.success(`${SOUND_MESSAGES.ready} ${result.version_label || ''}`.trim());
    else feedback.info(SOUND_MESSAGES.generating);
  }

  function openGenerate(from = null) {
    setSource(from);
    setPrompt(from?.prompt || defaultPrompt(role.value, scene, bible));
    setVoice(connections?.sound?.default_voice || voices[0] || '');
    setPanel('generate');
  }

  function generate() {
    const body = { prompt: prompt.trim() };
    if (voices.length > 0 && voice) body.voice = voice;
    run(
      'generate',
      () =>
        source
          ? reworkStorySceneAudio(projectId, reelId, scene.id, source.id, body)
          : createStorySceneAudio(projectId, reelId, scene.id, { role: role.value, ...body }),
      generated,
    );
  }

  function tryAgain() {
    run('retry', () => reworkStorySceneAudio(projectId, reelId, scene.id, latest.id), generated);
  }

  function approve(item) {
    run(`approve-${item.id}`, () => approveStorySceneAudio(projectId, reelId, scene.id, item.id), () => feedback.success(SOUND_MESSAGES.approved), 'This sound could not be approved. Please try again.');
  }

  function requestChanges() {
    run(
      'changes',
      () => requestStorySceneAudioChanges(projectId, reelId, scene.id, latest.id, comment.trim()),
      () => {
        feedback.success(SOUND_MESSAGES.changes);
        setComment('');
      },
      'The change request could not be saved. Please try again.',
    );
  }

  function chooseForVideo(item) {
    run(`select-${item.id}`, () => selectStorySceneAudio(projectId, reelId, scene.id, item.id), () => feedback.success(SOUND_MESSAGES.selected), 'This version could not be chosen. Please try again.');
  }

  async function check() {
    setBusy('check');
    try {
      const result = await getStorySceneAudio(projectId, reelId, scene.id, latest.id);
      markStoryProjectChanged(projectId);
      await Promise.all([onChanged(), refresh(['status'])]);
      if (result?.status === 'failed') feedback.error(soundFailure(result));
      else if (result?.has_file) feedback.success(SOUND_MESSAGES.ready);
      else feedback.info(SOUND_MESSAGES.generating);
    } catch (err) {
      feedback.error(friendlyError(err, 'Progress could not be checked. Please try again.', 'sound'));
    } finally {
      setBusy('');
    }
  }

  let detail = null;
  if (latest?.status === 'failed') detail = soundFailure(latest);
  else if (latest?.review_status === 'needs_rework' && latest.review_comment) detail = `You asked: “${latest.review_comment}”`;
  else if (inVideo && inVideo.id !== latest?.id) detail = `${inVideo.version_label} is used in the video.`;

  return (
    <li className="studio-sound-row">
      <span className="studio-sound-role">
        <i className={`bi ${role.icon}`} aria-hidden="true" />
        {role.label}
      </span>
      <span className="studio-sound-status">
        <StatusBadge tone={state.tone}>{state.label}</StatusBadge>
        {latest?.version_label ? <span className="small text-secondary ms-2">{latest.version_label}</span> : null}
        {detail ? <span className="studio-sound-detail small text-secondary">{detail}</span> : null}
      </span>
      <span className="studio-sound-actions">
        {latest?.has_file ? (
          <MediaPreview key={latest.id} kind="audio" load={() => getStorySceneAudioFileUrl(projectId, reelId, scene.id, latest.id)} label={`${label}, ${latest.version_label}`} />
        ) : null}
        {active ? (
          <BusyButton className="btn btn-outline-primary btn-sm" busy={busy === 'check'} busyLabel="Checking…" onClick={check}>
            Check progress
          </BusyButton>
        ) : null}
        {latest?.review_status === 'pending_review' && latest.has_file ? (
          <>
            <BusyButton className="btn btn-primary btn-sm" busy={busy === `approve-${latest.id}`} busyLabel="Approving…" onClick={() => approve(latest)}>
              Approve
              <span className="visually-hidden"> {label}</span>
            </BusyButton>
            <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => setPanel(panel === 'changes' ? '' : 'changes')} aria-expanded={panel === 'changes'}>
              Request changes
            </button>
          </>
        ) : null}
        {connected && latest?.status === 'failed' ? (
          <BusyButton className="btn btn-outline-primary btn-sm" busy={busy === 'retry'} busyLabel="Trying again…" onClick={tryAgain}>
            Try again
          </BusyButton>
        ) : null}
        {connected && !active && panel !== 'generate' ? (
          <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => openGenerate(latest)}>
            {latest ? 'New version' : 'Create'}
            <span className="visually-hidden"> {label}</span>
          </button>
        ) : null}
        {versions.length > 1 || (versions.length === 1 && inVideo) ? (
          <button type="button" className="btn btn-link btn-sm" onClick={() => setShowVersions(!showVersions)} aria-expanded={showVersions}>
            {showVersions ? 'Hide versions' : `Versions (${versions.length})`}
          </button>
        ) : null}
      </span>

      {panel === 'generate' ? (
        <form
          className="studio-inline-panel studio-sound-form"
          onSubmit={(event) => {
            event.preventDefault();
            generate();
          }}
        >
          {source ? <p className="small text-secondary mb-2">A new version is made from {source.version_label}. {source.version_label} stays as it is.</p> : null}
          <label className="form-label" htmlFor={inputId}>
            What should be said?
          </label>
          <textarea id={inputId} className="form-control" rows={2} value={prompt} onChange={(event) => setPrompt(event.target.value)} required />
          {voices.length > 1 ? (
            <div className="mt-2">
              <label className="form-label" htmlFor={`${inputId}-voice`}>
                Voice
              </label>
              <select id={`${inputId}-voice`} className="form-select form-select-sm w-auto" value={voice} onChange={(event) => setVoice(event.target.value)}>
                {voices.map((name) => (
                  <option key={name} value={name}>
                    {name}
                  </option>
                ))}
              </select>
            </div>
          ) : null}
          <div className="d-flex flex-wrap gap-2 mt-2">
            <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy === 'generate'} busyLabel="Generating sound…" disabled={!prompt.trim()}>
              {source ? 'Make new version' : `Create ${role.label.toLowerCase()}`}
            </BusyButton>
            <button type="button" className="btn btn-link btn-sm" onClick={() => setPanel('')}>
              Cancel
            </button>
          </div>
        </form>
      ) : null}

      {panel === 'changes' && latest ? (
        <form
          className="studio-inline-panel studio-sound-form"
          onSubmit={(event) => {
            event.preventDefault();
            requestChanges();
          }}
        >
          <label className="form-label" htmlFor={`${inputId}-changes`}>
            What should change in {latest.version_label}?
          </label>
          <textarea id={`${inputId}-changes`} className="form-control" rows={2} value={comment} onChange={(event) => setComment(event.target.value)} maxLength={2000} required />
          <div className="d-flex flex-wrap gap-2 mt-2">
            <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy === 'changes'} busyLabel="Saving…" disabled={!comment.trim()}>
              Request changes
            </BusyButton>
            <button type="button" className="btn btn-link btn-sm" onClick={() => setPanel('')}>
              Cancel
            </button>
          </div>
        </form>
      ) : null}

      {showVersions ? (
        <ul className="studio-sound-versions list-unstyled studio-inline-panel" aria-label={`${label} versions`}>
          {versions.map((item) => {
            const itemState = soundState(item);
            return (
              <li key={item.id} className="studio-sound-version">
                <span className="fw-semibold">{item.version_label}</span>
                <StatusBadge tone={itemState.tone}>{itemState.label}</StatusBadge>
                {item.review_comment ? <span className="small text-secondary">“{item.review_comment}”</span> : null}
                <span className="studio-sound-actions">
                  {item.has_file && item.id !== latest?.id ? (
                    <MediaPreview kind="audio" load={() => getStorySceneAudioFileUrl(projectId, reelId, scene.id, item.id)} label={`${label}, ${item.version_label}`} />
                  ) : null}
                  {item.review_status === 'approved' && !item.selected && item.has_file ? (
                    <BusyButton className="btn btn-outline-primary btn-sm" busy={busy === `select-${item.id}`} busyLabel="Choosing…" onClick={() => chooseForVideo(item)}>
                      Use in video
                      <span className="visually-hidden"> {item.version_label}</span>
                    </BusyButton>
                  ) : null}
                </span>
              </li>
            );
          })}
        </ul>
      ) : null}
    </li>
  );
}
