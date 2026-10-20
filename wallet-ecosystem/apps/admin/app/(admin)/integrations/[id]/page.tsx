import type { Metadata } from 'next';
import { ApiClientDetailPage } from '@/src/modules/integrations/pages/api-client-detail';

export const metadata: Metadata = {
  title: 'API Client — OleKard Admin',
};

export default function IntegrationDetailRoute({ params }: { params: { id: string } }) {
  return <ApiClientDetailPage clientId={params.id} />;
}
