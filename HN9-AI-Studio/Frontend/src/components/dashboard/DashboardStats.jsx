import { statusLabel } from '../../services/projectConstants';
import StatusBreakdownCard from '../ui/StatusBreakdownCard';

const KPI_CARDS = [
  { key: 'projects', label: 'Projects', icon: 'bi-folder2-open' },
  { key: 'scripts', label: 'Scripts', icon: 'bi-file-text' },
  { key: 'images', label: 'Image requests', icon: 'bi-image' },
  { key: 'videos', label: 'Video requests', icon: 'bi-camera-video' },
  { key: 'assets', label: 'Assets', icon: 'bi-archive' },
];

export default function DashboardStats({ summary }) {
  const values = {
    projects: summary.projects.total,
    scripts: summary.scripts.total,
    images: summary.images.total,
    videos: summary.videos.total,
    assets: summary.assets.total,
  };

  const projectStatuses = [
    { key: 'draft', value: summary.projects.draft },
    { key: 'active', value: summary.projects.active },
    { key: 'completed', value: summary.projects.completed },
    { key: 'archived', value: summary.projects.archived },
  ];

  return (
    <div className="dashboard-stats">
      <div className="kpi-grid">
        {KPI_CARDS.map((card) => (
          <div className="glass-card kpi-card card border-0" key={card.key}>
            <div className="card-body">
              <div className="kpi-icon" aria-hidden="true">
                <i className={`bi ${card.icon}`} />
              </div>
              <div className="kpi-copy">
                <p className="kpi-label mb-0">{card.label}</p>
                <p className="kpi-value mb-0">{values[card.key]}</p>
              </div>
            </div>
          </div>
        ))}
      </div>

      <div className="dashboard-status-grid">
        <StatusBreakdownCard title="Project status" items={projectStatuses} labelFor={statusLabel} />
        <StatusBreakdownCard title="Scripts" items={statusItems(summary.scripts.by_status)} />
        <StatusBreakdownCard title="Image requests" items={statusItems(summary.images.by_status)} />
        <StatusBreakdownCard title="Video requests" items={statusItems(summary.videos.by_status)} />
        <StatusBreakdownCard title="Assets" items={statusItems(summary.assets.by_status)} />
      </div>
    </div>
  );
}

function statusItems(byStatus) {
  return Object.entries(byStatus || {}).map(([key, value]) => ({ key, value: Number(value) || 0 }));
}
