import { useEffect, useState } from 'react';
import { fetchVideoObjectUrl } from '../../services/videoService';
import { videoStatusLabel } from '../../services/videoConstants';

export default function GeneratedVideo({ projectId, video }) {
  const [src, setSrc] = useState('');
  const [error, setError] = useState('');

  const inFlight = video?.status === 'pending' || video?.status === 'processing';

  useEffect(() => {
    if (!video?.has_file) {
      setSrc('');
      return undefined;
    }

    let cancelled = false;
    let objectUrl = '';

    async function load() {
      setError('');

      try {
        objectUrl = await fetchVideoObjectUrl(projectId, video.id);
        if (!cancelled) {
          setSrc(objectUrl);
        }
      } catch (err) {
        if (!cancelled) {
          setSrc('');
          setError(err?.message || 'Unable to load the generated video.');
        }
      }
    }

    load();

    return () => {
      cancelled = true;
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [projectId, video?.id, video?.has_file]);

  if (inFlight) {
    return (
      <p className="text-secondary mb-0" role="status" aria-live="polite">
        {videoStatusLabel(video.status)}. Waiting for the provider to finish. This page does not show a percentage.
      </p>
    );
  }

  if (video?.status === 'failed') {
    return (
      <p className="text-danger mb-0" role="alert">
        {video?.generation?.error || 'Video generation failed.'}
      </p>
    );
  }

  if (!video?.has_file) {
    return <p className="text-secondary mb-0">No generated file is stored for this video yet.</p>;
  }

  if (error) {
    return <p className="text-danger mb-0">{error}</p>;
  }

  if (!src) {
    return (
      <p className="text-secondary mb-0" role="status">
        Loading video…
      </p>
    );
  }

  return (
    <video
      className="w-100 rounded border"
      style={{ maxHeight: '420px', background: '#0D365C' }}
      src={src}
      controls
      playsInline
      preload="metadata"
    >
      <track kind="captions" />
      Your browser cannot play this video.
    </video>
  );
}
