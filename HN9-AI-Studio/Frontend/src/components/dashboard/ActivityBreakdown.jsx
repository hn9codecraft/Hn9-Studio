import EmptyState from '../ui/EmptyState';
import { activityModuleLabel } from '../../services/activityConstants';

const MODULES = ['project', 'script', 'image', 'video', 'asset'];

export default function ActivityBreakdown({ activity }) {
  const max = Math.max(...MODULES.map((module) => Number(activity.by_module?.[module]) || 0), 1);
  const hasAny = (activity.total || 0) > 0;

  if (!hasAny) {
    return (
      <EmptyState
        icon="bi-clock-history"
        title="No analytics data available yet."
        description="Activity counts appear after real studio actions are logged."
      />
    );
  }

  const actions = Object.entries(activity.by_action || {});

  return (
    <div className="card border-0 shadow-sm">
      <div className="card-body">
        <h3 className="card-heading mb-3">Activity breakdown</h3>
        <p className="small text-secondary mb-3">
          {activity.total} studio event{activity.total === 1 ? '' : 's'} total. {activity.recent_count} in the last 7
          days. <code>project_asset.*</code> is counted as asset.
        </p>
        <ul className="list-unstyled mb-4">
          {MODULES.map((module) => {
            const value = Number(activity.by_module?.[module]) || 0;
            return (
              <li key={module} className="mb-3">
                <div className="d-flex justify-content-between small mb-1">
                  <span>{activityModuleLabel(module)}</span>
                  <span className="fw-semibold">{value}</span>
                </div>
                <div className="analytics-bar-track">
                  <div
                    className="analytics-bar-fill"
                    style={{ width: `${Math.round((value / max) * 100)}%`, background: 'var(--chart-3)' }}
                  />
                </div>
              </li>
            );
          })}
        </ul>
        {actions.length ? (
          <div>
            <h4 className="mb-2">Actions</h4>
            <ul className="list-unstyled mb-0 dashboard-status-list">
              {actions.map(([action, count]) => (
                <li key={action} className="d-flex justify-content-between gap-3 py-1 small">
                  <code>{action}</code>
                  <span className="fw-semibold">{count}</span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>
    </div>
  );
}
