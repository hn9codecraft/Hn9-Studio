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
  const titleProps = path ? { to: path, className: 'fw-semibold text-decoration-none' } : { className: 'fw-semibold' };

  return (
    <article className="activity-item">
      <div className="activity-item-icon" aria-hidden="true">
        <i className={`bi ${activityModuleIcon(item.module)}`} />
      </div>
      <div className="activity-item-body">
        <div className="d-flex flex-wrap align-items-start justify-content-between gap-2 mb-1">
          <p className="mb-0">{item.description || activityActionLabel(item.action)}</p>
          <time className="small text-secondary" dateTime={item.created_at || undefined}>
            {formatActivityDateTime(item.created_at)}
          </time>
        </div>
        <div className="d-flex flex-wrap gap-2 align-items-center">
          <span className="status-pill">{activityModuleLabel(item.module)}</span>
          <span className="status-pill status-pending text-capitalize">{activityActionLabel(item.action)}</span>
          {title ? (
            <TitleTag {...titleProps}>{title}</TitleTag>
          ) : null}
        </div>
        <p className="small text-secondary mb-0 mt-2">{item.actor?.name ? `By ${item.actor.name}` : 'Actor not recorded'}</p>
      </div>
    </article>
  );
}
