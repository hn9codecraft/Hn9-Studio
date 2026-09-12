import { NavLink } from 'react-router-dom';

const NAV_ITEMS = [
  { to: '/dashboard', label: 'Dashboard', icon: 'bi-grid-1x2' },
  { to: '/projects', label: 'Projects', icon: 'bi-folder2-open' },
  { to: '/generations', label: 'Generations', icon: 'bi-stars' },
  { to: '/providers', label: 'Providers', icon: 'bi-hdd-network' },
  { to: '/settings', label: 'Settings', icon: 'bi-gear' },
];

export default function Sidebar() {
  return (
    <aside className="app-sidebar d-flex flex-column">
      <div className="sidebar-brand">
        <div className="brand-mark">HN9</div>
        <div>
          <div className="brand-title">HN9 AI Studio</div>
          <div className="brand-subtitle">Content production</div>
        </div>
      </div>

      <nav className="sidebar-nav flex-grow-1" aria-label="Primary">
        {NAV_ITEMS.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to !== '/projects'}
            className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
          >
            <span className="sidebar-link-icon" aria-hidden="true">
              <i className={`bi ${item.icon}`} />
            </span>
            <span>{item.label}</span>
          </NavLink>
        ))}
      </nav>

      <div className="sidebar-footnote">Connected to the Laravel API</div>
    </aside>
  );
}
