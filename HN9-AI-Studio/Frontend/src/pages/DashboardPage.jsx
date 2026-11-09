import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import ActionCenterSection from '../components/dashboard/ActionCenterSection';
import AnalyticsSection from '../components/dashboard/AnalyticsSection';
import DashboardStats from '../components/dashboard/DashboardStats';
import RecentActivity from '../components/dashboard/RecentActivity';
import RecentProjects from '../components/dashboard/RecentProjects';
import UsageCostSection from '../components/dashboard/UsageCostSection';
import AlertMessage from '../components/ui/AlertMessage';
import LoadingSpinner from '../components/ui/LoadingSpinner';
import { useAuth } from '../contexts/AuthContext';
import { ApiError } from '../services/apiClient';
import { getDashboardSummary } from '../services/dashboardService';

const EMPTY_SUMMARY = {
  projects: { total: 0, draft: 0, active: 0, completed: 0, archived: 0 },
  scripts: { total: 0, by_status: {} },
  images: { total: 0, by_status: {} },
  videos: { total: 0, by_status: {} },
  assets: { total: 0, by_status: {} },
  recent_projects: [],
  recent_activity: [],
};

const GETTING_STARTED_STEPS = [
  {
    icon: 'bi-pencil-square',
    title: 'Name the project',
    description: 'Give it a name and pick what you plan to produce.',
  },
  {
    icon: 'bi-camera-reels',
    title: 'Land in Creative Studio',
    description: 'Your new project opens straight in its Creative Studio.',
  },
  {
    icon: 'bi-film',
    title: 'Move from Story to Final Video',
    description: 'Work step by step in the studio, from Story through to the Final Video.',
  },
];

function hasAnyProjects(summary) {
  const total = Number(summary?.projects?.total) || 0;
  const recent = Array.isArray(summary?.recent_projects) ? summary.recent_projects.length : 0;

  return total > 0 || recent > 0;
}

function CreateProjectButton() {
  return (
    <Link className="btn btn-primary" to="/projects/new">
      <i className="bi bi-plus-lg me-1" aria-hidden="true" />
      Create project
    </Link>
  );
}

export default function DashboardPage() {
  const { user } = useAuth();
  const [summary, setSummary] = useState(EMPTY_SUMMARY);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');

      try {
        const result = await getDashboardSummary();
        if (!cancelled) {
          setSummary(result);
        }
      } catch (err) {
        if (!cancelled) {
          setSummary(EMPTY_SUMMARY);
          setError(err instanceof ApiError ? err.message : 'Unable to load dashboard.');
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
  }, []);

  const hasProjects = hasAnyProjects(summary);
  const showGettingStarted = !loading && !error && !hasProjects;
  const showStats = !loading && hasProjects;

  return (
    <div className="dashboard-page">
      <section className="page-section page-section--flush dashboard-hero glass-card card border-0">
        <div className="card-body dashboard-hero-body">
          <div>
            <p className="section-kicker mb-2">{showGettingStarted ? 'Get started' : 'Overview'}</p>
            <h1 className="section-title mb-2">
              {showGettingStarted ? `Welcome, ${user?.name || 'there'}` : `Welcome back, ${user?.name || 'there'}`}
            </h1>
            <p className="dashboard-hero-copy mb-0">
              {showGettingStarted
                ? 'Your studio is ready. Create your first project and it opens straight in Creative Studio.'
                : 'Here is what is in your studio right now, including anything that needs your attention.'}
            </p>
          </div>
          {!loading ? (
            <div className="dashboard-hero-actions">
              <CreateProjectButton />
            </div>
          ) : null}
        </div>
      </section>

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {loading ? <LoadingSpinner label={'Loading dashboard\u2026'} /> : null}

      {showGettingStarted ? (
        <section className="page-section" aria-labelledby="dashboard-steps-title">
          <h2 className="section-title" id="dashboard-steps-title">
            How it works
          </h2>
          <ol className="dashboard-steps">
            {GETTING_STARTED_STEPS.map((step, index) => (
              <li className="dashboard-step glass-card card border-0" key={step.title}>
                <div className="card-body">
                  <div className="dashboard-step-head">
                    <span className="dashboard-step-number" aria-hidden="true">
                      {index + 1}
                    </span>
                    <i className={`bi ${step.icon} dashboard-step-icon`} aria-hidden="true" />
                  </div>
                  <h3 className="card-heading h6 mb-1">{step.title}</h3>
                  <p className="text-secondary small mb-0">{step.description}</p>
                </div>
              </li>
            ))}
          </ol>
        </section>
      ) : null}

      {showStats ? (
        <>
          <section className="page-section">
            <h2 className="visually-hidden">Studio totals</h2>
            <DashboardStats summary={summary} />
          </section>

          <section className="page-section">
            <h2 className="section-title">Recent projects</h2>
            <RecentProjects projects={summary.recent_projects} />
          </section>

          <section className="page-section">
            <h2 className="section-title">Recent activity</h2>
            <RecentActivity activities={summary.recent_activity} />
          </section>

          <ActionCenterSection />
          <AnalyticsSection />
          <UsageCostSection />
        </>
      ) : null}
    </div>
  );
}