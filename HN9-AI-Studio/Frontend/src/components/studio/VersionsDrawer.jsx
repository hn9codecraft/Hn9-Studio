import { useEffect, useState } from 'react';
import { listSceneVersions, restoreAssembly, selectUnitVersion } from '../../services/storyVersionsService';
import { formatDateTime, formatSeconds } from '../../services/studioMessages';
import { friendlyErrorM19 } from '../../services/studioMessagesM19';
import { getProductionSceneAssemblyFileUrl, getProductionUnitVersionFileUrl } from '../../services/storyService';
import { useFeedback } from './StudioContext';
import { BusyButton, MediaPreview, StatusBadge } from './StudioUi';

export default function VersionsDrawer({ open, onClose, projectId, planId, sceneId }) {
  const feedback = useFeedback();
  const [payload, setPayload] = useState(null);
  const [pending, setPending] = useState(null);
  const [busy, setBusy] = useState('');

  useEffect(() => {
    if (!open) return undefined;
    let stopped = false;
    listSceneVersions(projectId, planId, sceneId)
      .then((data) => {
        if (!stopped) setPayload(data);
      })
      .catch((error) => {
        if (!stopped) feedback.error(friendlyErrorM19(error, 'Versions could not be loaded.'));
      });
    return () => {
      stopped = true;
    };
  }, [open, projectId, planId, sceneId]);

  if (!open) return null;

  async function confirmRestore() {
    if (!pending) return;
    setBusy('restore');
    try {
      const result = await restoreAssembly(projectId, planId, sceneId, pending.uuid);
      const message = result?.render_stale
        ? 'This version is on the timeline again. Render again to include this version.'
        : 'This version is on the timeline again. Nothing was deleted.';
      feedback.success(message);
      setPending(null);
      setPayload(await listSceneVersions(projectId, planId, sceneId));
    } catch (error) {
      feedback.error(friendlyErrorM19(error, 'That version could not be restored.'));
    } finally {
      setBusy('');
    }
  }

  async function useClip(unitId, versionId) {
    setBusy(versionId);
    try {
      await selectUnitVersion(projectId, planId, unitId, versionId);
      feedback.success('That clip will be used the next time this scene is built.');
      setPayload(await listSceneVersions(projectId, planId, sceneId));
    } catch (error) {
      feedback.error(friendlyErrorM19(error, 'That clip could not be selected.'));
    } finally {
      setBusy('');
    }
  }

  return (
    <>
      <div className="offcanvas-backdrop fade show" onClick={onClose} />
      <aside className="offcanvas offcanvas-end show" tabIndex="-1" role="dialog" aria-labelledby="versions-drawer-title">
        <div className="offcanvas-header">
          <h2 className="offcanvas-title h5" id="versions-drawer-title">Versions</h2>
          <button type="button" className="btn-close" aria-label="Close" onClick={onClose} />
        </div>
        <div className="offcanvas-body">
          {payload === null ? <p className="small text-secondary">Loading versions…</p> : null}
          {(payload?.units || []).map((unit) => (
            <section key={unit.uuid} className="mb-4">
              <h3 className="h6">Clip {unit.sequence}</h3>
              <ul className="list-unstyled d-grid gap-2">
                {(unit.versions || []).map((version) => (
                  <li key={version.uuid} className="border rounded p-2">
                    <div className="d-flex justify-content-between gap-2">
                      <span>{version.label || 'Clip'}</span>
                      {version.selected ? <StatusBadge tone="success">In use</StatusBadge> : null}
                    </div>
                    <p className="small text-secondary mb-2">{formatDateTime(version.created_at)} · {formatSeconds(version.duration_seconds)}</p>
                    {version.preview_url ? (
                      <MediaPreview kind="video" load={() => getProductionUnitVersionFileUrl(projectId, planId, unit.uuid, version.uuid)} label="Clip preview" />
                    ) : null}
                    {version.selected ? null : (
                      <BusyButton className="btn btn-outline-primary btn-sm mt-2" busy={busy === version.uuid} busyLabel="Saving…" onClick={() => useClip(unit.uuid, version.uuid)}>
                        Use this clip
                      </BusyButton>
                    )}
                  </li>
                ))}
              </ul>
            </section>
          ))}

          <section className="mb-4">
            <h3 className="h6">Scene videos</h3>
            <ul className="list-unstyled d-grid gap-2">
              {(payload?.assemblies || []).map((assembly) => (
                <li key={assembly.uuid} className="border rounded p-2">
                  <div className="d-flex justify-content-between gap-2">
                    <span>{formatDateTime(assembly.created_at)}</span>
                    {assembly.current ? <StatusBadge tone="success">Current</StatusBadge> : null}
                  </div>
                  <p className="small text-secondary mb-2">{formatSeconds(assembly.duration_seconds)} · {assembly.status}</p>
                  {assembly.preview_url ? (
                    <MediaPreview kind="video" load={() => getProductionSceneAssemblyFileUrl(projectId, planId, sceneId, assembly.uuid)} label="Scene preview" />
                  ) : null}
                  {assembly.current ? null : (
                    <button type="button" className="btn btn-outline-primary btn-sm mt-2" onClick={() => setPending(assembly)}>
                      Restore
                    </button>
                  )}
                </li>
              ))}
            </ul>
          </section>

          {pending ? (
            <div className="border rounded p-3 mb-4" role="dialog" aria-labelledby="restore-confirm-title">
              <h3 className="h6" id="restore-confirm-title">Restore this version?</h3>
              <p className="small">The timeline will use this version. Nothing is deleted; you can restore the newer one again.</p>
              <div className="d-flex gap-2">
                <BusyButton className="btn btn-primary btn-sm" busy={busy === 'restore'} busyLabel="Restoring…" onClick={confirmRestore}>
                  Restore
                </BusyButton>
                <button type="button" className="btn btn-link btn-sm" onClick={() => setPending(null)}>Cancel</button>
              </div>
            </div>
          ) : null}

          <section>
            <h3 className="h6">Restore history</h3>
            {(payload?.history || []).length === 0 ? <p className="small text-secondary">Nothing has been restored yet.</p> : null}
            <ul className="list-unstyled small">
              {(payload?.history || []).map((row) => (
                <li key={row.uuid} className="mb-1">{formatDateTime(row.created_at)}</li>
              ))}
            </ul>
          </section>
        </div>
      </aside>
    </>
  );
}
