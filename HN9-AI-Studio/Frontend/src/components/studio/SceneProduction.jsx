import { Component, useEffect, useMemo, useRef, useState } from 'react';
import {
  approveProductionUnitVersion,
  assembleProductionScene,
  generateProductionUnit,
  getProductionSceneAssemblyFileUrl,
  getProductionUnitGeneration,
  getProductionUnitVersionFileUrl,
  getSceneProduction,
  getStoryHistory,
  getStoryStyle,
  requestProductionUnitVersionChanges,
  selectProductionUnitVersion,
} from '../../services/storyService';
import { formatDateTime, formatSeconds, friendlyError, isJobActive } from '../../services/studioMessages';
import {
  availableModes,
  BUILD_FAILED,
  clipState,
  clipWindow,
  GENERATE_FAILED,
  nextClipIntent,
  NOT_CONFIGURED,
  PARTS_NOT_READY,
  plainFailure,
  sceneHistory,
  sceneSummary,
  soundSummary,
  UNSUPPORTED,
  versionActions,
} from '../../services/productionClips';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton, MediaPreview, StatusBadge } from './StudioUi';

const POLL_MS = 3000;

class ClipBoundary extends Component {
  constructor(props) {
    super(props);
    this.state = { failed: false };
  }

  static getDerivedStateFromError() {
    return { failed: true };
  }

  render() {
    if (this.state.failed) {
      return (
        <p className="studio-note studio-note--warning small mb-0" role="alert">
          This clip could not be shown. The rest of the scene is still here.
        </p>
      );
    }
    return this.props.children;
  }
}

function productionError(error, fallback) {
  const code = error?.errorCode || '';
  if (code === 'GENERATION_NOT_ENABLED' || code === 'ai_provider_not_configured' || code === 'story_generation_not_available') {
    return NOT_CONFIGURED;
  }
  if (code === 'VIDEO_CAPABILITY_NOT_AVAILABLE') return UNSUPPORTED;
  if (code === 'SCENE_NOT_READY') return PARTS_NOT_READY;
  if (code === 'SCENE_ASSEMBLY_FAILED') return plainFailure(error?.message, BUILD_FAILED);
  return friendlyError(error, fallback, 'video');
}

