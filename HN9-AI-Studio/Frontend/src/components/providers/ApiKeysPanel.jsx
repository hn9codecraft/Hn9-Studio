import { useEffect, useState } from 'react';
import { friendlyError } from '../../services/studioMessages';
import {
  listProviderCredentials,
  removeProviderCredential,
  saveProviderCredential,
  testProviderCredential,
} from '../../services/providerService';
import { BusyButton, StatusBadge } from '../studio/StudioUi';

const HELP = {
  openai: 'https://platform.openai.com/api-keys',
  gemini: 'https://aistudio.google.com/apikey',
  elevenlabs: 'https://elevenlabs.io/app/settings/api-keys',
  runway: 'https://dev.runwayml.com/',
  luma: 'https://lumalabs.ai/dream-machine/api/keys',
  seedance: 'https://console.byteplus.com/',
};

function badge(row) {
  if (row.source === 'env') return { tone: 'success', text: 'Set in .env' };
  if (row.last_test_result === 'connected') return { tone: 'success', text: 'Connected' };
  if (row.last_test_result === 'invalid_key') return { tone: 'danger', text: 'Invalid key' };
  if (row.last_test_result === 'unreachable' || row.last_test_result === 'rate_limited') return { tone: 'warning', text: 'Unreachable' };
  if (row.last_test_result === 'not_verifiable') return { tone: 'neutral', text: 'Not verifiable' };
  if (row.source === 'stored') return { tone: 'progress', text: 'Saved, not tested' };
  return { tone: 'neutral', text: 'Not set' };
}

export default function ApiKeysPanel() {
  const [rows, setRows] = useState(null);
  const [error, setError] = useState('');
  const [drafts, setDrafts] = useState({});
  const [advanced, setAdvanced] = useState({});
  const [busy, setBusy] = useState('');
  const [confirming, setConfirming] = useState('');

  async function load() {
    const result = await listProviderCredentials();
    setRows(Array.isArray(result) ? result : []);
  }

  useEffect(() => {
    let cancelled = false;
    listProviderCredentials()
      .then((result) => {
        if (!cancelled) setRows(Array.isArray(result) ? result : []);
      })
      .catch((err) => {
        if (!cancelled) setError(friendlyError(err, 'API keys could not be loaded.'));
      });
    return () => {
      cancelled = true;
    };
  }, []);

  function settingsDraft(row) {
    return advanced[row.slug] || Object.fromEntries((row.settings || []).map((item) => [item.key, item.value || '']));
  }

  async function save(row) {
    const slug = row.slug;
    const apiKey = drafts[slug] || '';
    const settings = settingsDraft(row);
    setBusy(`save-${slug}`);
    setError('');
    try {
      const payload = { settings };
      if (apiKey !== '') payload.api_key = apiKey;
      await saveProviderCredential(slug, payload);
      setDrafts((current) => ({ ...current, [slug]: '' }));
      await load();
    } catch (err) {
      setError(friendlyError(err, 'The key could not be saved.'));
    } finally {
      setBusy('');
    }
  }

  async function test(slug) {
    setBusy(`test-${slug}`);
    setError('');
    try {
      await testProviderCredential(slug);
      await load();
    } catch (err) {
      setError(friendlyError(err, 'The connection test could not be finished.'));
    } finally {
      setBusy('');
    }
  }

  async function remove(slug) {
    setBusy(`remove-${slug}`);
    setError('');
    try {
      await removeProviderCredential(slug);
      setConfirming('');
      await load();
    } catch (err) {
      setError(friendlyError(err, 'The key could not be removed.'));
    } finally {
      setBusy('');
    }
  }

  return (
    <section className="glass-card p-3 mb-4" aria-labelledby="api-keys-heading">
      <h2 className="h5" id="api-keys-heading">
        API keys
      </h2>
      <p className="small text-secondary">A key saved here is used only when Backend/.env does not already have one. The full key is never shown again.</p>
      {error ? <p className="small text-danger">{error}</p> : null}
      {rows === null ? <p className="small text-secondary mb-0">Loading keys…</p> : null}
      <div className="d-grid gap-3">
        {(rows || []).map((row) => {
          const state = badge(row);
          const locked = row.editable === false;
          return (
            <article key={row.slug} className="border rounded p-3">
              <div className="d-flex flex-wrap justify-content-between gap-2">
                <h3 className="h6 mb-0">{row.name}</h3>
                <StatusBadge tone={state.tone}>{state.text}</StatusBadge>
              </div>
              <p className="small mb-2 mt-2">
                {row.masked || 'No key saved'}
                {row.last_tested_at ? <span className="text-secondary"> · Last tested {row.last_tested_at}</span> : null}
              </p>
              {locked ? <p className="small text-secondary">Set in Backend/.env. Remove it there to manage it here.</p> : null}
              <label className="form-label" htmlFor={`key-${row.slug}`}>
                API key
              </label>
              <input
                id={`key-${row.slug}`}
                className="form-control"
                type="password"
                autoComplete="off"
                value={drafts[row.slug] || ''}
                disabled={locked}
                onChange={(event) => setDrafts((current) => ({ ...current, [row.slug]: event.target.value }))}
              />
              {HELP[row.slug] ? (
                <p className="small mt-2 mb-2">
                  <a href={HELP[row.slug]} target="_blank" rel="noreferrer">
                    Where to find this key
                  </a>
                </p>
              ) : null}
              <div className="d-flex flex-wrap gap-2">
                <BusyButton className="btn btn-primary btn-sm" busy={busy === `save-${row.slug}`} busyLabel="Saving…" disabled={locked} onClick={() => save(row)}>
                  Save
                </BusyButton>
                <BusyButton className="btn btn-outline-primary btn-sm" busy={busy === `test-${row.slug}`} busyLabel="Testing…" onClick={() => test(row.slug)}>
                  Test connection
                </BusyButton>
                {confirming === row.slug ? (
                  <BusyButton className="btn btn-outline-danger btn-sm" busy={busy === `remove-${row.slug}`} busyLabel="Removing…" onClick={() => remove(row.slug)}>
                    Confirm remove
                  </BusyButton>
                ) : (
                  <button type="button" className="btn btn-outline-danger btn-sm" disabled={locked || !row.configured} onClick={() => setConfirming(row.slug)}>
                    Remove
                  </button>
                )}
              </div>
              {(row.settings || []).length > 0 ? (
                <details className="mt-3">
                  <summary>Advanced</summary>
                  <div className="d-grid gap-2 mt-2">
                    {row.settings.map((setting) => (
                      <div key={setting.key}>
                        <label className="form-label" htmlFor={`${row.slug}-${setting.key}`}>
                          {setting.key.replaceAll('_', ' ')}
                        </label>
                        <input
                          id={`${row.slug}-${setting.key}`}
                          className="form-control form-control-sm"
                          value={settingsDraft(row)[setting.key] || ''}
                          onChange={(event) =>
                            setAdvanced((current) => ({
                              ...current,
                              [row.slug]: { ...settingsDraft(row), [setting.key]: event.target.value },
                            }))
                          }
                        />
                      </div>
                    ))}
                  </div>
                </details>
              ) : null}
            </article>
          );
        })}
      </div>
    </section>
  );
}
