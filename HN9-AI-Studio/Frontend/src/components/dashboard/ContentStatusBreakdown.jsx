import { statusLabel } from '../../services/projectConstants';
import EmptyState from '../ui/EmptyState';

const GROUPS = [
  { key: 'scripts', title: 'Scripts' },
  { key: 'images', title: 'Image requests' },
  { key: 'videos', title: 'Video requests' },
  { key: 'assets', title: 'Assets' },
];

export default function ContentStatusBreakdown({ content, projects }) {
  const projectItems = [
    { key: 'draft', value: projects.draft },
    { key: 'active', value: projects.active },
    { key: 'completed', value: projects.completed },
    { key: 'archived', value: projects.archived },
  ];
  const hasAny = projects.total > 0 || GROUPS.some((group) => (content[group.key]?.total || 0) > 0);

  if (!hasAny) {
    return (
      <EmptyState
        icon="bi-bar-chart"
        title="No analytics data available yet."
        description="Status counts appear after you create real studio records."
      />
    );
  }

  return (
    <div className="row g-3">
      <div className="col-md-6 col-xl-4">
        <StatusCard title="Projects" items={projectItems} labelFor={statusLabel} />
      </div>
      {GROUPS.map((group) => (
        <div className="col-md-6 col-xl-4" key={group.key}>
          <StatusCard title={group.title} items={statusItems(content[group.key])} />
        </div>
      ))}
    </div>
  );
}

function statusItems(counts) {
  return Object.entries(counts || {})
    .filter(([key]) => key !== 'total')
    .map(([key, value]) => ({ key, value: Number(value) || 0 }));
}

function StatusCard({ title, items, labelFor }) {
  return (
    <div className="card border-0 shadow-sm h-100">
      <div className="card-body p-4">
        <h3 className="h6 text-secondary text-uppercase mb-3">{title}</h3>
        <ul className="list-unstyled mb-0 dashboard-status-list">
          {items.map((item) => (
            <li key={item.key} className="d-flex align-items-center justify-content-between gap-3 py-1">
              <span className={`status-pill status-${item.key}`}>{labelFor ? labelFor(item.key) : labelStatus(item.key)}</span>
              <span className="fw-semibold">{item.value}</span>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}

function labelStatus(value) {
  return value.replace(/_/g, ' ');
}
