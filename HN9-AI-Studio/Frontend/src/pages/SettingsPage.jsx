import { useEffect, useState } from 'react';
import AlertMessage from '../components/ui/AlertMessage';
import LoadingSpinner from '../components/ui/LoadingSpinner';
import { useAuth } from '../contexts/AuthContext';
import { ApiError } from '../services/apiClient';
import { fieldError } from '../services/projectConstants';
import { fetchCurrentUser, updatePassword, updateProfile } from '../services/authService';

const EMPTY_PROFILE = {
  name: '',
  email: '',
  role: '',
  locale: '',
  timezone: '',
};

export default function SettingsPage() {
  const { user, applyUser } = useAuth();
  const [profile, setProfile] = useState(EMPTY_PROFILE);
  const [loading, setLoading] = useState(true);
  const [savingProfile, setSavingProfile] = useState(false);
  const [savingPassword, setSavingPassword] = useState(false);
  const [loadError, setLoadError] = useState('');
  const [profileError, setProfileError] = useState(null);
  const [passwordError, setPasswordError] = useState(null);
  const [profileNotice, setProfileNotice] = useState('');
  const [passwordNotice, setPasswordNotice] = useState('');
  const [passwordForm, setPasswordForm] = useState({
    current_password: '',
    new_password: '',
    new_password_confirmation: '',
  });

  useEffect(() => {
    let cancelled = false;

    async function load() {
      setLoading(true);
      setLoadError('');

      try {
        const current = await fetchCurrentUser();
        if (!cancelled) {
          setProfile({
            name: current.name || '',
            email: current.email || '',
            role: current.role || '',
            locale: current.locale || '',
            timezone: current.timezone || '',
          });
          applyUser(current);
        }
      } catch (err) {
        if (!cancelled) {
          setLoadError(err instanceof ApiError ? err.message : 'Unable to load your account.');
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
  }, [applyUser]);

  async function handleProfileSubmit(event) {
    event.preventDefault();
    setSavingProfile(true);
    setProfileError(null);
    setProfileNotice('');

    try {
      const updated = await updateProfile({
        name: profile.name.trim(),
        locale: profile.locale.trim() || null,
        timezone: profile.timezone.trim() || null,
      });
      setProfile({
        name: updated.name || '',
        email: updated.email || '',
        role: updated.role || '',
        locale: updated.locale || '',
        timezone: updated.timezone || '',
      });
      applyUser(updated);
      setProfileNotice('Account details saved.');
    } catch (err) {
      setProfileError(err instanceof ApiError ? err : new ApiError('Unable to save your account.', { status: 0 }));
    } finally {
      setSavingProfile(false);
    }
  }

  async function handlePasswordSubmit(event) {
    event.preventDefault();
    setSavingPassword(true);
    setPasswordError(null);
    setPasswordNotice('');

    try {
      await updatePassword(passwordForm);
      setPasswordForm({
        current_password: '',
        new_password: '',
        new_password_confirmation: '',
      });
      setPasswordNotice('Password updated. Use the new password the next time you sign in.');
    } catch (err) {
      setPasswordError(err instanceof ApiError ? err : new ApiError('Unable to update your password.', { status: 0 }));
    } finally {
      setSavingPassword(false);
    }
  }

  return (
    <div className="settings-page">
      <div className="page-toolbar mb-4">
        <p className="text-uppercase small text-secondary mb-1">Account</p>
        <h2 className="h3 mb-1">Settings</h2>
        <p className="text-secondary mb-0">
          Profile and password are stored on your user record. Application-wide settings and notification preferences
          are not available because those services have no persistence layer.
        </p>
      </div>

      {loadError ? (
        <div className="mb-4">
          <AlertMessage>{loadError}</AlertMessage>
        </div>
      ) : null}

      {loading ? <LoadingSpinner label="Loading account…" /> : null}

      {!loading && !loadError ? (
        <div className="row g-4">
          <div className="col-lg-7">
            <div className="card border-0 shadow-sm">
              <div className="card-body p-4">
                <h3 className="h5 mb-3">Profile</h3>
                {profileNotice ? (
                  <div className="alert alert-success py-2" role="status">
                    {profileNotice}
                  </div>
                ) : null}
                {profileError?.message ? (
                  <div className="alert alert-danger py-2" role="alert">
                    {profileError.message}
                  </div>
                ) : null}
                <form onSubmit={handleProfileSubmit}>
                  <div className="mb-3">
                    <label className="form-label" htmlFor="account-name">
                      Name
                    </label>
                    <input
                      id="account-name"
                      className={`form-control ${fieldError(profileError, 'name') ? 'is-invalid' : ''}`}
                      value={profile.name}
                      onChange={(event) => setProfile((current) => ({ ...current, name: event.target.value }))}
                      maxLength={191}
                      required
                    />
                    {fieldError(profileError, 'name') ? (
                      <div className="invalid-feedback">{fieldError(profileError, 'name')}</div>
                    ) : null}
                  </div>
                  <div className="mb-3">
                    <label className="form-label" htmlFor="account-email">
                      Email
                    </label>
                    <input id="account-email" className="form-control" value={profile.email} disabled />
                    <div className="form-text">Email is shown from your account and cannot be changed here.</div>
                  </div>
                  <div className="mb-3">
                    <label className="form-label" htmlFor="account-role">
                      Role
                    </label>
                    <input id="account-role" className="form-control" value={profile.role || user?.role || ''} disabled />
                    <div className="form-text">Roles are not editable. There is no role catalog API.</div>
                  </div>
                  <div className="row g-3 mb-4">
                    <div className="col-md-6">
                      <label className="form-label" htmlFor="account-locale">
                        Locale
                      </label>
                      <input
                        id="account-locale"
                        className={`form-control ${fieldError(profileError, 'locale') ? 'is-invalid' : ''}`}
                        value={profile.locale}
                        onChange={(event) => setProfile((current) => ({ ...current, locale: event.target.value }))}
                      />
                      {fieldError(profileError, 'locale') ? (
                        <div className="invalid-feedback">{fieldError(profileError, 'locale')}</div>
                      ) : null}
                    </div>
                    <div className="col-md-6">
                      <label className="form-label" htmlFor="account-timezone">
                        Timezone
                      </label>
                      <input
                        id="account-timezone"
                        className={`form-control ${fieldError(profileError, 'timezone') ? 'is-invalid' : ''}`}
                        value={profile.timezone}
                        onChange={(event) => setProfile((current) => ({ ...current, timezone: event.target.value }))}
                      />
                      {fieldError(profileError, 'timezone') ? (
                        <div className="invalid-feedback">{fieldError(profileError, 'timezone')}</div>
                      ) : null}
                    </div>
                  </div>
                  <button type="submit" className="btn btn-primary" disabled={savingProfile}>
                    {savingProfile ? 'Saving…' : 'Save profile'}
                  </button>
                </form>
              </div>
            </div>
          </div>

          <div className="col-lg-5">
            <div className="card border-0 shadow-sm">
              <div className="card-body p-4">
                <h3 className="h5 mb-3">Password</h3>
                {passwordNotice ? (
                  <div className="alert alert-success py-2" role="status">
                    {passwordNotice}
                  </div>
                ) : null}
                {passwordError?.message ? (
                  <div className="alert alert-danger py-2" role="alert">
                    {passwordError.message}
                  </div>
                ) : null}
                <form onSubmit={handlePasswordSubmit}>
                  <div className="mb-3">
                    <label className="form-label" htmlFor="current-password">
                      Current password
                    </label>
                    <input
                      id="current-password"
                      type="password"
                      autoComplete="current-password"
                      className={`form-control ${fieldError(passwordError, 'current_password') ? 'is-invalid' : ''}`}
                      value={passwordForm.current_password}
                      onChange={(event) =>
                        setPasswordForm((current) => ({ ...current, current_password: event.target.value }))
                      }
                      required
                    />
                    {fieldError(passwordError, 'current_password') ? (
                      <div className="invalid-feedback">{fieldError(passwordError, 'current_password')}</div>
                    ) : null}
                  </div>
                  <div className="mb-3">
                    <label className="form-label" htmlFor="new-password">
                      New password
                    </label>
                    <input
                      id="new-password"
                      type="password"
                      autoComplete="new-password"
                      className={`form-control ${fieldError(passwordError, 'new_password') ? 'is-invalid' : ''}`}
                      value={passwordForm.new_password}
                      onChange={(event) =>
                        setPasswordForm((current) => ({ ...current, new_password: event.target.value }))
                      }
                      minLength={8}
                      required
                    />
                    {fieldError(passwordError, 'new_password') ? (
                      <div className="invalid-feedback">{fieldError(passwordError, 'new_password')}</div>
                    ) : null}
                  </div>
                  <div className="mb-4">
                    <label className="form-label" htmlFor="confirm-password">
                      Confirm new password
                    </label>
                    <input
                      id="confirm-password"
                      type="password"
                      autoComplete="new-password"
                      className={`form-control ${fieldError(passwordError, 'new_password_confirmation') ? 'is-invalid' : ''}`}
                      value={passwordForm.new_password_confirmation}
                      onChange={(event) =>
                        setPasswordForm((current) => ({
                          ...current,
                          new_password_confirmation: event.target.value,
                        }))
                      }
                      minLength={8}
                      required
                    />
                    {fieldError(passwordError, 'new_password_confirmation') ? (
                      <div className="invalid-feedback">{fieldError(passwordError, 'new_password_confirmation')}</div>
                    ) : null}
                  </div>
                  <button type="submit" className="btn btn-outline-primary" disabled={savingPassword}>
                    {savingPassword ? 'Updating…' : 'Update password'}
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
      ) : null}
    </div>
  );
}
