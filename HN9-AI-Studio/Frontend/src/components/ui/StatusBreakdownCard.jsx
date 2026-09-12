export default function StatusBreakdownCard({ title, items, labelFor, headingLevel = 'h3' }) {
  const Heading = headingLevel;

  return (
    <section className="glass-card status-card card border-0" aria-label={title}>
      <div className="card-body">
        <Heading className="card-heading mb-3">{title}</Heading>
        <ul className="status-rows list-unstyled mb-0">
          {items.map((item) => {
            const label = labelFor ? labelFor(item.key) : labelStatus(item.key);
            return (
              <li key={item.key} className="status-row">
                <span className="status-row-label">
                  <span className={`status-dot status-${item.key}`} aria-hidden="true" />
                  <span>{label}</span>
                </span>
                <span className="status-row-count">{item.value}</span>
              </li>
            );
          })}
        </ul>
      </div>
    </section>
  );
}

function labelStatus(value) {
  return String(value).replace(/_/g, ' ');
}
