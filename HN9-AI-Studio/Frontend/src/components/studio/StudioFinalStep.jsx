import { useCallback, useEffect, useMemo, useState } from 'react';
import {
  approveStoryRenderReview,
  createStoryExport,
  deleteStoryTimelineClip,
  downloadStoryExport,
  duplicateStoryTimelineClip,
  getStoryRenderFileUrl,
  getStoryTimeline,
  listStoryRenders,
  placeStoryTimelineClip,
  reorderStoryTimeline,
  replaceStoryTimelineClip,
  reworkStoryRender,
  setStoryTimelineTransition,
  splitStoryTimelineClip,
  startStoryRender,
  submitStoryRenderReview,
  trimStoryTimelineClip,
} from '../../services/storyService';
import {
  failureReason,
  formatBytes,
  formatDateTime,
  formatTimecode,
  friendlyError,
  jobStatusLabel,
  msToSeconds,
  reviewStatusLabel,
  sceneName,
  TRANSITIONS,
} from '../../services/studioMessages';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton, jobTone, MediaPreview, reviewTone, StatusBadge, StepFooter, StepHeader, StepSkeleton } from './StudioUi';

function clipName(clip) {
  if (!clip.scene_sequence) return clip.media_kind === 'audio' ? 'Sound clip' : 'Video clip';
  const base = sceneName({ sequence: clip.scene_sequence, title: clip.scene_title });
  return clip.media_kind === 'audio' ? `Sound · ${base}` : base;
}

