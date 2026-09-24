import EmptyState from '../components/ui/EmptyState';

export default function GenerationsPage() {
  return (
    <>
      <h1 className="visually-hidden">Generations</h1>
      <EmptyState
        headingLevel="h2"
        icon="bi-stars"
        title="Generations is not available yet"
        description="This is a route shell for M7.1. Script, image, and video generation workflows are not implemented in this milestone."
      />
    </>
  );
}
