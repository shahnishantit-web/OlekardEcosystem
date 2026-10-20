import { LoadingState } from '@/components/ui/LoadingState';

/**
 * Shown by Next.js while a page segment inside the (admin) route group
 * is streaming / waiting for async server data.
 */
export default function AdminLoading() {
  return (
    <div className="flex items-center justify-center min-h-[calc(100vh-var(--header-height))]">
      <LoadingState label="Loading…" size="md" />
    </div>
  );
}
