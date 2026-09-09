import { useState } from 'react';
import { ApiError } from '../../services/apiClient';
import { fieldError, formatCapabilities, isProviderEnabled, SECRET_PLACEHOLDER, statusLabel } from '../../services/providerConstants';
import { disableProvider, enableProvider, updateProvider, updateProviderSetting } from '../../services/providerService';

function settingDraftValue(setting) {
  if (setting.is_secret) {
    return '';
  }

  return setting.value ?? '';
}

export default function ProviderRegistryCard({ provider, onUpdated }) {
  const [name, setName] = useState(provider.name || '');
  const [baseUrl, setBaseUrl] = useState(provider.base_url || '');
  const [priority, setPriority] = useState(String(provider.priority ?? 0));
  const [settingValues, setSettingValues] = useState(() =>
    Object.fromEntries((provider.settings || []).map((setting) => [setting.id, settingDraftValue(setting)])),
  );
  const [saving, setSaving] = useState(false);
  const [toggling, setToggling] = useState(false);
  const [savingSettingId, setSavingSettingId] = useState('');
  const [error, setError] = useState(null);
  const [notice, setNotice] = useState('');
  const enabled = isProviderEnabled(provider.status);

  function replaceProvider(next) {
    setName(next.name || '');
    setBaseUrl(next.base_url || '');
    setPriority(String(next.priority ?? 0));
    setSettingValues(Object.fromEntries((next.settings || []).map((setting) => [setting.id, settingDraftValue(setting)])));
    onUpdated(next);
  }

  async function handleSave(event) {
    event.preventDefault();
    setSaving(true);
    setError(null);
    setNotice('');

    try {
      const payload = {
        name: name.trim(),
        priority: Number(priority),
      };

      if (baseUrl.trim()) {
        payload.base_url = baseUrl.trim();
      } else {
        payload.base_url = null;
      }

      const updated = await updateProvider(provider.id, payload);
      replaceProvider(updated);
      setNotice('Provider registry details saved.');
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to save this provider.', { status: 0 }));
    } finally {
      setSaving(false);
    }
  }

  async function handleToggle() {
    setToggling(true);
    setError(null);
    setNotice('');

    try {
      const updated = enabled ? await disableProvider(provider.id) : await enableProvider(provider.id);
      replaceProvider(updated);
      setNotice(enabled ? 'Provider marked inactive in the registry.' : 'Provider marked active in the registry.');
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to update provider status.', { status: 0 }));
    } finally {
      setToggling(false);
    }
  }

  async function handleSettingSave(setting) {
    const nextValue = settingValues[setting.id] ?? '';

    if (setting.is_secret && (!nextValue || nextValue === SECRET_PLACEHOLDER)) {
      setError(new ApiError('Enter a new secret value to replace the stored credential.', { status: 422 }));
      return;
    }

    setSavingSettingId(setting.id);
    setError(null);
    setNotice('');

    try {
      const updatedSetting = await updateProviderSetting(setting.id, {
        value: nextValue,
        is_secret: setting.is_secret,
        environment: setting.environment,
      });
      const nextSettings = (provider.settings || []).map((item) => (item.id === setting.id ? updatedSetting : item));
      replaceProvider({ ...provider, settings: nextSettings });
      setNotice(setting.is_secret ? 'Secret updated. The stored value is not shown again.' : 'Configuration value saved.');
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Unable to save this configuration value.', { status: 0 }));
    } finally {
      setSavingSettingId('');
    }
  }

  const settings = Array.isArray(provider.settings) ? provider.settings : [];

  return (
    <article className="card border-0 shadow-sm provider-card">
      <div className="card-body p-4">
        <div className="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-3">
          <div>
            <h3 className="h5 mb-1">{provider.name}</h3>
            <p className="text-secondary small mb-0">
              <code>{provider.slug}</code>
              {provider.category ? ` · ${provider.category}` : ''}
            </p>
          </div>
          <div className="d-flex flex-wrap align-items-center gap-2">
            <span className={`status-pill status-${provider.status || 'inactive'}`}>{statusLabel(provider.status)}</span>
            <button type="button" className="btn btn-outline-secondary btn-sm" onClick={handleToggle} disabled={toggling}>
              {toggling ? 'Updating…' : enabled ? 'Disable' : 'Enable'}
            </button>
          </div>
        </div>

        <p className="small text-secondary mb-3">
          This is the database registry status, not a live vendor connection check. Runtime adapters still read
          environment configuration separately.
        </p>

        {notice ? (
          <div className="alert alert-success py-2" role="status">
            {notice}
          </div>
        ) : null}

        {error?.message ? (
          <div className="alert alert-danger py-2" role="alert">
            {error.message}
          </div>
        ) : null}

        <form onSubmit={handleSave} className="row g-3 mb-4">
          <div className="col-md-6">
            <label className="form-label" htmlFor={`provider-name-${provider.id}`}>
              Display name
            </label>
            <input
              id={`provider-name-${provider.id}`}
              className={`form-control ${fieldError(error, 'name') ? 'is-invalid' : ''}`}
              value={name}
              onChange={(event) => setName(event.target.value)}
              maxLength={255}
              required
            />
            {fieldError(error, 'name') ? <div className="invalid-feedback">{fieldError(error, 'name')}</div> : null}
          </div>
          <div className="col-md-4">
            <label className="form-label" htmlFor={`provider-base-url-${provider.id}`}>
              Base URL
            </label>
            <input
              id={`provider-base-url-${provider.id}`}
              className={`form-control ${fieldError(error, 'base_url') ? 'is-invalid' : ''}`}
              value={baseUrl}
              onChange={(event) => setBaseUrl(event.target.value)}
              placeholder="Optional"
              maxLength={255}
            />
            {fieldError(error, 'base_url') ? <div className="invalid-feedback">{fieldError(error, 'base_url')}</div> : null}
          </div>
          <div className="col-md-2">
            <label className="form-label" htmlFor={`provider-priority-${provider.id}`}>
              Priority
            </label>
            <input
              id={`provider-priority-${provider.id}`}
              className={`form-control ${fieldError(error, 'priority') ? 'is-invalid' : ''}`}
              type="number"
              min="0"
              value={priority}
              onChange={(event) => setPriority(event.target.value)}
              required
            />
            {fieldError(error, 'priority') ? <div className="invalid-feedback">{fieldError(error, 'priority')}</div> : null}
          </div>
          <div className="col-12">
            <p className="small text-secondary mb-2">
              Capabilities: {formatCapabilities(provider.capabilities)}
            </p>
            <button type="submit" className="btn btn-primary" disabled={saving}>
              {saving ? 'Saving…' : 'Save registry details'}
            </button>
          </div>
        </form>

        <div>
          <h4 className="h6 mb-2">Stored configuration</h4>
          {settings.length === 0 ? (
            <p className="text-secondary small mb-0">No configuration entries are stored for this provider.</p>
          ) : (
            <div className="d-flex flex-column gap-3">
              {settings.map((setting) => (
                <div key={setting.id} className="provider-setting-row">
                  <div className="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <strong>{setting.key}</strong>
                    <span className="small text-secondary">
                      {setting.environment || 'production'}
                      {setting.is_secret ? ' · secret' : ''}
                    </span>
                  </div>
                  {setting.is_secret ? (
                    <p className="small text-secondary mb-2">Stored value is hidden. Leave blank to keep the current secret.</p>
                  ) : null}
                  <div className="input-group">
                    <input
                      className="form-control"
                      type={setting.is_secret ? 'password' : 'text'}
                      autoComplete="off"
                      placeholder={setting.is_secret ? 'Enter a new value to replace the secret' : ''}
                      value={settingValues[setting.id] ?? ''}
                      onChange={(event) =>
                        setSettingValues((current) => ({ ...current, [setting.id]: event.target.value }))
                      }
                    />
                    <button
                      type="button"
                      className="btn btn-outline-primary"
                      disabled={savingSettingId === setting.id}
                      onClick={() => handleSettingSave(setting)}
                    >
                      {savingSettingId === setting.id ? 'Saving…' : 'Save value'}
                    </button>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>
      </div>
    </article>
  );
}
