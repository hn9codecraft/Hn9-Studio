import { useEffect, useState } from 'react';
import { fetchImageObjectUrl } from '../../services/imageService';

export default function GeneratedImage({ projectId, image }) {
  const [src, setSrc] = useState('');
  const [error, setError] = useState('');

  useEffect(() => {
    if (!image?.has_file) {
      setSrc('');
      return undefined;
    }

    let cancelled = false;
    let objectUrl = '';

    async function load() {
      setError('');

      try {
        objectUrl = await fetchImageObjectUrl(projectId, image.id);
        if (!cancelled) {
          setSrc(objectUrl);
        }
      } catch (err) {
        if (!cancelled) {
          setSrc('');
          setError(err?.message || 'Unable to load the generated image.');
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
  }, [projectId, image?.id, image?.has_file]);

  if (!image?.has_file) {
    return <p className="text-secondary mb-0">No generated file is stored for this image yet.</p>;
  }

  if (error) {
    return <p className="text-danger mb-0">{error}</p>;
  }

  if (!src) {
    return <p className="text-secondary mb-0">Loading image…</p>;
  }

  return (
    <img
      src={src}
      alt={image.prompt || image.title || 'Generated image'}
      className="img-fluid rounded border"
      style={{ maxHeight: '420px', width: 'auto' }}
    />
  );
}
