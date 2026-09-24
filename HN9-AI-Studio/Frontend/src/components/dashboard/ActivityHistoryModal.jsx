import { useCallback, useEffect, useState } from 'react';
import { ApiError } from '../../services/apiClient';
import { getDashboardActivity } from '../../services/dashboardService';
import ActivityItem from '../activity/ActivityItem';
import AlertMessage from '../ui/AlertMessage';
import EmptyState from '../ui/EmptyState';
import LoadingSpinner from '../ui/LoadingSpinner';

const PAGE_SIZE = 20;

export default function ActivityHistoryModal({ open, onClose }) {
  const [items, setItems] = useState([]);
  const [meta, setMeta] = useState(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(false);
  const [loadingMore, setLoadingMore] = useState(false);
  const [error, setError] = useState('');

  const loadPage = useCallback(async (nextPage, append) => {
    if (append) {
      setLoadingMore(true);
    } else {
      setLoading(true);
      setError('');
    }

    try {
      const result = await getDashboardActivity({ page: nextPage, perPage: PAGE_SIZE });
      setItems((current) => (append ? [...current, ...result.data] : result.data));
      setMeta(result.meta);
      setPage(nextPage);
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to load activity history.');
      if (!append) {
        setItems([]);
        setMeta(null);
      }
    } finally {
      setLoading(false);
      setLoadingMore(false);
    }
  }, []);

  useEffect(() => {
    if (!open) {
      return undefined;
    }

    setItems([]);
    setMeta(null);
    setPage(1);
    loadPage(1, false);

    function handleKeyDown(event) {
      if (event.key === 'Escape') {
        onClose();
      }
    }

    window.addEventListener('keydown', handleKeyDown);

    return () => {
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [open, onClose, loadPage]);

  if (!open) {
    return null;
  }

  const total = Number(meta?.total) || 0;
  const lastPage = Number(meta?.lastPage) || 1;
  const hasMore = page < lastPage;

  return (
    <div className="modal-layer activity-history-modal" role="presentation">
      <div className="modal-backdrop fade show" onClick={onClose} />
      <div className="modal fade show d-block" tabIndex="-1" role="dialog" aria-modal="true" aria-labelledby="activityHistoryTitle">
        <div className="modal-dialog modal-dialog-centered">
          <div className="modal-content glass-card">
            <div className="modal-header">
              <h2 className="modal-title h5" id="activityHistoryTitle">
                Activity History
              </h2>
              <button type="button" className="btn-close" aria-label="Close" onClick={onClose} />
            </div>
            <div className="modal-body p-0">
              {loading ? <LoadingSpinner label="Loading activity history…" /> : null}

              {!loading && error ? (
                <div className="p-4">
                  <AlertMessage>{error}</AlertMessage>
                </div>
              ) : null}

              {!loading && !error && items.length === 0 ? (
                <div className="p-4">
                  <EmptyState
                    icon="bi-clock-history"
                    title="No activity yet"
                    description="Studio actions on your projects will appear here after they happen."
                  />
                </div>
              ) : null}

              {!loading && items.length > 0 ? (
                <div className="activity-timeline">
                  {items.map((item) => (
                    <ActivityItem key={item.id} projectId={item.project?.id} item={item} />
                  ))}
                </div>
              ) : null}
            </div>
            <div className="modal-footer justify-content-between align-items-center gap-3">
              <p className="small text-secondary mb-0">
                {total
                  ? `Showing ${items.length} of ${total} studio event${total === 1 ? '' : 's'}.`
                  : 'Owner-scoped studio history from your projects.'}
              </p>
              <div className="d-flex gap-2">
                {hasMore ? (
                  <button
                    type="button"
                    className="btn btn-outline-primary"
                    onClick={() => loadPage(page + 1, true)}
                    disabled={loadingMore}
                  >
                    {loadingMore ? 'Loading…' : 'Load more'}
                  </button>
                ) : null}
                <button type="button" className="btn btn-outline-secondary" onClick={onClose}>
                  Close
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
