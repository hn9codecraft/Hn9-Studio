import { useEffect, useState } from 'react';
import { getStoryProgress } from '../../services/storyProgressService';

const STEP = {
  story: 'story',
  cast: 'cast',
  style: 'cast',
  plan: 'scenes',
  production: 'scenes',
  timeline: 'scenes',
  sound: 'sound',
  render: 'final',
  review: 'final',
};

function elapsedLabel(seconds) {
  const total = Math.max(0, Number(seconds) || 0);
  const minutes = Math.floor(total / 60);
  const rest = total % 60;
  return minutes > 0 ? `${minutes}m ${rest}s` : `${rest}s`;
}

function renderPill(render) {
  const status = render?.status;
  if (status === 'queued') return 'Queued';
  if (status === 'rendering' || status === 'processing') return `Rendering… ${elapsedLabel(render.elapsed_seconds)}`;
  if (status === 'failed') return 'Render failed — see Final step';
  if (status === 'completed') return 'Render complete';
  return '';
}

export default function StudioProgressBar({ projectId, onNavigate }) {
  const [progress, setProgress] = useState(null);

  useEffect(() => {
    let stopped = false;
    let timer = 0;

    async function load() {
      try {
        const next = await getStoryProgress(projectId);
        if (!stopped) setProgress(next);
        return Boolean(next?.active);
      } catch {
        return false;
      }
    }

    function schedule(active) {
      window.clearTimeout(timer);
      timer = window.setTimeout(async () => {
        const still = await load();
        if (!stopped) schedule(still);
      }, active ? 10000 : 60000);
    }

    load().then((active) => {
      if (!stopped) schedule(active);
    });

    function onFocus() {
      load().then((active) => {
        if (!stopped) schedule(active);
      });
    }

    window.addEventListener('focus', onFocus);
    return () => {
      stopped = true;
      window.clearTimeout(timer);
      window.removeEventListener('focus', onFocus);
    };
  }, [projectId]);

  if (!progress?.stages) return null;
  const pill = renderPill(progress.render);
  const percent = Number(progress.percent) || 0;

  return (
    <div className="mt-3">
      <div className="d-flex justify-content-between small mb-1">
        <span>Story progress</span>
        <span>{percent}%</span>
      </div>
      <div className="progress" role="progressbar" aria-label="Story progress" aria-valuenow={percent} aria-valuemin={0} aria-valuemax={100}>
        <div className="progress-bar" style={{ width: `${percent}%` }} />
      </div>
      <div className="d-flex flex-wrap gap-1 mt-2">
        {progress.stages.map((stage) => (
          <button
            key={stage.key}
            type="button"
            className={`btn btn-sm ${stage.status === 'blocked' ? 'btn-outline-danger' : stage.status === 'done' ? 'btn-outline-success' : 'btn-outline-secondary'}`}
            title={stage.hint || `${stage.done} of ${stage.total}`}
            onClick={() => onNavigate(STEP[stage.key] || 'story')}
          >
            {stage.label} {stage.done}/{stage.total}
          </button>
        ))}
      </div>
      {pill ? <p className="small mb-0 mt-2">{pill}</p> : null}
    </div>
  );
}
