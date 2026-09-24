export default function EmptyState({ title, description, icon = 'bi-inbox', children, headingLevel = 'h3' }) {
  const Heading = headingLevel;

  return (
    <div className="empty-state card border-0 shadow-sm">
      <div className="card-body text-center py-5 px-4">
        <div className="empty-state-icon mb-3">
          <i className={`bi ${icon}`} aria-hidden="true" />
        </div>
        <Heading className="card-heading mb-2">{title}</Heading>
        <p className="text-secondary mb-3">{description}</p>
        {children}
      </div>
    </div>
  );
}
