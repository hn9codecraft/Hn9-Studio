import { cachedRequest } from './requestCache';
import { apiRequest } from './apiClient';

const VOICE_TTL_MS = 5 * 60 * 1000;

export function listVoiceLanguages() {
  return cachedRequest('story:voice-languages', () => apiRequest('/story/voice-languages'), { ttlMs: VOICE_TTL_MS });
}
