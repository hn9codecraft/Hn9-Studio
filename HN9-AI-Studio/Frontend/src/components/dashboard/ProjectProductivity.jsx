import { Link } from 'react-router-dom';
import EmptyState from '../ui/EmptyState';

export default function ProjectProductivity({ rows }) {
  if (!rows.length) {
    return (
      <EmptyState
        icon="bi-kanban"
        title="No analytics data available yet."
        description="Project productivity lists your owned projects and their real studio item counts."
      />
    );
  }

  return (
    <div className="card border-0 shadow-sm studio-table-card">
      <div className="card-body p-0">
        <div className="productivity-table-wrap">
          <table className="table studio-table productivity-table mb-0 align-middle">
            <thead>
              <tr>
                <th scope="col">Project</th>
                <th scope="col" className="numeric-cell">
                  Scripts
                </th>
                <th scope="col" className="numeric-cell">
                  Images
                </th>
                <th scope="col" className="numeric-cell">
                  Videos
                </th>
                <th scope="col" className="numeric-cell">
                  Assets
                </th>
                <th scope="col" className="numeric-cell">
                  Total items
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.project.id}>
                  <td data-label="Project">
                    <Link to={`/projects/${row.project.id}`} className="project-name activity-link text-decoration-none">
                      {row.project.name}
                    </Link>
                  </td>
                  <td className="numeric-cell" data-label="Scripts">
                    {row.scripts}
                  </td>
                  <td className="numeric-cell" data-label="Images">
                    {row.images}
                  </td>
                  <td className="numeric-cell" data-label="Videos">
                    {row.videos}
                  </td>
                  <td className="numeric-cell" data-label="Assets">
                    {row.assets}
                  </td>
                  <td className="numeric-cell fw-semibold" data-label="Total items">
                    {row.total_items}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
