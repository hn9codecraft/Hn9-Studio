import { useCallback, useState } from 'react';
import ActivityItem from '../activity/ActivityItem';
import EmptyState from '../ui/EmptyState';
import ActivityHistoryModal from './ActivityHistoryModal';

const DASHBOARD_PREVIEW_LIMIT = 5;

export default function RecentActivity({ activities }) {
  const [historyOpen, setHistoryOpen] = useState(false);
  const closeHistory = useCallback(() => setHistoryOpen(false), []);
  const preview = activities.slice(0, DASHBOARD_PREVIEW_LIMIT);

  if (!activities.length) {
    return (
      <>
        <EmptyState
          icon="bi-clock-history"
          title="No activity yet"
          description="Studio actions on your projects will appear here after they happen."
        />
        <div className="mt-3">
          <button type="button" className="btn btn-primary" onClick={() => setHistoryOpen(true)}>
            View All Activity
          </button>
        </div>
        <ActivityHistoryModal open={historyOpen} onClose={closeHistory} />
      </>
    );
  }

  return (
    <>
      <div className="activity-timeline dashboard-recent-activity glass-card card border-0">
        <div className="card-body p-0">
          {preview.map((item) => (
            <ActivityItem key={item.id} projectId={item.project?.id} item={item} />
          ))}
        </div>
        <div className="card-footer bg-transparent border-0 pt-0 pb-3 px-3">
          <button type="button" className="btn btn-primary" onClick={() => setHistoryOpen(true)}>
            View All Activity
          </button>
        </div>
      </div>
      <ActivityHistoryModal open={historyOpen} onClose={closeHistory} />
    </>
  );
}