export default function StudioFinalStep({ nav }) {
  const { projectId, reelId, reels, sceneStatus, selectReel, goTo, setFinalState, project } = useStudio();
  const feedback = useFeedback();
  const [timeline, setTimeline] = useState(null);
  const [renders, setRenders] = useState(null);
  const [busy, setBusy] = useState('');

  const load = useCallback(async () => {
    if (!reelId) {
      setTimeline({ clips: [], transitions: [] });
      setRenders([]);
      return;
    }
    const [nextTimeline, nextRenders] = await Promise.all([
      getStoryTimeline(projectId, reelId).catch(() => null),
      listStoryRenders(projectId, reelId).catch(() => []),
    ]);
    setTimeline(nextTimeline || { clips: [], transitions: [] });
    setRenders(nextRenders);
  }, [projectId, reelId]);

  useEffect(() => {
    load();
  }, [load]);

  const latest = renders?.[0] || null;

  useEffect(() => {
    if (!latest) {
      setFinalState(null);
      return;
    }
    const review = latest.review_status;
    if (review === 'approved') setFinalState({ done: true, text: 'Approved' });
    else if (review === 'pending_review') setFinalState({ done: false, text: 'In review' });
    else if (latest.has_file) setFinalState({ done: false, text: 'Built, needs review' });
    else setFinalState({ done: false, text: 'Build failed' });
  }, [latest, setFinalState]);

  async function run(key, action, success, after = null) {
    setBusy(key);
    try {
      const result = await action();
      await load();
      const message = typeof success === 'function' ? success(result) : success;
      if (message) feedback.success(message);
      after?.(result);
      return result;
    } catch (err) {
      feedback.error(friendlyError(err, 'That did not work. Please try again.'));
      return null;
    } finally {
      setBusy('');
    }
  }

  const clips = useMemo(() => [...(timeline?.clips || [])].sort((a, b) => a.position - b.position), [timeline]);
  const approvedVersions = sceneStatus.filter((item) => item.version?.status === 'approved' && item.video?.has_file);
  const onTimeline = new Set(clips.filter((clip) => clip.media_kind === 'video').map((clip) => clip.scene_id));
  const missing = approvedVersions.filter((item) => !onTimeline.has(item.scene_id));
  const totalMs = clips.reduce((sum, clip) => sum + Math.max(0, clip.out_ms - clip.in_ms), 0);

  async function addMissing() {
    await run(
      'add-missing',
      async () => {
        for (const item of missing) {
          await placeStoryTimelineClip(projectId, reelId, 'video', item.version.id);
        }
      },
      `${missing.length === 1 ? 'One approved scene was' : `${missing.length} approved scenes were`} added to the timeline.`,
    );
  }

  async function build() {
    await run('build', () => startStoryRender(projectId, reelId), (render) =>
      render?.status === 'completed'
        ? 'Your final video is built. Preview it below, then send it for review.'
        : `The build did not finish: ${failureReason(render?.error_code)}`,
    );
  }

  return (
    <div className="studio-step">
      <StepHeader
        title="Final Video"
        purpose="Approved scene videos appear here automatically. Arrange them, build the final video, review it, then download it."
      />

      {reels.length > 1 ? (
        <div className="studio-reel-bar">
          <label className="form-label mb-0 me-2" htmlFor="final-reel">
            Video
          </label>
          <select id="final-reel" className="form-select form-select-sm w-auto" value={reelId || ''} onChange={(event) => selectReel(event.target.value)}>
            {reels.map((item) => (
              <option key={item.id} value={item.id}>
                {item.title || project.name}
              </option>
            ))}
          </select>
        </div>
      ) : null}

      <ol className="studio-flow" aria-label="Final video steps">
        <li className={clips.length ? 'is-done' : ''}>Arrange clips</li>
        <li className={latest?.has_file ? 'is-done' : ''}>Build</li>
        <li className={latest?.review_status === 'approved' ? 'is-done' : ''}>Review</li>
        <li className={latest?.latest_export?.status === 'completed' ? 'is-done' : ''}>Download</li>
      </ol>

      {timeline === null ? (
        <StepSkeleton rows={2} />
      ) : (
        <>
          <section className="card border-0 glass-card mb-4" aria-labelledby="timeline-heading">
            <div className="card-body">
              <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <h3 className="h5 mb-0" id="timeline-heading">
                  Timeline
                </h3>
                {clips.length ? (
                  <span className="small text-secondary">
                    {clips.length} {clips.length === 1 ? 'clip' : 'clips'} · total {formatTimecode(totalMs)}
                  </span>
                ) : null}
              </div>

              {missing.length > 0 ? (
                <div className="studio-note mb-3">
                  <i className="bi bi-info-circle" aria-hidden="true" />
                  <span className="flex-grow-1">
                    {missing.length === 1 ? 'One approved scene is' : `${missing.length} approved scenes are`} not on the timeline yet.
                  </span>
                  <BusyButton className="btn btn-outline-primary btn-sm" busy={busy === 'add-missing'} busyLabel="Adding…" onClick={addMissing}>
                    Add to timeline
                  </BusyButton>
                </div>
              ) : null}

              {clips.length === 0 ? (
                <div className="studio-empty">
                  <i className="bi bi-collection-play" aria-hidden="true" />
                  <p className="fw-semibold mb-1">No clips yet</p>
                  <p className="small text-secondary mb-3">
                    When you approve a scene that has a finished video, it appears here automatically in scene order.
                  </p>
                  <button type="button" className="btn btn-outline-primary btn-sm" onClick={() => goTo('scenes')}>
                    Go to Scenes
                  </button>
                </div>
              ) : (
                <ol className="studio-timeline list-unstyled mb-0">
                  {clips.map((clip, index) => (
                    <TimelineClip
                      key={clip.id}
                      clip={clip}
                      index={index}
                      clips={clips}
                      nextClip={clips[index + 1] || null}
                      transition={(timeline.transitions || []).find((item) => item.from_clip_id === clip.id) || null}
                      approvedVersions={approvedVersions}
                      busy={busy}
                      run={run}
                    />
                  ))}
                </ol>
              )}
            </div>
          </section>

          <section className="card border-0 glass-card mb-4" aria-labelledby="build-heading">
            <div className="card-body">
              <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h3 className="h5 mb-0" id="build-heading">
                  Final video
                </h3>
                <BusyButton busy={busy === 'build'} busyLabel="Building…" disabled={clips.length === 0 || Boolean(busy)} onClick={build}>
                  <i className="bi bi-hammer me-1" aria-hidden="true" />
                  {latest ? 'Build again' : 'Build final video'}
                </BusyButton>
              </div>
              {clips.length === 0 ? <p className="small text-secondary mb-0">Add clips to the timeline before building.</p> : null}
              {renders === null ? <StepSkeleton rows={1} /> : null}
              {latest ? <RenderCard render={latest} clips={clips} busy={busy} run={run} /> : null}
              {renders && renders.length > 1 ? (
                <details className="studio-more mt-3">
                  <summary>Earlier builds ({renders.length - 1})</summary>
                  <ul className="list-unstyled mt-2 mb-0 d-grid gap-2">
                    {renders.slice(1).map((render) => (
                      <li key={render.id} className="d-flex flex-wrap gap-2 align-items-center small">
                        <span>{formatDateTime(render.created_at)}</span>
                        <StatusBadge tone={jobTone(render.status)}>{jobStatusLabel(render.status)}</StatusBadge>
                        <StatusBadge tone={reviewTone(render.review_status)}>{reviewStatusLabel(render.review_status)}</StatusBadge>
                        <span className="text-secondary">{formatTimecode(render.duration_ms)}</span>
                      </li>
                    ))}
                  </ul>
                </details>
              ) : null}
            </div>
          </section>
        </>
      )}

      <StepFooter prev={nav.prev} onNavigate={nav.onNavigate} />
    </div>
  );
}

