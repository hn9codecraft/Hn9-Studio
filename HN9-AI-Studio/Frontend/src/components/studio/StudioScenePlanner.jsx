import { useEffect, useState } from 'react';
import {
  approveStoryPlanVersion,
  createStoryPlan,
  estimateStorySceneCount,
  generateStoryPlan,
  listStoryPlans,
  readyStoryPlan,
  regenerateStoryPlan,
  storyPlanStage,
} from '../../services/storyService';
import { formatSeconds, friendlyError } from '../../services/studioMessages';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton, NotConnectedNote, StatusBadge } from './StudioUi';

const LENGTHS = [30, 60, 120, 180, 300];
const APPROVAL_FAILED = 'We couldn’t prepare this story for production. Nothing was changed.';

/** Plan → review → approve. Approval prepares the scenes for production. Never asks for the idea again. */
export default function StudioScenePlanner({ firstTime, onManual, onDone, onCancel }) {
  const { projectId, project, bible, connections, canApprove, refresh, goTo } = useStudio();
  const feedback = useFeedback();
  const [length, setLength] = useState(60);
  const [plan, setPlan] = useState(null);
  const [busy, setBusy] = useState('');
  const [confirming, setConfirming] = useState(false);
  const connected = Boolean(connections?.story_planning);
  const idea = bible?.concept?.trim() || '';

  useEffect(() => {
    let cancelled = false;
    listStoryPlans(projectId)
      .then((plans) => {
        if (!cancelled) setPlan(readyStoryPlan(plans));
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  async function generate() {
    setBusy('plan');
    try {
      const created = await createStoryPlan(projectId, { title: project.name || null, idea, requested_duration_seconds: length });
      const generated = await generateStoryPlan(projectId, created.id);
      setPlan(generated);
      feedback.success('Your story plan is ready. Review the scenes below, then approve it.');
    } catch (err) {
      feedback.error(friendlyError(err, 'The scenes could not be planned. Please try again.', 'plan'));
    } finally {
      setBusy('');
    }
  }

  async function retry() {
    setBusy('retry');
    setConfirming(false);
    try {
      setPlan(await regenerateStoryPlan(projectId, plan.id, {}));
      feedback.success('A new version of your story plan is ready to review.');
    } catch (err) {
      feedback.error(friendlyError(err, 'The scenes could not be planned. Please try again.', 'plan'));
    } finally {
      setBusy('');
    }
  }

  async function approve() {
    setBusy('approve');
    try {
      const result = await approveStoryPlanVersion(projectId, plan.id, plan.current_version.id);
      setPlan(result.plan);
      setConfirming(false);
      await refresh(['reels', 'status']);
      feedback.success(
        result.approved ? 'Story approved. Your production scenes are ready.' : 'This story was already approved. Your production scenes are ready.',
      );
      onDone(result.production_plan?.reel?.id || null);
    } catch (err) {
      setConfirming(false);
      feedback.error(friendlyError(err, APPROVAL_FAILED));
      listStoryPlans(projectId)
        .then((plans) => setPlan(readyStoryPlan(plans)))
        .catch(() => {});
    } finally {
      setBusy('');
    }
  }

  const planned = plan?.current_version?.plan?.scenes || [];
  const { approved, productionReady, production } = storyPlanStage(plan);
  const prepared = approved && productionReady;

  return (
    <div className="row g-4">
      <div className={firstTime ? 'col-lg-7' : 'col-12'}>
        <section className="card border-0 glass-card h-100" aria-labelledby="planner-heading">
          <div className="card-body">
            <h3 className="h5 mb-1" id="planner-heading">
              Plan scenes from your story
            </h3>
            <p className="small text-secondary">
              The studio writes a story plan from your idea, in roughly 30-second story beats. You review it, then approve it to prepare
              the scenes for video production. Every scene stays editable, at any length.
            </p>

            {!connected && planned.length === 0 ? <NotConnectedNote area="plan" /> : null}

            {connected && !idea && planned.length === 0 ? (
              <div className="studio-note">
                <i className="bi bi-info-circle" aria-hidden="true" />
                <span>
                  Add your idea in Story first.{' '}
                  <button type="button" className="btn btn-link btn-sm p-0 align-baseline" onClick={() => goTo('story')}>
                    Go to Story
                  </button>
                </span>
              </div>
            ) : null}

            {connected && idea && planned.length === 0 ? (
              <>
                <blockquote className="studio-quote">
                  <span className="small text-secondary d-block">Your idea (from Story)</span>
                  {idea}
                </blockquote>
                <label className="form-label" htmlFor="planner-length">
                  How long should the video be?
                </label>
                <select
                  id="planner-length"
                  className="form-select w-auto mb-1"
                  value={length}
                  onChange={(event) => setLength(Number(event.target.value))}
                  disabled={Boolean(busy)}
                >
                  {LENGTHS.map((value) => (
                    <option key={value} value={value}>
                      {formatSeconds(value)}
                    </option>
                  ))}
                </select>
                <p className="form-text mt-0 mb-3">About {estimateStorySceneCount(length)} scenes to start with.</p>
                <BusyButton busy={busy === 'plan'} busyLabel="Planning… this can take a minute" onClick={generate}>
                  <i className="bi bi-magic me-1" aria-hidden="true" />
                  Plan my scenes
                </BusyButton>
              </>
            ) : null}

            {planned.length > 0 ? (
              <>
                <dl className="studio-scene-facts mt-0">
                  <div>
                    <dt>Story plan</dt>
                    <dd>
                      <StatusBadge tone={approved ? 'success' : 'progress'}>{approved ? 'Approved' : 'Ready for review'}</StatusBadge>
                    </dd>
                  </div>
                  <div>
                    <dt>Production plan</dt>
                    <dd>
                      <StatusBadge tone={prepared ? 'success' : 'neutral'}>{prepared ? 'Ready' : 'Not prepared yet'}</StatusBadge>
                    </dd>
                  </div>
                </dl>
                <p className="fw-semibold mb-2">
                  {planned.length} {planned.length === 1 ? 'scene' : 'scenes'} ·{' '}
                  {formatSeconds(planned.reduce((sum, item) => sum + (Number(item.duration_seconds) || 0), 0))}
                </p>
                <ol className="studio-plan-list">
                  {planned.map((item, index) => (
                    <li key={`${index}-${item.title}`}>
                      <span className="fw-semibold">{item.title || `Scene ${index + 1}`}</span>
                      <span className="small text-secondary"> · {formatSeconds(item.duration_seconds)}</span>
                      {item.summary || item.story ? <span className="d-block small text-secondary">{item.summary || item.story}</span> : null}
                    </li>
                  ))}
                </ol>

                {prepared ? (
                  <>
                    <p className="small text-secondary">
                      This story is approved and its {production?.scene_count || planned.length} scenes are prepared for video production.
                    </p>
                    <div className="d-flex flex-wrap gap-2">
                      <button type="button" className="btn btn-primary" disabled={Boolean(busy)} onClick={() => onDone(production?.reel?.id || null)}>
                        Continue to Scenes
                        <i className="bi bi-arrow-right ms-1" aria-hidden="true" />
                      </button>
                      {connected ? (
                        <BusyButton className="btn btn-outline-secondary" busy={busy === 'retry'} busyLabel="Planning…" disabled={Boolean(busy)} onClick={retry}>
                          Plan a new version
                        </BusyButton>
                      ) : null}
                    </div>
                  </>
                ) : null}

                {!prepared && confirming ? (
                  <div className="studio-inline-panel" role="group" aria-labelledby="approve-heading">
                    <p className="fw-semibold mb-1" id="approve-heading">
                      Approve this story?
                    </p>
                    <p className="small mb-2">
                      Approving this story will prepare its {planned.length} scenes for video production. You can still edit any scene afterwards.
                    </p>
                    <div className="d-flex flex-wrap gap-2">
                      <BusyButton busy={busy === 'approve'} busyLabel="Preparing production…" disabled={Boolean(busy)} onClick={approve}>
                        Yes, approve and prepare production
                      </BusyButton>
                      <button type="button" className="btn btn-link" disabled={Boolean(busy)} onClick={() => setConfirming(false)}>
                        Not yet
                      </button>
                    </div>
                  </div>
                ) : null}

                {!prepared && !confirming && canApprove ? (
                  <>
                    <p className="small text-secondary">Read the plan above. When it looks right, approve it to prepare the scenes for production.</p>
                    <div className="d-flex flex-wrap gap-2">
                      <button type="button" className="btn btn-primary" disabled={Boolean(busy)} onClick={() => setConfirming(true)}>
                        <i className="bi bi-check2-circle me-1" aria-hidden="true" />
                        Approve and prepare production
                      </button>
                      {connected ? (
                        <BusyButton className="btn btn-outline-secondary" busy={busy === 'retry'} busyLabel="Planning…" disabled={Boolean(busy)} onClick={retry}>
                          Plan again
                        </BusyButton>
                      ) : null}
                    </div>
                  </>
                ) : null}

                {!prepared && !canApprove ? <p className="small text-secondary mb-0">Waiting for the project owner to approve this story.</p> : null}
              </>
            ) : null}

            {onCancel ? (
              <button type="button" className="btn btn-link px-0 mt-3" onClick={onCancel}>
                Back to scenes
              </button>
            ) : null}
          </div>
        </section>
      </div>

      {firstTime ? (
        <div className="col-lg-5">
          <section className="card border-0 glass-card h-100" aria-labelledby="manual-heading">
            <div className="card-body">
              <h3 className="h5 mb-1" id="manual-heading">
                Add scenes yourself
              </h3>
              <p className="small text-secondary">Write each scene by hand. You can still create videos and sound for them.</p>
              <button type="button" className={`btn ${connected && idea ? 'btn-outline-primary' : 'btn-primary'}`} onClick={onManual}>
                <i className="bi bi-plus-lg me-1" aria-hidden="true" />
                Write the first scene
              </button>
            </div>
          </section>
        </div>
      ) : null}
    </div>
  );
}
