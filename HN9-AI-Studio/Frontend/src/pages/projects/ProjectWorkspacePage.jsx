import { Fragment, useEffect, useMemo, useState } from 'react';
import { Link, Navigate, useMatch, useNavigate, useParams } from 'react-router-dom';
import ComingNextPanel from '../../components/projects/ComingNextPanel';
import DeleteProjectModal from '../../components/projects/DeleteProjectModal';
import ProjectForm from '../../components/projects/ProjectForm';
import WorkspaceTabs from '../../components/projects/WorkspaceTabs';
import AssetStudio from '../../components/assets/AssetStudio';
import ActivityStudio from '../../components/activity/ActivityStudio';
import ImageStudio from '../../components/images/ImageStudio';
import ScriptStudio from '../../components/scripts/ScriptStudio';
import VideoStudio from '../../components/videos/VideoStudio';
import AlertMessage from '../../components/ui/AlertMessage';
import LoadingSpinner from '../../components/ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { formatProjectDate, statusLabel, typeLabel } from '../../services/projectConstants';
import { deleteProject, getProject, updateProject } from '../../services/projectService';

const SECTION_TITLES = {
  scripts: 'Scripts',
  images: 'Images',
  videos: 'Videos',
  assets: 'Assets',
  activity: 'Activity',
};

