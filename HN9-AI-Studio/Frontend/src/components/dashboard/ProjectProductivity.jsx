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
    <div className="card border-0 shadow-sm">
      <div className="card-body p-0">
        <div className="table-responsive">
          <table className="table studio-table mb-0 align-middle">
            <thead>
              <tr>
                <th>Project</th>
                <th>Scripts</th>
                <th>Images</th>
                <th>Videos</th>
                <th>Assets</th>
                <th>Total items</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.project.id}>
                  <td>
                    <Link to={`/projects/${row.project.id}`} className="fw-semibold text-decoration-none">
                      {row.project.name}
                    </Link>
                  </td>
                  <td>{row.scripts}</td>
                  <td>{row.images}</td>
                  <td>{row.videos}</td>
                  <td>{row.assets}</td>
                  <td className="fw-semibold">{row.total_items}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
