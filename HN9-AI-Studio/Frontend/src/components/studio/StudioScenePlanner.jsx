import { useEffect, useState } from 'react';
import {
  createStoryPlan,
  estimateStorySceneCount,
  generateStoryPlan,
  listStoryPlans,
  materializeStoryPlanVersion,
  regenerateStoryPlan,
} from '../../services/storyService';
import { formatSeconds, friendlyError } from '../../services/studioMessages';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton, NotConnectedNote } from './StudioUi';

const LENGTHS = [30, 60, 120, 180, 300];

function readyPlan(plans) {
  return plans.find((plan) => plan.current_version?.status === 'completed' && (plan.current_version.plan?.scenes || []).length > 0) || null;
}

/** Turns the Story idea into scenes. Never asks for the idea again. */
export default function StudioScenePlanner({ firstTime, onManual, onDone, onCancel }) {
  const { projectId, project, bible, connections, refresh, goTo } = useStudio();
  const feedback = useFeedback();
  const [length, setLength] = useState(60);
  const [plan, setPlan] = useState(null);
  const [busy, setBusy] = useState('');
  const connected = Boolean(connections?.story_planning);
  const idea = bible?.concept?.trim() || '';

  useEffect(() => {
    if (!connected) return undefined;
    let cancelled = false;
    listStoryPlans(projectId)
      .then((plans) => {
        if (!cancelled) setPlan(readyPlan(plans));
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, [connected, projectId]);

  async function generate() {
    setBusy('plan');
    try {
      const created = await createStoryPlan(projectId, { title: project.name || null, idea, requested_duration_seconds: length });
      const generated = await generateStoryPlan(projectId, created.id);
      setPlan(generated);
      feedback.success('Your scene plan is ready. Check it below, then create the scenes.');
    } catch (err) {
      feedback.error(friendlyError(err, 'The scenes could not be planned. Please try again.', 'plan'));
    } finally {
      setBusy('');
    }
  }

  async function retry() {
    setBusy('retry');
    try {
      setPlan(await regenerateStoryPlan(projectId, plan.id, {}));
      feedback.success('A new scene plan is ready.');
    } catch (err) {
      feedback.error(friendlyError(err, 'The scenes could not be planned. Please try again.', 'plan'));
    } finally {
      setBusy('');
    }
  }

  async function createScenes() {
    setBusy('create');
    try {
      const result = await materializeStoryPlanVersion(projectId, plan.id, plan.current_version.id);
      await refresh(['reels']);
      feedback.success(result?.created ? `${result.scene_count} scenes were created.` : 'These scenes already exist. Opening them.');
      onDone(result?.reel?.id || null);
    } catch (err) {
      feedback.error(friendlyError(err, 'The scenes could not be created. Please try again.'));
    } finally {
      setBusy('');
    }
  }

  const planned = plan?.current_version?.plan?.scenes || [];

  return (
    <div className="row g-4">
      <div className={firstTime ? 'col-lg-7' : 'col-12'}>
        <section className="card border-0 glass-card h-100" aria-labelledby="planner-heading">
          <div className="card-body">
            <h3 className="h5 mb-1" id="planner-heading">
              Plan scenes from your story
            </h3>
            <p className="small text-secondary">The studio splits your story idea into 30-second scenes you can edit afterwards.</p>

            {!connected ? <NotConnectedNote area="plan" /> : null}

            {connected && !idea ? (
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
                <p className="form-text mt-0 mb-3">About {estimateStorySceneCount(length)} scenes.</p>
                <BusyButton busy={busy === 'plan'} busyLabel="Planning… this can take a minute" onClick={generate}>
                  <i className="bi bi-magic me-1" aria-hidden="true" />
                  Plan my scenes
                </BusyButton>
              </>
            ) : null}

            {planned.length > 0 ? (
              <>
                <p className="fw-semibold mb-2">
                  Planned: {planned.length} scenes · {formatSeconds(planned.reduce((sum, item) => sum + (Number(item.duration_seconds) || 0), 0))}
                </p>
                <ol className="studio-plan-list">
                  {planned.map((item, index) => (
                    <li key={`${index}-${item.title}`}>
                      <span className="fw-semibold">{item.title || `Scene ${index + 1}`}</span>
                      {item.summary || item.story ? <span className="d-block small text-secondary">{item.summary || item.story}</span> : null}
                    </li>
                  ))}
                </ol>
                <div className="d-flex flex-wrap gap-2">
                  <BusyButton busy={busy === 'create'} busyLabel="Creating scenes…" disabled={Boolean(busy)} onClick={createScenes}>
                    Create these scenes
                  </BusyButton>
                  <BusyButton
                    className="btn btn-outline-secondary"
                    busy={busy === 'retry'}
                    busyLabel="Planning…"
                    disabled={Boolean(busy)}
                    onClick={retry}
                  >
                    Plan again
                  </BusyButton>
                </div>
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
