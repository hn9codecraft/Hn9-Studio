import { statusLabel } from '../../services/projectConstants';

export default function DashboardStats({ summary }) {
  const projectCards = [
    { key: 'projects', label: 'Projects', value: summary.projects.total, icon: 'bi-folder2-open' },
    { key: 'scripts', label: 'Scripts', value: summary.scripts.total, icon: 'bi-file-text' },
    { key: 'images', label: 'Image requests', value: summary.images.total, icon: 'bi-image' },
    { key: 'videos', label: 'Video requests', value: summary.videos.total, icon: 'bi-camera-video' },
    { key: 'assets', label: 'Assets', value: summary.assets.total, icon: 'bi-archive' },
  ];

  const projectStatuses = [
    { key: 'draft', value: summary.projects.draft },
    { key: 'active', value: summary.projects.active },
    { key: 'completed', value: summary.projects.completed },
    { key: 'archived', value: summary.projects.archived },
  ];

  return (
    <div className="dashboard-stats">
      <div className="row g-3 mb-4">
        {projectCards.map((card) => (
          <div className="col-6 col-xl" key={card.key}>
            <div className="card border-0 shadow-sm h-100">
              <div className="card-body p-4">
                <p className="small text-secondary text-uppercase mb-2">
                  <i className={`bi ${card.icon} me-1`} aria-hidden="true" />
                  {card.label}
                </p>
                <p className="dashboard-stat-value mb-0">{card.value}</p>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="row g-3">
        <div className="col-lg-4">
          <StatusBreakdown title="Project status" items={projectStatuses} labelFor={statusLabel} />
        </div>
        <div className="col-lg-8">
          <div className="row g-3">
            <div className="col-md-6">
              <StatusBreakdown title="Scripts" items={statusItems(summary.scripts.by_status)} />
            </div>
            <div className="col-md-6">
              <StatusBreakdown title="Image requests" items={statusItems(summary.images.by_status)} />
            </div>
            <div className="col-md-6">
              <StatusBreakdown title="Video requests" items={statusItems(summary.videos.by_status)} />
            </div>
            <div className="col-md-6">
              <StatusBreakdown title="Assets" items={statusItems(summary.assets.by_status)} />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function statusItems(byStatus) {
  return Object.entries(byStatus || {}).map(([key, value]) => ({ key, value: Number(value) || 0 }));
}

function StatusBreakdown({ title, items, labelFor }) {
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
