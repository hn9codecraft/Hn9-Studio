import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useMatch, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import ImageStudio from '../../components/images/ImageStudio';
import AlertMessage from '../../components/ui/AlertMessage';
import EmptyState from '../../components/ui/EmptyState';
import { StudioContext, StudioFeedbackProvider } from '../../components/studio/StudioContext';
import { StepSkeleton } from '../../components/studio/StudioUi';
import { listProjects } from '../../services/projectService';
import {
  normalizeStudioWorkflows,
  resolveStudioSection,
  studioWorkflowLabel,
  visibleStudioSecondary,
  visibleStudioSteps,
} from '../../services/storyConstants';
import {
  getStoryBible,
  getStoryProjectRevision,
  getStoryReelSceneStatus,
  getStoryWorkspace,
  listStoryCharacters,
  listStoryReels,
} from '../../services/storyService';
import { friendlyError } from '../../services/studioMessages';

const StudioStoryStep = lazy(() => import('../../components/studio/StudioStoryStep'));
const StudioCastStep = lazy(() => import('../../components/studio/StudioCastStep'));
const StudioScenesStep = lazy(() => import('../../components/studio/StudioScenesStep'));
const StudioSoundStep = lazy(() => import('../../components/studio/StudioSoundStep'));
const StudioFinalStep = lazy(() => import('../../components/studio/StudioFinalStep'));
const StudioHistoryStep = lazy(() => import('../../components/studio/StudioHistoryStep'));
const StudioSettingsStep = lazy(() => import('../../components/studio/StudioSettingsStep'));

export default function ProjectStoryPage() {
  const { projectId } = useParams();

  return projectId ? (
    <StudioFeedbackProvider>
      <StudioWorkspace key={projectId} projectId={projectId} />
    </StudioFeedbackProvider>
  ) : (
    <StudioProjectPicker />
  );
}

const PICKER_PAGE_SIZE = 24;

