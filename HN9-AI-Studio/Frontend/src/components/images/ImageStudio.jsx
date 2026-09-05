import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import { ApiError } from '../../services/apiClient';
import { listImages } from '../../services/imageService';
import ImageEditor from './ImageEditor';
import ImageList from './ImageList';

export default function ImageStudio({ project, creating = false, imageId = null }) {
  const location = useLocation();
  const [images, setImages] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(!creating && !imageId);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(location.state?.notice || '');

  useEffect(() => {
    if (location.state?.notice) {
      setNotice(location.state.notice);
    }
  }, [location.state]);

  const showEditor = creating || Boolean(imageId);

  useEffect(() => {
    if (showEditor) {
      return undefined;
    }

    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');

      try {
        const result = await listImages(project.id);
        if (!cancelled) {
          setImages(result.data);
          setMeta(result.meta);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load image requests.');
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
    return <ImageEditor projectId={project.id} imageId={imageId} creating={creating} />;
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
      <ImageList projectId={project.id} images={images} loading={loading} error={error} meta={meta} />
    </div>
  );
}
