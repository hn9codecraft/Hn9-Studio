import { formatProjectDate } from '../../services/projectConstants';
import { scriptReviewActionLabel, scriptStatusLabel } from '../../services/scriptConstants';

export default function ScriptReviewHistory({ events, loading }) {
  return (
    <section className="script-review-history" aria-labelledby="script-review-history-title">
      <h3 className="card-heading mb-3" id="script-review-history-title">
        Review history
      </h3>
      {loading ? <p className="small text-secondary mb-0">Loading review history…</p> : null}
      {!loading && events.length === 0 ? (
        <p className="small text-secondary mb-0">No review actions yet. Submit this script when it is ready.</p>
      ) : null}
      {!loading && events.length > 0 ? (
        <ol className="script-review-timeline list-unstyled mb-0">
          {events.map((event) => (
            <li key={event.id} className="script-review-event">
              <div className="d-flex flex-wrap justify-content-between gap-2">
                <p className="fw-semibold mb-1">{scriptReviewActionLabel(event.action)}</p>
                <time className="small text-secondary" dateTime={event.created_at || undefined}>
                  {formatProjectDate(event.created_at)}
                </time>
              </div>
              <p className="small text-secondary mb-1">
                {event.actor?.name || 'Unknown reviewer'}
                {event.from_status && event.to_status
                  ? ` · ${scriptStatusLabel(event.from_status)} → ${scriptStatusLabel(event.to_status)}`
                  : ''}
              </p>
              {event.comment ? <p className="script-review-comment mb-0">{event.comment}</p> : null}
            </li>
          ))}
        </ol>
      ) : null}
    </section>
  );
}
