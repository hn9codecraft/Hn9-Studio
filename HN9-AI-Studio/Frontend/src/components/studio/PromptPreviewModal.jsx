import { useEffect, useState } from 'react';
import { formatEstimate } from './CostEstimate';
import { BusyButton, StatusBadge } from './StudioUi';
import { friendlyError } from '../../services/studioMessages';

const SENT = {
  image: 'Sent as the starting picture',
  reference_image: 'Sent as a reference picture',
  text: 'Only the text lock is used in this mode',
  not_sent: 'Not sent',
};

export default function PromptPreviewModal({
  title,
  load,
  allowEdit = false,
  onSave,
  onReset,
  onGenerate,
  onCancel,
  busy = false,
}) {
  const [preview, setPreview] = useState(null);
  const [error, setError] = useState('');
  const [edit, setEdit] = useState('');
  const [editing, setEditing] = useState(false);
  const [saving, setSaving] = useState(false);

  async function refresh() {
    setError('');
    try {
      const next = await load();
      setPreview(next);
      setEdit(next?.prompt?.base || '');
    } catch (err) {
      setError(friendlyError(err, 'The preview could not be loaded.', 'video'));
    }
  }

  useEffect(() => {
    refresh();
    // The preview is loaded when the modal opens.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function generate() {
    if (!preview) return;
    try {
      await onGenerate(preview);
    } catch (err) {
      if (err?.errorCode === 'story_prompt_preview_stale') {
        setError(friendlyError(err, 'The prompt changed since your preview.', 'video'));
        await refresh();
        return;
      }
      setError(friendlyError(err, 'This clip could not be started.', 'video'));
    }
  }

  async function save() {
    if (!onSave) return;
    setSaving(true);
    try {
      await onSave(edit);
      setEditing(false);
      await refresh();
    } catch (err) {
      setError(friendlyError(err, 'The prompt could not be saved.'));
    } finally {
      setSaving(false);
    }
  }

  async function reset() {
    if (!onReset) return;
    setSaving(true);
    try {
      await onReset();
      setEditing(false);
      await refresh();
    } catch (err) {
      setError(friendlyError(err, 'The automatic prompt could not be restored.'));
    } finally {
      setSaving(false);
    }
  }

  const price = preview?.idempotent_repeat ? 'no charge' : formatEstimate(preview?.estimate);
  const button = preview?.idempotent_repeat ? 'Show existing generation (no charge)' : `Generate (${price})`;
  const final = preview?.prompt?.final || '';
  const limit = preview?.prompt?.limit;

  return (
    <div className="studio-inline-panel border rounded p-3 mb-2" role="dialog" aria-label={title}>
      <p className="fw-semibold mb-2">{title}</p>
      {!preview && !error ? <p className="small mb-2">Checking the prompt and price…</p> : null}
      {error ? <p className="small text-danger mb-2">{error}</p> : null}
      {preview ? (
        <>
          <p className="small mb-2">
            {preview.provider?.label || 'Video service'}
            {preview.model ? ` · ${preview.model}` : ''}
            {preview.capability ? ` · ${preview.capability.replaceAll('_', ' ')}` : ''}
            {preview.duration_seconds ? ` · ${preview.duration_seconds}s` : ''}
            {preview.aspect_ratio ? ` · ${preview.aspect_ratio}` : ''}
          </p>
          {preview.routing?.matched === false ? (
            <p className="small mb-2">No video service can make this yet. Nothing will be sent.</p>
          ) : null}
          <p className="small mb-1">
            Prompt
            {limit ? ` · ${preview.prompt.length}/${limit}` : ` · ${preview.prompt.length} characters`}
          </p>
          <pre className="small border rounded p-2 mb-2" style={{ whiteSpace: 'pre-wrap' }}>
            {preview.prompt.lock_block && final.includes(preview.prompt.lock_block) ? (
              <>
                {final.slice(0, final.indexOf(preview.prompt.lock_block))}
                <mark>{preview.prompt.lock_block}</mark>
              </>
            ) : (
              final
            )}
          </pre>
          {preview.prompt.trimmed?.length ? (
            <p className="small text-secondary mb-2">Shortened to fit: {preview.prompt.trimmed.join(', ')}.</p>
          ) : null}
          {preview.warnings?.includes('edit_based_on_old_scene_text') ? (
            <p className="small mb-2">This saved text is based on an older scene. The automatic text is now: {preview.prompt.automatic_base}</p>
          ) : null}
          {preview.warnings?.includes('references_not_supported_by_mode') ? (
            <p className="small mb-2">Only the text lock is used in this mode.</p>
          ) : null}
          {preview.locks?.length ? (
            <ul className="list-unstyled small mb-2">
              {preview.locks.map((lock) => (
                <li key={lock.character_uuid} className="mb-1">
                  <StatusBadge tone={lock.sent_as === 'not_sent' ? 'warning' : 'success'}>{lock.name}</StatusBadge>{' '}
                  {SENT[lock.sent_as] || lock.sent_as}
                  {lock.reason === 'too_many' ? ' · too many locked characters for one scene' : ''}
                </li>
              ))}
            </ul>
          ) : null}
          <p className="small mb-2">
            <span className="fw-semibold">Estimated price: </span>
            {preview.idempotent_repeat ? 'No charge. This generation already exists.' : formatEstimate(preview.estimate)}
            {preview.budget?.remaining != null ? ` · Remaining budget: ${preview.budget.remaining}` : ''}
          </p>
          {preview.budget?.would_exceed ? (
            <p className="small text-danger mb-2">This would go over the project budget, so nothing will be sent.</p>
          ) : null}
          {allowEdit && editing ? (
            <div className="mb-2">
              <label className="form-label small" htmlFor="prompt-edit">
                Scene part
              </label>
              <textarea id="prompt-edit" className="form-control" rows={4} value={edit} onChange={(event) => setEdit(event.target.value)} />
              <div className="d-flex gap-2 mt-2">
                <BusyButton type="button" className="btn btn-outline-primary btn-sm" busy={saving} busyLabel="Saving…" onClick={save}>
                  Save
                </BusyButton>
                <button type="button" className="btn btn-link btn-sm" onClick={reset} disabled={saving}>
                  Reset to automatic
                </button>
              </div>
            </div>
          ) : null}
          {allowEdit && !editing ? (
            <button type="button" className="btn btn-link btn-sm px-0 mb-2" onClick={() => setEditing(true)}>
              Edit scene part
            </button>
          ) : null}
        </>
      ) : null}
      <div className="d-flex gap-2">
        <BusyButton type="button" className="btn btn-primary btn-sm" busy={busy} busyLabel="Sending…" onClick={generate} disabled={!preview || busy}>
          {button}
        </BusyButton>
        <button type="button" className="btn btn-link btn-sm" onClick={onCancel} disabled={busy}>
          Cancel
        </button>
      </div>
    </div>
  );
}
