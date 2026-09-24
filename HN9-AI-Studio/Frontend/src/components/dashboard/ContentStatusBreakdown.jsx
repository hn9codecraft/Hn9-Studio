import { statusLabel } from '../../services/projectConstants';
import EmptyState from '../ui/EmptyState';
import StatusBreakdownCard from '../ui/StatusBreakdownCard';

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
    <div className="dashboard-status-grid">
      <StatusBreakdownCard title="Projects" items={projectItems} labelFor={statusLabel} headingLevel="h4" />
      {GROUPS.map((group) => (
        <StatusBreakdownCard
          key={group.key}
          title={group.title}
          items={statusItems(content[group.key])}
          headingLevel="h4"
        />
      ))}
    </div>
  );
}

function statusItems(counts) {
  return Object.entries(counts || {})
    .filter(([key]) => key !== 'total')
    .map(([key, value]) => ({ key, value: Number(value) || 0 }));
}
