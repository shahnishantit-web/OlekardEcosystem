import type { Metadata } from 'next';
import { Suspense } from 'react';
import { ConfigurationKeyDetailPage } from '@/src/modules/configuration/pages/configuration-key-detail';

export const metadata: Metadata = {
  title: 'Configuration Key Detail — OleKard Admin',
};

interface Props {
  params: { domain: string; key: string };
}

export default function ConfigurationKeyDetailRoute({ params }: Props) {
  // The list supplies the complete key; domain is only its grouping metadata.
  const configKey = params.key;
  return (
    <Suspense fallback={<div className="p-8 text-muted-foreground">Loading key details...</div>}>
      <ConfigurationKeyDetailPage configKey={configKey} />
    </Suspense>
  );
}
