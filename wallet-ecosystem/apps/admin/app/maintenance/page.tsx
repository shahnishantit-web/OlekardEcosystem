import { SystemStatusView } from '@/components/system/SystemStatusView';
export const dynamic = 'force-dynamic';
export default function MaintenancePage() { const raw = process.env.MAINTENANCE_RETURN_AT; const valid = raw !== undefined && /^\d{4}-\d{2}-\d{2}T.*Z$/.test(raw) && Number.isFinite(Date.parse(raw)); return <main><SystemStatusView code="maintenance" {...(valid ? { estimatedReturn: new Date(raw).toISOString() + ' (UTC)' } : {})}/></main>; }
