import { Navigate, Route, Routes, useParams } from 'react-router-dom';
import AppShell from '../components/layout/AppShell';
import DashboardPage from '../pages/DashboardPage';
import GenerationsPage from '../pages/GenerationsPage';
import LoginPage from '../pages/LoginPage';
import CreateProjectPage from '../pages/projects/CreateProjectPage';
import ProjectsPage from '../pages/projects/ProjectsPage';
import ProjectWorkspacePage from '../pages/projects/ProjectWorkspacePage';
import ProvidersPage from '../pages/ProvidersPage';
import SettingsPage from '../pages/SettingsPage';
import ProjectStoryPage from '../pages/story/ProjectStoryPage';
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
          <Route path="/studio" element={<ProjectStoryPage />} />
          <Route path="/studio/:projectId" element={<ProjectStoryPage />} />
          <Route path="/studio/:projectId/images" element={<ProjectStoryPage />} />
          <Route path="/studio/:projectId/images/new" element={<ProjectStoryPage />} />
          <Route path="/studio/:projectId/images/generate" element={<ProjectStoryPage />} />
          <Route path="/studio/:projectId/images/:imageId/regenerate" element={<ProjectStoryPage />} />
          <Route path="/studio/:projectId/images/:imageId" element={<ProjectStoryPage />} />
          <Route path="/story" element={<Navigate to="/studio" replace />} />
          <Route path="/story/:projectId" element={<LegacyStoryRedirect />} />
          <Route path="/projects/:projectId" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/scripts/generate" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/scripts/new" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/scripts/:scriptId" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/images/generate" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/images/new" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/images/:imageId/regenerate" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/images/:imageId" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/videos/generate" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/videos/new" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/videos/:videoId/regenerate" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/videos/:videoId" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/assets/new" element={<ProjectWorkspacePage />} />
          <Route path="/projects/:projectId/assets/:assetId" element={<ProjectWorkspacePage />} />
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

function LegacyStoryRedirect() {
  const { projectId } = useParams();

  return <Navigate to={`/studio/${projectId}`} replace />;
}
