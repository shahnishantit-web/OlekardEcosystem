import { ErrorPage } from '@/components/ui/ErrorPage';

export default function NotFound() {
  return (
    <ErrorPage
      code="404"
      title="Page not found"
      message="The page you're looking for doesn't exist or may have been moved. Check the URL or navigate back to the dashboard."
      variant="neutral"
      primaryAction={{ label: 'Go to Dashboard', href: '/dashboard' }}
      secondaryAction={{ label: 'Go to Login', href: '/login' }}
    />
  );
}
