import { getToken } from './tokenStorage';

// Entries are scoped to the signed-in token so a later session in the same tab
// can never be served another user's cached response.
const entries = new Map();

function scopedKey(key) {
  return `${getToken() || 'guest'}\u0000${key}`;
}

/**
 * Shares one in-flight request between concurrent callers and, when `ttlMs`
 * is positive, reuses the settled response until it expires or is invalidated.
 */
export function cachedRequest(key, loader, { ttlMs = 0 } = {}) {
  const fullKey = scopedKey(key);
  const existing = entries.get(fullKey);

  if (existing && (existing.pending || existing.expiresAt > Date.now())) {
    return existing.promise;
  }

  const entry = { key, pending: true, expiresAt: 0, promise: null };
  entry.promise = loader().then(
    (value) => {
      entry.pending = false;
      entry.expiresAt = Date.now() + ttlMs;
      if (ttlMs <= 0 && entries.get(fullKey) === entry) {
        entries.delete(fullKey);
      }
      return value;
    },
    (error) => {
      if (entries.get(fullKey) === entry) {
        entries.delete(fullKey);
      }
      throw error;
    },
  );
  entries.set(fullKey, entry);

  return entry.promise;
}

export function invalidateCachedRequests(prefix) {
  for (const [fullKey, entry] of entries) {
    if (entry.key.startsWith(prefix)) {
      entries.delete(fullKey);
    }
  }
}
