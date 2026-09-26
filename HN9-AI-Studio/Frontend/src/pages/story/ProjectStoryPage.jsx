import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import AlertMessage from '../../components/ui/AlertMessage';
import EmptyState from '../../components/ui/EmptyState';
import LoadingSpinner from '../../components/ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { listProjects } from '../../services/projectService';
import { statusLabel } from '../../services/projectConstants';
import { storyCapabilityLabel } from '../../services/storyConstants';
import StoryBibleForm from '../../components/story/StoryBibleForm';
import StoryCharactersPanel from '../../components/story/StoryCharactersPanel';
import StoryPlannerPanel from '../../components/story/StoryPlannerPanel';
import StoryReelsPanel from '../../components/story/StoryReelsPanel';
import StoryStylePanel from '../../components/story/StoryStylePanel';
import StoryVideoEnginePanel from '../../components/story/StoryVideoEnginePanel';
import StoryAudioStudioPanel from '../../components/story/StoryAudioStudioPanel';
import { getStoryEntry, getStoryWorkspace } from '../../services/storyService';

export default function ProjectStoryPage() {
  const { projectId } = useParams();
  const navigate = useNavigate();
  const [entry, setEntry] = useState(null);
  const [projects, setProjects] = useState([]);
  const [workspace, setWorkspace] = useState(null);
  const [section, setSection] = useState('bible');
  const [focusReelId, setFocusReelId] = useState(null);
  const [loading, setLoading] = useState(true);
  const [workspaceLoading, setWorkspaceLoading] = useState(Boolean(projectId));
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');

      try {
        const [module, projectResult] = await Promise.all([getStoryEntry(), listProjects({ perPage: 50 })]);
        if (!cancelled) {
          setEntry(module);
          setProjects(Array.isArray(projectResult.data) ? projectResult.data : []);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load Project Story.');
        }
      } finally {
        if (!cancelled) {
          setLoading(false);
        }
      }
    }

    load();

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (!projectId) {
      setWorkspace(null);
      setWorkspaceLoading(false);
      return undefined;
    }

    let cancelled = false;

    async function loadWorkspace() {
      setWorkspaceLoading(true);
      setError('');

      try {
        const result = await getStoryWorkspace(projectId);
        if (!cancelled) {
          setWorkspace(result);
        }
      } catch (err) {
        if (!cancelled) {
          setWorkspace(null);
          setError(err instanceof ApiError ? err.message : 'Unable to open that Project Story workspace.');
        }
      } finally {
        if (!cancelled) {
          setWorkspaceLoading(false);
        }
      }
    }

    loadWorkspace();

    return () => {
      cancelled = true;
    };
  }, [projectId]);

  const selectedProject = useMemo(
    () => projects.find((project) => project.id === projectId) || workspace?.project || null,
    [projectId, projects, workspace],
  );

  function handleSelect(event) {
    const nextId = event.target.value;
    navigate(nextId ? `/story/${nextId}` : '/story');
  }

  const capabilities = Array.isArray(entry?.capabilities) ? entry.capabilities : [];

  return (
    <div className="story-page">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <h1 className="h3 mb-2">Project Story</h1>
          <p className="page-lede mb-0">
            {entry?.description ||
              'A dedicated workspace for long-form story production. Select an existing project to continue.'}
          </p>
        </div>
      </div>

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {loading ? <LoadingSpinner label="Loading Project Story…" /> : null}

      {!loading && projects.length === 0 ? (
        <EmptyState
          icon="bi-journal-richtext"
          title="No projects available"
          description="Project Story uses your existing projects. Create a project first, then return here to open its story workspace."
        >
          <Link className="btn btn-primary" to="/projects/new">
            New Project
          </Link>
        </EmptyState>
      ) : null}

      {!loading && projects.length > 0 ? (
        <div className="card border-0 glass-card mb-4">
          <div className="card-body">
            <label className="form-label" htmlFor="story-project">
              Project
            </label>
            <select
              id="story-project"
              className="form-select"
              value={projectId || ''}
              onChange={handleSelect}
            >
              <option value="">Select a project</option>
              {projects.map((project) => (
                <option key={project.id} value={project.id}>
                  {project.name}
                </option>
              ))}
            </select>
          </div>
        </div>
      ) : null}

      {!loading && projects.length > 0 && !projectId ? (
        <EmptyState
          icon="bi-journal-richtext"
          title="Select a project"
          description="Choose an existing project to open its Project Story workspace. Later story modules will live here."
        />
      ) : null}

      {workspaceLoading ? <LoadingSpinner label="Opening story workspace…" /> : null}

      {!workspaceLoading && workspace ? (
        <div className="card border-0 glass-card story-workspace-card">
          <div className="card-body">
            <p className="text-uppercase small text-secondary mb-2">Selected project</p>
            <h2 className="h4 mb-2">{selectedProject?.name || workspace.project?.name}</h2>
            <p className="text-secondary mb-3">
              Status: {statusLabel(selectedProject?.status || workspace.project?.status)}
            </p>
            <p className="mb-4">
              This project has a Project Story workspace. Configure Story Bible, Characters, Style Bible, Story Planner, Reels / Scenes, Video Engine, and Audio Studio below.
            </p>
            <dl className="story-meta mb-0">
              <div>
                <dt>Workspace</dt>
                <dd>{workspace.id}</dd>
              </div>
              <div>
                <dt>Workspace status</dt>
                <dd>{workspace.status}</dd>
              </div>
            </dl>
          </div>
        </div>
      ) : null}

      {!workspaceLoading && workspace ? (
        <div className="story-section-tabs mb-3" role="tablist" aria-label="Project Story sections">
          <button
            type="button"
            role="tab"
            aria-selected={section === 'bible'}
            className={`btn ${section === 'bible' ? 'btn-primary' : 'btn-outline-primary'}`}
            onClick={() => setSection('bible')}
          >
            Story Bible
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={section === 'characters'}
            className={`btn ${section === 'characters' ? 'btn-primary' : 'btn-outline-primary'}`}
            onClick={() => setSection('characters')}
          >
            Characters
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={section === 'style'}
            className={`btn ${section === 'style' ? 'btn-primary' : 'btn-outline-primary'}`}
            onClick={() => setSection('style')}
          >
            Style Bible
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={section === 'planner'}
            className={`btn ${section === 'planner' ? 'btn-primary' : 'btn-outline-primary'}`}
            onClick={() => setSection('planner')}
          >
            Story Planner
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={section === 'reels'}
            className={`btn ${section === 'reels' ? 'btn-primary' : 'btn-outline-primary'}`}
            onClick={() => setSection('reels')}
          >
            Reels / Scenes
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={section === 'video'}
            className={`btn ${section === 'video' ? 'btn-primary' : 'btn-outline-primary'}`}
            onClick={() => setSection('video')}
          >
            Video Engine
          </button>
          <button
            type="button"
            role="tab"
            aria-selected={section === 'audio'}
            className={`btn ${section === 'audio' ? 'btn-primary' : 'btn-outline-primary'}`}
            onClick={() => setSection('audio')}
          >
            Audio Studio
          </button>
        </div>
      ) : null}

      {!workspaceLoading && workspace && section === 'bible' ? <StoryBibleForm projectId={projectId} /> : null}
      {!workspaceLoading && workspace && section === 'characters' ? (
        <StoryCharactersPanel projectId={projectId} />
      ) : null}
      {!workspaceLoading && workspace && section === 'style' ? (
        <StoryStylePanel projectId={projectId} />
      ) : null}
      {!workspaceLoading && workspace && section === 'planner' ? (
        <StoryPlannerPanel
          projectId={projectId}
          onMaterialized={(reelId) => {
            setFocusReelId(reelId || null);
            setSection('reels');
          }}
        />
      ) : null}
      {!workspaceLoading && workspace && section === 'reels' ? (
        <StoryReelsPanel projectId={projectId} focusReelId={focusReelId} />
      ) : null}
      {!workspaceLoading && workspace && section === 'video' ? (
        <StoryVideoEnginePanel projectId={projectId} />
      ) : null}
      {!workspaceLoading && workspace && section === 'audio' ? (
        <StoryAudioStudioPanel projectId={projectId} />
      ) : null}

      {!loading && capabilities.length > 0 ? (
        <section className="story-capability-list mt-4" aria-label="Story capabilities">
          <h2 className="h5 mb-3">Capability foundation</h2>
          <div className="row g-3">
            {capabilities.map((item) => (
              <div className="col-12 col-md-6 col-xl-4" key={item.capability}>
                <div className="card border-0 glass-card h-100">
                  <div className="card-body">
                    <h3 className="h6 mb-2">{item.label || storyCapabilityLabel(item.capability)}</h3>
                    <p className="small text-secondary mb-0">
                      {item.available ? 'Available' : 'Reserved for a later Project Story sprint.'}
                    </p>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </section>
      ) : null}
    </div>
  );
}
