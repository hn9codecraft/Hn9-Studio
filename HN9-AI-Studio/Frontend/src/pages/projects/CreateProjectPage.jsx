import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import ProjectForm from '../../components/projects/ProjectForm';
import { ApiError } from '../../services/apiClient';
import { createProject } from '../../services/projectService';

const INITIAL_VALUES = {
  name: '',
  description: '',
  type: '',
  status: 'draft',
};

export default function CreateProjectPage() {
  const navigate = useNavigate();
  const [values, setValues] = useState(INITIAL_VALUES);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState(null);

  async function handleSubmit(event) {
    event.preventDefault();
    setSubmitting(true);
    setError(null);

    try {
      const project = await createProject({
        name: values.name.trim(),
        description: values.description.trim(),
        type: values.type,
        status: values.status,
      });
      navigate(`/projects/${project.id}`, { replace: true });
    } catch (err) {
      setError(
        err instanceof ApiError
          ? err
          : new ApiError('Unable to create the project.', { status: 0 }),
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="row justify-content-center">
      <div className="col-lg-8 col-xl-7">
        <div className="mb-4">
          <Link to="/projects" className="activity-link small text-decoration-none">
            <i className="bi bi-arrow-left me-1" aria-hidden="true" />
            Back to Projects
          </Link>
          <h1 className="visually-hidden">New Project</h1>
          <p className="page-lede mt-3 mb-0">Saved to the database as soon as you create it. No mock records.</p>
        </div>

        <div className="card border-0 shadow-sm">
          <div className="card-body p-4 p-md-5">
            <ProjectForm
              values={values}
              onChange={setValues}
              onSubmit={handleSubmit}
              submitting={submitting}
              error={error}
              submitLabel="Create project"
              creating
            />
          </div>
        </div>
      </div>
    </div>
  );
}
