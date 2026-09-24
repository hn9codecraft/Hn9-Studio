import { Link } from 'react-router-dom';
import {
  activityActionLabel,
  activityModuleIcon,
  activityModuleLabel,
  activitySubjectPath,
  formatActivityDateTime,
} from '../../services/activityConstants';

export default function ActivityItem({ projectId, item }) {
  const path = activitySubjectPath(projectId, item);
  const title = item.subject?.title;
  const TitleTag = path ? Link : 'span';
  const titleProps = path ? { to: path, className: 'activity-link fw-semibold text-decoration-none' } : { className: 'fw-semibold' };

  return (
    <article className="activity-item">
      <div className="activity-item-icon" aria-hidden="true">
        <i className={`bi ${activityModuleIcon(item.module)}`} />
      </div>
      <div className="activity-item-body">
        <div className="activity-item-top">
          <p className="activity-item-title mb-0">{item.description || activityActionLabel(item.action)}</p>
          <time className="activity-item-time" dateTime={item.created_at || undefined}>
            {formatActivityDateTime(item.created_at)}
          </time>
        </div>
        <p className="activity-item-meta mb-0">
          {activityModuleLabel(item.module)}
          <span aria-hidden="true"> · </span>
          {activityActionLabel(item.action)}
          {title ? (
            <>
              <span aria-hidden="true"> · </span>
              <TitleTag {...titleProps}>{title}</TitleTag>
            </>
          ) : null}
          <span aria-hidden="true"> · </span>
          {item.actor?.name ? `By ${item.actor.name}` : 'Actor not recorded'}
        </p>
      </div>
    </article>
  );
}
