import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { getProject, updateProject } from '../../services/projectService';
import { normalizeStudioWorkflows } from '../../services/storyConstants';
import { friendlyError, SOUND_ROLES } from '../../services/studioMessages';
import StudioWorkflowPicker from '../projects/StudioWorkflowPicker';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton, StatusBadge, StepHeader } from './StudioUi';

export default function StudioSettingsStep() {
  const { projectId, project, connections, setWorkflows } = useStudio();
  const { user } = useAuth();
  const feedback = useFeedback();
  const saved = normalizeStudioWorkflows(project.studio_modules);
  const [draft, setDraft] = useState(saved);
  const [saving, setSaving] = useState(false);
  const dirty = JSON.stringify(draft) !== JSON.stringify(saved);

  async function save() {
    setSaving(true);
    try {
      // Project updates replace the whole settings object, so merge with what is stored.
      const current = await getProject(projectId);
      const stored = current?.settings && typeof current.settings === 'object' && !Array.isArray(current.settings) ? current.settings : {};
      const { studio_modules: _previous, ...rest } = stored;
      await updateProject(projectId, { settings: draft.length > 0 ? { ...rest, studio_modules: draft } : rest });
      setWorkflows(draft);
      feedback.success('Studio steps updated.');
    } catch (err) {
      feedback.error(friendlyError(err, 'Your choice could not be saved. Please try again.'));
    } finally {
      setSaving(false);
    }
  }

  const video = connections?.video || {};
  const soundRoles = connections?.sound?.roles || [];
  const rows = [
    { label: 'Scene planning from your story', on: Boolean(connections?.story_planning) },
    { label: 'AI pictures for characters and look & feel', on: Boolean(connections?.reference_images) },
    { label: 'Video from a description', on: Boolean(video.text) },
    { label: 'Video from a picture', on: Boolean(video.image) },
    { label: 'Video from your characters/style', on: Boolean(video.reference) },
    {
      label: 'Scene sound',
      on: soundRoles.length > 0,
      detail: soundRoles.length
        ? SOUND_ROLES.filter((role) => soundRoles.includes(role.value))
            .map((role) => role.label)
            .join(', ')
        : '',
    },
  ];

  return (
    <div className="studio-step">
      <StepHeader title="Studio settings" purpose="Choose which steps this project needs and see which creation features are connected." />
      <div className="row g-4">
        <div className="col-lg-7">
          <section className="card border-0 glass-card h-100">
            <div className="card-body">
              <StudioWorkflowPicker value={draft} onChange={setDraft} idPrefix="studio-settings-workflow" disabled={saving} />
              <BusyButton className="btn btn-primary mt-3" busy={saving} busyLabel="Saving…" disabled={!dirty} onClick={save}>
                Save steps
              </BusyButton>
            </div>
          </section>
        </div>
        <div className="col-lg-5">
          <section className="card border-0 glass-card h-100" aria-labelledby="connections-heading">
            <div className="card-body">
              <h3 className="h6" id="connections-heading">
                Connected features
              </h3>
              <ul className="list-unstyled d-grid gap-2 mb-3">
                {rows.map((row) => (
                  <li key={row.label} className="d-flex justify-content-between align-items-start gap-2">
                    <span>
                      {row.label}
                      {row.detail ? <span className="d-block small text-secondary">{row.detail}</span> : null}
                    </span>
                    <StatusBadge tone={row.on ? 'success' : 'warning'}>{row.on ? 'Connected' : 'Not connected'}</StatusBadge>
                  </li>
                ))}
              </ul>
              <p className="small text-secondary mb-0">
                Features that are not connected stay hidden in the steps, and you can still write, upload and arrange everything by hand.
                {user?.role === 'admin' ? (
                  <>
                    {' '}
                    <Link to="/providers">Manage providers</Link>
                  </>
                ) : (
                  ' Ask an administrator to connect a provider.'
                )}
              </p>
            </div>
          </section>
        </div>
      </div>
    </div>
  );
}
