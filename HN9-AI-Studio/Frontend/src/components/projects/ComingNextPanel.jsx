import EmptyState from '../ui/EmptyState';

export default function ComingNextPanel({ title }) {
  return (
    <EmptyState
      icon="bi-hourglass-split"
      title={`${title} is coming next`}
      description={`${title} is not part of this milestone. This workspace tab is a placeholder so the studio layout is ready — it does not generate or display fake content.`}
    />
  );
}
