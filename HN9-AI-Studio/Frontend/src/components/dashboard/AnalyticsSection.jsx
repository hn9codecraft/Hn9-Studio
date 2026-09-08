import { useEffect, useState } from 'react';
import ActivityBreakdown from './ActivityBreakdown';
import ContentStatusBreakdown from './ContentStatusBreakdown';
import CreationTimeline from './CreationTimeline';
import ProjectProductivity from './ProjectProductivity';
import AlertMessage from '../ui/AlertMessage';
import LoadingSpinner from '../ui/LoadingSpinner';
import { ApiError } from '../../services/apiClient';
import { getDashboardAnalytics } from '../../services/dashboardService';

const EMPTY = {
  projects: { total: 0, draft: 0, active: 0, completed: 0, archived: 0, timeline: [] },
  content: {
    scripts: { total: 0 },
    images: { total: 0 },
    videos: { total: 0 },
    assets: { total: 0 },
  },
  creation_timeline: [],
  activity: { total: 0, recent_count: 0, by_module: {}, by_action: {} },
  project_productivity: [],
};

export default function AnalyticsSection() {
  const [analytics, setAnalytics] = useState(EMPTY);
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [applied, setApplied] = useState({ from: '', to: '' });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [rangeError, setRangeError] = useState('');

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');

      try {
        const result = await getDashboardAnalytics(applied);
        if (!cancelled) {
          setAnalytics(result);
        }
      } catch (err) {
        if (!cancelled) {
          setAnalytics(EMPTY);
          setError(err instanceof ApiError ? err.message : 'Unable to load analytics.');
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
  }, [applied]);

  function applyRange(event) {
    event.preventDefault();
    if (from && to && from > to) {
      setRangeError('The from date must be on or before the to date.');
      return;
    }
    setRangeError('');
    setApplied({ from, to });
  }

  const overview = [
    { label: 'Projects', value: analytics.projects.total, icon: 'bi-folder2-open' },
    { label: 'Scripts', value: analytics.content.scripts.total, icon: 'bi-file-text' },
    { label: 'Images', value: analytics.content.images.total, icon: 'bi-image' },
    { label: 'Videos', value: analytics.content.videos.total, icon: 'bi-camera-video' },
    { label: 'Assets', value: analytics.content.assets.total, icon: 'bi-archive' },
  ];

  return (
    <section className="analytics-section mt-5">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
          <p className="text-uppercase small text-secondary mb-1">Analytics</p>
          <h3 className="h4 mb-1">Studio analytics</h3>
          <p className="text-secondary mb-0">
            Aggregated from your owned records. Zeros and empty charts are real. Provider usage and cost are listed
            below.
          </p>
        </div>
        <form className="d-flex flex-wrap align-items-end gap-2" onSubmit={applyRange}>
          <div>
            <label className="form-label small mb-1" htmlFor="analytics-from">
              From
            </label>
            <input
              id="analytics-from"
              className="form-control"
              type="date"
              value={from}
              onChange={(event) => setFrom(event.target.value)}
            />
          </div>
          <div>
            <label className="form-label small mb-1" htmlFor="analytics-to">
              To
            </label>
            <input
              id="analytics-to"
              className="form-control"
              type="date"
              value={to}
              onChange={(event) => setTo(event.target.value)}
            />
          </div>
          <button className="btn btn-outline-primary" type="submit">
            Apply
          </button>
        </form>
      </div>

      {rangeError ? (
        <div className="mb-3">
          <AlertMessage>{rangeError}</AlertMessage>
        </div>
      ) : null}

      {error ? (
        <div className="mb-3">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {loading ? <LoadingSpinner label="Loading analytics..." /> : null}

      {!loading ? (
        <>
          <div className="row g-3 mb-4">
            {overview.map((card) => (
              <div className="col-6 col-xl" key={card.label}>
                <div className="card border-0 shadow-sm h-100">
                  <div className="card-body p-4">
                    <p className="small text-secondary text-uppercase mb-2">
                      <i className={`bi ${card.icon} me-1`} aria-hidden="true" />
                      {card.label}
                    </p>
                    <p className="dashboard-stat-value mb-0">{card.value}</p>
                  </div>
                </div>
              </div>
            ))}
          </div>

          <div className="mb-4">
            <CreationTimeline rows={analytics.creation_timeline} />
          </div>

          <div className="mb-4">
            <h3 className="h5 mb-3">Content status</h3>
            <ContentStatusBreakdown content={analytics.content} projects={analytics.projects} />
          </div>

          <div className="mb-4">
            <h3 className="h5 mb-3">Project productivity</h3>
            <ProjectProductivity rows={analytics.project_productivity} />
          </div>

          <ActivityBreakdown activity={analytics.activity} />
        </>
      ) : null}
    </section>
  );
}
