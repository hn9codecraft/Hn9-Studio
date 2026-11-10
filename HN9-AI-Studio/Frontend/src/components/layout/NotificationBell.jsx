import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { listNotifications, markAllNotificationsRead, markNotificationRead } from '../../services/notificationService';

const POLL_MS = 30000;

export default function NotificationBell() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [items, setItems] = useState([]);
  const [unread, setUnread] = useState(0);

  useEffect(() => {
    if (!user) {
      setItems([]);
      setUnread(0);
      return undefined;
    }

    let stopped = false;
    async function load() {
      try {
        const payload = await listNotifications();
        if (stopped) return;
        setItems(Array.isArray(payload?.notifications) ? payload.notifications : []);
        setUnread(Number(payload?.unread_count) || 0);
      } catch {
        if (!stopped) {
          setItems([]);
          setUnread(0);
        }
      }
    }

    load();
    const timer = window.setInterval(load, POLL_MS);
    window.addEventListener('focus', load);
    return () => {
      stopped = true;
      window.clearInterval(timer);
      window.removeEventListener('focus', load);
    };
  }, [user]);

  if (!user) return null;

  async function open(item) {
    try {
      await markNotificationRead(item.id);
    } catch {
      // The list refreshes on the next poll.
    }
    setItems((current) => current.map((row) => (row.id === item.id ? { ...row, read_at: row.read_at || new Date().toISOString() } : row)));
    setUnread((count) => Math.max(0, count - (item.read_at ? 0 : 1)));
    const link = item?.data?.link;
    if (typeof link === 'string' && link.startsWith('/')) navigate(link);
  }

  async function markAll() {
    try {
      await markAllNotificationsRead();
      setItems((current) => current.map((row) => ({ ...row, read_at: row.read_at || new Date().toISOString() })));
      setUnread(0);
    } catch {
      // Leave the badge as it is.
    }
  }

  return (
    <div className="dropdown">
      <button className="btn btn-ghost position-relative" type="button" data-bs-toggle="dropdown" aria-expanded="false" aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}>
        <i className="bi bi-bell" aria-hidden="true" />
        {unread > 0 ? <span className="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-primary">{unread > 9 ? '9+' : unread}</span> : null}
      </button>
      <div className="dropdown-menu dropdown-menu-end p-2 shadow" style={{ minWidth: '18rem' }}>
        <div className="d-flex justify-content-between align-items-center px-2 mb-2">
          <span className="fw-semibold">Notifications</span>
          <button type="button" className="btn btn-link btn-sm" onClick={markAll} disabled={unread === 0}>
            Mark all read
          </button>
        </div>
        {items.length === 0 ? <p className="small text-secondary px-2 mb-1">No notifications yet.</p> : null}
        <ul className="list-unstyled mb-0">
          {items.map((item) => (
            <li key={item.id}>
              <button type="button" className={`dropdown-item text-wrap small ${item.read_at ? '' : 'fw-semibold'}`} onClick={() => open(item)}>
                {item?.data?.message || 'Update'}
              </button>
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
