import type { Metadata } from 'next';
import { IntegrationListPage } from '@/src/modules/integrations/pages/integration-list';

export const metadata: Metadata = {
  title: 'Provider Integrations — OleKard Admin',
};

export default function IntegrationProvidersRoute() {
  return <IntegrationListPage />;
}
