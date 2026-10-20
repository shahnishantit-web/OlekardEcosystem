import type { Metadata } from 'next';
import { Suspense } from 'react';
import { FxRatesPage } from '@/src/modules/multi-currency/pages/fx-rates';

export const metadata: Metadata = {
  title: 'FX Rates — Multi-Currency — OleKard Admin',
};

export default function MultiCurrencyFxRatesRoute() {
  return (
    <Suspense fallback={<div className="p-8 text-muted-foreground">Loading FX rates...</div>}>
      <FxRatesPage />
    </Suspense>
  );
}
