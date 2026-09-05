export default function EmptyState({ title, description, icon = 'bi-inbox', children }) {
  return (
    <div className="empty-state card border-0 shadow-sm">
      <div className="card-body text-center py-5 px-4">
        <div className="empty-state-icon mb-3">
          <i className={`bi ${icon}`} aria-hidden="true" />
        </div>
        <h2 className="h5 mb-2">{title}</h2>
        <p className="text-secondary mb-3">{description}</p>
        {children}
      </div>
    </div>
  );
}
