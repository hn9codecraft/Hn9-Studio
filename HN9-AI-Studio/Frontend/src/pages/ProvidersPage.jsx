import { useEffect, useState } from 'react';
import ProviderRegistryCard from '../components/providers/ProviderRegistryCard';
import AlertMessage from '../components/ui/AlertMessage';
import EmptyState from '../components/ui/EmptyState';
import LoadingSpinner from '../components/ui/LoadingSpinner';
import { ApiError } from '../services/apiClient';
import { listProviders } from '../services/providerService';

export default function ProvidersPage() {
  const [providers, setProviders] = useState([]);
  const [meta, setMeta] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [forbidden, setForbidden] = useState(false);

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setError('');
      setForbidden(false);

      try {
        const result = await listProviders({ perPage: 50 });
        if (!cancelled) {
          setProviders(result.data);
          setMeta(result.meta);
        }
      } catch (err) {
        if (!cancelled) {
          setProviders([]);
          setMeta(null);
          if (err instanceof ApiError && err.status === 403) {
            setForbidden(true);
            setError('');
          } else {
            setError(err instanceof ApiError ? err.message : 'Unable to load providers.');
          }
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

  function handleUpdated(nextProvider) {
    setProviders((current) => current.map((item) => (item.id === nextProvider.id ? nextProvider : item)));
  }

  return (
    <div className="providers-page">
      <div className="page-toolbar d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4">
        <div>
          <p className="text-uppercase small text-secondary mb-1">Administration</p>
          <h2 className="h3 mb-1">AI providers</h2>
          <p className="text-secondary mb-0">
            Registry records from the database. Enable, disable, and configuration changes are saved through the live
            API. Secrets stay masked after save.
          </p>
        </div>
      </div>

      {error ? (
        <div className="mb-4">
          <AlertMessage>{error}</AlertMessage>
        </div>
      ) : null}

      {loading ? <LoadingSpinner label="Loading providers…" /> : null}

      {!loading && forbidden ? (
        <EmptyState
          icon="bi-shield-lock"
          title="Administrator access required"
          description="Provider management is limited to administrators. Your account can still use the rest of the studio."
        />
      ) : null}

      {!loading && !forbidden && !error && providers.length === 0 ? (
        <EmptyState
          icon="bi-hdd-network"
          title="No providers in the registry"
          description="There are no ai_providers rows to manage yet. This page does not invent catalog entries."
        />
      ) : null}

      {!loading && !forbidden && providers.length > 0 ? (
        <div className="d-flex flex-column gap-4">
          {providers.map((provider) => (
            <ProviderRegistryCard key={provider.id} provider={provider} onUpdated={handleUpdated} />
          ))}
          {meta?.total ? (
            <p className="small text-secondary mb-0">
              Showing {providers.length} of {meta.total} registry records.
            </p>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}
