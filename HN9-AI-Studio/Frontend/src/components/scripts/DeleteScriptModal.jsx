export default function DeleteScriptModal({ script, open, onCancel, onConfirm, deleting }) {
  if (!open) {
    return null;
  }

  return (
    <div className="modal-layer" role="presentation">
      <div className="modal-backdrop fade show" onClick={onCancel} />
      <div className="modal fade show d-block" tabIndex="-1" role="dialog" aria-modal="true" aria-labelledby="deleteScriptTitle">
        <div className="modal-dialog modal-dialog-centered">
          <div className="modal-content">
            <div className="modal-header">
              <h2 className="modal-title h5" id="deleteScriptTitle">
                Delete script
              </h2>
              <button type="button" className="btn-close" aria-label="Close" onClick={onCancel} />
            </div>
            <div className="modal-body">
              <p className="mb-0">
                Delete <strong>{script?.title}</strong>? This removes it from the project. This cannot be undone from
                this screen.
              </p>
            </div>
            <div className="modal-footer">
              <button type="button" className="btn btn-outline-secondary" onClick={onCancel} disabled={deleting}>
                Cancel
              </button>
              <button type="button" className="btn btn-danger" onClick={onConfirm} disabled={deleting}>
                {deleting ? 'Deleting…' : 'Delete script'}
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
