import { useEffect, useId, useRef, useState } from 'react';
import { fieldError } from '../../services/scriptConstants';

export default function NeedsReworkModal({ open, submitting, error, onCancel, onConfirm }) {
  const titleId = useId();
  const commentId = useId();
  const inputRef = useRef(null);
  const [comment, setComment] = useState('');

  useEffect(() => {
    if (!open) {
      setComment('');
      return undefined;
    }

    const timer = window.setTimeout(() => inputRef.current?.focus(), 30);

    function onKey(event) {
      if (event.key === 'Escape' && !submitting) {
        onCancel();
      }
    }

    document.addEventListener('keydown', onKey);

    return () => {
      window.clearTimeout(timer);
      document.removeEventListener('keydown', onKey);
    };
  }, [open, submitting, onCancel]);

  if (!open) {
    return null;
  }

  return (
    <div className="modal-layer" role="presentation">
      <div className="modal-backdrop fade show" onClick={submitting ? undefined : onCancel} />
      <div className="modal fade show d-block" tabIndex="-1" role="dialog" aria-modal="true" aria-labelledby={titleId}>
        <div className="modal-dialog modal-dialog-centered">
          <div className="modal-content">
            <form
              onSubmit={(event) => {
                event.preventDefault();
                onConfirm(comment);
              }}
            >
              <div className="modal-header">
                <h2 className="modal-title h5" id={titleId}>
                  Request rework
                </h2>
                <button type="button" className="btn-close" aria-label="Close" onClick={onCancel} disabled={submitting} />
              </div>
              <div className="modal-body">
                <label className="form-label" htmlFor={commentId}>
                  Reviewer feedback
                </label>
                <textarea
                  ref={inputRef}
                  id={commentId}
                  className={`form-control ${fieldError(error, 'comment') ? 'is-invalid' : ''}`}
                  rows="5"
                  value={comment}
                  onChange={(event) => setComment(event.target.value)}
                  minLength={10}
                  required
                  disabled={submitting}
                  aria-describedby={`${commentId}-help`}
                />
                <div className="form-text" id={`${commentId}-help`}>
                  Required. Explain what the creator should change before resubmitting.
                </div>
                {fieldError(error, 'comment') ? <div className="invalid-feedback d-block">{fieldError(error, 'comment')}</div> : null}
              </div>
              <div className="modal-footer">
                <button type="button" className="btn btn-outline-secondary" onClick={onCancel} disabled={submitting}>
                  Cancel
                </button>
                <button type="submit" className="btn btn-primary" disabled={submitting || comment.trim().length < 10}>
                  {submitting ? 'Sending…' : 'Send back for rework'}
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  );
}
