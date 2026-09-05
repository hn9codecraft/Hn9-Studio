import { useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import AlertMessage from '../components/ui/AlertMessage';
import { useAuth } from '../contexts/AuthContext';
import { ApiError } from '../services/apiClient';

export default function LoginPage() {
  const { login } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event) {
    event.preventDefault();
    setError('');
    setSubmitting(true);

    try {
      await login(email.trim(), password);
      const next = location.state?.from && location.state.from !== '/login' ? location.state.from : '/dashboard';
      navigate(next, { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Unable to sign in. Try again.');
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div className="login-screen">
      <div className="login-panel card shadow">
        <div className="card-body p-4 p-md-5">
          <div className="mb-4">
            <div className="brand-mark mb-3">HN9</div>
            <h1 className="h3 mb-1">HN9 AI Studio</h1>
            <p className="text-secondary mb-0">Sign in with your studio account.</p>
          </div>

          {error ? (
            <div className="mb-3">
              <AlertMessage>{error}</AlertMessage>
            </div>
          ) : null}

          <form onSubmit={handleSubmit}>
            <div className="mb-3">
              <label className="form-label" htmlFor="email">
                Email
              </label>
              <input
                id="email"
                className="form-control"
                type="email"
                autoComplete="username"
                value={email}
                onChange={(event) => setEmail(event.target.value)}
                required
              />
            </div>
            <div className="mb-4">
              <label className="form-label" htmlFor="password">
                Password
              </label>
              <input
                id="password"
                className="form-control"
                type="password"
                autoComplete="current-password"
                value={password}
                onChange={(event) => setPassword(event.target.value)}
                required
              />
            </div>
            <button className="btn btn-primary w-100" type="submit" disabled={submitting}>
              {submitting ? 'Signing in…' : 'Sign in'}
            </button>
          </form>
        </div>
      </div>
    </div>
  );
}
