import { useEffect, useRef, useState } from 'react';
import { friendlyError } from '../../services/studioMessages';
import { useFeedback, useStudio } from './StudioContext';
import { BusyButton, StatusBadge, StoredPicture } from './StudioUi';

const ACCEPT = 'image/png,image/jpeg,image/webp';

function pictureStatus(reference) {
  if (reference.status === 'approved') {
    return reference.is_approved_current ? { tone: 'success', text: 'In use' } : { tone: 'neutral', text: 'Approved earlier' };
  }
  if (reference.status === 'pending_review') return { tone: 'progress', text: 'Waiting for approval' };
  if (reference.status === 'rejected') return { tone: 'warning', text: 'Not used' };
  return { tone: 'neutral', text: 'Not approved yet' };
}

/**
 * Upload, create, approve and remove reference pictures for a character or the
 * look & feel. `api` adapts the character/style endpoints to one shape.
 */
export default function StudioReferencePictures({ subject, api, onChanged }) {
  const { connections, canApprove } = useStudio();
  const feedback = useFeedback();
  const fileInput = useRef(null);
  const [references, setReferences] = useState(null);
  const [busy, setBusy] = useState('');
  const [rejecting, setRejecting] = useState(null);
  const [rejectNote, setRejectNote] = useState('');
  const canGenerate = Boolean(connections?.reference_images);

  useEffect(() => {
    let cancelled = false;
    api
      .list()
      .then((items) => {
        if (!cancelled) setReferences(items);
      })
      .catch((err) => {
        if (!cancelled) {
          setReferences([]);
          feedback.error(friendlyError(err, 'Pictures could not be loaded. Please try again.'));
        }
      });
    return () => {
      cancelled = true;
    };
    // `api` is rebuilt by the parent on every render; `api.key` identifies it.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [api.key]);

  async function run(key, action, success, area = null) {
    setBusy(key);
    try {
      await action();
      setReferences(await api.list());
      onChanged?.();
      if (success) feedback.success(success);
    } catch (err) {
      feedback.error(friendlyError(err, 'That did not work. Please try again.', area));
    } finally {
      setBusy('');
    }
  }

  function upload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    run(
      'upload',
      () => api.upload(file),
      canApprove ? 'Picture added. Choose “Use this picture” to approve it.' : 'Picture added. Send it for approval to use it.',
    );
  }

  function useThis(reference) {
    run(
      `use-${reference.id}`,
      async () => {
        if (reference.status !== 'pending_review') await api.submit(reference.id);
        await api.approve(reference.id);
      },
      `This picture is now used for ${subject}.`,
    );
  }

  const visible = (references || []).filter((reference) => reference.status !== 'archived');

  return (
    <div className="studio-pictures">
      <div className="d-flex flex-wrap align-items-center gap-2 mb-3">
        <input ref={fileInput} type="file" accept={ACCEPT} className="d-none" tabIndex={-1} aria-hidden="true" onChange={upload} />
        <BusyButton
          className="btn btn-outline-primary btn-sm"
          busy={busy === 'upload'}
          busyLabel="Uploading…"
          disabled={Boolean(busy)}
          onClick={() => fileInput.current?.click()}
        >
          <i className="bi bi-upload me-1" aria-hidden="true" />
          Upload a picture
        </BusyButton>
        {canGenerate ? (
          <BusyButton
            className="btn btn-outline-primary btn-sm"
            busy={busy === 'generate'}
            busyLabel="Creating…"
            disabled={Boolean(busy)}
            onClick={() => run('generate', () => api.generate(), 'A new picture was created. Review it below.', 'images')}
          >
            <i className="bi bi-stars me-1" aria-hidden="true" />
            Create a picture with AI
          </BusyButton>
        ) : (
          <span className="small text-secondary">AI picture creation is not connected, so upload a JPG, PNG or WebP picture.</span>
        )}
      </div>

      {references === null ? <p className="small text-secondary mb-0">Loading pictures…</p> : null}

      {references !== null && visible.length === 0 ? (
        <p className="small text-secondary mb-0">No pictures yet. Add one so {subject} looks the same in every scene.</p>
      ) : null}

      {visible.length > 0 ? (
        <ul className="studio-picture-grid list-unstyled mb-0">
          {visible.map((reference) => {
            const status = pictureStatus(reference);
            const isCurrent = reference.status === 'approved' && reference.is_approved_current;
            const canUse = canApprove && (reference.status === 'draft' || reference.status === 'pending_review');
            return (
              <li key={reference.id} className={`studio-picture-card${isCurrent ? ' is-current' : ''}`}>
                <StoredPicture load={() => api.file(reference.id)} pictureKey={reference.id} alt={`Picture ${reference.version} of ${subject}`} />
                <div className="d-flex flex-wrap justify-content-between align-items-center gap-1 mt-2">
                  <span className="small fw-semibold">Picture {reference.version}</span>
                  <StatusBadge tone={status.tone}>{status.text}</StatusBadge>
                </div>
                {reference.review_comment && reference.status === 'rejected' ? (
                  <p className="small text-secondary mb-0 mt-1">“{reference.review_comment}”</p>
                ) : null}
                <div className="d-flex flex-wrap gap-2 mt-2">
                  {canUse ? (
                    <BusyButton
                      className="btn btn-primary btn-sm"
                      busy={busy === `use-${reference.id}`}
                      busyLabel="Saving…"
                      disabled={Boolean(busy)}
                      onClick={() => useThis(reference)}
                    >
                      Use this picture
                    </BusyButton>
                  ) : null}
                  {!canApprove && reference.status === 'draft' ? (
                    <BusyButton
                      className="btn btn-primary btn-sm"
                      busy={busy === `submit-${reference.id}`}
                      disabled={Boolean(busy)}
                      onClick={() => run(`submit-${reference.id}`, () => api.submit(reference.id), 'Sent to the project owner for approval.')}
                    >
                      Send for approval
                    </BusyButton>
                  ) : null}
                  {!canApprove && reference.status === 'pending_review' ? (
                    <span className="small text-secondary">The project owner will review it.</span>
                  ) : null}
                  {canApprove && reference.status === 'pending_review' && rejecting !== reference.id ? (
                    <button
                      type="button"
                      className="btn btn-outline-secondary btn-sm"
                      disabled={Boolean(busy)}
                      onClick={() => {
                        setRejecting(reference.id);
                        setRejectNote('');
                      }}
                    >
                      Don’t use
                    </button>
                  ) : null}
                  {!isCurrent && reference.status !== 'pending_review' ? (
                    <BusyButton
                      className="btn btn-link btn-sm text-danger px-0"
                      busy={busy === `remove-${reference.id}`}
                      busyLabel="Removing…"
                      disabled={Boolean(busy)}
                      onClick={() => run(`remove-${reference.id}`, () => api.archive(reference.id), 'Picture removed.')}
                    >
                      Remove
                    </BusyButton>
                  ) : null}
                </div>
                {rejecting === reference.id ? (
                  <form
                    className="mt-2 d-grid gap-2"
                    onSubmit={(event) => {
                      event.preventDefault();
                      run(`reject-${reference.id}`, () => api.reject(reference.id, rejectNote.trim()), 'Picture set aside.').then(() =>
                        setRejecting(null),
                      );
                    }}
                  >
                    <label className="visually-hidden" htmlFor={`reject-${reference.id}`}>
                      What is wrong with this picture?
                    </label>
                    <input
                      id={`reject-${reference.id}`}
                      className="form-control form-control-sm"
                      placeholder="What is wrong with it? (required)"
                      value={rejectNote}
                      onChange={(event) => setRejectNote(event.target.value)}
                      required
                    />
                    <div className="d-flex gap-2">
                      <BusyButton
                        type="submit"
                        className="btn btn-outline-danger btn-sm"
                        busy={busy === `reject-${reference.id}`}
                        disabled={!rejectNote.trim()}
                      >
                        Set aside
                      </BusyButton>
                      <button type="button" className="btn btn-link btn-sm" onClick={() => setRejecting(null)}>
                        Cancel
                      </button>
                    </div>
                  </form>
                ) : null}
              </li>
            );
          })}
        </ul>
      ) : null}
    </div>
  );
}