function StudioProjectPicker() {
  const [projects, setProjects] = useState([]);
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');
  const [search, setSearch] = useState('');
  const [query, setQuery] = useState('');
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [total, setTotal] = useState(0);

  useEffect(() => {
    const timer = setTimeout(() => setQuery(search.trim()), 300);
    return () => clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');

    listProjects({ perPage: PICKER_PAGE_SIZE, search: query, cache: !query })
      .then((result) => {
        if (cancelled) return;
        setProjects(Array.isArray(result.data) ? result.data : []);
        setPage(1);
        setLastPage(Number(result.meta?.lastPage) || 1);
        setTotal(Number(result.meta?.total) || 0);
      })
      .catch((err) => {
        if (!cancelled) setError(friendlyError(err, 'Your projects could not be loaded. Please try again.'));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [query]);

  async function showMore() {
    setLoadingMore(true);
    try {
      const next = page + 1;
      const result = await listProjects({ perPage: PICKER_PAGE_SIZE, search: query, page: next });
      const more = Array.isArray(result.data) ? result.data : [];
      setProjects((current) => [...current, ...more.filter((item) => !current.some((existing) => existing.id === item.id))]);
      setPage(next);
      setLastPage(Number(result.meta?.lastPage) || next);
    } catch (err) {
      setError(friendlyError(err, 'More projects could not be loaded. Please try again.'));
    } finally {
      setLoadingMore(false);
    }
  }

  return (
    <div className="story-page">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <h1 className="h3 mb-2">Creative Production Studio</h1>
          <p className="page-lede mb-0">
            Every project has a studio that takes it from story to finished video. Choose a project to open it.
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

      <div className="mb-3" role="search">
        <label className="visually-hidden" htmlFor="studio-project-search">
          Search projects
        </label>
        <div className="input-group">
          <span className="input-group-text" aria-hidden="true">
            <i className="bi bi-search" />
          </span>
          <input
            id="studio-project-search"
            type="search"
            className="form-control"
            placeholder="Search projects by name"
            value={search}
            onChange={(event) => setSearch(event.target.value)}
          />
        </div>
        {!loading && total > 0 ? (
          <p className="small text-secondary mt-1 mb-0" aria-live="polite">
            Showing {projects.length} of {total} project{total === 1 ? '' : 's'}
          </p>
        ) : null}
      </div>

      {loading ? <StepSkeleton rows={3} /> : null}

      {!loading && !error && projects.length === 0 && !query ? (
        <EmptyState icon="bi-camera-reels" title="No projects yet" description="Create a project to get its Creative Studio.">
          <Link className="btn btn-primary" to="/projects/new">
            New Project
          </Link>
        </EmptyState>
      ) : null}

      {!loading && !error && projects.length === 0 && query ? (
        <EmptyState icon="bi-search" title="No matching projects" description={`Nothing is called “${query}”. Try a different name.`} />
      ) : null}

      {!loading && projects.length > 0 ? (
        <div className="row g-3">
          {projects.map((project) => (
            <div className="col-12 col-md-6 col-xl-4" key={project.id}>
              <Link to={`/studio/${project.id}`} className="project-card glass-card card border-0 h-100 text-decoration-none">
                <div className="card-body d-flex flex-column">
                  <h2 className="card-heading h6 mb-2">{project.name}</h2>
                  <WorkflowChips workflows={normalizeStudioWorkflows(project.settings?.studio_modules)} />
                  <span className="small text-secondary mt-auto pt-3">
                    Open studio <i className="bi bi-arrow-right" aria-hidden="true" />
                  </span>
                </div>
              </Link>
            </div>
          ))}
        </div>
      ) : null}

      {!loading && page < lastPage ? (
        <div className="text-center mt-4">
          <button type="button" className="btn btn-outline-primary" onClick={showMore} disabled={loadingMore}>
            {loadingMore ? 'Loading…' : 'Show more projects'}
          </button>
        </div>
      ) : null}
    </div>
  );
}

const EMPTY_DATA = { bible: null, characters: [], reels: [], sceneStatus: null };

function StudioWorkspace({ projectId }) {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const imagesRoot = useMatch('/studio/:projectId/images');
  const imagesNew = useMatch('/studio/:projectId/images/new');
  const imagesGenerate = useMatch('/studio/:projectId/images/generate');
  const imagesRegenerate = useMatch('/studio/:projectId/images/:imageId/regenerate');
  const imagesDetail = useMatch('/studio/:projectId/images/:imageId');
  const inImages = Boolean(imagesRoot || imagesNew || imagesGenerate || imagesRegenerate || imagesDetail);

  const [workspace, setWorkspace] = useState(null);
  const [data, setData] = useState(EMPTY_DATA);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [finalState, setFinalState] = useState(null);

  useEffect(() => {
    let cancelled = false;

    Promise.all([
      getStoryWorkspace(projectId),
      getStoryBible(projectId).catch(() => null),
      listStoryCharacters(projectId).catch(() => []),
      listStoryReels(projectId).catch(() => []),
    ])
      .then(([space, bible, characters, reels]) => {
        if (cancelled) return;
        setWorkspace(space);
        setData((current) => ({ ...current, bible, characters, reels }));
      })
      .catch((err) => {
        if (!cancelled) setError(friendlyError(err, 'This studio could not be opened. Please try again.'));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });

    return () => {
      cancelled = true;
    };
  }, [projectId]);

  const requestedReel = searchParams.get('reel');
  const reelId = data.reels.some((reel) => reel.id === requestedReel) ? requestedReel : data.reels[0]?.id || null;
  const reel = data.reels.find((item) => item.id === reelId) || null;

  useEffect(() => {
    if (!reelId) {
      if (!loading) setData((current) => (current.sceneStatus?.length === 0 ? current : { ...current, sceneStatus: [] }));
      return undefined;
    }

    let cancelled = false;
    getStoryReelSceneStatus(projectId, reelId)
      .then((sceneStatus) => {
        if (!cancelled) setData((current) => ({ ...current, sceneStatus }));
      })
      .catch(() => {});

    return () => {
      cancelled = true;
    };
  }, [projectId, reelId, loading]);

  /** Re-reads shared data after a step changed it. Keys: bible, characters, reels, status. */
  const refresh = useCallback(
    async (keys) => {
      const loaders = {
        bible: () => getStoryBible(projectId),
        characters: () => listStoryCharacters(projectId),
        reels: () => listStoryReels(projectId),
        sceneStatus: () => (reelId ? getStoryReelSceneStatus(projectId, reelId) : Promise.resolve([])),
      };
      const wanted = keys.map((key) => (key === 'status' ? 'sceneStatus' : key)).filter((key) => loaders[key]);
      const results = await Promise.all(
        wanted.map((key) =>
          loaders[key]()
            .then((value) => [key, value])
            .catch(() => null),
        ),
      );
      setData((current) => ({ ...current, ...Object.fromEntries(results.filter(Boolean)) }));
    },
    [projectId, reelId],
  );

  const workflows = normalizeStudioWorkflows(workspace?.project?.studio_modules);
  const steps = visibleStudioSteps(workflows);
  const secondary = visibleStudioSecondary(workflows);
  const requested = inImages ? 'images' : resolveStudioSection(searchParams.get('section'));
  const isKnown = [...steps, ...secondary].some((item) => item.key === requested);
  const section = isKnown ? requested : steps[0]?.key || 'story';

  const goTo = useCallback(
    (next, params = {}) => {
      if (next === 'images') {
        navigate(`/studio/${projectId}/images`);
        return;
      }
      const query = new URLSearchParams();
      query.set('section', next);
      const nextReel = params.reel || reelId;
      if (nextReel) query.set('reel', nextReel);
      Object.entries(params).forEach(([key, value]) => {
        if (key !== 'reel' && value) query.set(key, value);
      });
      navigate({ pathname: `/studio/${projectId}`, search: `?${query.toString()}` });
    },
    [navigate, projectId, reelId],
  );

  const selectReel = useCallback(
    (nextReel) => {
      const query = new URLSearchParams(searchParams);
      query.set('section', section);
      query.set('reel', nextReel);
      query.delete('scene');
      query.delete('plan');
      navigate({ pathname: `/studio/${projectId}`, search: `?${query.toString()}` }, { replace: true });
    },
    [navigate, projectId, searchParams, section],
  );

  // Steps stay mounted after their first visit so revisiting one costs no
  // requests. A step is rebuilt only when another step changed project data.
  const [visited, setVisited] = useState(() => [section]);
  const [panelKeys, setPanelKeys] = useState({});
  const seenRevisions = useRef({});
  const lastSection = useRef(section);

  useEffect(() => {
    const previous = lastSection.current;
    if (previous === section) return;

    const revision = getStoryProjectRevision(projectId);
    seenRevisions.current[previous] = revision;
    const seen = seenRevisions.current[section];
    if (seen !== undefined && seen < revision) {
      setPanelKeys((keys) => ({ ...keys, [section]: (keys[section] || 0) + 1 }));
    }
    seenRevisions.current[section] = revision;
    lastSection.current = section;
    setVisited((current) => (current.includes(section) ? current : [...current, section]));

    document.querySelector('.studio-header')?.scrollIntoView({ block: 'start' });
    document.getElementById(`studio-panel-${section}`)?.focus({ preventScroll: true });
    const stepper = document.querySelector('.studio-stepper');
    const activeStep = stepper?.querySelector('.studio-stepper-item.is-active');
    if (stepper && activeStep && stepper.scrollWidth > stepper.clientWidth) {
      const box = stepper.getBoundingClientRect();
      const item = activeStep.getBoundingClientRect();
      stepper.scrollLeft += item.left - box.left - (box.width - item.width) / 2;
    }
  }, [section, projectId]);

  const project = workspace?.project || {};
  const connections = workspace?.connections || null;
  const canApprove = Boolean(workspace?.abilities?.approve);

  const contextValue = useMemo(
    () => ({
      projectId,
      project,
      connections,
      canApprove,
      bible: data.bible,
      characters: data.characters,
      reels: data.reels,
      reel,
      reelId,
      sceneStatus: data.sceneStatus || [],
      refresh,
      goTo,
      selectReel,
      setFinalState,
      renameProject: (name) =>
        setWorkspace((current) => ({ ...current, project: { ...current.project, name } })),
      setWorkflows: (next) =>
        setWorkspace((current) => ({
          ...current,
          project: { ...current.project, studio_modules: next.length > 0 ? next : null },
        })),
    }),
    [projectId, project, connections, canApprove, data, reel, reelId, refresh, goTo, selectReel],
  );

  if (loading) {
    return (
      <div className="story-page">
        <div className="studio-skeleton-block studio-skeleton-hero" />
        <div className="studio-skeleton-block studio-skeleton-nav" />
        <StepSkeleton rows={2} />
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
          <AlertMessage>{error || 'This studio could not be found.'}</AlertMessage>
        </div>
      </div>
    );
  }

  const allSections = [...steps, ...secondary];
  const current = allSections.find((item) => item.key === section);
  const stepIndex = steps.findIndex((step) => step.key === section);
  const prev = stepIndex > 0 ? steps[stepIndex - 1] : null;
  const next = stepIndex >= 0 && stepIndex < steps.length - 1 ? steps[stepIndex + 1] : null;
  const progress = stepProgress(data, finalState);

  function renderPanel(key) {
    const nav = { prev, next, onNavigate: goTo };
    switch (key) {
      case 'story':
        return <StudioStoryStep nav={nav} />;
      case 'cast':
        return <StudioCastStep nav={nav} />;
      case 'scenes':
        return <StudioScenesStep nav={nav} focusSceneId={searchParams.get('scene')} openPlanner={searchParams.get('plan') === 'review'} />;
      case 'sound':
        return <StudioSoundStep nav={nav} focusSceneId={searchParams.get('scene')} />;
      case 'final':
        return <StudioFinalStep nav={nav} />;
      case 'history':
        return <StudioHistoryStep />;
      case 'settings':
        return <StudioSettingsStep />;
      case 'images':
        return (
          <ImageStudio
            project={project}
            basePath={`/studio/${projectId}/images`}
            creating={Boolean(imagesNew)}
            generating={Boolean(imagesGenerate)}
            parentImageId={imagesRegenerate?.params.imageId || null}
            imageId={!imagesNew && !imagesGenerate && !imagesRegenerate ? imagesDetail?.params.imageId || null : null}
          />
        );
      default:
        return null;
    }
  }

  return (
    <StudioContext.Provider value={contextValue}>
      <div className="story-page studio">
        <header className="studio-header">
          <nav aria-label="Breadcrumb">
            <ol className="breadcrumb studio-breadcrumb mb-2">
              <li className="breadcrumb-item">
                <Link to="/studio">Creative Studio</Link>
              </li>
              <li className="breadcrumb-item">
                <Link to={`/studio/${projectId}`}>{project.name}</Link>
              </li>
              <li className="breadcrumb-item active" aria-current="page">
                {current?.label}
              </li>
            </ol>
          </nav>
          <div className="studio-header-row">
            <h1 className="studio-title">{project.name}</h1>
            <div className="studio-secondary-nav" role="group" aria-label="More studio pages">
              {secondary.map((item) => (
                <button
                  key={item.key}
                  type="button"
                  className={`btn btn-sm ${section === item.key ? 'btn-secondary' : 'btn-outline-secondary'}`}
                  aria-current={section === item.key ? 'page' : undefined}
                  onClick={() => goTo(item.key)}
                >
                  <i className={`bi ${item.icon} me-1`} aria-hidden="true" />
                  {item.label}
                </button>
              ))}
              <Link className="btn btn-sm btn-outline-secondary" to={`/projects/${projectId}`}>
                <i className="bi bi-folder2-open me-1" aria-hidden="true" />
                Project files
              </Link>
            </div>
          </div>
        </header>

        <nav className="studio-stepper" aria-label="Production steps">
          <ol>
            {steps.map((step, index) => {
              const meta = progress[step.key] || {};
              const active = section === step.key;
              return (
                <li key={step.key}>
                  <button
                    type="button"
                    className={`studio-stepper-item${active ? ' is-active' : ''}${meta.done ? ' is-done' : ''}`}
                    aria-current={active ? 'step' : undefined}
                    onClick={() => goTo(step.key)}
                  >
                    <span className="studio-stepper-index" aria-hidden="true">
                      {meta.done ? <i className="bi bi-check-lg" /> : index + 1}
                    </span>
                    <span className="studio-stepper-text">
                      <span className="studio-stepper-label">{step.label}</span>
                      {meta.text ? <span className="studio-stepper-meta">{meta.text}</span> : null}
                    </span>
                    <span className="visually-hidden">
                      {`, step ${index + 1} of ${steps.length}`}
                      {meta.done ? ', complete' : ''}
                    </span>
                  </button>
                </li>
              );
            })}
          </ol>
        </nav>

        {allSections
          .filter((item) => visited.includes(item.key) || item.key === section)
          .map((item) => (
            <section
              key={`${item.key}-${panelKeys[item.key] || 0}`}
              id={`studio-panel-${item.key}`}
              className="studio-panel"
              aria-label={item.label}
              tabIndex={-1}
              hidden={item.key !== section}
            >
              <Suspense fallback={<StepSkeleton rows={2} />}>{renderPanel(item.key)}</Suspense>
            </section>
          ))}
      </div>
    </StudioContext.Provider>
  );
}

function stepProgress(data, finalState) {
  const characters = data.characters || [];
  const ready = characters.filter((character) => character.approved_reference).length;
  const statusLoaded = data.sceneStatus !== null;
  const scenes = data.sceneStatus || [];
  const approved = scenes.filter((item) => item.version?.status === 'approved').length;
  const withSound = scenes.filter((item) => (item.sounds || []).some((sound) => sound.has_file)).length;
  const pending = { done: false, text: '' };

  return {
    story: data.bible?.concept ? { done: true, text: 'Done' } : { done: false, text: 'Start here' },
    cast:
      characters.length === 0
        ? { done: false, text: 'No characters yet' }
        : { done: ready === characters.length, text: `${ready} of ${characters.length} ready` },
    scenes: !statusLoaded
      ? pending
      : scenes.length === 0
        ? { done: false, text: 'No scenes yet' }
        : { done: approved === scenes.length, text: `${approved} of ${scenes.length} approved` },
    sound: !statusLoaded
      ? pending
      : scenes.length === 0
        ? { done: false, text: 'Optional' }
        : { done: false, text: `${withSound} of ${scenes.length} have sound` },
    final: finalState || (!statusLoaded ? pending : { done: false, text: approved > 0 ? 'Ready to build' : 'Needs approved scenes' }),
  };
}

function WorkflowChips({ workflows }) {
  if (workflows.length === 0) {
    return <span className="studio-chip studio-chip--muted">Full production</span>;
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
