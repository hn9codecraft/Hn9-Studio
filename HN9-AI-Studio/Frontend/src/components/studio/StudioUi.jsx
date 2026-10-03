import { useEffect, useState } from 'react';
import { NOT_CONNECTED } from '../../services/studioMessages';

export function StepHeader({ title, purpose, children = null }) {
  return (
    <div className="studio-step-header">
      <div className="studio-step-header-text">
        <h2 className="section-title h4 mb-1">{title}</h2>
        <p className="text-secondary mb-0">{purpose}</p>
      </div>
      {children ? <div className="studio-step-header-actions">{children}</div> : null}
    </div>
  );
}

/** Back / next navigation shared by every step; `primary` replaces the plain next link. */
export function StepFooter({ prev = null, next = null, onNavigate, primary = null }) {
  return (
    <div className="studio-step-footer">
      {prev ? (
        <button type="button" className="btn btn-outline-secondary" onClick={() => onNavigate(prev.key)}>
          <i className="bi bi-arrow-left me-1" aria-hidden="true" />
          Back: {prev.label}
        </button>
      ) : (
        <span />
      )}
      {primary ||
        (next ? (
          <button type="button" className="btn btn-primary" onClick={() => onNavigate(next.key)}>
            Next: {next.label}
            <i className="bi bi-arrow-right ms-1" aria-hidden="true" />
          </button>
        ) : null)}
    </div>
  );
}

const TONES = {
  neutral: 'studio-badge--neutral',
  progress: 'studio-badge--progress',
  success: 'studio-badge--success',
  warning: 'studio-badge--warning',
  danger: 'studio-badge--danger',
};

export function StatusBadge({ tone = 'neutral', children }) {
  return <span className={`studio-badge ${TONES[tone] || TONES.neutral}`}>{children}</span>;
}

export function reviewTone(status) {
  if (status === 'approved') return 'success';
  if (status === 'pending_review') return 'progress';
  if (status === 'needs_rework' || status === 'rejected') return 'warning';
  return 'neutral';
}

export function jobTone(status) {
  if (status === 'completed') return 'success';
  if (status === 'failed' || status === 'cancelled') return 'danger';
  if (status === 'not_connected') return 'warning';
  if (status) return 'progress';
  return 'neutral';
}

export function NotConnectedNote({ area, className = '' }) {
  return (
    <div className={`studio-note studio-note--warning ${className}`}>
      <i className="bi bi-plug" aria-hidden="true" />
      <span>{NOT_CONNECTED[area]}</span>
    </div>
  );
}

export function BusyButton({ busy = false, busyLabel = 'Working…', children, className = 'btn btn-primary', disabled = false, ...props }) {
  return (
    <button type="button" className={className} disabled={busy || disabled} aria-busy={busy || undefined} {...props}>
      {busy ? (
        <>
          <span className="spinner-border spinner-border-sm me-2" aria-hidden="true" />
          {busyLabel}
        </>
      ) : (
        children
      )}
    </button>
  );
}

/** Renders a private picture through an authenticated loader that caches the object URL. */
export function StoredPicture({ load, pictureKey, alt, className = 'studio-picture' }) {
  const [state, setState] = useState({ src: '', failed: false });

  useEffect(() => {
    let cancelled = false;
    load()
      .then((src) => {
        if (!cancelled) setState({ src, failed: false });
      })
      .catch(() => {
        if (!cancelled) setState({ src: '', failed: true });
      });
    return () => {
      cancelled = true;
    };
    // The loader is recreated every render; `pictureKey` identifies the file it loads.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pictureKey]);

  if (state.src) {
    return <img src={state.src} alt={alt} className={className} />;
  }

  return (
    <div className={`${className} studio-picture--placeholder`} role="img" aria-label={state.failed ? `${alt} could not be loaded` : `Loading ${alt}`}>
      <i className={`bi ${state.failed ? 'bi-image' : 'bi-hourglass-split'}`} aria-hidden="true" />
    </div>
  );
}

/** Loads a private video or sound only when asked, then plays it inline. */
export function MediaPreview({ load, kind = 'video', label }) {
  const [state, setState] = useState({ src: '', loading: false, failed: false });

  async function open() {
    setState({ src: '', loading: true, failed: false });
    try {
      setState({ src: await load(), loading: false, failed: false });
    } catch {
      setState({ src: '', loading: false, failed: true });
    }
  }

  if (state.src) {
    return kind === 'audio' ? (
      <audio controls src={state.src} className="studio-audio" aria-label={label} />
    ) : (
      <video controls src={state.src} className="studio-video" aria-label={label} />
    );
  }

  return (
    <span className="d-inline-flex align-items-center gap-2">
      <BusyButton className="btn btn-outline-primary btn-sm" busy={state.loading} busyLabel="Loading…" onClick={open}>
        <i className={`bi ${kind === 'audio' ? 'bi-play-circle' : 'bi-play-btn'} me-1`} aria-hidden="true" />
        {kind === 'audio' ? 'Play' : 'Watch'}
        <span className="visually-hidden"> {label}</span>
      </BusyButton>
      {state.failed ? <span className="small text-danger">It could not be loaded. Try again.</span> : null}
    </span>
  );
}

export function StepSkeleton({ rows = 3 }) {
  return (
    <div aria-busy="true" className="studio-step-skeleton">
      <span className="visually-hidden">Loading…</span>
      {Array.from({ length: rows }, (_, index) => (
        <div key={index} className="studio-skeleton-block studio-skeleton-card mb-3" />
      ))}
    </div>
  );
}
