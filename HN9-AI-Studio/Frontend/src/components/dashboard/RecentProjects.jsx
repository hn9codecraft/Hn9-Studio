import { Link } from 'react-router-dom';
import ProjectCard from '../projects/ProjectCard';
import EmptyState from '../ui/EmptyState';

const DASHBOARD_PROJECT_LIMIT = 3;

export default function RecentProjects({ projects }) {
  if (!projects.length) {
    return (
      <EmptyState
        icon="bi-folder2-open"
        title="No projects yet"
        description="Create a project and it opens straight in its Creative Studio."
      >
        <Link className="btn btn-primary" to="/projects/new">
          Create project
        </Link>
      </EmptyState>
    );
  }

  const preview = projects.slice(0, DASHBOARD_PROJECT_LIMIT);

  return (
    <div className="recent-projects-grid">
      {preview.map((project) => (
        <ProjectCard key={project.id} project={project} compact />
      ))}
    </div>
  );
}
