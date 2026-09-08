import { Link } from 'react-router-dom';
import ProjectCard from '../projects/ProjectCard';
import EmptyState from '../ui/EmptyState';

export default function RecentProjects({ projects }) {
  if (!projects.length) {
    return (
      <EmptyState
        icon="bi-folder2-open"
        title="No projects yet"
        description="Create a project to start counting real studio work on this dashboard."
      >
        <Link className="btn btn-primary" to="/projects/new">
          New Project
        </Link>
      </EmptyState>
    );
  }

  return (
    <div className="row g-3">
      {projects.map((project) => (
        <div className="col-md-6" key={project.id}>
          <ProjectCard project={project} />
        </div>
      ))}
    </div>
  );
}
