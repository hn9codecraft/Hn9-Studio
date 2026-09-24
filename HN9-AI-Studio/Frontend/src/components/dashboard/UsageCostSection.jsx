import { useEffect, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { getDashboardCosts, getDashboardUsage } from '../../services/dashboardService';

const EMPTY_USAGE = {
  operations: 0,
  tokens: { input: null, output: null, total: null, operations_with_tokens: 0, operations_without_tokens: 0 },
  by_provider: [],
  by_model: [],
  timeline: [],
};

const EMPTY_COSTS = {
  has_records: false,
  message: 'Cost data is not available yet. No provider costs have been recorded.',
  totals: [],
  by_provider: [],
  by_model: [],
  timeline: [],
};

export default function UsageCostSection() {
  const [usage, setUsage] = useState(EMPTY_USAGE);
  const [costs, setCosts] = useState(EMPTY_COSTS);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [project, setProject] = useState('');
  const [provider, setProvider] = useState('');
  const [applied, setApplied] = useState({ from: '', to: '', project: '', provider: '' });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [rangeError, setRangeError] = useState('');

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');

      try {
        const [usageResult, costResult] = await Promise.all([
          getDashboardUsage(applied),
          getDashboardCosts(applied),
        ]);
        if (!cancelled) {
          setUsage(usageResult);
          setCosts(costResult);
        }
      } catch (err) {
        if (!cancelled) {
          setUsage(EMPTY_USAGE);
          setCosts(EMPTY_COSTS);
          setError(err instanceof ApiError ? err.message : 'Unable to load usage and cost.');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    load();

    return () => {
      cancelled = true;
    };
  }, [applied]);

  function applyFilters(event) {
    event.preventDefault();
    if (from && to && from > to) {
      setRangeError('The from date must be on or before the to date.');
      return;
    }
    setRangeError('');
    setApplied({ from, to, project: project.trim(), provider: provider.trim() });
  }

  return (
    <section className="page-section usage-cost-section">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <h2 className="section-title mb-1">Provider usage and cost</h2>
          <p className="page-lede mb-0">
            Recorded from real prompt executions. Missing tokens stay unknown. Cost appears only when a provider
            reported a charge or a configured price exists.
          </p>
        </div>
        <form className="filter-bar" onSubmit={applyFilters}>
          <div>
            <label className="form-label small mb-1" htmlFor="usage-from">
              From
            </label>
            <input id="usage-from" className="form-control" type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
          </div>
          <div>
            <label className="form-label small mb-1" htmlFor="usage-to">
              To
            </label>
            <input id="usage-to" className="form-control" type="date" value={to} onChange={(event) => setTo(event.target.value)} />
          </div>
          <div>
            <label className="form-label small mb-1" htmlFor="usage-project">
              Project UUID
            </label>
            <input
              id="usage-project"
              className="form-control"
              value={project}
              onChange={(event) => setProject(event.target.value)}
              placeholder="Optional"
            />
          </div>
          <div>
            <label className="form-label small mb-1" htmlFor="usage-provider">
              Provider
            </label>
            <input
              id="usage-provider"
              className="form-control"
              value={provider}
              onChange={(event) => setProvider(event.target.value)}
              placeholder="e.g. openai"
            />
          </div>
          <button className="btn btn-outline-primary" type="submit">
            Apply
          </button>
        </form>
      </div>

      {rangeError ? (
        <div className="mb-3">
          <AlertMessage>{rangeError}</AlertMessage>
        </div>
      ) : null}

      {error ? (
        <div className="mb-3">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {loading ? <LoadingSpinner label="Loading usage and cost..." /> : null}

      {!loading ? (
        <>
          <UsagePanel usage={usage} />
          <CostPanel costs={costs} />
        </>
      ) : null}
    </section>
  );
}

function UsagePanel({ usage }) {
  if (usage.operations < 1) {
    return (
      <div className="subsection">
        <h3 className="card-heading">Usage overview</h3>
        <EmptyState
          icon="bi-cpu"
          title="No usage has been recorded yet."
          description="Usage appears after a real provider execution writes tokens onto a prompt execution."
        />
      </div>
    );
  }

  return (
    <div className="subsection">
      <h3 className="card-heading">Usage overview</h3>
      <div className="stat-grid">
        <StatCard label="Operations" value={usage.operations} />
        <StatCard label="Input tokens" value={formatUnknown(usage.tokens.input)} />
        <StatCard label="Output tokens" value={formatUnknown(usage.tokens.output)} />
        <StatCard label="Total tokens" value={formatUnknown(usage.tokens.total)} />
      </div>
      <p className="small text-secondary mt-3 mb-0">
        {usage.tokens.operations_without_tokens === 1
          ? '1 operation had no token data from the provider.'
          : `${usage.tokens.operations_without_tokens} operations had no token data from the provider.`}
      </p>
      <div className="equal-card-row mt-3">
        <BreakdownCard title="By provider" rows={usage.by_provider} labelKey="provider" valueKey="operations" />
        <BreakdownCard title="By model" rows={usage.by_model} labelKey="model" valueKey="operations" />
      </div>
      <div className="mt-3">
        <TimelineCard title="Usage timeline" rows={usage.timeline} valueKey="operations" />
      </div>
    </div>
  );
}

function CostPanel({ costs }) {
  if (!costs.has_records) {
    return (
      <div className="subsection">
        <h3 className="card-heading">Cost overview</h3>
        <EmptyState icon="bi-currency-dollar" title="Cost data is not available yet." description={costs.message} />
      </div>
    );
  }

  return (
    <div className="subsection">
      <h3 className="card-heading">Cost overview</h3>
      <div className="stat-grid">
        {costs.totals.map((total) => (
          <StatCard
            key={total.currency}
            label={`Recorded cost (${total.currency})`}
            value={total.amount}
          />
        ))}
      </div>
      <div className="equal-card-row mt-3">
        <BreakdownCard title="Cost by provider" rows={costs.by_provider} labelKey="provider" valueKey="amount" />
        <BreakdownCard title="Cost by model" rows={costs.by_model} labelKey="model" valueKey="amount" />
      </div>
      <div className="mt-3">
        <TimelineCard title="Cost timeline" rows={costs.timeline} valueKey="amount" />
      </div>
    </div>
  );
}

function StatCard({ label, value }) {
  return (
    <div className="glass-card kpi-card card border-0">
      <div className="card-body">
        <div className="kpi-copy">
          <p className="kpi-label mb-0">{label}</p>
          <p className="kpi-value mb-0">{value}</p>
        </div>
      </div>
    </div>
  );
}

function BreakdownCard({ title, rows, labelKey, valueKey }) {
  const max = Math.max(...rows.map((row) => Number(row[valueKey]) || 0), 1);

  return (
    <div className="card border-0 shadow-sm h-100">
      <div className="card-body">
        <h4 className="card-heading mb-3">{title}</h4>
        <ul className="list-unstyled mb-0">
          {rows.map((row) => {
            const label = row[labelKey] || 'Unattributed';
            const value = row[valueKey];
            const width = `${Math.round((Number(value) / max) * 100)}%`;
            return (
              <li key={`${label}-${row.currency || ''}`} className="mb-3">
                <div className="d-flex justify-content-between small mb-1">
                  <span>{label}</span>
                  <span className="fw-semibold numeric-cell">{value}</span>
                </div>
                <div className="analytics-bar-track">
                  <div className="analytics-bar-fill" style={{ width, background: 'var(--chart-3)' }} />
                </div>
              </li>
            );
          })}
        </ul>
      </div>
    </div>
  );
}

function TimelineCard({ title, rows, valueKey }) {
  if (!rows.length) {
    return null;
  }

  const max = Math.max(...rows.map((row) => Number(row[valueKey]) || 0), 1);

  return (
    <div className="card border-0 shadow-sm">
      <div className="card-body">
        <h4 className="card-heading mb-3">{title}</h4>
        <p className="small text-secondary mb-3">Real recorded days only. Empty days are omitted.</p>
        {rows.map((row) => (
          <div key={`${row.date}-${row.currency || ''}`} className="analytics-timeline-row">
            <div className="small text-secondary analytics-timeline-date">{row.date}</div>
            <div className="analytics-timeline-bars">
              <div className="analytics-bar-track">
                <div
                  className="analytics-bar-fill"
                  style={{ width: `${Math.round((Number(row[valueKey]) / max) * 100)}%`, background: 'var(--chart-3)' }}
                />
              </div>
            </div>
            <div className="small text-secondary analytics-timeline-counts">{row[valueKey]}</div>
          </div>
        ))}
      </div>
    </div>
  );
}

function formatUnknown(value) {
  return value === null || value === undefined ? 'Unknown' : value;
}
