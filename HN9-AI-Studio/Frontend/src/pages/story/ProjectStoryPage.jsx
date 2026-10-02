import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import StudioWorkflowPicker from '../../components/projects/StudioWorkflowPicker';
import AlertMessage from '../../components/ui/AlertMessage';
import EmptyState from '../../components/ui/EmptyState';
import { ApiError } from '../../services/apiClient';
import { getProject, listProjects, updateProject } from '../../services/projectService';
import { statusLabel } from '../../services/projectConstants';
import {
  normalizeStudioWorkflows,
  STUDIO_GROUPS,
  STUDIO_PROJECT_TOOLS,
  studioWorkflowLabel,
  visibleStudioSections,
} from '../../services/storyConstants';
import { getStoryProjectRevision, getStoryWorkspace } from '../../services/storyService';

const StoryBibleForm = lazy(() => import('../../components/story/StoryBibleForm'));
const StoryCharactersPanel = lazy(() => import('../../components/story/StoryCharactersPanel'));
const StoryPlannerPanel = lazy(() => import('../../components/story/StoryPlannerPanel'));
const StoryReelsPanel = lazy(() => import('../../components/story/StoryReelsPanel'));
const StoryStylePanel = lazy(() => import('../../components/story/StoryStylePanel'));
const StoryVideoEnginePanel = lazy(() => import('../../components/story/StoryVideoEnginePanel'));
const StoryAudioStudioPanel = lazy(() => import('../../components/story/StoryAudioStudioPanel'));
const StoryTimelinePanel = lazy(() => import('../../components/story/StoryTimelinePanel'));
const StoryHistoryPanel = lazy(() => import('../../components/story/StoryHistoryPanel'));

export default function ProjectStoryPage() {
  const { projectId } = useParams();

  return projectId ? <StudioWorkspace key={projectId} projectId={projectId} /> : <StudioProjectPicker />;
}

function StudioProjectPicker() {
  const [projects, setProjects] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;

    listProjects({ perPage: 50, cache: true })
      .then((result) => {
        if (!cancelled) setProjects(Array.isArray(result.data) ? result.data : []);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof ApiError ? err.message : 'Unable to load your projects.');
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, []);

  return (
    <div className="story-page">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <h1 className="h3 mb-2">Creative Production Studio</h1>
          <p className="page-lede mb-0">
            Every project has a studio for planning, producing and finishing its content. Choose a project to open it.
          </p>
        </div>
        <Link className="btn btn-primary" to="/projects/new">
          New Project
        </Link>
      </div>

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {loading ? <StudioSkeleton rows={3} /> : null}

      {!loading && !error && projects.length === 0 ? (
        <EmptyState
          icon="bi-camera-reels"
          title="No projects yet"
          description="Create a project to get its Creative Studio."
        >
          <Link className="btn btn-primary" to="/projects/new">
            New Project
          </Link>
        </EmptyState>
      ) : null}

      {!loading && projects.length > 0 ? (
        <div className="row g-3">
          {projects.map((project) => {
            const workflows = normalizeStudioWorkflows(project.settings?.studio_modules);

            return (
              <div className="col-12 col-md-6 col-xl-4" key={project.id}>
                <Link
                  to={`/studio/${project.id}`}
                  className="project-card glass-card card border-0 h-100 text-decoration-none"
                >
                  <div className="card-body d-flex flex-column">
                    <div className="d-flex justify-content-between align-items-start gap-3 mb-2">
                      <h2 className="card-heading h6 mb-0">{project.name}</h2>
                      <span className={`status-pill status-${project.status || 'draft'}`}>
                        {statusLabel(project.status)}
                      </span>
                    </div>
                    <WorkflowChips workflows={workflows} />
                    <span className="small text-secondary mt-auto pt-3">
                      Open studio <i className="bi bi-arrow-right" aria-hidden="true" />
                    </span>
                  </div>
                </Link>
              </div>
            );
          })}
        </div>
      ) : null}
    </div>
  );
}

