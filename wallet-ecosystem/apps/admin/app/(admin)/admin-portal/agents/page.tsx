import type { Metadata } from 'next';
import { AgentManagementPage } from '@/src/modules/agents/pages/agent-management';

export const metadata: Metadata = {
  title: 'Agents — OleKard Admin',
};

export default function AdminAgentsRoute() {
  return <AgentManagementPage />;
}
