export default function LoadingSpinner({ label = 'Loading…', fullPage = false }) {
  const spinner = (
    <div className="d-flex flex-column align-items-center justify-content-center gap-3 py-5" role="status">
      <div className="spinner-border text-primary" aria-hidden="true" />
      <span className="text-secondary">{label}</span>
    </div>
  );

  if (!fullPage) {
    return spinner;
  }

  return <div className="app-loading-screen">{spinner}</div>;
}
