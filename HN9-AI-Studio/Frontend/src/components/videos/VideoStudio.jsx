import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import { ApiError } from '../../services/apiClient';
import { listVideos } from '../../services/videoService';
import VideoEditor from './VideoEditor';
import VideoList from './VideoList';

export default function VideoStudio({ project, creating = false, videoId = null }) {
  const location = useLocation();
  const [videos, setVideos] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(!creating && !videoId);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(location.state?.notice || '');

  useEffect(() => {
    if (location.state?.notice) {
      setNotice(location.state.notice);
    }
  }, [location.state]);

  const showEditor = creating || Boolean(videoId);

  useEffect(() => {
    if (showEditor) {
      return undefined;
    }

    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');

      try {
        const result = await listVideos(project.id);
        if (!cancelled) {
          setVideos(result.data);
          setMeta(result.meta);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load video requests.');
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
  }, [project.id, showEditor]);

  if (showEditor) {
    return <VideoEditor projectId={project.id} videoId={videoId} creating={creating} />;
  }

  return (
    <div>
      {notice ? (
        <div className="mb-4">
          <AlertMessage variant="success">
            {notice}
            <button type="button" className="btn-close float-end" aria-label="Dismiss" onClick={() => setNotice('')} />
          </AlertMessage>
        </div>
      ) : null}
      <VideoList projectId={project.id} videos={videos} loading={loading} error={error} meta={meta} />
    </div>
  );
}
