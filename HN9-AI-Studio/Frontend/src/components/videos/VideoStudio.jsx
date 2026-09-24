import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import { ApiError } from '../../services/apiClient';
import { getVideo, listVideos } from '../../services/videoService';
import VideoEditor from './VideoEditor';
import VideoGenerateForm from './VideoGenerateForm';
import VideoList from './VideoList';
import LoadingSpinner from '../ui/LoadingSpinner';

export default function VideoStudio({
  project,
  creating = false,
  videoId = null,
  generating = false,
  parentVideoId = null,
}) {
  const location = useLocation();
  const [videos, setVideos] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(!creating && !videoId && !generating && !parentVideoId);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(location.state?.notice || '');
  const showEditor = creating || Boolean(videoId);
  const showList = !generating && !parentVideoId && !showEditor;

  useEffect(() => {
    if (location.state?.notice) {
      setNotice(location.state.notice);
    }
  }, [location.state]);

  useEffect(() => {
    if (!showList) {
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
  }, [project.id, showList]);

  if (generating) {
    return <VideoGenerateForm project={project} />;
  }

  if (parentVideoId) {
    return <RegenerateVideo project={project} videoId={parentVideoId} />;
  }

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

function RegenerateVideo({ project, videoId }) {
  const [video, setVideo] = useState(null);
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;

    getVideo(project.id, videoId)
      .then((data) => {
        if (!cancelled) {
          setVideo(data);
        }
      })
      .catch((err) => {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load this video.');
        }
      });

    return () => {
      cancelled = true;
    };
  }, [project.id, videoId]);

  if (error) {
    return <AlertMessage>{error}</AlertMessage>;
  }

  if (!video) {
    return <LoadingSpinner label="Opening video…" />;
  }

  return <VideoGenerateForm project={project} parentVideo={video} />;
}