function TimelineClip({ clip, index, clips, nextClip, transition, approvedVersions, busy, run }) {
  const { projectId, reelId, reel } = useStudio();
  const [panel, setPanel] = useState(null);
  const [start, setStart] = useState(msToSeconds(clip.in_ms));
  const [end, setEnd] = useState(msToSeconds(clip.out_ms));
  const [splitAt, setSplitAt] = useState(msToSeconds((clip.in_ms + clip.out_ms) / 2));
  const [swapTo, setSwapTo] = useState('');
  const name = clipName(clip);
  const swapOptions = approvedVersions.filter((item) => item.version.id !== clip.source_version_id);
  const disabled = Boolean(busy);

  function move(offset) {
    const ids = clips.map((item) => item.id);
    const [moved] = ids.splice(index, 1);
    ids.splice(index + offset, 0, moved);
    run(`move-${clip.id}`, () => reorderStoryTimeline(projectId, reelId, ids), `${name} moved.`);
  }

  return (
    <li className="studio-clip">
      <div className="studio-clip-main">
        <span className="studio-scene-number" aria-hidden="true">
          {index + 1}
        </span>
        <div className="flex-grow-1 min-w-0">
          <span className="fw-semibold d-block">{name}</span>
          <span className="small text-secondary">
            {clip.source_version_number ? `Version ${clip.source_version_number} · ` : ''}
            Plays {formatTimecode(clip.out_ms - clip.in_ms)} (from {formatTimecode(clip.in_ms)} to {formatTimecode(clip.out_ms)})
          </span>
        </div>
        <div className="studio-clip-actions">
          {index > 0 ? (
            <button type="button" className="btn btn-outline-secondary btn-sm" disabled={disabled} onClick={() => move(-1)} aria-label={`Move ${name} earlier`}>
              <i className="bi bi-arrow-up" aria-hidden="true" />
            </button>
          ) : null}
          {index < clips.length - 1 ? (
            <button type="button" className="btn btn-outline-secondary btn-sm" disabled={disabled} onClick={() => move(1)} aria-label={`Move ${name} later`}>
              <i className="bi bi-arrow-down" aria-hidden="true" />
            </button>
          ) : null}
          <details className="studio-menu">
            <summary className="btn btn-outline-secondary btn-sm">Edit</summary>
            <div className="studio-menu-items" onClick={(event) => event.currentTarget.closest('details')?.removeAttribute('open')}>
              <button
                type="button"
                className="dropdown-item"
                onClick={() => {
                  setStart(msToSeconds(clip.in_ms));
                  setEnd(msToSeconds(clip.out_ms));
                  setPanel('trim');
                }}
              >
                Adjust start and end
              </button>
              <button
                type="button"
                className="dropdown-item"
                onClick={() => {
                  setSplitAt(msToSeconds((clip.in_ms + clip.out_ms) / 2));
                  setPanel('split');
                }}
              >
                Split in two
              </button>
              {clip.media_kind === 'video' && swapOptions.length ? (
                <button type="button" className="dropdown-item" onClick={() => setPanel('swap')}>
                  Swap clip
                </button>
              ) : null}
              <button
                type="button"
                className="dropdown-item"
                disabled={disabled}
                onClick={() => run(`dup-${clip.id}`, () => duplicateStoryTimelineClip(projectId, reelId, clip.id), `${name} duplicated.`)}
              >
                Duplicate
              </button>
              <button
                type="button"
                className="dropdown-item text-danger"
                disabled={disabled}
                onClick={() => run(`del-${clip.id}`, () => deleteStoryTimelineClip(projectId, reelId, clip.id), `${name} removed from the timeline.`)}
              >
                Remove from timeline
              </button>
            </div>
          </details>
        </div>
      </div>

      {panel === 'trim' ? (
        <form
          className="studio-inline-panel"
          onSubmit={(event) => {
            event.preventDefault();
            run(
              `trim-${clip.id}`,
              () => trimStoryTimelineClip(projectId, reelId, clip.id, Math.round(Number(start) * 1000), Math.round(Number(end) * 1000)),
              `${name} now plays from ${start} s to ${end} s.`,
              () => setPanel(null),
            );
          }}
        >
          <div className="row g-2 align-items-end">
            <div className="col-6 col-md-3">
              <label className="form-label small" htmlFor={`trim-start-${clip.id}`}>
                Start (seconds)
              </label>
              <input id={`trim-start-${clip.id}`} type="number" step="0.1" min="0" className="form-control form-control-sm" value={start} onChange={(event) => setStart(event.target.value)} />
            </div>
            <div className="col-6 col-md-3">
              <label className="form-label small" htmlFor={`trim-end-${clip.id}`}>
                End (seconds)
              </label>
              <input id={`trim-end-${clip.id}`} type="number" step="0.1" min="0" className="form-control form-control-sm" value={end} onChange={(event) => setEnd(event.target.value)} />
            </div>
            <div className="col-12 col-md-6 d-flex gap-2">
              <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy === `trim-${clip.id}`} disabled={Number(end) <= Number(start)}>
                Save timing
              </BusyButton>
              <button type="button" className="btn btn-link btn-sm" onClick={() => setPanel(null)}>
                Cancel
              </button>
            </div>
          </div>
        </form>
      ) : null}

      {panel === 'split' ? (
        <form
          className="studio-inline-panel"
          onSubmit={(event) => {
            event.preventDefault();
            run(`split-${clip.id}`, () => splitStoryTimelineClip(projectId, reelId, clip.id, Math.round(Number(splitAt) * 1000)), `${name} was split in two.`, () =>
              setPanel(null),
            );
          }}
        >
          <label className="form-label small" htmlFor={`split-${clip.id}`}>
            Split at (seconds, between {msToSeconds(clip.in_ms)} and {msToSeconds(clip.out_ms)})
          </label>
          <div className="d-flex gap-2">
            <input id={`split-${clip.id}`} type="number" step="0.1" className="form-control form-control-sm w-auto" value={splitAt} onChange={(event) => setSplitAt(event.target.value)} />
            <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy === `split-${clip.id}`}>
              Split
            </BusyButton>
            <button type="button" className="btn btn-link btn-sm" onClick={() => setPanel(null)}>
              Cancel
            </button>
          </div>
        </form>
      ) : null}

      {panel === 'swap' ? (
        <form
          className="studio-inline-panel"
          onSubmit={(event) => {
            event.preventDefault();
            run(`swap-${clip.id}`, () => replaceStoryTimelineClip(projectId, reelId, clip.id, swapTo), 'Clip swapped.', () => setPanel(null));
          }}
        >
          <label className="form-label small" htmlFor={`swap-${clip.id}`}>
            Use this approved scene video instead
          </label>
          <div className="d-flex flex-wrap gap-2">
            <select id={`swap-${clip.id}`} className="form-select form-select-sm w-auto" value={swapTo} onChange={(event) => setSwapTo(event.target.value)} required>
              <option value="">Choose a scene…</option>
              {swapOptions.map((item) => (
                <option key={item.version.id} value={item.version.id}>
                  {sceneName((reel?.scenes || []).find((scene) => scene.id === item.scene_id))} · Version {item.version.version}
                </option>
              ))}
            </select>
            <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy === `swap-${clip.id}`} disabled={!swapTo}>
              Swap clip
            </BusyButton>
            <button type="button" className="btn btn-link btn-sm" onClick={() => setPanel(null)}>
              Cancel
            </button>
          </div>
        </form>
      ) : null}

      {nextClip ? (
        <div className="studio-transition">
          <label className="small text-secondary" htmlFor={`transition-${clip.id}`}>
            Then
          </label>
          <select
            id={`transition-${clip.id}`}
            className="form-select form-select-sm w-auto"
            value={transition?.type || 'cut'}
            disabled={disabled}
            onChange={(event) => {
              const type = event.target.value;
              run(
                `tr-${clip.id}`,
                () => setStoryTimelineTransition(projectId, reelId, clip.id, nextClip.id, type, type === 'cut' ? 0 : 500),
                `${TRANSITIONS.find((item) => item.value === type)?.label} set before ${clipName(nextClip)}.`,
              );
            }}
          >
            {TRANSITIONS.map((item) => (
              <option key={item.value} value={item.value}>
                {item.label}
              </option>
            ))}
          </select>
        </div>
      ) : null}
    </li>
  );
}

