import EmptyState from '../ui/EmptyState';

export default function CreationTimeline({ rows }) {
  if (!rows.length) {
    return (
      <EmptyState
        icon="bi-graph-up"
        title="No analytics data available yet."
        description="The creation timeline only appears after you create real studio records."
      />
    );
  }

  const max = Math.max(
    ...rows.flatMap((row) => [row.projects, row.scripts, row.images, row.videos, row.assets].map((n) => Number(n) || 0)),
    1,
  );

  return (
    <div className="card border-0 shadow-sm">
      <div className="card-body p-4">
        <h3 className="h6 text-secondary text-uppercase mb-3">Creation timeline</h3>
        <p className="small text-secondary mb-3">Real created_at counts. Days without records are omitted.</p>
        <div className="d-flex flex-wrap gap-2 mb-3 small">
          <Legend color="#0d365c" label="Projects" />
          <Legend color="#1e5a8c" label="Scripts" />
          <Legend color="#f0a80b" label="Images" />
          <Legend color="#8a97a6" label="Videos" />
          <Legend color="#146c43" label="Assets" />
        </div>
        <div className="analytics-timeline">
          {rows.map((row) => (
            <div key={row.date} className="analytics-timeline-row">
              <div className="small text-secondary analytics-timeline-date">{row.date}</div>
              <div className="analytics-timeline-bars" aria-hidden="true">
                <Bar value={row.projects} max={max} color="#0d365c" />
                <Bar value={row.scripts} max={max} color="#1e5a8c" />
                <Bar value={row.images} max={max} color="#f0a80b" />
                <Bar value={row.videos} max={max} color="#8a97a6" />
                <Bar value={row.assets} max={max} color="#146c43" />
              </div>
              <div className="small text-secondary analytics-timeline-counts">
                {row.projects}/{row.scripts}/{row.images}/{row.videos}/{row.assets}
              </div>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}

function Bar({ value, max, color }) {
  const width = `${Math.round(((Number(value) || 0) / max) * 100)}%`;
  return (
    <div className="analytics-bar-track">
      <div className="analytics-bar-fill" style={{ width, background: color }} />
    </div>
  );
}

function Legend({ color, label }) {
  return (
    <span className="d-inline-flex align-items-center gap-1">
      <span className="analytics-legend-swatch" style={{ background: color }} />
      {label}
    </span>
  );
}
