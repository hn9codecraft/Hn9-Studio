import { useState } from 'react';
import { approveBulkSelection, previewBulkApprove } from '../../services/storyBulkApproveService';
import { friendlyError } from '../../services/studioMessages';
import { BusyButton } from './StudioUi';

export default function BulkApproveBar({ projectId, kind, scope = {}, selected = [], eligibleIds = [], onChange, onDone }) {
  const [preview, setPreview] = useState(null);
  const [result, setResult] = useState(null);
  const [error, setError] = useState('');
  const [busy, setBusy] = useState(false);
  const count = selected.length;

  if (count === 0 && eligibleIds.length === 0 && !preview && !result) return null;

  async function openPreview() {
    setBusy(true);
    setError('');
    setResult(null);
    try {
      const next = await previewBulkApprove(projectId, { kind, scope, ids: selected });
      setPreview(next);
    } catch (err) {
      setError(friendlyError(err, 'Those items could not be checked.'));
    } finally {
      setBusy(false);
    }
  }

  async function confirm() {
    if (!preview?.selection_hash) return;
    setBusy(true);
    setError('');
    try {
      const next = await approveBulkSelection(projectId, {
        kind,
        scope,
        ids: selected,
        selection_hash: preview.selection_hash,
      });
      setResult(next);
      setPreview(null);
      onChange([]);
      onDone?.();
    } catch (err) {
      setError(friendlyError(err, 'Those items could not be approved.'));
    } finally {
      setBusy(false);
    }
  }

  function clear() {
    onChange([]);
    setPreview(null);
    setResult(null);
    setError('');
  }

  return (
    <div className="position-sticky bottom-0 border rounded p-2 mb-3 bg-body" style={{ zIndex: 2 }}>
      <div className="d-flex flex-wrap align-items-center gap-2">
        <span className="small fw-semibold">{count} selected</span>
        <BusyButton type="button" className="btn btn-success btn-sm" busy={busy && !preview} busyLabel="Checking…" disabled={count === 0 || busy} onClick={openPreview}>
          Approve selected
        </BusyButton>
        <button type="button" className="btn btn-outline-secondary btn-sm" onClick={clear} disabled={busy || count === 0}>
          Clear
        </button>
        <button type="button" className="btn btn-link btn-sm" onClick={() => onChange(eligibleIds)} disabled={busy || eligibleIds.length === 0}>
          Select all eligible
        </button>
      </div>
      {error ? <p className="small text-danger mb-0 mt-2">{error}</p> : null}
      {preview ? (
        <div className="mt-2" role="dialog" aria-label="Approve selected">
          <p className="small mb-1">These items will be approved. Nothing paid starts.</p>
          <ul className="small mb-2">
            {(preview.items || []).map((item) => (
              <li key={item.id}>
                {item.label}: {item.eligible ? 'will be approved' : item.reason || 'skipped'}
              </li>
            ))}
          </ul>
          {preview.next_paid_step?.label ? (
            <p className="small mb-2">
              {preview.next_paid_step.label}. <span className="fw-semibold">Not charged now.</span>
            </p>
          ) : (
            <p className="small mb-2">Not charged now.</p>
          )}
          <div className="d-flex gap-2">
            <BusyButton type="button" className="btn btn-success btn-sm" busy={busy} busyLabel="Approving…" onClick={confirm}>
              Confirm approval
            </BusyButton>
            <button type="button" className="btn btn-link btn-sm" onClick={() => setPreview(null)} disabled={busy}>
              Back
            </button>
          </div>
        </div>
      ) : null}
      {result ? (
        <p className="small mb-0 mt-2">
          Approved {result.counts?.approved || 0}. Skipped {result.counts?.skipped || 0}. Could not approve {result.counts?.failed || 0}.
        </p>
      ) : null}
    </div>
  );
}