function RenderCard({ render, clips, busy, run }) {
  const { projectId, reelId, canApprove } = useStudio();
  const [reworking, setReworking] = useState(false);
  const [comment, setComment] = useState('');
  const [target, setTarget] = useState('timeline');
  const exportInfo = render.latest_export;
  const review = render.review_status;
  const sceneTargets = clips.filter((clip) => clip.media_kind === 'video' && clip.source_version_id);

  function requestChanges(event) {
    event.preventDefault();
    const [kind, id] = target === 'timeline' ? ['timeline', render.timeline_id] : ['scene_version', target];
    run('rework', () => reworkStoryRender(projectId, reelId, render.id, comment.trim(), kind, id), 'Changes requested. Update the timeline or scene, then build again.', () => {
      setReworking(false);
      setComment('');
    });
  }

  return (
    <div className="studio-render">
      <div className="studio-render-facts">
        <StatusBadge tone={jobTone(render.status)}>{render.has_file ? 'Built' : jobStatusLabel(render.status)}</StatusBadge>
        <StatusBadge tone={reviewTone(review)}>{reviewStatusLabel(review)}</StatusBadge>
        <span>{formatDateTime(render.created_at)}</span>
        <span>{formatTimecode(render.duration_ms)} long</span>
        <span>
          {render.clip_count} {render.clip_count === 1 ? 'clip' : 'clips'}
        </span>
        {render.has_file ? <span>{formatBytes(render.size_bytes)}</span> : null}
      </div>

      {render.status === 'failed' ? <p className="small text-danger mt-2 mb-0">The build did not finish: {failureReason(render.error_code)}</p> : null}

      {render.has_file ? (
        <div className="mt-3">
          <MediaPreview load={() => getStoryRenderFileUrl(projectId, reelId, render.id)} label="Final video" />
        </div>
      ) : null}

      <div className="d-flex flex-wrap gap-2 mt-3">
        {render.has_file && (review === 'draft' || review === 'needs_rework') ? (
          <BusyButton busy={busy === 'submit'} busyLabel="Sending…" onClick={() => run('submit', () => submitStoryRenderReview(projectId, reelId, render.id), 'Final video sent for review.')}>
            Send for review
          </BusyButton>
        ) : null}
        {review === 'pending_review' && canApprove && !reworking ? (
          <>
            <BusyButton
              className="btn btn-success"
              busy={busy === 'approve'}
              busyLabel="Approving…"
              onClick={() => run('approve', () => approveStoryRenderReview(projectId, reelId, render.id), 'Final video approved. You can now prepare the download.')}
            >
              Approve
            </BusyButton>
            <button type="button" className="btn btn-outline-secondary" onClick={() => setReworking(true)}>
              Request changes
            </button>
          </>
        ) : null}
        {review === 'pending_review' && !canApprove ? <span className="small text-secondary">Waiting for the project owner to review.</span> : null}
        {review === 'approved' && (!exportInfo || exportInfo.status === 'failed') ? (
          <BusyButton busy={busy === 'export'} busyLabel="Preparing…" onClick={() => run('export', () => createStoryExport(projectId, reelId, render.id), 'Your download is ready.')}>
            <i className="bi bi-box-arrow-down me-1" aria-hidden="true" />
            {exportInfo?.status === 'failed' ? 'Try preparing again' : 'Prepare download'}
          </BusyButton>
        ) : null}
        {review === 'approved' && exportInfo && (exportInfo.status === 'queued' || exportInfo.status === 'processing') ? (
          <span className="small text-secondary">Preparing your download…</span>
        ) : null}
        {exportInfo?.status === 'completed' ? (
          <BusyButton
            className="btn btn-primary"
            busy={busy === 'download'}
            busyLabel="Downloading…"
            onClick={() =>
              run('download', () => downloadStoryExport(projectId, reelId, exportInfo.id, exportInfo.filename || 'final-video.zip'), 'Download started.')
            }
          >
            <i className="bi bi-download me-1" aria-hidden="true" />
            Download ({formatBytes(exportInfo.size)})
          </BusyButton>
        ) : null}
      </div>

      {reworking ? (
        <form className="studio-inline-panel" onSubmit={requestChanges}>
          <fieldset className="mb-2">
            <legend className="form-label mb-1">What needs to change?</legend>
            <label className={`studio-choice studio-choice--compact me-2 mb-2${target === 'timeline' ? ' is-selected' : ''}`}>
              <input type="radio" className="form-check-input" name="rework-target" checked={target === 'timeline'} onChange={() => setTarget('timeline')} />
              <span>The edit (order, timing, transitions)</span>
            </label>
            {sceneTargets.map((clip) => (
              <label key={clip.id} className={`studio-choice studio-choice--compact me-2 mb-2${target === clip.source_version_id ? ' is-selected' : ''}`}>
                <input
                  type="radio"
                  className="form-check-input"
                  name="rework-target"
                  checked={target === clip.source_version_id}
                  onChange={() => setTarget(clip.source_version_id)}
                />
                <span>{clipName(clip)}</span>
              </label>
            ))}
          </fieldset>
          <label className="form-label" htmlFor="render-rework-comment">
            Describe the change
          </label>
          <textarea id="render-rework-comment" className="form-control" rows={2} value={comment} onChange={(event) => setComment(event.target.value)} required />
          <div className="d-flex gap-2 mt-2">
            <BusyButton type="submit" className="btn btn-primary btn-sm" busy={busy === 'rework'} disabled={!comment.trim()}>
              Request changes
            </BusyButton>
            <button type="button" className="btn btn-link btn-sm" onClick={() => setReworking(false)}>
              Cancel
            </button>
          </div>
        </form>
      ) : null}
    </div>
  );
}
