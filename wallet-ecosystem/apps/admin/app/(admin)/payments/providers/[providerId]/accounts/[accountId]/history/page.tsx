'use client';

import { useEffect, useState } from 'react';
import { useParams } from 'next/navigation';
import Link from 'next/link';
import { useAuth } from '@/lib/auth/context';
import { PaymentApiClient } from '@/lib/api/payment';
import { VersionHistoryTimeline } from '@/src/modules/payment/components/VersionHistoryTimeline';
import type { ConfigDraft } from '@/lib/api/payment';

export default function AccountHistoryPage() {
  const { providerId, accountId } = useParams<{ providerId: string; accountId: string }>();
  const { authenticatedClient, hasPermission } = useAuth();
  const [drafts, setDrafts] = useState<ConfigDraft[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authenticatedClient || !providerId) return;
    const client = new PaymentApiClient(authenticatedClient);
    setLoading(true);
    client.listConfigDrafts(providerId)
      .then((res) => {
        setDrafts((res.data as { data: ConfigDraft[] }).data);
      })
      .catch(() => setError('Failed to load history'))
      .finally(() => setLoading(false));
  }, [authenticatedClient, providerId]);

  if (!hasPermission('payment.providers.read')) {
    return <div className="p-6 text-destructive">Access denied.</div>;
  }

  return (
    <div className="p-6 max-w-2xl">
      <div className="mb-2 text-sm">
        <Link href={`/payments/providers/${providerId}/accounts/${accountId}`} className="underline text-muted-foreground">
          ← Account Detail
        </Link>
      </div>
      <h1 className="text-xl font-semibold mb-6">Configuration Change History</h1>
      {loading && <p>Loading history...</p>}
      {error && <p className="text-destructive">{error}</p>}
      {!loading && !error && <VersionHistoryTimeline drafts={drafts} />}
    </div>
  );
}
