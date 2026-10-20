import type { Metadata } from 'next';
import { WebhookMonitorPage } from '@/src/modules/integrations/pages/webhook-monitor';

export const metadata: Metadata = {
  title: 'Webhook Events — OleKard Admin',
};

export default function IntegrationWebhooksRoute() {
  return <WebhookMonitorPage />;
}
