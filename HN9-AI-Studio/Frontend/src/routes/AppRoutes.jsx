import { Navigate, Route, Routes } from 'react-router-dom';
import AppShell from '../components/layout/AppShell';
import DashboardPage from '../pages/DashboardPage';
import GenerationsPage from '../pages/GenerationsPage';
import LoginPage from '../pages/LoginPage';
import CreateProjectPage from '../pages/projects/CreateProjectPage';
import ProjectsPage from '../pages/projects/ProjectsPage';
import ProjectWorkspacePage from '../pages/projects/ProjectWorkspacePage';
import ProvidersPage from '../pages/ProvidersPage';
import SettingsPage from '../pages/SettingsPage';
import GuestRoute from './GuestRoute';
import ProtectedRoute from './ProtectedRoute';

export default function AppRoutes() {
  return (
    <Routes>
      <Route element={<GuestRoute />}>
        <Route path="/login" element={<LoginPage />} />
      </Route>

      <Route element={<ProtectedRoute />}>
        <Route element={<AppShell />}>
          <Route path="/dashboard" element={<DashboardPage />} />
          <Route path="/projects" element={<ProjectsPage />} />
          <Route path="/projects/new" element={<CreateProjectPage />} />
          <Route path="/projects/:projectId" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/:section" element={<ProjectWorkspacePage />} />
          <Route path="/generations" element={<GenerationsPage />} />
          <Route path="/providers" element={<ProvidersPage />} />
          <Route path="/settings" element={<SettingsPage />} />
        </Route>
      </Route>

      <Route path="/" element={<Navigate to="/dashboard" replace />} />
      <Route path="*" element={<Navigate to="/dashboard" replace />} />
    </Routes>
  );
}
