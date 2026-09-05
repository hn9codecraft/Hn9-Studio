import { apiRequest } from './apiClient';
import { clearToken, setToken } from './tokenStorage';

export async function login(email, password) {
  const data = await apiRequest('/auth/login', {
    method: 'POST',
    auth: false,
    body: { email, password },
  });

  if (!data?.token) {
    throw new Error('Login succeeded without a token.');
  }

  setToken(data.token);

  return data;
}

export async function fetchCurrentUser() {
  return apiRequest('/auth/user');
}

export async function logout() {
  try {
    await apiRequest('/auth/logout', { method: 'POST' });
  } finally {
    clearToken();
  }
}
