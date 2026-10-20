import type { Metadata } from 'next';
import { RetentionPoliciesPage } from '@/src/modules/audit/pages/retention-policies';

export const metadata: Metadata = {
  title: 'Retention Policies — OleKard Admin',
};

export default function RetentionPoliciesRoute() {
  return <RetentionPoliciesPage />;
}
