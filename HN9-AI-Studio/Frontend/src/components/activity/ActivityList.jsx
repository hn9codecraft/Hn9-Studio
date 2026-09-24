import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';
import ActivityItem from './ActivityItem';

export default function ActivityList({ projectId, activities, loading, error, meta }) {
  if (loading) {
    return <LoadingSpinner label="Loading activity…" />;
  }

  return (
    <div className="activity-list">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <p className="section-kicker mb-1">Activity Studio</p>
          <h2 className="section-title mb-1">Activity</h2>
          <p className="text-secondary mb-0">A timeline of real actions on this project. Nothing here is invented.</p>
        </div>
      </div>

      {!error && activities.length === 0 ? (
        <EmptyState
          icon="bi-clock-history"
          title="No activity yet"
          description="Actions such as creating or updating scripts, images, videos, or assets will appear here after they happen."
        />
      ) : null}

      {activities.length > 0 ? (
        <>
          <div className="activity-timeline card border-0 shadow-sm">
            <div className="card-body p-0">
              {activities.map((item) => (
                <ActivityItem key={item.id} projectId={projectId} item={item} />
              ))}
            </div>
          </div>
          {meta?.total ? (
            <p className="small text-secondary mt-3 mb-0">
              Showing {activities.length} of {meta.total} event{meta.total === 1 ? '' : 's'}
            </p>
          ) : null}
        </>
      ) : null}
    </div>
  );
}
