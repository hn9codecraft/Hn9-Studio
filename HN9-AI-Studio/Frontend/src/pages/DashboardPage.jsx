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
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
          <p className="text-uppercase small text-secondary mb-1">Overview</p>
          <h2 className="h3 mb-1">Welcome back, {user?.name || 'there'}</h2>
          <p className="text-secondary mb-0">
            Counts are live from your projects. Zeros are real. The Action Center lists only items that currently need
            work. Usage and cost below only include recorded provider executions.
          </p>
        </div>
      </div>

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {loading ? <LoadingSpinner label="Loading dashboard…" /> : null}

      {!loading ? (
        <>
          <DashboardStats summary={summary} />

          <div className="row g-4 mt-1">
            <div className="col-xl-7">
              <div className="d-flex align-items-center justify-content-between gap-3 mb-3">
                <h3 className="h5 mb-0">Recent projects</h3>
              </div>
              <RecentProjects projects={summary.recent_projects} />
            </div>
            <div className="col-xl-5">
              <div className="d-flex align-items-center justify-content-between gap-3 mb-3">
                <h3 className="h5 mb-0">Recent activity</h3>
              </div>
              <RecentActivity activities={summary.recent_activity} />
            </div>
          </div>

          <ActionCenterSection />
          <AnalyticsSection />
          <UsageCostSection />
        </>
      ) : null}
    </div>
  );
}
