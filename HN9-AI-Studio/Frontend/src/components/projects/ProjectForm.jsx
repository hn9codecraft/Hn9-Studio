import { allowedStatuses, fieldError, PROJECT_TYPES } from '../../services/projectConstants';

export default function ProjectForm({
  values,
  onChange,
  onSubmit,
  submitting,
  error,
  submitLabel,
  creating = false,
  currentStatus,
}) {
  const statuses = allowedStatuses(currentStatus || values.status || 'draft', { creating });
  const types = PROJECT_TYPES.some((item) => item.value === values.type)
    ? PROJECT_TYPES
    : [...PROJECT_TYPES, { value: values.type, label: values.type }];

  function handleChange(event) {
    const { name, value } = event.target;
    onChange({ ...values, [name]: value });
  }

  return (
    <form className="project-form" onSubmit={onSubmit}>
      {error?.message ? (
        <div className="alert alert-danger" role="alert">
          {error.message}
        </div>
      ) : null}

      <div className="mb-3">
        <label className="form-label" htmlFor="name">
          Project name
        </label>
        <input
          id="name"
          name="name"
          className={`form-control ${fieldError(error, 'name') ? 'is-invalid' : ''}`}
          value={values.name}
          onChange={handleChange}
          maxLength={255}
          required
        />
        {fieldError(error, 'name') ? <div className="invalid-feedback">{fieldError(error, 'name')}</div> : null}
      </div>

      <div className="mb-3">
        <label className="form-label" htmlFor="description">
          Description
        </label>
        <textarea
          id="description"
          name="description"
          className={`form-control ${fieldError(error, 'description') ? 'is-invalid' : ''}`}
          rows="4"
          maxLength={5000}
          value={values.description}
          onChange={handleChange}
        />
        {fieldError(error, 'description') ? (
          <div className="invalid-feedback">{fieldError(error, 'description')}</div>
        ) : null}
      </div>

      <div className="row g-3 mb-4">
        <div className="col-md-6">
          <label className="form-label" htmlFor="type">
            Type
          </label>
          <select id="type" name="type" className="form-select" value={values.type} onChange={handleChange}>
            {types.map((type) => (
              <option key={type.value || 'none'} value={type.value}>
                {type.label}
              </option>
            ))}
          </select>
        </div>
        <div className="col-md-6">
          <label className="form-label" htmlFor="status">
            Status
          </label>
          <select id="status" name="status" className="form-select" value={values.status} onChange={handleChange}>
            {statuses.map((status) => (
              <option key={status.value} value={status.value}>
                {status.label}
              </option>
            ))}
          </select>
        </div>
      </div>

      <button className="btn btn-primary" type="submit" disabled={submitting}>
        {submitting ? 'Saving…' : submitLabel}
      </button>
    </form>
  );
}
