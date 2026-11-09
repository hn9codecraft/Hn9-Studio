import EmptyState from '../ui/EmptyState';

export default function ComingNextPanel({ title }) {
  return (
    <EmptyState
      icon="bi-hourglass-split"
      title={`${title} is coming next`}
      description={`${title} is not available on this page. Open the project in Creative Studio to keep working on the video.`}
    />
  );
}
