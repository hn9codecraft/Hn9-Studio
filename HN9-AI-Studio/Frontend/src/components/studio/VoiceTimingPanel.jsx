import { useEffect, useState } from 'react';
import { listProductionPlans } from '../../services/storyService';
import { m16Message } from '../../services/studioMessagesM16';
import { friendlyError } from '../../services/studioMessages';
import { applyVoiceTiming, previewVoiceTiming } from '../../services/storyVoiceTimingService';
import { formatEstimate } from './CostEstimate';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton } from './StudioUi';

export default function VoiceTimingPanel() {
  const { projectId, refresh } = useStudio();
  const feedback = useFeedback();
  const [planId, setPlanId] = useState('');
  const [roundTo, setRoundTo] = useState(1);
  const [preview, setPreview] = useState(null);
  const [accept, setAccept] = useState(false);
  const [confirming, setConfirming] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    listProductionPlans(projectId)
      .then((plans) => {
        if (cancelled) return;
        const current = plans.find((plan) => plan.status === 'active') || plans[0];
        setPlanId(current?.id || '');
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  useEffect(() => {
    if (!planId) return undefined;
    let cancelled = false;
    previewVoiceTiming(projectId, planId, { round_to: roundTo })
      .then((result) => {
        if (!cancelled) setPreview(result);
      })
      .catch(() => {
        if (!cancelled) setPreview(null);
      });
    return () => {
      cancelled = true;
    };
  }, [planId, projectId, roundTo]);

  const changed = (preview?.scenes || []).filter((scene) => scene.changed);
  const needsClips = changed.some((scene) => scene.has_unit_versions);

  async function apply() {
    setBusy(true);
    try {
      const result = await applyVoiceTiming(projectId, planId, {
        round_to: roundTo,
        accept_regeneration: accept,
        idempotency_key: crypto.randomUUID(),
      });
      setConfirming(false);
      if (result?.changed) {
        feedback.success(`Production plan revision ${result.production_plan?.revision} is ready. Generate and assemble the changed scenes again, then render.`);
        await refresh(['reels', 'sceneStatus']);
      } else {
        feedback.info('The scene lengths already match the voiceover.');
      }
    } catch (error) {
      feedback.error(m16Message(error) || friendlyError(error, 'The scene lengths could not be updated.'));
    } finally {
      setBusy(false);
    }
  }

  if (!planId) return null;

  return (
    <section className="card border-0 glass-card mt-4" aria-labelledby="voice-timing-title">
      <div className="card-body d-grid gap-3">
        <div>
          <h2 className="h5 mb-1" id="voice-timing-title">Match scene length to voiceover</h2>
          <p className="text-secondary mb-0">This updates scene lengths and adds a production plan revision. Nothing is generated.</p>
        </div>
        {preview?.measurable === false ? <p className="mb-0">{preview.message || 'FFmpeg/ffprobe not found'}</p> : null}
        <div className="btn-group" role="group" aria-label="Round to">
          {[1, 5].map((value) => (
            <button key={value} type="button" className={`btn btn-sm ${roundTo === value ? 'btn-primary' : 'btn-outline-secondary'}`} onClick={() => setRoundTo(value)}>
              Round to {value} s
            </button>
          ))}
        </div>
        <div className="table-responsive">
          <table className="table table-sm align-middle mb-0">
            <thead>
              <tr>
                <th>Scene</th>
                <th>Now</th>
                <th>Voice</th>
                <th>New</th>
                <th>Units</th>
              </tr>
            </thead>
            <tbody>
              {(preview?.scenes || []).map((scene) => (
                <tr key={scene.scene_uuid}>
                  <td>{scene.title || `Scene ${scene.sequence}`}</td>
                  <td>{scene.current_seconds}s</td>
                  <td>{scene.voice_seconds == null ? '—' : `${scene.voice_seconds}s`}</td>
                  <td>{scene.proposed_seconds}s</td>
                  <td className="small">{(scene.units_after || []).map((unit) => `${unit.duration_seconds}s`).join(' + ') || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {(preview?.scenes || []).some((scene) => (scene.warnings || []).includes('voice_not_ready')) ? <p className="mb-0">A voiceover is still being made. That scene stays the same.</p> : null}
        {(preview?.scenes || []).some((scene) => !scene.voice_audio_uuid) ? <p className="small text-secondary mb-0">Scenes without a voiceover stay the same.</p> : null}
        {(preview?.scenes || []).some((scene) => (scene.warnings || []).includes('voice_longer_than_max')) ? <p className="mb-0">A voiceover is longer than the maximum scene length, so the scene stays long enough for the voice.</p> : null}
        {preview?.drift_warning ? <p className="mb-0">The new total drifts from the requested length.</p> : null}
        {needsClips ? (
          <label className="d-flex gap-2 mb-0">
            <input type="checkbox" checked={accept} onChange={(event) => setAccept(event.target.checked)} />
            <span>
              I understand the changed scenes need new video clips.
              <span className="d-block small text-secondary">
                {preview?.regeneration_estimate?.available ? formatEstimate(preview.regeneration_estimate) : 'Price estimate not available'} — not charged now
              </span>
            </span>
          </label>
        ) : null}
        {confirming ? (
          <div className="d-grid gap-2">
            <p className="mb-0">Scene lengths will change and a new production plan revision is created. Nothing is generated. Old clips are kept.</p>
            <div className="d-flex gap-2">
              <BusyButton type="button" busy={busy} busyLabel="Applying…" disabled={needsClips && !accept} onClick={apply}>Apply</BusyButton>
              <button type="button" className="btn btn-link" onClick={() => setConfirming(false)}>Cancel</button>
            </div>
          </div>
        ) : (
          <div>
            <button type="button" className="btn btn-primary btn-sm" disabled={!changed.length || preview?.measurable === false} onClick={() => setConfirming(true)}>Apply</button>
          </div>
        )}
      </div>
    </section>
  );
}
