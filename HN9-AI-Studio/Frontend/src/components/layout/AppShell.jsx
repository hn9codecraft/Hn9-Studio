import { Outlet, useLocation } from 'react-router-dom';
import DocumentTitle from '../ui/DocumentTitle';
import Header from './Header';
import Sidebar from './Sidebar';

const TITLES = {
  '/dashboard': 'Dashboard',
  '/projects': 'Projects',
  '/projects/new': 'New Project',
  '/generations': 'Generations',
  '/providers': 'Providers',
  '/settings': 'Settings',
};

function pageTitle(pathname) {
  if (TITLES[pathname]) {
    return TITLES[pathname];
  }

  if (pathname.includes('/scripts')) {
    return 'Script Studio';
  }

  if (pathname.includes('/images')) {
    return 'Image Studio';
  }

  if (pathname.includes('/videos')) {
    return 'Video Studio';
  }

  if (pathname.includes('/assets')) {
    return 'Asset Studio';
  }

  if (pathname.includes('/activity')) {
    return 'Activity Studio';
  }

  if (pathname.startsWith('/projects/')) {
    return 'Project Workspace';
  }

  return 'HN9 AI Studio';
}

export default function AppShell() {
  const location = useLocation();
  const title = pageTitle(location.pathname);

  return (
    <div className="app-shell">
      <DocumentTitle title={title} />
      <a className="skip-link" href="#main-content">
        Skip to main content
      </a>
      <div className="app-sidebar-rail d-none d-lg-block">
        <Sidebar />
      </div>

      <div className="offcanvas offcanvas-start app-offcanvas d-lg-none" tabIndex="-1" id="mobileSidebar" aria-label="Mobile navigation">
        <div className="offcanvas-header">
          <p className="offcanvas-title h5 mb-0">Navigation</p>
          <button type="button" className="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close navigation" />
        </div>
        <div className="offcanvas-body p-0" data-bs-dismiss="offcanvas">
          <Sidebar />
        </div>
      </div>

      <div className="app-main">
        <Header title={title} />
        <main className="app-content" id="main-content">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