export default function SceneProduction({ scene, status, planId, planReady = true, onClose, onChanged }) {
  const { projectId, canApprove, connections, characters, goTo } = useStudio();
  const feedback = useFeedback();
  const [workspace, setWorkspace] = useState(null);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [busy, setBusy] = useState('');
  const [style, setStyle] = useState(null);
  const [history, setHistory] = useState([]);
  const watchRef = useRef({ jobs: [], assembly: false, planId: null });
  const changedRef = useRef(onChanged);
  changedRef.current = onChanged;

  const pictures = useMemo(() => {
    const rows = (characters || []).filter((item) => item.approved_reference).map((item) => item.approved_reference.id);
    if (style?.approved_reference?.id) rows.push(style.approved_reference.id);
    return rows;
  }, [characters, style]);
  const modes = availableModes(connections, pictures.length);

  async function load() {
    const next = await getSceneProduction(projectId, planId, scene.id);
    setWorkspace(next);
    return next;
  }

  useEffect(() => {
    let cancelled = false;
    if (!planId) return undefined;
    load()
      .catch((err) => {
        if (!cancelled) setError(productionError(err, 'This scene could not be loaded.'));
      });
    getStoryStyle(projectId)
      .then((value) => {
        if (!cancelled) setStyle(value);
      })
      .catch(() => {});
    getStoryHistory(projectId)
      .then((items) => {
        if (!cancelled) setHistory(items);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
    // The scene workspace loads once, then after each action.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId, planId, scene.id]);

  const summary = sceneSummary(workspace?.clips, workspace?.assemblies);
  const activeJobs = (workspace?.clips || [])
    .filter((clip) => clip.active_generation && isJobActive(clip.active_generation.status))
    .map((clip) => ({ clipId: clip.id, jobId: clip.active_generation.id }));
  const assemblyRunning = summary.sceneVideo === 'Building';
  const watchKey = [...activeJobs.map((job) => job.jobId), assemblyRunning ? 'assembly' : ''].filter(Boolean).join('|');
  watchRef.current = { jobs: activeJobs, assembly: assemblyRunning, planId: workspace?.plan_id || planId };

  useEffect(() => {
    if (!watchKey) return undefined;
    let stopped = false;
    const timer = setInterval(() => {
      const current = watchRef.current;
      const reads = current.jobs.map((job) =>
        getProductionUnitGeneration(projectId, current.planId, job.clipId, job.jobId)
          .then((payload) => payload?.generation?.status || '')
          .catch(() => ''),
      );
      Promise.all(reads).then(async (statuses) => {
        if (stopped) return;
        const finished = statuses.some((status) => status && !isJobActive(status));
        if (!finished && !current.assembly) return;
        try {
          const next = await getSceneProduction(projectId, current.planId, scene.id);
          if (stopped) return;
          setWorkspace(next);
          if (finished) {
            const failed = statuses.some((status) => status === 'failed' || status === 'cancelled');
            setNotice(failed ? GENERATE_FAILED : 'Your clip is ready for review.');
            changedRef.current?.();
          } else {
            const nextSummary = sceneSummary(next?.clips, next?.assemblies);
            if (nextSummary.sceneVideo !== 'Building') {
              setNotice(nextSummary.sceneVideo === 'Ready' ? 'Scene ready.' : plainFailure(nextSummary.failed?.error_message, BUILD_FAILED));
              changedRef.current?.();
            }
          }
        } catch (err) {
          if (!stopped) setNotice(productionError(err, 'Progress could not be checked. Please try again.'));
        }
      });
    }, POLL_MS);
    return () => {
      stopped = true;
      clearInterval(timer);
    };
  }, [watchKey, projectId, scene.id]);

  async function refreshHistory() {
    try {
      setHistory(await getStoryHistory(projectId));
    } catch {
      /* History is optional beside the clips. */
    }
  }

  async function run(key, action, success) {
    setBusy(key);
    setError('');
    try {
      const result = await action();
      await load();
      await refreshHistory();
      const message = typeof success === 'function' ? success(result) : success;
      const failed = result?.assembly?.status === 'failed' || result?.generation?.status === 'failed';
      setNotice(message);
      if (failed) {
        setError(message);
        feedback.error(message);
      } else {
        feedback.success(message);
      }
      changedRef.current?.();
      return !failed;
    } catch (err) {
      const message = productionError(err, 'That could not be saved. Please try again.');
      setNotice(message);
      setError(message);
      feedback.error(message);
      return false;
    } finally {
      setBusy('');
    }
  }

  const duration = workspace?.duration_seconds ?? scene.duration_seconds;
  const recent = sceneHistory(history, scene.id).map((row) => row.text);
  const sound = soundSummary(status?.sounds);

  return (
    <section className="studio-inline-panel studio-production" aria-labelledby={`production-${scene.id}`}>
      <div className="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <div>
          <h4 className="h6 mb-1" id={`production-${scene.id}`}>
            Video production
          </h4>
          <p className="mb-0">
            Scene {scene.sequence}
            {scene.title ? ` · ${scene.title}` : ''} · {formatSeconds(duration)}
          </p>
        </div>
        <button type="button" className="btn btn-link btn-sm" onClick={onClose}>
          Close
        </button>
      </div>

      <SceneContext scene={scene} styleName={style?.visual_style} pictureCount={pictures.length} />

      <div className="studio-production-summary" aria-live="polite">
        <span>{formatSeconds(duration)}</span>
        <span>
          {summary.total} {summary.total === 1 ? 'clip' : 'clips'}
        </span>
        <span>{summary.approved} approved</span>
        <span>{summary.selected} selected</span>
        <strong>Scene video: {summary.sceneVideo}</strong>
      </div>
      {summary.generating > 0 ? <p className="small mb-0">{summary.generating === 1 ? '1 clip is generating.' : `${summary.generating} clips are generating.`}</p> : null}
      {workspace && !summary.ready && summary.total > 0 ? (
        <p className="small mb-0">
          {summary.selected} of {summary.total} clips ready. {PARTS_NOT_READY}
        </p>
      ) : null}

      {notice ? (
        <p className="studio-note small mb-0" role="status">
          {notice}
        </p>
      ) : null}
      {error ? (
        <p className="text-danger small mb-0" role="alert">
          {error}
        </p>
      ) : null}

      {!planReady ? <p className="small text-secondary mb-0">Loading clips…</p> : null}
      {planReady && !planId ? <p className="small mb-0">This scene is not in a production plan yet. Approve the story, and the clips will appear here.</p> : null}
      {planId && workspace === null && !error ? <p className="small text-secondary mb-0">Loading clips…</p> : null}

      {workspace?.current?.output_available ? (
        <div>
          <h5 className="h6">Scene video {workspace.current.label || ''}</h5>
          <p className="small text-secondary mb-2">
            {formatSeconds(workspace.current.duration_seconds)}
            {workspace.current.label ? ` · ${workspace.current.label}` : ''} · Ready
          </p>
          <MediaPreview
            load={() => getProductionSceneAssemblyFileUrl(projectId, workspace.plan_id, scene.id, workspace.current.id)}
            label={`Scene ${scene.sequence} video`}
          />
        </div>
      ) : null}

      <div className="studio-production-actions">
        {canApprove ? (
          <BusyButton
            className="btn btn-primary btn-sm"
            busy={busy === 'build'}
            busyLabel="Building…"
            disabled={!summary.ready || assemblyRunning}
            onClick={() => {
              if (!summary.ready) return;
              run('build', () => assembleProductionScene(projectId, workspace.plan_id, scene.id), (result) => {
                const status = result?.assembly?.status;
                if (status === 'failed') return plainFailure(result.assembly?.error_message, BUILD_FAILED);
                if (status === 'completed') return 'Scene ready.';
                return `Building scene... Combining ${summary.total} video parts...`;
              });
            }}
          >
            {summary.current ? 'Rebuild scene' : 'Build scene'}
          </BusyButton>
        ) : null}
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => goTo('sound', { scene: scene.id })}>
          Open sound
        </button>
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => goTo('final')}>
          Open Final Video
        </button>
      </div>
      {workspace ? (
        <p className="small text-secondary mb-0">
          {summary.ready ? `${formatSeconds(duration)} · ${summary.total} video parts · Ready to build` : summary.total > 0 ? PARTS_NOT_READY : ''}
          {assemblyRunning ? ` Building scene... Combining ${summary.total} video parts...` : ''}
        </p>
      ) : null}
      {summary.sceneVideo === 'Could not be built' ? (
        <p className="small text-danger mb-0" role="alert">
          {plainFailure(summary.failed?.error_message, BUILD_FAILED)}
        </p>
      ) : null}
      <p className="small mb-0">
        Sound: {sound}.{' '}
        {summary.sceneVideo === 'Ready' ? 'This scene can be placed on the Timeline in Final Video.' : 'The Timeline uses the finished scene video.'}
      </p>

      {modes.length === 0 ? (
        <p className="studio-note studio-note--warning small mb-0">
          <i className="bi bi-plug" aria-hidden="true" />
          <span>{NOT_CONFIGURED}</span>
        </p>
      ) : null}

      <div className="d-grid gap-3">
        {(workspace?.clips || []).map((clip) => (
          <ClipBoundary key={clip.id}>
            <ProductionClip
              clip={clip}
              planId={workspace.plan_id}
              modes={modes}
              pictures={pictures}
              canApprove={canApprove}
              busy={busy}
              onRun={run}
            />
          </ClipBoundary>
        ))}
      </div>

      {workspace?.assemblies?.length > 1 || (workspace?.assemblies?.length === 1 && !workspace.current) ? (
        <div>
          <h5 className="h6">Scene videos</h5>
          <ul className="list-unstyled d-grid gap-2 mb-0">
            {workspace.assemblies.map((item) => (
              <li key={item.id} className="studio-production-clip">
                <span className="fw-semibold">{item.label || 'Scene video'}</span>
                <StatusBadge tone={item.status === 'completed' ? 'success' : item.status === 'failed' ? 'danger' : 'progress'}>
                  {item.status === 'completed' ? 'Ready' : item.status === 'failed' ? 'Could not be built' : 'Building'}
                </StatusBadge>
                {workspace.current?.id === item.id ? <span className="small"> Current</span> : null}
                {item.output_available ? (
                  <div className="mt-2">
                    <MediaPreview
                      load={() => getProductionSceneAssemblyFileUrl(projectId, workspace.plan_id, scene.id, item.id)}
                      label={`${item.label || 'Scene video'} for scene ${scene.sequence}`}
                    />
                  </div>
                ) : null}
              </li>
            ))}
          </ul>
        </div>
      ) : null}

      <div>
        <h5 className="h6">Recent activity</h5>
        {recent.length === 0 ? <p className="small text-secondary mb-2">Nothing for this scene yet.</p> : null}
        {recent.length > 0 ? (
          <ul className="small mb-2">
            {sceneHistory(history, scene.id).map((row) => (
              <li key={`${row.at}-${row.text}`}>
                {row.text}
                {row.at ? <span className="text-secondary"> · {formatDateTime(row.at)}</span> : null}
              </li>
            ))}
          </ul>
        ) : null}
        <button type="button" className="btn btn-link btn-sm p-0" onClick={() => goTo('history')}>
          Open history
        </button>
      </div>
    </section>
  );
}

function SceneContext({ scene, styleName, pictureCount }) {
  const characters = Array.isArray(scene.characters) ? scene.characters.filter(Boolean) : [];
  return (
    <div className="small">
      {scene.story ? <p className="mb-1">{scene.story}</p> : null}
      <p className="mb-1 text-secondary">
        {scene.location ? `Location: ${scene.location}. ` : ''}
        {characters.length ? `Characters: ${characters.join(', ')}. ` : ''}
        {styleName ? `Visual style: ${styleName}. ` : ''}
        {pictureCount ? `References: ${pictureCount} approved.` : ''}
      </p>
      {scene.visual_prompt ? (
        <details>
          <summary>Visual direction</summary>
          <p className="mb-0 mt-1">{scene.visual_prompt}</p>
        </details>
      ) : null}
    </div>
  );
}

function ProductionClip({ clip, planId, modes, pictures, canApprove, busy, onRun }) {
  const { projectId } = useStudio();
  const [mode, setMode] = useState(modes[0]?.value || '');
  const [instruction, setInstruction] = useState('');
  const [asking, setAsking] = useState(null);
  const [note, setNote] = useState('');
  const state = clipState(clip);
  const versions = Array.isArray(clip.versions) ? clip.versions : [];
  const number = String(clip.sequence).padStart(2, '0');
  const windowLabel = clipWindow(clip);

  function generate() {
    const capability = modes.some((item) => item.value === mode) ? mode : modes[0]?.value;
    if (!capability) return;
    const payload = { capability, intent: nextClipIntent(versions.length) };
    const extra = instruction.trim();
    if (extra) payload.instruction = extra.slice(0, 500);
    if (capability === 'image_to_video' && pictures[0]) payload.inputs = [{ type: 'image', asset_id: pictures[0] }];
    if (capability === 'reference_to_video' && pictures.length) {
      payload.inputs = pictures.slice(0, 8).map((id) => ({ type: 'reference_image', asset_id: id }));
    }
    onRun(`generate-${clip.id}`, () => generateProductionUnit(projectId, planId, clip.id, payload), `Clip ${number} is being created.`);
  }

  return (
    <article className="studio-production-clip" aria-labelledby={`clip-${clip.id}`}>
      <h5 className="h6 mb-1" id={`clip-${clip.id}`}>
        Clip {number}
        <span className="fw-normal text-secondary"> · {windowLabel}</span>
      </h5>
      <p className="small mb-2">
        <StatusBadge tone={state.key === 'failed' ? 'danger' : state.key === 'selected' ? 'success' : state.key === 'generating' ? 'progress' : 'neutral'}>
          {state.label}
        </StatusBadge>
        {state.detail ? <span className="ms-2">{state.detail}</span> : null}
      </p>

      {versions.length === 0 && state.key !== 'generating' && modes.length > 0 && canApprove ? (
        <GenerateForm
          clip={clip}
          modes={modes}
          mode={mode}
          setMode={setMode}
          instruction={instruction}
          setInstruction={setInstruction}
          busy={busy === `generate-${clip.id}`}
          onSubmit={generate}
        />
      ) : null}
      {versions.length > 0 && state.key !== 'generating' && modes.length > 0 && canApprove ? (
        <details className="mb-2">
          <summary className="small">Generate another clip version</summary>
          <GenerateForm
            clip={clip}
            modes={modes}
            mode={mode}
            setMode={setMode}
            instruction={instruction}
            setInstruction={setInstruction}
            busy={busy === `generate-${clip.id}`}
            onSubmit={generate}
          />
        </details>
      ) : null}

      <div className="d-grid gap-2">
        {versions.map((version) => {
          const actions = versionActions(version);
          return (
            <div key={version.id} className={`border rounded p-2 ${version.selected ? 'border-success' : ''}`}>
              <div className="d-flex flex-wrap align-items-center gap-2">
                <span className="fw-semibold">{version.label}</span>
                <StatusBadge tone={version.status === 'approved' ? 'success' : version.status === 'needs_rework' ? 'warning' : 'progress'}>
                  {version.status_label || version.status}
                </StatusBadge>
                {version.selected ? <StatusBadge tone="success">Selected for this clip</StatusBadge> : null}
              </div>
              {actions.preview ? (
                <div className="mt-2">
                  <MediaPreview
                    load={() => getProductionUnitVersionFileUrl(projectId, planId, clip.id, version.id)}
                    label={`Clip ${number} ${version.label}`}
                  />
                </div>
              ) : null}
              {version.comment ? <p className="small mt-2 mb-0">“{version.comment}”</p> : null}
              {canApprove ? (
                <div className="studio-production-actions mt-2">
                  {actions.approve ? (
                    <BusyButton
                      className="btn btn-success btn-sm"
                      busy={busy === `approve-${version.id}`}
                      busyLabel="Approving…"
                      disabled={Boolean(busy)}
                      onClick={() =>
                        onRun(
                          `approve-${version.id}`,
                          () => approveProductionUnitVersion(projectId, planId, clip.id, version.id),
                          `${version.label} approved.`,
                        )
                      }
                    >
                      Approve
                    </BusyButton>
                  ) : null}
                  {actions.requestChanges ? (
                    <button type="button" className="btn btn-outline-secondary btn-sm" disabled={Boolean(busy)} onClick={() => setAsking(version.id)}>
                      Request changes
                    </button>
                  ) : null}
                  {actions.select ? (
                    <BusyButton
                      className="btn btn-primary btn-sm"
                      busy={busy === `select-${version.id}`}
                      busyLabel="Selecting…"
                      disabled={Boolean(busy)}
                      onClick={() =>
                        onRun(
                          `select-${version.id}`,
                          () => selectProductionUnitVersion(projectId, planId, clip.id, version.id),
                          `${version.label} selected for this clip.`,
                        )
                      }
                    >
                      Select
                    </BusyButton>
                  ) : null}
                </div>
              ) : null}
              {asking === version.id ? (
                <form
                  className="mt-2"
                  onSubmit={(event) => {
                    event.preventDefault();
                    const comment = note.trim();
                    if (!comment) return;
                    onRun(
                      `changes-${version.id}`,
                      () => requestProductionUnitVersionChanges(projectId, planId, clip.id, version.id, comment),
                      `Changes requested for ${version.label}.`,
                    ).then((ok) => {
                      if (!ok) return;
                      setAsking(null);
                      setNote('');
                    });
                  }}
                >
                  <label className="form-label" htmlFor={`change-${version.id}`}>
                    What should change?
                  </label>
                  <textarea id={`change-${version.id}`} className="form-control" rows={2} value={note} onChange={(event) => setNote(event.target.value)} required />
                  <div className="studio-production-actions mt-2">
                    <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy === `changes-${version.id}`} busyLabel="Sending…" disabled={!note.trim()}>
                      Request changes
                    </BusyButton>
                    <button type="button" className="btn btn-link btn-sm" onClick={() => setAsking(null)}>
                      Cancel
                    </button>
                  </div>
                </form>
              ) : null}
            </div>
          );
        })}
      </div>
    </article>
  );
}

function GenerateForm({ clip, modes, mode, setMode, instruction, setInstruction, busy, onSubmit }) {
  const selected = modes.find((item) => item.value === mode) || modes[0];
  return (
    <form
      className="mb-2"
      onSubmit={(event) => {
        event.preventDefault();
        onSubmit();
      }}
    >
      {modes.length > 1 ? (
        <fieldset className="mb-2">
          <legend className="form-label mb-1">How should this clip be made?</legend>
          <div className="studio-choice-grid">
            {modes.map((option) => (
              <label key={option.value} className={`studio-choice${selected?.value === option.value ? ' is-selected' : ''}`}>
                <input
                  type="radio"
                  className="form-check-input"
                  name={`clip-mode-${clip.id}`}
                  value={option.value}
                  checked={selected?.value === option.value}
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
      ) : null}
      <label className="form-label" htmlFor={`instruction-${clip.id}`}>
        Anything to add? <span className="fw-normal text-secondary">(optional)</span>
      </label>
      <textarea
        id={`instruction-${clip.id}`}
        className="form-control"
        rows={2}
        maxLength={500}
        value={instruction}
        onChange={(event) => setInstruction(event.target.value)}
      />
      <div className="studio-production-actions mt-2">
        <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy} busyLabel="Starting…">
          Generate clip
        </BusyButton>
      </div>
    </form>
  );
}
