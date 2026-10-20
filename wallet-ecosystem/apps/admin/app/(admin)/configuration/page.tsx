import type { Metadata } from 'next';
import { Suspense } from 'react';
import { ConfigurationListPage } from '@/src/modules/configuration/pages/configuration-list';

export const metadata: Metadata = {
  title: 'Configuration Management — OleKard Admin',
};

export default function ConfigurationRoute() {
  return (
    <Suspense fallback={<div className="p-8 text-muted-foreground">Loading configuration...</div>}>
      <ConfigurationListPage />
    </Suspense>
  );
}
