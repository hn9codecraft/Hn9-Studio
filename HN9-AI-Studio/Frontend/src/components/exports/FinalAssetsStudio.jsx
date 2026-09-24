import { useEffect, useMemo, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import {
  createProjectExport,
  downloadProjectExport,
  finalizeProject,
  getFinalAssets,
  getProjectExport,
} from '../../services/exportService';
import { exportErrorMessage, exportStateClass, exportStateLabel, issueMessage } from '../../services/exportConstants';

export default function FinalAssetsStudio({ project, onProjectUpdated }) {
  const [readiness, setReadiness] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [finalizing, setFinalizing] = useState(false);
  const [exporting, setExporting] = useState(false);
  const [downloading, setDownloading] = useState(false);

  async function load() {
    setLoading(true);
    setError('');

    try {
      const data = await getFinalAssets(project.id);
      setReadiness(data);
    } catch (err) {
      setReadiness(null);
      setError(err instanceof ApiError ? exportErrorMessage(err) : 'Unable to load final assets.');
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    let cancelled = false;

    async function initialLoad() {
      setLoading(true);
      setError('');

      try {
        const data = await getFinalAssets(project.id);
        if (!cancelled) {
          setReadiness(data);
        }
      } catch (err) {
        if (!cancelled) {
          setReadiness(null);
          setError(err instanceof ApiError ? exportErrorMessage(err) : 'Unable to load final assets.');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    initialLoad();

    return () => {
      cancelled = true;
    };
  }, [project.id]);

  const latest = readiness?.latest_export;
  const inFlight = latest?.status === 'queued' || latest?.status === 'processing';

  useEffect(() => {
    if (!inFlight || !latest?.id) {
      return undefined;
    }

    const timer = window.setInterval(async () => {
      try {
        const current = await getProjectExport(project.id, latest.id);
        setReadiness((prev) => (prev ? { ...prev, latest_export: current } : prev));
        if (current.status === 'completed' || current.status === 'failed') {
          const refreshed = await getFinalAssets(project.id);
          setReadiness(refreshed);
        }
      } catch {
        // Keep the last known state; the next poll retries.
      }
    }, 2000);

    return () => window.clearInterval(timer);
  }, [inFlight, latest?.id, project.id]);

  const workflowState = useMemo(() => {
    if (finalizing) {
      return 'finalizing';
    }

    if (exporting || inFlight) {
      return 'exporting';
    }

    return readiness?.workflow_state || 'not_ready';
  }, [finalizing, exporting, inFlight, readiness?.workflow_state]);

  const canFinalize = Boolean(readiness?.can_finalize) && !finalizing && !exporting;
  const canExport = Boolean(readiness?.can_export) && !finalizing && !exporting && !inFlight;
  const canDownload = latest?.status === 'completed' && Boolean(latest?.id) && !downloading;

  async function handleFinalize() {
    setFinalizing(true);
    setError('');
    setNotice('');

    try {
      const result = await finalizeProject(project.id);
      setReadiness(result.readiness);
      onProjectUpdated?.(result.project);
      setNotice('Project finalized. You can export the package.');
    } catch (err) {
      setError(err instanceof ApiError ? exportErrorMessage(err) : 'Unable to finalize this project.');
    } finally {
      setFinalizing(false);
    }
  }

  async function handleExport() {
    setExporting(true);
    setError('');
    setNotice('');

    try {
      const created = await createProjectExport(project.id);
      const refreshed = await getFinalAssets(project.id);
      setReadiness({
        ...refreshed,
        latest_export: created,
      });

      if (created.status === 'completed') {
        setNotice('Export ready. You can download the package.');
      } else if (created.status === 'failed') {
        setError(created.error || 'Export generation failed. Please try again.');
      }
    } catch (err) {
      setError(err instanceof ApiError ? exportErrorMessage(err) : 'Unable to export this project.');
    } finally {
      setExporting(false);
    }
  }

  async function handleDownload() {
    if (!latest?.id) {
      return;
    }

    setDownloading(true);
    setError('');

    try {
      await downloadProjectExport(project.id, latest.id, latest.filename || 'project-export.zip');
    } catch (err) {
      setError(err instanceof ApiError ? exportErrorMessage(err) : 'Unable to download the export.');
    } finally {
      setDownloading(false);
    }
  }

  if (loading && !readiness) {
    return <LoadingSpinner label="Checking final assets…" />;
  }

  return (
    <div className="final-assets-studio">
      {notice ? (
        <div className="mb-4">
          <AlertMessage variant="success">{notice}</AlertMessage>
        </div>
      ) : null}

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      <div className="row g-4">
        <div className="col-lg-5">
          <div className="card border-0 glass-card h-100">
            <div className="card-body">
              <p className="section-kicker mb-1">Final package</p>
              <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 className="card-heading mb-0">Project readiness</h2>
                <span className={`status-pill ${exportStateClass(workflowState)}`}>{exportStateLabel(workflowState)}</span>
              </div>

              <p className="text-secondary" aria-live="polite">
                {readiness?.ready
                  ? readiness.finalized
                    ? 'Approved script, image, and video are ready for export.'
                    : 'Approved assets are in place. Finalize the project to unlock export.'
                  : 'Project is not ready for export.'}
              </p>

              {readiness?.issues?.length ? (
                <ul className="mb-4">
                  {readiness.issues.map((issue) => (
                    <li key={issue.code}>{issueMessage(issue.code) || issue.message}</li>
                  ))}
                </ul>
              ) : (
                <p className="mb-4">All required assets are approved and readable.</p>
              )}

              <div className="d-flex flex-wrap gap-2">
                <button
                  type="button"
                  className="btn btn-outline-primary"
                  onClick={handleFinalize}
                  disabled={!canFinalize}
                  aria-disabled={!canFinalize}
                >
                  {finalizing ? 'Finalizing…' : 'Finalize project'}
                </button>
                <button
                  type="button"
                  className="btn btn-primary"
                  onClick={handleExport}
                  disabled={!canExport}
                  aria-disabled={!canExport}
                >
                  {exporting || inFlight ? 'Exporting…' : 'Export package'}
                </button>
                <button
                  type="button"
                  className="btn btn-outline-secondary"
                  onClick={handleDownload}
                  disabled={!canDownload}
                  aria-disabled={!canDownload}
                >
                  {downloading ? 'Downloading…' : 'Download ZIP'}
                </button>
                <button type="button" className="btn btn-link" onClick={load} disabled={loading}>
                  Refresh
                </button>
              </div>
            </div>
          </div>
        </div>

        <div className="col-lg-7">
          <div className="card border-0 glass-card h-100">
            <div className="card-body">
              <h2 className="card-heading mb-3">Approved assets</h2>
              <AssetGroup title="Script" items={readiness?.scripts} empty="No approved script." />
              <AssetGroup title="Images" items={readiness?.images} empty="No approved image." />
              <AssetGroup title="Videos" items={readiness?.videos} empty="No approved video." />

              {latest ? (
                <div className="mt-4">
                  <h3 className="card-heading">Latest export</h3>
                  <dl className="row mb-0">
                    <dt className="col-sm-4">Status</dt>
                    <dd className="col-sm-8">{latest.status}</dd>
                    <dt className="col-sm-4">Filename</dt>
                    <dd className="col-sm-8">{latest.filename || '—'}</dd>
                    <dt className="col-sm-4">Size</dt>
                    <dd className="col-sm-8">{latest.size ? `${latest.size} bytes` : '—'}</dd>
                  </dl>
                </div>
              ) : null}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function AssetGroup({ title, items, empty }) {
  const list = Array.isArray(items) ? items : [];

  return (
    <section className="mb-4">
      <h3 className="card-heading">{title}</h3>
      {list.length === 0 ? (
        <p className="text-secondary mb-0">{empty}</p>
      ) : (
        <ul className="mb-0">
          {list.map((item) => (
            <li key={item.id}>
              {item.title || 'Untitled'} <span className="text-secondary">({item.status})</span>
              {item.has_file === false ? <span className="text-danger"> — file missing</span> : null}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
