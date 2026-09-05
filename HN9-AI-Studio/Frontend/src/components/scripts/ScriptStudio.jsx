import { useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import AlertMessage from '../ui/AlertMessage';
import { ApiError } from '../../services/apiClient';
import { listScripts } from '../../services/scriptService';
import ScriptEditor from './ScriptEditor';
import ScriptList from './ScriptList';

export default function ScriptStudio({ project, creating = false, scriptId = null }) {
  const location = useLocation();
  const [scripts, setScripts] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(!creating && !scriptId);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(location.state?.notice || '');

  useEffect(() => {
    if (location.state?.notice) {
      setNotice(location.state.notice);
    }
  }, [location.state]);

  const showEditor = creating || Boolean(scriptId);

  useEffect(() => {
    if (showEditor) {
      return undefined;
    }

    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');

      try {
        const result = await listScripts(project.id);
        if (!cancelled) {
          setScripts(result.data);
          setMeta(result.meta);
        }
      } catch (err) {
        if (!cancelled) {
          setError(err instanceof ApiError ? err.message : 'Unable to load scripts.');
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
    return <ScriptEditor projectId={project.id} scriptId={scriptId} creating={creating} />;
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
      <ScriptList projectId={project.id} scripts={scripts} loading={loading} error={error} meta={meta} />
    </div>
  );
}
