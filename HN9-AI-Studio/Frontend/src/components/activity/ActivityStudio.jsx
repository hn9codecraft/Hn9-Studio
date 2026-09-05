import { useEffect, useState } from 'react';
import AlertMessage from '../ui/AlertMessage';
import { ApiError } from '../../services/apiClient';
import { listActivities } from '../../services/activityService';
import ActivityList from './ActivityList';

export default function ActivityStudio({ project }) {
  const [activities, setActivities] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');

      try {
        const result = await listActivities(project.id);
        if (!cancelled) {
          setActivities(result.data);
          setMeta(result.meta);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load activity.');
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
  }, [project.id]);

  return (
    <div>
      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}
      <ActivityList projectId={project.id} activities={activities} loading={loading} error={error} meta={meta} />
    </div>
  );
}
