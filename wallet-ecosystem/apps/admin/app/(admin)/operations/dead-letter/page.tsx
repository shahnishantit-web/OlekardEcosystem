import type { Metadata } from 'next';
import { DeadLetterPage } from '@/src/modules/operations/pages/dead-letter';

export const metadata: Metadata = {
  title: 'Dead-Letter Items — OleKard Admin',
};

export default function DeadLetterRoute() {
  return <DeadLetterPage />;
}
