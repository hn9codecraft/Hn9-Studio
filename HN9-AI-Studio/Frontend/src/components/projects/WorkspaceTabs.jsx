import { NavLink } from 'react-router-dom';
import { WORKSPACE_SECTIONS } from '../../services/projectConstants';

export default function WorkspaceTabs({ projectId, section }) {
  return (
    <nav className="workspace-tabs" aria-label="Project workspace">
      {WORKSPACE_SECTIONS.map((item) => {
        const isActive = item.key === (section || 'overview');
        // Images, video and the final video are produced in the Creative Studio.
        if (item.studioPath && !isActive) return null;
        const to = item.path ? `/projects/${projectId}/${item.path}` : `/projects/${projectId}`;

        return (
          <NavLink key={item.key} to={to} className={`workspace-tab ${isActive ? 'active' : ''}`} end={!item.path}>
            {item.label}
          </NavLink>
        );
      })}
    </nav>
  );
}
