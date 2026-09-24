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
      <div className="card-body">
        <h3 className="card-heading mb-3">Creation timeline</h3>
        <p className="small text-secondary mb-3">Real created_at counts. Days without records are omitted.</p>
        <div className="d-flex flex-wrap gap-3 mb-4 small">
          <Legend color="var(--chart-1)" label="Projects" />
          <Legend color="var(--chart-2)" label="Scripts" />
          <Legend color="var(--chart-3)" label="Images" />
          <Legend color="var(--chart-4)" label="Videos" />
          <Legend color="var(--chart-5)" label="Assets" />
        </div>
        <div className="analytics-timeline">
          {rows.map((row) => (
            <div key={row.date} className="analytics-timeline-row">
              <div className="analytics-timeline-date">{row.date}</div>
              <div className="analytics-timeline-bars" aria-hidden="true">
                <Bar value={row.projects} max={max} color="var(--chart-1)" />
                <Bar value={row.scripts} max={max} color="var(--chart-2)" />
                <Bar value={row.images} max={max} color="var(--chart-3)" />
                <Bar value={row.videos} max={max} color="var(--chart-4)" />
                <Bar value={row.assets} max={max} color="var(--chart-5)" />
              </div>
              <div className="analytics-timeline-counts">
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