export default function ProjectWorkspacePage() {
  const { projectId, section } = useParams();
  const navigate = useNavigate();
  const newScriptMatch = useMatch('/projects/:projectId/scripts/new');
  const scriptMatch = useMatch('/projects/:projectId/scripts/:scriptId');
  const newImageMatch = useMatch('/projects/:projectId/images/new');
  const imageMatch = useMatch('/projects/:projectId/images/:imageId');
  const newVideoMatch = useMatch('/projects/:projectId/videos/new');
  const videoMatch = useMatch('/projects/:projectId/videos/:videoId');
  const newAssetMatch = useMatch('/projects/:projectId/assets/new');
  const assetMatch = useMatch('/projects/:projectId/assets/:assetId');
  const inScriptStudio = Boolean(newScriptMatch || scriptMatch || section === 'scripts');
  const inImageStudio = Boolean(newImageMatch || imageMatch || section === 'images');
  const inVideoStudio = Boolean(newVideoMatch || videoMatch || section === 'videos');
  const inAssetStudio = Boolean(newAssetMatch || assetMatch || section === 'assets');
  const inActivityStudio = section === 'activity';
  const activeSection = inScriptStudio
    ? 'scripts'
    : inImageStudio
      ? 'images'
      : inVideoStudio
        ? 'videos'
        : inAssetStudio
          ? 'assets'
          : inActivityStudio
            ? 'activity'
            : section || 'overview';
  const [project, setProject] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [editing, setEditing] = useState(false);
  const [values, setValues] = useState(null);
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState(null);
  const [notice, setNotice] = useState('');
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState('');

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');
      setEditing(false);

      try {
        const data = await getProject(projectId);
        if (!cancelled) {
          setProject(data);
          setValues(formValuesFromProject(data));
        }
      } catch (err) {
        if (!cancelled) {
          setProject(null);
          setError(err instanceof ApiError ? err.message : 'Unable to load this project.');
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
  }, [projectId]);

  const metadataEntries = useMemo(() => readableEntries(project?.metadata), [project]);
  const settingsEntries = useMemo(() => readableEntries(project?.settings), [project]);

  if (section && !SECTION_TITLES[section] && !inScriptStudio && !inImageStudio && !inVideoStudio && !inAssetStudio) {
    return <Navigate to={`/projects/${projectId}`} replace />;
  }

  async function handleSave(event) {
    event.preventDefault();
    setSaving(true);
    setFormError(null);
    setNotice('');

    try {
      const updated = await updateProject(projectId, {
        name: values.name.trim(),
        description: values.description.trim(),
        type: values.type,
        status: values.status,
      });
      setProject(updated);
      setValues(formValuesFromProject(updated));
      setEditing(false);
      setNotice('Project updated.');
    } catch (err) {
      setFormError(
        err instanceof ApiError ? err : new ApiError('Unable to update the project.', { status: 0 }),
      );
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete() {
    setDeleting(true);
    setDeleteError('');

    try {
      await deleteProject(projectId);
      navigate('/projects', { replace: true, state: { notice: 'Project deleted.' } });
    } catch (err) {
      setDeleteError(err instanceof ApiError ? err.message : 'Unable to delete this project.');
      setDeleting(false);
    }
  }

  if (loading) {
    return <LoadingSpinner label="Opening project workspace…" />;
  }

  if (error || !project) {
    return (
      <div>
        <h1 className="visually-hidden">Project Workspace</h1>
        <Link to="/projects" className="activity-link small text-decoration-none">
          <i className="bi bi-arrow-left me-1" aria-hidden="true" />
          Back to Projects
        </Link>
        <div className="mt-4">
          <AlertMessage>{error || 'Project not found.'}</AlertMessage>
        </div>
      </div>
    );
  }

  return (
    <div className="project-workspace">
      <section className="page-section page-section--flush workspace-hero glass-card card border-0">
        <div className="card-body">
          <div className="d-flex flex-wrap justify-content-between gap-3 mb-3">
            <Link to="/projects" className="activity-link small text-decoration-none">
              <i className="bi bi-arrow-left me-1" aria-hidden="true" />
              Back to Projects
            </Link>
            <span className={`status-pill status-${project.status || 'draft'}`}>{statusLabel(project.status)}</span>
          </div>

          <div className="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
              <p className="section-kicker mb-1">Project workspace</p>
              <h1 className="section-title mb-2">{project.name}</h1>
              <p className="text-secondary mb-0">{project.description || 'No description yet.'}</p>
            </div>
            <div className="d-flex flex-wrap gap-2">
              <button
                type="button"
                className="btn btn-outline-primary"
                onClick={() => {
                  setEditing((open) => !open);
                  setFormError(null);
                  setValues(formValuesFromProject(project));
                }}
              >
                {editing ? 'Cancel edit' : 'Edit Project'}
              </button>
              <button type="button" className="btn btn-outline-danger" onClick={() => setDeleteOpen(true)}>
                Delete Project
              </button>
            </div>
          </div>

          <dl className="workspace-meta row mt-4 mb-0">
            <div className="col-sm-6 col-lg-3 mb-3 mb-lg-0">
              <dt>Type</dt>
              <dd>{typeLabel(project.type)}</dd>
            </div>
            <div className="col-sm-6 col-lg-3 mb-3 mb-lg-0">
              <dt>Status</dt>
              <dd>{statusLabel(project.status)}</dd>
            </div>
            <div className="col-sm-6 col-lg-3 mb-3 mb-lg-0">
              <dt>Created</dt>
              <dd>{formatProjectDate(project.created_at)}</dd>
            </div>
            <div className="col-sm-6 col-lg-3">
              <dt>Updated</dt>
              <dd>{formatProjectDate(project.updated_at)}</dd>
            </div>
          </dl>
        </div>
      </section>

      {notice ? (
        <div className="mb-4">
          <AlertMessage variant="success">{notice}</AlertMessage>
        </div>
      ) : null}

      {editing ? (
        <section className="page-section">
          <div className="card border-0 glass-card">
          <div className="card-body">
            <h3 className="card-heading mb-3">Edit project</h3>
            <ProjectForm
              values={values}
              onChange={setValues}
              onSubmit={handleSave}
              submitting={saving}
              error={formError}
              submitLabel="Save changes"
              currentStatus={project.status}
            />
          </div>
          </div>
        </section>
      ) : null}

      <section className="page-section">
      <WorkspaceTabs projectId={project.id} section={activeSection} />

      <div className="workspace-panel">
        {activeSection === 'overview' ? (
          <div className="row g-4">
            <div className="col-lg-7">
              <div className="card border-0 glass-card h-100">
                <div className="card-body">
                  <h3 className="card-heading mb-3">Overview</h3>
                  <p className="mb-4">{project.description || 'This project has no description yet.'}</p>
                  <dl className="row mb-0">
                    <dt className="col-sm-4">Slug</dt>
                    <dd className="col-sm-8">
                      <code>{project.slug || '—'}</code>
                    </dd>
                    <dt className="col-sm-4">Project ID</dt>
                    <dd className="col-sm-8">
                      <code>{project.id}</code>
                    </dd>
                  </dl>
                </div>
              </div>
            </div>
            <div className="col-lg-5">
              <div className="card border-0 glass-card h-100">
                <div className="card-body">
                  <h3 className="card-heading mb-3">Metadata</h3>
                  {metadataEntries.length === 0 && settingsEntries.length === 0 ? (
                    <p className="text-secondary mb-0">No metadata or settings are stored on this project yet.</p>
                  ) : (
                    <>
                      <MetaList title="Metadata" entries={metadataEntries} />
                      <MetaList title="Settings" entries={settingsEntries} />
                    </>
                  )}
                </div>
              </div>
            </div>
          </div>
        ) : activeSection === 'scripts' ? (
          <ScriptStudio
            project={project}
            creating={Boolean(newScriptMatch)}
            scriptId={newScriptMatch ? null : scriptMatch?.params.scriptId || null}
          />
        ) : activeSection === 'images' ? (
          <ImageStudio
            project={project}
            creating={Boolean(newImageMatch)}
            imageId={newImageMatch ? null : imageMatch?.params.imageId || null}
          />
        ) : activeSection === 'videos' ? (
          <VideoStudio
            project={project}
            creating={Boolean(newVideoMatch)}
            videoId={newVideoMatch ? null : videoMatch?.params.videoId || null}
          />
        ) : activeSection === 'assets' ? (
          <AssetStudio
            project={project}
            creating={Boolean(newAssetMatch)}
            assetId={newAssetMatch ? null : assetMatch?.params.assetId || null}
          />
        ) : activeSection === 'activity' ? (
          <ActivityStudio project={project} />
        ) : (
          <ComingNextPanel title={SECTION_TITLES[activeSection]} />
        )}
      </div>
      </section>

      {deleteError ? (
        <div className="mt-4">
          <AlertMessage>{deleteError}</AlertMessage>
        </div>
      ) : null}

      <DeleteProjectModal
        project={project}
        open={deleteOpen}
        deleting={deleting}
        onCancel={() => setDeleteOpen(false)}
        onConfirm={handleDelete}
      />
    </div>
  );
}

function formValuesFromProject(project) {
  return {
    name: project.name || '',
    description: project.description || '',
    type: project.type || '',
    status: project.status || 'draft',
  };
}

function readableEntries(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return [];
  }

  return Object.entries(value).filter(([, item]) => item !== null && item !== undefined && item !== '');
}

function MetaList({ title, entries }) {
  if (entries.length === 0) {
    return null;
  }

  return (
    <div className="mb-3">
      <h4 className="card-heading">{title}</h4>
      <dl className="row mb-0">
        {entries.map(([key, value]) => (
          <Fragment key={key}>
            <dt className="col-sm-4">{key}</dt>
            <dd className="col-sm-8">{typeof value === 'object' ? JSON.stringify(value) : String(value)}</dd>
          </Fragment>
        ))}
      </dl>
    </div>
  );
}
