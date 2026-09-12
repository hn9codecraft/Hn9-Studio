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
    <section className="page-section analytics-section">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
          <h2 className="section-title mb-1">Studio analytics</h2>
          <p className="page-lede mb-0">
            Aggregated from your owned records. Zeros and empty charts are real. Provider usage and cost are listed
            below.
          </p>
        </div>
        <form className="filter-bar" onSubmit={applyRange}>
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
          <div className="kpi-grid">
            {overview.map((card) => (
              <div className="glass-card kpi-card card border-0" key={card.label}>
                <div className="card-body">
                  <div className="kpi-icon" aria-hidden="true">
                    <i className={`bi ${card.icon}`} />
                  </div>
                  <div className="kpi-copy">
                    <p className="kpi-label mb-0">{card.label}</p>
                    <p className="kpi-value mb-0">{card.value}</p>
                  </div>
                </div>
              </div>
            ))}
          </div>

          <div className="subsection">
            <CreationTimeline rows={analytics.creation_timeline} />
          </div>

          <div className="subsection">
            <h3 className="card-heading">Content status</h3>
            <ContentStatusBreakdown content={analytics.content} projects={analytics.projects} />
          </div>

          <div className="subsection">
            <h3 className="card-heading">Project productivity</h3>
            <ProjectProductivity rows={analytics.project_productivity} />
          </div>

          <div className="subsection">
            <ActivityBreakdown activity={analytics.activity} />
          </div>
        </>
      ) : null}
    </section>
  );
}