function StudioWorkspace({ projectId }) {
  const [searchParams, setSearchParams] = useSearchParams();
  const [workspace, setWorkspace] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [focusReelId, setFocusReelId] = useState(null);
  const [panelKeys, setPanelKeys] = useState({});
  const seenRevisions = useRef({});

  useEffect(() => {
    let cancelled = false;

    getStoryWorkspace(projectId)
      .then((result) => {
        if (!cancelled) setWorkspace(result);
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to open this studio.');
        }
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [projectId]);

  const workflows = normalizeStudioWorkflows(workspace?.project?.studio_modules);
  const sections = visibleStudioSections(workflows);
  const requested = searchParams.get('section') || 'overview';
  const section = sections.some((item) => item.key === requested) ? requested : 'overview';
  const [visited, setVisited] = useState(() => [section]);
  const mounted = visited.includes(section) ? visited : [...visited, section];

  function selectSection(next) {
    if (next === section) return;

    // Sections stay mounted after their first visit. One is rebuilt only when
    // this client changed the project's story data since it was last shown.
    const revision = getStoryProjectRevision(projectId);
    seenRevisions.current[section] = revision;
    const seen = seenRevisions.current[next];
    if (seen !== undefined && seen < revision) {
      setPanelKeys((keys) => ({ ...keys, [next]: (keys[next] || 0) + 1 }));
    }
    seenRevisions.current[next] = revision;

    setVisited((current) => (current.includes(next) ? current : [...current, next]));
    setSearchParams(next === 'overview' ? {} : { section: next }, { replace: true });
  }

  if (loading) {
    return (
      <div className="story-page">
        <StudioSkeleton rows={2} hero />
      </div>
    );
  }

  if (error || !workspace) {
    return (
      <div className="story-page">
        <h1 className="visually-hidden">Creative Production Studio</h1>
        <Link to="/studio" className="activity-link small text-decoration-none">
          <i className="bi bi-arrow-left me-1" aria-hidden="true" />
          All studios
        </Link>
        <div className="mt-4">
          <AlertMessage>{error || 'Studio not found.'}</AlertMessage>
        </div>
      </div>
    );
  }

  const project = workspace.project || {};

  function renderPanel(key) {
    switch (key) {
      case 'overview':
        return (
          <StudioOverview
            projectId={projectId}
            sections={sections}
            workflows={workflows}
            onOpen={selectSection}
            onWorkflowsSaved={(next) =>
              setWorkspace((current) => ({
                ...current,
                project: { ...current.project, studio_modules: next.length > 0 ? next : null },
              }))
            }
          />
        );
      case 'story':
        return <StoryBibleForm projectId={projectId} />;
      case 'characters':
        return <StoryCharactersPanel projectId={projectId} />;
      case 'style':
        return <StoryStylePanel projectId={projectId} />;
      case 'planner':
        return (
          <StoryPlannerPanel
            projectId={projectId}
            onMaterialized={(reelId) => {
              setFocusReelId(reelId || null);
              selectSection('reels');
            }}
          />
        );
      case 'reels':
        return <StoryReelsPanel projectId={projectId} focusReelId={focusReelId} />;
      case 'video':
        return <StoryVideoEnginePanel projectId={projectId} />;
      case 'audio':
        return <StoryAudioStudioPanel projectId={projectId} />;
      case 'timeline':
        return <StoryTimelinePanel projectId={projectId} />;
      case 'history':
        return <StoryHistoryPanel projectId={projectId} />;
      default:
        return null;
    }
  }

  return (
    <div className="story-page">
      <section className="page-section page-section--flush workspace-hero glass-card card border-0">
        <div className="card-body">
          <div className="d-flex flex-wrap justify-content-between gap-3 mb-3">
            <Link to="/studio" className="activity-link small text-decoration-none">
              <i className="bi bi-arrow-left me-1" aria-hidden="true" />
              All studios
            </Link>
            <span className={`status-pill status-${project.status || 'draft'}`}>{statusLabel(project.status)}</span>
          </div>
          <div className="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
              <p className="section-kicker mb-1">Creative Production Studio</p>
              <h1 className="section-title mb-2">{project.name}</h1>
              <WorkflowChips workflows={workflows} />
            </div>
            <Link className="btn btn-outline-primary" to={`/projects/${projectId}`}>
              Project details
            </Link>
          </div>
        </div>
      </section>

      <nav className="studio-nav mb-4" aria-label="Studio sections">
        {STUDIO_GROUPS.map((group) => {
          const items = sections.filter((item) => item.group === group.key);
          if (items.length === 0) return null;

          return (
            <div className="studio-nav-group" key={group.key} role="tablist" aria-label={group.label || 'Start'}>
              {group.label ? <span className="studio-nav-label">{group.label}</span> : null}
              <div className="studio-nav-items">
                {items.map((item) => (
                  <button
                    key={item.key}
                    type="button"
                    role="tab"
                    id={`studio-tab-${item.key}`}
                    aria-selected={section === item.key}
                    aria-controls={`studio-panel-${item.key}`}
                    className={`workspace-tab ${section === item.key ? 'active' : ''}`}
                    onClick={() => selectSection(item.key)}
                  >
                    {item.label}
                  </button>
                ))}
              </div>
            </div>
          );
        })}
      </nav>

      {sections
        .filter((item) => mounted.includes(item.key))
        .map((item) => (
          <div
            key={`${item.key}-${panelKeys[item.key] || 0}`}
            role="tabpanel"
            id={`studio-panel-${item.key}`}
            aria-labelledby={`studio-tab-${item.key}`}
            hidden={item.key !== section}
          >
            <Suspense fallback={<StudioSkeleton rows={1} />}>{renderPanel(item.key)}</Suspense>
          </div>
        ))}
    </div>
  );
}

function StudioOverview({ projectId, sections, workflows, onOpen, onWorkflowsSaved }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(workflows);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const steps = sections.filter((item) => item.key !== 'overview' && item.key !== 'history');
  const tools = STUDIO_PROJECT_TOOLS.filter(
    (tool) => !tool.workflow || workflows.length === 0 || workflows.includes(tool.workflow),
  );

  async function saveWorkflows() {
    setSaving(true);
    setError('');

    try {
      // Project updates replace the whole settings object, so merge with what is stored.
      const current = await getProject(projectId);
      const stored = current?.settings && typeof current.settings === 'object' && !Array.isArray(current.settings)
        ? current.settings
        : {};
      const { studio_modules: _previous, ...rest } = stored;
      await updateProject(projectId, { settings: draft.length > 0 ? { ...rest, studio_modules: draft } : rest });
      onWorkflowsSaved(draft);
      setEditing(false);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to save workflows.');
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="row g-4">
      <div className="col-lg-7">
        <div className="card border-0 glass-card h-100">
          <div className="card-body">
            <h2 className="card-heading h5 mb-1">Your production steps</h2>
            <p className="text-secondary small mb-3">Work top to bottom. Every step saves on its own.</p>
            <ol className="studio-steps list-unstyled mb-0">
              {steps.map((item, index) => (
                <li key={item.key}>
                  <button type="button" className="studio-step" onClick={() => onOpen(item.key)}>
                    <span className="studio-step-index">{index + 1}</span>
                    <span className="studio-step-text">
                      <span className="studio-step-title">{item.label}</span>
                      <span className="studio-step-desc">{item.description}</span>
                    </span>
                    <i className="bi bi-chevron-right" aria-hidden="true" />
                  </button>
                </li>
              ))}
            </ol>
            {steps.length === 0 ? (
              <p className="text-secondary mb-0">Use the project tools for this workflow.</p>
            ) : null}
          </div>
        </div>
      </div>

      <div className="col-lg-5 d-flex flex-column gap-4">
        <div className="card border-0 glass-card">
          <div className="card-body">
            <div className="d-flex justify-content-between align-items-center gap-2 mb-2">
              <h2 className="card-heading h5 mb-0">Workflows</h2>
              {!editing ? (
                <button
                  type="button"
                  className="btn btn-outline-primary btn-sm"
                  onClick={() => {
                    setDraft(workflows);
                    setError('');
                    setEditing(true);
                  }}
                >
                  Change
                </button>
              ) : null}
            </div>
            {error ? (
              <div className="mb-3">
                <AlertMessage>{error}</AlertMessage>
              </div>
            ) : null}
            {editing ? (
              <>
                <StudioWorkflowPicker value={draft} onChange={setDraft} idPrefix="studio-workflow" disabled={saving} />
                <div className="d-flex gap-2 mt-3">
                  <button type="button" className="btn btn-primary btn-sm" onClick={saveWorkflows} disabled={saving}>
                    {saving ? 'Saving…' : 'Save workflows'}
                  </button>
                  <button
                    type="button"
                    className="btn btn-outline-secondary btn-sm"
                    onClick={() => setEditing(false)}
                    disabled={saving}
                  >
                    Cancel
                  </button>
                </div>
              </>
            ) : (
              <>
                <WorkflowChips workflows={workflows} />
                <p className="small text-secondary mt-2 mb-0">
                  {workflows.length === 0
                    ? 'No workflow chosen, so every studio step is shown.'
                    : 'Only the steps for these workflows are shown.'}
                </p>
              </>
            )}
          </div>
        </div>

        <div className="card border-0 glass-card">
          <div className="card-body">
            <h2 className="card-heading h5 mb-3">Project tools</h2>
            <div className="studio-tool-list">
              {tools.map((tool) => (
                <Link key={tool.key} className="studio-tool" to={`/projects/${projectId}/${tool.path}`}>
                  <i className={`bi ${tool.icon}`} aria-hidden="true" />
                  <span>{tool.label}</span>
                </Link>
              ))}
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}

function WorkflowChips({ workflows }) {
  if (workflows.length === 0) {
    return <span className="studio-chip studio-chip--muted">All workflows</span>;
  }

  return (
    <div className="studio-chip-list">
      {workflows.map((item) => (
        <span className="studio-chip" key={item}>
          {studioWorkflowLabel(item)}
        </span>
      ))}
    </div>
  );
}

function StudioSkeleton({ rows = 2, hero = false }) {
  return (
    <div className="studio-skeleton" aria-busy="true" aria-live="polite">
      <span className="visually-hidden">Loading studio…</span>
      {hero ? <div className="studio-skeleton-block studio-skeleton-hero" /> : null}
      {hero ? <div className="studio-skeleton-block studio-skeleton-nav" /> : null}
      <div className="row g-3">
        {Array.from({ length: rows * 3 }, (_, index) => (
          <div className="col-12 col-md-6 col-xl-4" key={index}>
            <div className="studio-skeleton-block studio-skeleton-card" />
          </div>
        ))}
      </div>
    </div>
  );
}
