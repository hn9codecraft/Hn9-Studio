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
      <div className="sidebar-brand px-4 py-4">
        <div className="brand-mark">HN9</div>
        <div>
          <div className="brand-title">HN9 AI Studio</div>
          <div className="brand-subtitle">Content production</div>
        </div>
      </div>

      <nav className="sidebar-nav flex-grow-1 px-3" aria-label="Primary">
        {NAV_ITEMS.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.to !== '/projects'}
            className={({ isActive }) => `sidebar-link ${isActive ? 'active' : ''}`}
          >
            <i className={`bi ${item.icon}`} aria-hidden="true" />
            <span>{item.label}</span>
          </NavLink>
        ))}
      </nav>

      <div className="sidebar-footnote px-4 py-3">Connected to the Laravel API</div>
    </aside>
  );
}
