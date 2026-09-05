import EmptyState from '../components/ui/EmptyState';

export default function ProvidersPage() {
  return (
    <EmptyState
      icon="bi-hdd-network"
      title="Providers is not available yet"
      description="This is a route shell for M7.1. Provider catalog and credential management will be added later against the live Laravel provider API."
    />
  );
}
