import type { Metadata } from 'next';
import { redirect } from 'next/navigation';

export const metadata: Metadata = { title: 'Agent Detail — OleKard Admin' };

export default function AgentDetailAliasRoute({ params }: { params: { id: string } }) {
  redirect(`/admin-portal/agents/${encodeURIComponent(params.id)}`);
}
