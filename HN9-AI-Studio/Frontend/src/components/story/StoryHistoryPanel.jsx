import { useEffect, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import { ApiError } from '../../services/apiClient';
import { getStoryHistory } from '../../services/storyService';

function formatCost(row) {
  if (!row.cost_reported || row.cost === null || row.cost === undefined) {
    return 'Not reported';
  }
  return `${Number(row.cost)}${row.currency ? ` ${row.currency}` : ''}`;
}

function formatTime(value) {
  return value ? new Date(value).toLocaleString() : '—';
}

export default function StoryHistoryPanel({ projectId }) {
  const [rows, setRows] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    getStoryHistory(projectId)
      .then((data) => {
        if (!cancelled) setRows(data);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Unable to load generation history.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  return (
    <section className="card border-0 glass-card" aria-label="Generation history">
      <div className="card-body">
        <h2 className="h5 mb-3">Generation history</h2>
        {error ? <AlertMessage>{error}</AlertMessage> : null}
        {loading ? <p className="text-secondary mb-0">Loading history…</p> : null}
        {!loading && !error && rows.length === 0 ? (
          <p className="text-secondary mb-0">No Story generation jobs yet.</p>
        ) : null}
        {!loading && rows.length > 0 ? (
          <div className="table-responsive">
            <table className="table table-sm align-middle mb-0">
              <thead>
                <tr>
                  <th scope="col">Created</th>
                  <th scope="col">Capability</th>
                  <th scope="col">Provider</th>
                  <th scope="col">Model</th>
                  <th scope="col">Status</th>
                  <th scope="col">Operation</th>
                  <th scope="col">Finished</th>
                  <th scope="col">Cost</th>
                </tr>
              </thead>
              <tbody>
                {rows.map((row) => (
                  <tr key={row.id}>
                    <td>{formatTime(row.created_at)}</td>
                    <td>{row.capability}</td>
                    <td>{row.provider_key || '—'}</td>
                    <td>{row.model_key || '—'}</td>
                    <td>
                      {row.status}
                      {row.error_message ? <div className="small text-secondary">{row.error_message}</div> : null}
                    </td>
                    <td className="text-break small">{row.operation_id || '—'}</td>
                    <td>{formatTime(row.completed_at || row.failed_at)}</td>
                    <td>{formatCost(row)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : null}
      </div>
    </section>
  );
}
