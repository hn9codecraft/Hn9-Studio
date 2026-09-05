import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import { ApiError } from '../../services/apiClient';
import { listAssets } from '../../services/assetService';
import AssetEditor from './AssetEditor';
import AssetList from './AssetList';

export default function AssetStudio({ project, creating = false, assetId = null }) {
  const location = useLocation();
  const [assets, setAssets] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(!creating && !assetId);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(location.state?.notice || '');

  useEffect(() => {
    if (location.state?.notice) {
      setNotice(location.state.notice);
    }
  }, [location.state]);

  const showEditor = creating || Boolean(assetId);

  useEffect(() => {
    if (showEditor) {
      return undefined;
    }

    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');

      try {
        const result = await listAssets(project.id);
        if (!cancelled) {
          setAssets(result.data);
          setMeta(result.meta);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load assets.');
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
    return <AssetEditor projectId={project.id} assetId={assetId} creating={creating} />;
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
      <AssetList projectId={project.id} assets={assets} loading={loading} error={error} meta={meta} />
    </div>
  );
}
