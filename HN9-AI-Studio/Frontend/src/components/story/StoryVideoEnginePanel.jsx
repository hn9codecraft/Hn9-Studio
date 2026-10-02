import { useEffect, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { storyCapabilityLabel } from '../../services/storyConstants';
import {
  generateStoryVideo,
  getStoryVideoCapabilities,
  getStoryVideoFileUrl,
  getStoryVideoJob,
  getStoryVideoProviders,
  listStoryCharacterReferences,
  listStoryCharacters,
  listStoryStyleReferences,
  validateStoryVideoCompatibility,
} from '../../services/storyService';

const GENERATE_MODES = ['text_to_video', 'image_to_video', 'reference_to_video'];
const DURATION_PRESETS = [
  { value: '30', label: '30 seconds' },
  { value: '60', label: '60 seconds' },
  { value: '90', label: '90 seconds' },
  { value: '120', label: '2 minutes' },
  { value: '300', label: '5 minutes' },
  { value: 'custom', label: 'Custom' },
];

export default function StoryVideoEnginePanel({ projectId }) {
  const [capabilities, setCapabilities] = useState([]);
  const [providers, setProviders] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [capability, setCapability] = useState('text_to_video');
  const [generateMode, setGenerateMode] = useState('text_to_video');
  const [duration, setDuration] = useState(30);
  const [aspectRatio, setAspectRatio] = useState('9:16');
  const [audioRequested, setAudioRequested] = useState(false);
  const [validation, setValidation] = useState(null);
  const [busy, setBusy] = useState(false);
  const [prompt, setPrompt] = useState('');
  const [durationChoice, setDurationChoice] = useState('30');
  const [customDuration, setCustomDuration] = useState(45);
  const [assetId, setAssetId] = useState('');
  const [assets, setAssets] = useState([]);
  const [job, setJob] = useState(null);
  const [generating, setGenerating] = useState(false);
  const [openingFile, setOpeningFile] = useState(false);

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

  useEffect(() => {
    if (!projectId) return undefined;
    let cancelled = false;
    async function loadAssets() {
      try {
        const [characters, styleRefs] = await Promise.all([
          listStoryCharacters(projectId),
          listStoryStyleReferences(projectId),
        ]);
        const characterGroups = await Promise.all(
          characters.map(async (character) => {
            const refs = await listStoryCharacterReferences(projectId, character.id);
            return refs.map((ref) => ({
              id: ref.id,
              kind: 'character',
              label: `${character.name} · v${ref.version}`,
            }));
          }),
        );
        const styleOptions = styleRefs.map((ref) => ({
          id: ref.id,
          kind: 'style',
          label: `Style reference · v${ref.version}`,
        }));
        if (!cancelled) {
          setAssets([...characterGroups.flat(), ...styleOptions]);
        }
      } catch {
        if (!cancelled) setAssets([]);
      }
    }
    loadAssets();
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  useEffect(() => {
    if (!projectId || !job?.id) return undefined;
    if (!['queued', 'submitted', 'processing'].includes(job.status)) return undefined;
    let cancelled = false;
    const timer = setInterval(async () => {
      try {
        const next = await getStoryVideoJob(projectId, job.id);
        if (!cancelled && next?.job) setJob(next.job);
      } catch {
        // Keep the last known status. The refresh button can retry.
      }
    }, 4000);
    return () => {
      cancelled = true;
      clearInterval(timer);
    };
  }, [projectId, job?.id, job?.status]);

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

  const generationModes = capabilities.filter((item) => GENERATE_MODES.includes(item.capability));
  const durationSeconds = durationChoice === 'custom' ? Number(customDuration) : Number(durationChoice);
  const assetChoices = assets.filter((item) => (
    generateMode === 'reference_to_video' ? true : item.kind === 'character'
  ));

  async function handleGenerate(event) {
    event.preventDefault();
    if (!projectId) return;
    setGenerating(true);
    setError('');
    setMessage('');
    try {
      const payload = {
        capability: generateMode,
        duration_seconds: durationSeconds,
        aspect_ratio: aspectRatio,
        prompt,
        idempotency_key: crypto.randomUUID(),
      };
      if (generateMode === 'image_to_video') {
        payload.inputs = [{ type: 'image', asset_id: assetId, order: 0 }];
      }
      if (generateMode === 'reference_to_video') {
        payload.inputs = [{ type: 'reference_image', asset_id: assetId, order: 0 }];
      }
      const result = await generateStoryVideo(projectId, payload);
      setJob(result?.job || null);
      setMessage(result?.created === false ? 'Existing generation job reused.' : 'Generation job submitted.');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to start video generation.');
    } finally {
      setGenerating(false);
    }
  }

  async function handleRefreshStatus() {
    if (!projectId || !job?.id) return;
    setError('');
    try {
      const next = await getStoryVideoJob(projectId, job.id);
      setJob(next?.job || job);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to refresh job status.');
    }
  }

  async function handleOpenFile() {
    if (!projectId || !job?.id) return;
    setOpeningFile(true);
    setError('');
    try {
      const url = await getStoryVideoFileUrl(projectId, job.id);
      window.open(url, '_blank', 'noopener');
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Unable to open the stored video.');
    } finally {
      setOpeningFile(false);
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
          Start Text to Video, Image to Video, or Reference to Video for this project.
          Job status stays on the private story record.
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

          <div className="card border-0 glass-card mb-3">
            <div className="card-body">
              <h3 className="h5 mb-3">Generate video</h3>
              <form className="row g-3" onSubmit={handleGenerate}>
                <div className="col-md-6">
                  <label className="form-label" htmlFor="ve-generate-mode">Generation mode</label>
                  <select
                    id="ve-generate-mode"
                    className="form-select"
                    value={generateMode}
                    onChange={(e) => {
                      setGenerateMode(e.target.value);
                      setAssetId('');
                    }}
                  >
                    {generationModes.map((item) => (
                      <option key={item.capability} value={item.capability}>
                        {item.label || storyCapabilityLabel(item.capability)}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="col-md-3">
                  <label className="form-label" htmlFor="ve-generate-duration">Target length</label>
                  <select
                    id="ve-generate-duration"
                    className="form-select"
                    value={durationChoice}
                    onChange={(e) => setDurationChoice(e.target.value)}
                  >
                    {DURATION_PRESETS.map((item) => (
                      <option key={item.value} value={item.value}>{item.label}</option>
                    ))}
                  </select>
                </div>
                <div className="col-md-3">
                  <label className="form-label" htmlFor="ve-generate-aspect">Aspect</label>
                  <select
                    id="ve-generate-aspect"
                    className="form-select"
                    value={aspectRatio}
                    onChange={(e) => setAspectRatio(e.target.value)}
                  >
                    <option value="16:9">16:9</option>
                    <option value="9:16">9:16</option>
                    <option value="1:1">1:1</option>
                  </select>
                </div>
                {durationChoice === 'custom' ? (
                  <div className="col-md-4">
                    <label className="form-label" htmlFor="ve-custom-duration">Custom seconds</label>
                    <input
                      id="ve-custom-duration"
                      type="number"
                      min={1}
                      max={3600}
                      className="form-control"
                      value={customDuration}
                      onChange={(e) => setCustomDuration(e.target.value)}
                    />
                  </div>
                ) : null}
                {generateMode !== 'text_to_video' ? (
                  <div className="col-12">
                    <label className="form-label" htmlFor="ve-asset">
                      {generateMode === 'image_to_video' ? 'Character image' : 'Reference'}
                    </label>
                    <select
                      id="ve-asset"
                      className="form-select"
                      value={assetId}
                      onChange={(e) => setAssetId(e.target.value)}
                      required
                    >
                      <option value="">Select a project asset</option>
                      {assetChoices.map((item) => (
                        <option key={`${item.kind}-${item.id}`} value={item.id}>{item.label}</option>
                      ))}
                    </select>
                  </div>
                ) : null}
                <div className="col-12">
                  <label className="form-label" htmlFor="ve-prompt">Prompt</label>
                  <textarea
                    id="ve-prompt"
                    className="form-control"
                    rows={3}
                    value={prompt}
                    onChange={(e) => setPrompt(e.target.value)}
                    required
                  />
                </div>
                <div className="col-12 d-flex flex-wrap gap-2">
                  <button
                    type="submit"
                    className="btn btn-primary"
                    disabled={generating || !projectId || !prompt.trim() || (generateMode !== 'text_to_video' && !assetId)}
                  >
                    {generating ? 'Starting…' : 'Generate video'}
                  </button>
                </div>
              </form>
              {job ? (
                <div className="mt-3 small">
                  <p className="mb-1">
                    Status: {job.recovering ? 'recovering' : job.status || 'unknown'}
                    {job.capability ? ` · ${storyCapabilityLabel(job.capability)}` : ''}
                  </p>
                  {job.recovering ? (
                    <p className="mb-1 text-warning">
                      The last status check failed. Checking the same job again
                      {job.max_attempts ? ` (failed check ${job.failed_checks} of ${job.max_attempts}).` : '.'}
                    </p>
                  ) : null}
                  {job.error_message && job.status === 'failed' ? (
                    <p className="mb-1 text-danger">{job.error_message}</p>
                  ) : null}
                  {job.error_message && job.recovering ? (
                    <p className="mb-1 text-secondary">{job.error_message}</p>
                  ) : null}
                  <div className="d-flex flex-wrap gap-2">
                    <button type="button" className="btn btn-outline-primary btn-sm" onClick={handleRefreshStatus}>
                      Refresh status
                    </button>
                    {job.status === 'completed' ? (
                      <button type="button" className="btn btn-outline-primary btn-sm" onClick={handleOpenFile} disabled={openingFile}>
                        {openingFile ? 'Opening…' : 'Open stored video'}
                      </button>
                    ) : null}
                  </div>
                </div>
              ) : null}
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
