import { createContext, useCallback, useContext, useMemo, useRef, useState } from 'react';

export const StudioContext = createContext(null);

/** Shared project data and navigation for every studio step. */
export function useStudio() {
  const value = useContext(StudioContext);
  if (!value) {
    throw new Error('useStudio must be used inside the Creative Studio.');
  }
  return value;
}

const FeedbackContext = createContext(null);

const AUTO_DISMISS_MS = 6000;

/**
 * The one place studio steps report success and failure. Messages are pinned to
 * the viewport so they are always visible, and announced to screen readers.
 */
export function StudioFeedbackProvider({ children }) {
  const [messages, setMessages] = useState([]);
  const nextId = useRef(0);

  const dismiss = useCallback((id) => {
    setMessages((list) => list.filter((item) => item.id !== id));
  }, []);

  const notify = useCallback(
    (tone, text) => {
      if (!text) return;
      nextId.current += 1;
      const id = nextId.current;
      setMessages((list) => [...list.filter((item) => item.text !== text).slice(-2), { id, tone, text }]);
      if (tone !== 'error') {
        window.setTimeout(() => dismiss(id), AUTO_DISMISS_MS);
      }
    },
    [dismiss],
  );

  const value = useMemo(
    () => ({
      success: (text) => notify('success', text),
      error: (text) => notify('error', text),
      info: (text) => notify('info', text),
    }),
    [notify],
  );

  const polite = messages.filter((item) => item.tone !== 'error');
  const urgent = messages.filter((item) => item.tone === 'error');

  return (
    <FeedbackContext.Provider value={value}>
      {children}
      <div className="studio-toasts">
        <div role="alert" aria-live="assertive">
          {urgent.map((item) => (
            <Toast key={item.id} item={item} onDismiss={dismiss} />
          ))}
        </div>
        <div role="status" aria-live="polite">
          {polite.map((item) => (
            <Toast key={item.id} item={item} onDismiss={dismiss} />
          ))}
        </div>
      </div>
    </FeedbackContext.Provider>
  );
}

const TONE_ICONS = {
  success: 'bi-check-circle-fill',
  error: 'bi-exclamation-triangle-fill',
  info: 'bi-info-circle-fill',
};

function Toast({ item, onDismiss }) {
  return (
    <div className={`studio-toast studio-toast--${item.tone}`}>
      <i className={`bi ${TONE_ICONS[item.tone]}`} aria-hidden="true" />
      <span className="studio-toast-text">{item.text}</span>
      <button type="button" className="btn-close btn-sm" aria-label="Dismiss message" onClick={() => onDismiss(item.id)} />
    </div>
  );
}

export function useFeedback() {
  const value = useContext(FeedbackContext);
  if (!value) {
    throw new Error('useFeedback must be used inside StudioFeedbackProvider.');
  }
  return value;
}
