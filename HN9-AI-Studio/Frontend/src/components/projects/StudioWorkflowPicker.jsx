import { normalizeStudioWorkflows, STUDIO_WORKFLOWS } from '../../services/storyConstants';

export default function StudioWorkflowPicker({ value, onChange, idPrefix = 'workflow', disabled = false }) {
  const selected = Array.isArray(value) ? value : [];
  const allSelected = STUDIO_WORKFLOWS.every((item) => selected.includes(item.value));

  function toggle(workflow) {
    onChange(
      normalizeStudioWorkflows(
        selected.includes(workflow) ? selected.filter((item) => item !== workflow) : [...selected, workflow],
      ),
    );
  }

  return (
    <fieldset className="studio-workflow-picker" disabled={disabled}>
      <legend className="form-label mb-1">What will you produce?</legend>
      <p className="form-text mt-0 mb-3">
        Optional. Pick one or more and the Creative Studio shows only the steps you need. You can change this later.
      </p>
      <div className="studio-workflow-grid">
        {STUDIO_WORKFLOWS.map((item) => {
          const id = `${idPrefix}-${item.value}`;
          const checked = selected.includes(item.value);

          return (
            <label key={item.value} htmlFor={id} className={`studio-workflow-option${checked ? ' is-selected' : ''}`}>
              <input
                id={id}
                type="checkbox"
                className="form-check-input"
                checked={checked}
                onChange={() => toggle(item.value)}
              />
              <span className="studio-workflow-icon" aria-hidden="true">
                <i className={`bi ${item.icon}`} />
              </span>
              <span className="studio-workflow-text">
                <span className="studio-workflow-title">{item.label}</span>
                <span className="studio-workflow-desc">{item.description}</span>
              </span>
            </label>
          );
        })}
      </div>
      <button
        type="button"
        className="btn btn-link btn-sm px-0 mt-2"
        onClick={() => onChange(allSelected ? [] : STUDIO_WORKFLOWS.map((item) => item.value))}
      >
        {allSelected ? 'Clear selection' : 'Select all (full creative production)'}
      </button>
    </fieldset>
  );
}
