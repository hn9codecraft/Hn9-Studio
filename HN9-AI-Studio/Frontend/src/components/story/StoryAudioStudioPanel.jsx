import { useEffect, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import {
  createStorySceneAudio,
  getStorySceneAudio,
  getStorySceneAudioFileUrl,
  getStoryAudioRoles,
  listStoryReels,
  listStorySceneAudio,
  listStoryScenes,
} from '../../services/storyService';

export default function StoryAudioStudioPanel({ projectId }) {
  const [roles, setRoles] = useState([]);
  const [reels, setReels] = useState([]);
  const [scenes, setScenes] = useState([]);
  const [items, setItems] = useState([]);
  const [reelId, setReelId] = useState('');
  const [sceneId, setSceneId] = useState('');
  const [role, setRole] = useState('voice');
  const [prompt, setPrompt] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [selected, setSelected] = useState(null);

  useEffect(() => {
    let cancelled = false;
    async function load() {
      setLoading(true);
      setError('');
      try {
        const [roleList, reelList] = await Promise.all([
          getStoryAudioRoles(),
          listStoryReels(projectId),
        ]);
        if (!cancelled) {
          setRoles(Array.isArray(roleList) ? roleList : []);
          setReels(Array.isArray(reelList) ? reelList : []);
          if (Array.isArray(roleList) && roleList.length > 0) {
            setRole(roleList[0].role);
          }
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load Audio Studio.');
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    load();
    return () => {
      cancelled = true;
    };
  }, [projectId]);

  useEffect(() => {
    if (!projectId || !reelId) {
      setScenes([]);
      setSceneId('');
      return undefined;
    }
    let cancelled = false;
    async function loadScenes() {
      try {
        const list = await listStoryScenes(projectId, reelId);
        if (!cancelled) {
          setScenes(Array.isArray(list) ? list : []);
          setSceneId('');
          setItems([]);
          setSelected(null);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load scenes.');
        }
      }
    }
    loadScenes();
    return () => {
      cancelled = true;
    };
  }, [projectId, reelId]);

  useEffect(() => {
    if (!projectId || !reelId || !sceneId) {
      setItems([]);
      return undefined;
    }
    let cancelled = false;
    async function loadAudio() {
      try {
        const list = await listStorySceneAudio(projectId, reelId, sceneId);
        if (!cancelled) {
          setItems(Array.isArray(list) ? list : []);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load scene audio.');
        }
      }
    }
    loadAudio();
    return () => {
      cancelled = true;
    };
  }, [projectId, reelId, sceneId]);

  async function handleCreate(nextRole) {
    if (!reelId || !sceneId) {
      setError('Select a reel and scene first.');
      return;
    }
    setBusy(true);
    setError('');
    setMessage('');
    try {
      const result = await createStorySceneAudio(projectId, reelId, sceneId, {
        role: nextRole || role,
        prompt,
      });
      setSelected(result);
      setMessage(
        result?.created === false
          ? `${result.role_label || result.role} request reused an existing job.`
          : `${result.role_label || result.role} audio request submitted.`,
      );
      const list = await listStorySceneAudio(projectId, reelId, sceneId);
      setItems(Array.isArray(list) ? list : []);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to create scene audio.');
    } finally {
      setBusy(false);
    }
  }

  async function handleRefresh(audioId) {
    setBusy(true);
    setError('');
    try {
      const status = await getStorySceneAudio(projectId, reelId, sceneId, audioId);
      setSelected(status);
      const list = await listStorySceneAudio(projectId, reelId, sceneId);
      setItems(Array.isArray(list) ? list : []);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to refresh audio status.');
    } finally {
      setBusy(false);
    }
  }

  async function handleOpenFile(audioId) {
    setBusy(true);
    setError('');
    try {
      const url = await getStorySceneAudioFileUrl(projectId, reelId, sceneId, audioId);
      window.open(url, '_blank', 'noopener,noreferrer');
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to open the private audio file.');
    } finally {
      setBusy(false);
    }
  }

  if (loading) {
    return <LoadingSpinner label="Loading Audio Studio…" />;
  }

  return (
    <div className="story-audio-studio">
      <div className="mb-3">
        <h2 className="h5 mb-1">Audio Studio</h2>
        <p className="text-secondary small mb-0">
          Create scene audio by role. Actions use role names only — not provider brands.
        </p>
      </div>

      {error ? (
        <div className="mb-3">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}
      {message ? (
        <div className="mb-3">
          <AlertMessage variant="success">{message}</AlertMessage>
        </div>
      ) : null}

      <div className="row g-3 mb-4">
        <div className="col-12 col-md-4">
          <label className="form-label" htmlFor="audio-reel">
            Reel
          </label>
          <select
            id="audio-reel"
            className="form-select"
            value={reelId}
            onChange={(event) => setReelId(event.target.value)}
          >
            <option value="">Select a reel</option>
            {reels.map((reel) => (
              <option key={reel.id} value={reel.id}>
                {reel.title || reel.id}
              </option>
            ))}
          </select>
        </div>
        <div className="col-12 col-md-4">
          <label className="form-label" htmlFor="audio-scene">
            Scene
          </label>
          <select
            id="audio-scene"
            className="form-select"
            value={sceneId}
            onChange={(event) => setSceneId(event.target.value)}
            disabled={!reelId}
          >
            <option value="">Select a scene</option>
            {scenes.map((scene) => (
              <option key={scene.id} value={scene.id}>
                {scene.title || scene.id}
              </option>
            ))}
          </select>
        </div>
        <div className="col-12 col-md-4">
          <label className="form-label" htmlFor="audio-role">
            Role
          </label>
          <select
            id="audio-role"
            className="form-select"
            value={role}
            onChange={(event) => setRole(event.target.value)}
          >
            {roles.map((item) => (
              <option key={item.role} value={item.role}>
                {item.label || item.role}
              </option>
            ))}
          </select>
        </div>
      </div>

      <div className="mb-3">
        <label className="form-label" htmlFor="audio-prompt">
          Prompt
        </label>
        <textarea
          id="audio-prompt"
          className="form-control"
          rows={3}
          value={prompt}
          onChange={(event) => setPrompt(event.target.value)}
          placeholder="Describe the voice, narration, music, or effect for this scene"
        />
      </div>

      <div className="d-flex flex-wrap gap-2 mb-4">
        {roles.map((item) => (
          <button
            key={item.role}
            type="button"
            className={`btn btn-sm ${role === item.role ? 'btn-primary' : 'btn-outline-primary'}`}
            disabled={busy || !sceneId || !prompt.trim()}
            onClick={() => {
              setRole(item.role);
              handleCreate(item.role);
            }}
          >
            {item.label || item.role}
          </button>
        ))}
      </div>

      <h3 className="h6 mb-2">Scene audio by role</h3>
      {items.length === 0 ? (
        <p className="text-secondary small">No audio records for this scene yet.</p>
      ) : (
        <ul className="list-unstyled mb-0">
          {items.map((item) => (
            <li key={item.id} className="border-bottom py-2">
              <div className="d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                  <strong>{item.role_label || item.role}</strong>
                  <span className="text-secondary small ms-2">{item.status}</span>
                  {item.has_file ? (
                    <span className="badge text-bg-secondary ms-2">Private file</span>
                  ) : null}
                  <p className="small text-secondary mb-0">{item.prompt || '—'}</p>
                </div>
                <div className="d-flex gap-2">
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary"
                    disabled={busy}
                    onClick={() => handleRefresh(item.id)}
                  >
                    Status
                  </button>
                  <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary"
                    disabled={busy || !item.has_file}
                    onClick={() => handleOpenFile(item.id)}
                  >
                    Open file
                  </button>
                </div>
              </div>
            </li>
          ))}
        </ul>
      )}

      {selected ? (
        <p className="small text-secondary mt-3 mb-0">
          Selected: {selected.role_label || selected.role} · {selected.status}
          {selected.output_url ? '' : ' · output_url null'}
        </p>
      ) : null}
    </div>
  );
}
