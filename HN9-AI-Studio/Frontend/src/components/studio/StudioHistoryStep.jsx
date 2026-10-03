import { useEffect, useState } from 'react';
import { getStoryHistory } from '../../services/storyService';
import { failureReason, formatDateTime, friendlyError, jobStatusLabel } from '../../services/studioMessages';
import { useStudio } from './StudioContext';
import { jobTone, StatusBadge, StepHeader, StepSkeleton } from './StudioUi';

const KINDS = {
  video: { label: 'Scene video', icon: 'bi-camera-reels', step: 'scenes' },
  audio: { label: 'Scene sound', icon: 'bi-soundwave', step: 'sound' },
  plan: { label: 'Scene plan', icon: 'bi-magic', step: 'scenes' },
  character_image: { label: 'Character picture', icon: 'bi-person-bounding-box', step: 'cast' },
  style_image: { label: 'Look & feel picture', icon: 'bi-palette', step: 'cast' },
  final_video: { label: 'Final video build', icon: 'bi-collection-play', step: 'final' },
};

const FILTERS = [
  { value: 'all', label: 'Everything' },
  { value: 'problems', label: 'Problems only' },
];

function isProblem(item) {
  return item.status === 'failed' || item.status === 'not_connected' || item.status === 'cancelled';
}

export default function StudioHistoryStep() {
  const { projectId, goTo } = useStudio();
  const [items, setItems] = useState(null);
  const [error, setError] = useState('');
  const [filter, setFilter] = useState('all');

  useEffect(() => {
    let cancelled = false;
    getStoryHistory(projectId)
      .then((result) => {
        if (!cancelled) setItems(result);
      })
      .catch((err) => {
        if (!cancelled) {
          setItems([]);
          setError(friendlyError(err, 'History could not be loaded. Please try again.'));
        }
      });
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  const visible = (items || []).filter((item) => filter === 'all' || isProblem(item));

  return (
    <div className="studio-step">
      <StepHeader title="History" purpose="Everything the studio tried to create for this project, including what failed and why." />

      <div className="btn-group mb-3" role="group" aria-label="Filter history">
        {FILTERS.map((option) => (
          <button
            key={option.value}
            type="button"
            className={`btn btn-sm ${filter === option.value ? 'btn-secondary' : 'btn-outline-secondary'}`}
            aria-pressed={filter === option.value}
            onClick={() => setFilter(option.value)}
          >
            {option.label}
          </button>
        ))}
      </div>

      {error ? <p className="text-danger">{error}</p> : null}
      {items === null ? <StepSkeleton rows={3} /> : null}

      {items !== null && visible.length === 0 && !error ? (
        <div className="studio-empty card border-0 glass-card">
          <i className="bi bi-clock-history" aria-hidden="true" />
          <p className="fw-semibold mb-1">{filter === 'all' ? 'Nothing created yet' : 'No problems so far'}</p>
          <p className="small text-secondary mb-0">
            {filter === 'all'
              ? 'Videos, sound, pictures and final builds you request will be listed here.'
              : 'Failed or not-connected attempts will appear here.'}
          </p>
        </div>
      ) : null}

      {visible.length > 0 ? (
        <ul className="studio-history list-unstyled">
          {visible.map((item) => {
            const kind = KINDS[item.kind] || KINDS.video;
            const problem = isProblem(item);
            const where = [
              item.scene_sequence ? `Scene ${item.scene_sequence}${item.scene_title ? ` · ${item.scene_title}` : ''}` : null,
              item.reel_title,
            ].filter(Boolean);
            return (
              <li key={`${item.kind}-${item.id}`} className="studio-history-item card border-0 glass-card">
                <div className="card-body">
                  <div className="d-flex flex-wrap align-items-start gap-2">
                    <i className={`bi ${kind.icon} studio-history-icon`} aria-hidden="true" />
                    <div className="flex-grow-1 min-w-0">
                      <span className="fw-semibold d-block">{kind.label}</span>
                      {where.length ? <span className="small text-secondary d-block">{where.join(' in ')}</span> : null}
                    </div>
                    <StatusBadge tone={jobTone(item.status)}>{jobStatusLabel(item.status)}</StatusBadge>
                  </div>
                  {problem ? <p className="small mt-2 mb-0">{failureReason(item.error_code, item.status)}</p> : null}
                  <div className="d-flex flex-wrap align-items-center gap-3 mt-2 small text-secondary">
                    <span>{formatDateTime(item.created_at)}</span>
                    {item.model_key && item.status === 'completed' ? <span>Model: {item.model_key}</span> : null}
                    {item.cost_reported ? (
                      <span>
                        Cost reported by provider: {item.cost} {item.currency}
                      </span>
                    ) : null}
                    {problem ? (
                      <button
                        type="button"
                        className="btn btn-link btn-sm p-0"
                        onClick={() => goTo(kind.step, item.scene_id ? { scene: item.scene_id, reel: item.reel_id } : item.reel_id ? { reel: item.reel_id } : {})}
                      >
                        {item.status === 'not_connected' ? 'Open this step' : 'Go there to try again'}
                      </button>
                    ) : null}
                  </div>
                </div>
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}
