'use client';

import { Badge } from '@/components/ui/Badge';
import type { ProviderEnvironment } from '@/lib/api/payment';

interface Props {
  environment: ProviderEnvironment;
  className?: string;
}

export function EnvironmentBadge({ environment, className }: Props) {
  const extra = className !== undefined ? { className } : {};
  if (environment === 'production') {
    return <Badge label="PRODUCTION — Live traffic" variant="error" {...extra} />;
  }
  if (environment === 'sandbox') {
    return <Badge label="SANDBOX" variant="warning" {...extra} />;
  }
  return <Badge label="MOCK" variant="neutral" {...extra} />;
}
