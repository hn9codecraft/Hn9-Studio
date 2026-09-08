import ActivityItem from '../activity/ActivityItem';
import EmptyState from '../ui/EmptyState';

export default function RecentActivity({ activities }) {
  if (!activities.length) {
    return (
      <EmptyState
        icon="bi-clock-history"
        title="No activity yet"
        description="Studio actions on your projects will appear here after they happen."
      />
    );
  }

  return (
    <div className="activity-timeline card border-0 shadow-sm">
      <div className="card-body p-0">
        {activities.map((item) => (
          <ActivityItem key={item.id} projectId={item.project?.id} item={item} />
        ))}
      </div>
    </div>
  );
}
