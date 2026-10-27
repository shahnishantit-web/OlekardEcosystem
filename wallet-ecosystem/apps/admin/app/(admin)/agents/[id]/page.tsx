import type { Metadata } from 'next';
import { AgentDetailPage } from '@/src/modules/agents/pages/agent-detail';

export const metadata: Metadata = {
  title: 'Agent Detail — OleKard Admin',
};

export default function AgentDetailRoute({ params }: { params: { id: string } }) {
  return <AgentDetailPage agentId={params.id} />;
}
