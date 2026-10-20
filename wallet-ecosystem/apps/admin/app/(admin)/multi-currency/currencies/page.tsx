import type { Metadata } from 'next';
import { Suspense } from 'react';
import { CurrenciesPage } from '@/src/modules/multi-currency/pages/currencies';

export const metadata: Metadata = {
  title: 'Currencies — Multi-Currency — OleKard Admin',
};

export default function MultiCurrencyCurrenciesRoute() {
  return (
    <Suspense fallback={<div className="p-8 text-muted-foreground">Loading currencies...</div>}>
      <CurrenciesPage />
    </Suspense>
  );
}
