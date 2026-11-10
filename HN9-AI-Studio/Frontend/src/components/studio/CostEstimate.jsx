import { useEffect, useState } from 'react';
import { getStoryCostSummary, getStoryProjectRevision } from '../../services/storyService';
import { useStudio } from './StudioContext';
import { BusyButton } from './StudioUi';

export function formatEstimate(estimate) {
  if (!estimate || estimate.available === false || estimate.amount_usd == null) {
    return 'Price not available yet. This can still be sent.';
  }
  const usd = `$${Number(estimate.amount_usd).toFixed(2)}`;
  if (estimate.display_currency === 'INR' && estimate.amount_display != null) {
    return `₹${Number(estimate.amount_display).toFixed(2)} (${usd})`;
  }
  return usd;
}

export function CostConfirm({ estimate, busy, onConfirm, onCancel, confirmLabel = 'Confirm and send' }) {
  return (
    <div className="border rounded p-2 mt-2" role="group" aria-label="Estimated price">
      <p className="small mb-2">
        <span className="fw-semibold">Estimated price: </span>
        {formatEstimate(estimate)}
      </p>
      <div className="d-flex gap-2">
        <BusyButton type="button" className="btn btn-primary btn-sm" busy={busy} busyLabel="Sending…" onClick={onConfirm}>
          {confirmLabel}
        </BusyButton>
        <button type="button" className="btn btn-link btn-sm" onClick={onCancel}>
          Cancel
        </button>
      </div>
    </div>
  );
}

export function SpendChip() {
  const { projectId } = useStudio();
  const revision = getStoryProjectRevision(projectId);
  const [summary, setSummary] = useState(null);

  useEffect(() => {
    let cancelled = false;
    getStoryCostSummary(projectId)
      .then((result) => {
        if (!cancelled) setSummary(result);
      })
      .catch(() => {
        if (!cancelled) setSummary(null);
      });
    return () => {
      cancelled = true;
    };
  }, [projectId, revision]);

  if (!summary) return null;
  const symbol = summary.display_currency === 'INR' ? '₹' : '$';
  const spent = Number(summary.display_currency === 'INR' ? summary.spent_total_display : summary.spent_total_usd || 0).toFixed(2);
  const budget = summary.budget?.amount != null ? `${symbol}${Number(summary.budget.amount).toFixed(2)}` : null;

  return (
    <span className="badge text-bg-light border" title="Estimated and reported spend for this project">
      {budget ? `Spent ${symbol}${spent} / ${budget}` : 'No budget set'}
    </span>
  );
}

export function SpendLines({ summary }) {
  const rows = Array.isArray(summary?.by_provider) ? summary.by_provider : [];
  return (
    <div className="mt-3">
      <p className="small mb-2">
        Reported ${Number(summary.spent_reported_usd || 0).toFixed(2)} · Estimated ${Number(summary.spent_estimated_usd || 0).toFixed(2)}
        {summary.estimate_unavailable_jobs ? ` · ${summary.estimate_unavailable_jobs} without a price` : ''}
      </p>
      {rows.length ? (
        <table className="table table-sm mb-0">
          <caption className="visually-hidden">Spend by provider</caption>
          <thead>
            <tr>
              <th>Provider</th>
              <th>Reported</th>
              <th>Estimated</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={`${row.provider_key}-${row.capability}`}>
                <td>{row.label}</td>
                <td>{row.reported_usd > 0 ? `$${Number(row.reported_usd).toFixed(2)}` : 'Not reported'}</td>
                <td>{row.estimated_usd > 0 ? `$${Number(row.estimated_usd).toFixed(2)}` : 'Not reported'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      ) : (
        <p className="small text-secondary mb-0">No spend yet.</p>
      )}
    </div>
  );
}
