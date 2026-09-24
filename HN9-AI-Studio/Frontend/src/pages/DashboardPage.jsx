import { useEffect, useState } from 'react';
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

  return (
    <div className="dashboard-page">
      <section className="page-section page-section--flush dashboard-hero glass-card card border-0">
        <div className="card-body">
          <p className="section-kicker mb-2">Overview</p>
          <h1 className="section-title mb-2">Welcome back, {user?.name || 'there'}</h1>
          <p className="dashboard-hero-copy mb-0">
            Counts are live from your projects. Zeros are real. The Action Center lists only items that currently need
            work. Usage and cost below only include recorded provider executions.
          </p>
        </div>
      </section>

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {loading ? <LoadingSpinner label="Loading dashboard…" /> : null}

      {!loading ? (
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
