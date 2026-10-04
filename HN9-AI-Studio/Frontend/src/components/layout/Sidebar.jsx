import { NavLink } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import BrandLogo from '../ui/BrandLogo';

const NAV_ITEMS = [
  { to: '/dashboard', label: 'Dashboard', icon: 'bi-grid-1x2' },
  { to: '/projects', label: 'Projects', icon: 'bi-folder2-open' },
  { to: '/studio', label: 'Creative Studio', icon: 'bi-camera-reels' },
  { to: '/providers', label: 'AI services', icon: 'bi-hdd-network', adminOnly: true },
  { to: '/settings', label: 'Settings', icon: 'bi-gear' },
];

export default function Sidebar() {
  const { user } = useAuth();
  const items = NAV_ITEMS.filter((item) => !item.adminOnly || user?.role === 'admin');

  return (
    <aside className="app-sidebar d-flex flex-column">
      <div className="sidebar-brand">
        <BrandLogo variant="white" height={44} />
      </div>

      <nav className="sidebar-nav flex-grow-1" aria-label="Primary">
        {items.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to !== '/projects' && item.to !== '/studio'}
            className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
          >
            <span className="sidebar-link-icon" aria-hidden="true">
              <i className={`bi ${item.icon}`} />
            </span>
            <span>{item.label}</span>
          </NavLink>
        ))}
      </nav>

      <div className="sidebar-footnote">Your work is saved to your studio account</div>
    </aside>
  );
}
