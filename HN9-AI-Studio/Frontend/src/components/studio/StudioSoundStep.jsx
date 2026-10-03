import { useCallback, useEffect, useState } from 'react';
import {
  createStorySceneAudio,
  getStorySceneAudio,
  getStorySceneAudioFileUrl,
  listStoryReelAudio,
  markStoryProjectChanged,
} from '../../services/storyService';
import {
  failureReason,
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
import { BusyButton, jobTone, MediaPreview, NotConnectedNote, reviewTone, StatusBadge, StepFooter, StepHeader, StepSkeleton } from './StudioUi';

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
      <StepHeader title="Sound" purpose="Give each scene narration, dialogue, music or sound effects. Sound is optional; skip any scene that doesn’t need it." />

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
              latest={items.find((item) => item.role === role.value) || null}
              connected={roles.includes(role.value)}
              onChanged={onChanged}
            />
          ))}
        </ul>
      </div>
    </li>
  );
}

function SoundRow({ scene, role, latest, connected, onChanged }) {
  const { projectId, reelId, bible, refresh } = useStudio();
  const feedback = useFeedback();
  const [open, setOpen] = useState(false);
  const [prompt, setPrompt] = useState('');
  const [busy, setBusy] = useState('');
  const active = latest && isJobActive(latest.status);
  const label = `${role.label} for ${sceneName(scene)}`;

  async function create(text) {
    setBusy('create');
    try {
      await createStorySceneAudio(projectId, reelId, scene.id, { role: role.value, prompt: text });
      await Promise.all([onChanged(), refresh(['status'])]);
      feedback.success(`${role.label} requested for ${sceneName(scene)}.`);
      setOpen(false);
    } catch (err) {
      feedback.error(friendlyError(err, 'The sound could not be requested. Please try again.', 'sound'));
    } finally {
      setBusy('');
    }
  }

  async function check() {
    setBusy('check');
    try {
      await getStorySceneAudio(projectId, reelId, scene.id, latest.id);
      markStoryProjectChanged(projectId);
      await Promise.all([onChanged(), refresh(['status'])]);
      feedback.info(`${label}: progress updated.`);
    } catch (err) {
      feedback.error(friendlyError(err, 'Progress could not be checked. Please try again.', 'sound'));
    } finally {
      setBusy('');
    }
  }

  let statusText = 'None';
  if (latest) statusText = latest.status === 'failed' ? `Failed: ${failureReason(latest.error_code)}` : jobStatusLabel(latest.status);

  return (
    <li className="studio-sound-row">
      <span className="studio-sound-role">
        <i className={`bi ${role.icon}`} aria-hidden="true" />
        {role.label}
      </span>
      <span className="studio-sound-status">
        <StatusBadge tone={latest ? jobTone(latest.status) : 'neutral'}>{statusText}</StatusBadge>
      </span>
      <span className="studio-sound-actions">
        {latest?.has_file ? (
          <MediaPreview kind="audio" load={() => getStorySceneAudioFileUrl(projectId, reelId, scene.id, latest.id)} label={label} />
        ) : null}
        {active ? (
          <BusyButton className="btn btn-outline-primary btn-sm" busy={busy === 'check'} busyLabel="Checking…" onClick={check}>
            Check progress
          </BusyButton>
        ) : null}
        {connected && latest?.status === 'failed' && latest.prompt ? (
          <BusyButton className="btn btn-outline-primary btn-sm" busy={busy === 'create'} busyLabel="Retrying…" onClick={() => create(latest.prompt)}>
            Try again
          </BusyButton>
        ) : null}
        {connected && !active && !open ? (
          <button
            type="button"
            className="btn btn-outline-secondary btn-sm"
            onClick={() => {
              setPrompt(latest?.prompt || defaultPrompt(role.value, scene, bible));
              setOpen(true);
            }}
          >
            {latest?.has_file ? 'Replace' : 'Create'}
            <span className="visually-hidden"> {label}</span>
          </button>
        ) : null}
      </span>
      {open ? (
        <form
          className="studio-inline-panel studio-sound-form"
          onSubmit={(event) => {
            event.preventDefault();
            create(prompt.trim());
          }}
        >
          <label className="form-label" htmlFor={`sound-${scene.id}-${role.value}`}>
            {role.value === 'narration' || role.value === 'dialogue' ? 'What should be said?' : 'Describe the sound'}
          </label>
          <textarea
            id={`sound-${scene.id}-${role.value}`}
            className="form-control"
            rows={2}
            value={prompt}
            onChange={(event) => setPrompt(event.target.value)}
            required
          />
          <div className="d-flex gap-2 mt-2">
            <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy === 'create'} busyLabel="Requesting…" disabled={!prompt.trim()}>
              {latest?.has_file ? `Replace ${role.label.toLowerCase()}` : `Create ${role.label.toLowerCase()}`}
            </BusyButton>
            <button type="button" className="btn btn-link btn-sm" onClick={() => setOpen(false)}>
              Cancel
            </button>
          </div>
        </form>
      ) : null}
    </li>
  );
}
