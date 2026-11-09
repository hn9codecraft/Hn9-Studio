import { Link } from 'react-router-dom';
import EmptyState from '../components/ui/EmptyState';

export default function GenerationsPage() {
  return (
    <EmptyState
      headingLevel="h1"
      icon="bi-slash-circle"
      title="This page is not available"
      description="Go back to Creative Studio to keep working on a video."
    >
      <Link className="btn btn-primary" to="/studio">
        Creative Studio
      </Link>
    </EmptyState>
  );
}
