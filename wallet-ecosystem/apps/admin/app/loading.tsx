import { LoadingState } from '@/components/ui/LoadingState';

export default function RootLoading() {
  return (
    <div className="min-h-screen bg-bg-primary flex items-center justify-center">
      <LoadingState label="Loading OleKard Admin…" size="lg" />
    </div>
  );
}
