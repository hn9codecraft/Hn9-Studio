import { useEffect, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { storyCapabilityLabel } from '../../services/storyConstants';
import {
  getStoryVideoCapabilities,
  getStoryVideoProviders,
  validateStoryVideoCompatibility,
} from '../../services/storyService';

export default function StoryVideoEnginePanel({ projectId }) {
  const [capabilities, setCapabilities] = useState([]);
  const [providers, setProviders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [capability, setCapability] = useState('text_to_video');
  const [duration, setDuration] = useState(30);
  const [aspectRatio, setAspectRatio] = useState('9:16');
  const [audioRequested, setAudioRequested] = useState(false);
  const [validation, setValidation] = useState(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError('');
      try {
        const [caps, prov] = await Promise.all([
          getStoryVideoCapabilities(),
          getStoryVideoProviders(),
        ]);
        if (!cancelled) {
          setCapabilities(Array.isArray(caps) ? caps : []);
          setProviders(Array.isArray(prov) ? prov : []);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load video engine catalog.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, []);

  async function handleValidate(event) {
    event.preventDefault();
    if (!projectId) return;
    setBusy(true);
    setError('');
    setMessage('');
    try {
      const result = await validateStoryVideoCompatibility(projectId, {
        capability,
        duration_seconds: Number(duration),
        aspect_ratio: aspectRatio,
        audio_requested: audioRequested,
        prompt: 'Compatibility check only',
      });
      setValidation(result);
      setMessage(
        result?.compatible
          ? 'Request is compatible with the routed catalog provider.'
          : 'Request is not currently compatible.',
      );
    } catch (err) {
      setValidation(null);
      setError(err instanceof ApiError ? err.message : 'Unable to validate compatibility.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return <LoadingSpinner label="Loading Video Engine…" />;
  }

  return (
    <section className="story-video-engine mt-4" aria-label="Video Engine">
      <div className="mb-3">
        <h2 className="h4 mb-1">Video Engine</h2>
        <p className="text-secondary mb-0">
          Provider-independent capability foundation. This sprint shows availability and routing
          compatibility only — no video is generated.
        </p>
      </div>

      {error ? <AlertMessage className="mb-3">{error}</AlertMessage> : null}
      {message ? <p className="text-success small mb-3">{message}</p> : null}

      <div className="row g-4">
        <div className="col-12 col-lg-7">
          <div className="card border-0 glass-card mb-3">
            <div className="card-body">
              <h3 className="h5 mb-3">Video modes</h3>
              <div className="story-video-capability-grid">
                {capabilities.map((item) => (
                  <article key={item.capability} className="story-video-capability-card">
                    <div className="d-flex justify-content-between gap-2 mb-1">
                      <strong>{item.label || storyCapabilityLabel(item.capability)}</strong>
                      <span className={`badge ${item.available ? 'text-bg-success' : 'text-bg-secondary'}`}>
                        {item.available ? 'Available' : 'Unavailable'}
                      </span>
                    </div>
                    <p className="small text-secondary mb-1">
                      Durations: {(item.supported_durations || []).join(', ') || '—'}
                    </p>
                    <p className="small text-secondary mb-1">
                      Aspect ratios: {(item.supported_aspect_ratios || []).join(', ') || '—'}
                    </p>
                    <p className="small text-secondary mb-1">
                      Inputs: {(item.supported_input_types || []).join(', ') || '—'}
                    </p>
                    <p className="small mb-0">
                      Audio: {item.audio_supported ? 'Yes' : 'No'} · Async:{' '}
                      {item.async_supported ? 'Yes' : 'No'} · Download:{' '}
                      {item.download_supported ? 'Yes' : 'No'}
                    </p>
                  </article>
                ))}
              </div>
            </div>
          </div>

          <div className="card border-0 glass-card">
            <div className="card-body">
              <h3 className="h5 mb-3">Scene compatibility check</h3>
              <form className="row g-3" onSubmit={handleValidate}>
                <div className="col-md-6">
                  <label className="form-label" htmlFor="ve-capability">Mode</label>
                  <select
                    id="ve-capability"
                    className="form-select"
                    value={capability}
                    onChange={(e) => setCapability(e.target.value)}
                  >
                    {capabilities.map((item) => (
                      <option key={item.capability} value={item.capability}>
                        {item.label || storyCapabilityLabel(item.capability)}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="col-md-3">
                  <label className="form-label" htmlFor="ve-duration">Duration</label>
                  <input
                    id="ve-duration"
                    type="number"
                    min={1}
                    max={3600}
                    className="form-control"
                    value={duration}
                    onChange={(e) => setDuration(e.target.value)}
                  />
                </div>
                <div className="col-md-3">
                  <label className="form-label" htmlFor="ve-aspect">Aspect</label>
                  <select
                    id="ve-aspect"
                    className="form-select"
                    value={aspectRatio}
                    onChange={(e) => setAspectRatio(e.target.value)}
                  >
                    <option value="16:9">16:9</option>
                    <option value="9:16">9:16</option>
                    <option value="1:1">1:1</option>
                  </select>
                </div>
                <div className="col-12">
                  <div className="form-check">
                    <input
                      id="ve-audio"
                      className="form-check-input"
                      type="checkbox"
                      checked={audioRequested}
                      onChange={(e) => setAudioRequested(e.target.checked)}
                    />
                    <label className="form-check-label" htmlFor="ve-audio">
                      Audio requested
                    </label>
                  </div>
                </div>
                <div className="col-12">
                  <button type="submit" className="btn btn-primary" disabled={busy || !projectId}>
                    {busy ? 'Checking…' : 'Validate compatibility'}
                  </button>
                </div>
              </form>
              {validation ? (
                <div className="mt-3 small">
                  <p className="mb-1">
                    Compatible: {validation.compatible ? 'Yes' : 'No'}
                    {validation.routing?.provider ? ` · Routed: ${validation.routing.provider}` : ''}
                  </p>
                  {Array.isArray(validation.issues) && validation.issues.length > 0 ? (
                    <p className="mb-0 text-secondary">Issues: {validation.issues.join(', ')}</p>
                  ) : null}
                </div>
              ) : null}
            </div>
          </div>
        </div>

        <div className="col-12 col-lg-5">
          <div className="card border-0 glass-card">
            <div className="card-body">
              <h3 className="h5 mb-3">Registered providers</h3>
              <p className="small text-secondary mb-3">
                Catalog providers demonstrate multi-provider registration. Vendor product names are
                not exposed in this foundation UI.
              </p>
              {providers.map((provider) => (
                <article key={provider.key} className="story-video-provider-card mb-3">
                  <div className="d-flex justify-content-between gap-2">
                    <strong>{provider.label}</strong>
                    <span className="small">Priority {provider.priority}</span>
                  </div>
                  <p className="small text-secondary mb-1">{provider.key}</p>
                  <p className="small mb-0">
                    {provider.enabled ? 'Enabled' : 'Disabled'} ·{' '}
                    {provider.healthy ? 'Healthy' : 'Unavailable'} ·{' '}
                    {(provider.capabilities || []).length} capabilities ·{' '}
                    {(provider.models || []).length} models
                  </p>
                </article>
              ))}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}
