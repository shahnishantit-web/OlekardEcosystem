'use client';

import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { useAuth } from '@/lib/auth/context';

type AssignedReport = { event_id: string; event_title: string; event_starts_at: string; organisation_name: string; sponsors: string[] };
type SponsorReport = {
  event_title: string;
  event_starts_at: string;
  event_ends_at: string;
  event_capacity: number;
  sponsors: string[];
  privacy: string;
  categories: Array<{
    category: string;
    allocation_type: string;
    capacity: number;
    tickets_issued: number;
    paid_sales: number;
    free_registrations: number;
    complimentary_allocations_used: number;
    active_holds: number;
    remaining_capacity: number;
    admissions: number;
    no_shows: number | null;
    no_shows_finalized: boolean;
  }>;
};

export function SponsorReportsPage() {
  const { authenticatedClient } = useAuth();
  const [events, setEvents] = useState<AssignedReport[]>([]);
  const [reports, setReports] = useState<Record<string, SponsorReport>>({});
  const [busyEvent, setBusyEvent] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!authenticatedClient) return;
    let active = true;
    void authenticatedClient.get<AssignedReport[]>('/v1/events/sponsor-reports')
      .then(response => { if (active) setEvents(response.data ?? []); })
      .catch(() => { if (active) setError('Could not load sponsor reports for this account.'); });
    return () => { active = false; };
  }, [authenticatedClient]);

  const loadReport = async (eventId: string) => {
    if (!authenticatedClient) return;
    setBusyEvent(eventId); setError(null);
    try {
      const response = await authenticatedClient.get<SponsorReport>(`/v1/events/${eventId}/sponsor-report`);
      setReports(current => ({ ...current, [eventId]: response.data }));
    } catch { setError('The sponsor report could not be loaded. Access may have been removed.'); }
    finally { setBusyEvent(null); }
  };

  const exportReport = async (eventId: string) => {
    if (!authenticatedClient) return;
    setBusyEvent(eventId); setError(null);
    try {
      const blob = await authenticatedClient.getBlob(`/v1/events/${eventId}/sponsor-report.csv`);
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url; link.download = `sponsor-event-report-${eventId}.csv`; link.click();
      URL.revokeObjectURL(url);
    } catch { setError('The sponsor report export could not be downloaded.'); }
    finally { setBusyEvent(null); }
  };

  return <PageContainer>
    <PageHeader title="Sponsor Event Reports" description="Aggregate performance for events where your account is an assigned sponsor report viewer." />
    {error && <p role="alert" className="mb-4 rounded border border-error p-3 text-sm text-error">{error}</p>}
    {events.length === 0 ? <Card className="p-4"><p className="text-sm text-text-secondary">No sponsor reports are assigned to this account.</p></Card> : <div className="space-y-4">
      {events.map(event => {
        const report = reports[event.event_id];
        return <Card key={event.event_id} className="p-4">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div><h2 className="font-semibold">{event.event_title}</h2><p className="text-sm text-text-secondary">{event.organisation_name} · {new Date(event.event_starts_at).toLocaleString()}</p><p className="mt-1 text-xs text-text-secondary">Sponsor: {event.sponsors.join(', ')}</p></div>
            <div className="flex gap-2"><Button variant="secondary" onClick={() => void loadReport(event.event_id)} disabled={busyEvent === event.event_id}>{busyEvent === event.event_id ? 'Loading…' : report ? 'Refresh report' : 'View report'}</Button>{report && <Button variant="secondary" onClick={() => void exportReport(event.event_id)} disabled={busyEvent === event.event_id}>Export CSV</Button>}</div>
          </div>
          {report && <div className="mt-4"><p className="mb-2 text-xs text-text-secondary">{report.privacy} Event capacity: {report.event_capacity}.</p><div className="overflow-x-auto"><table className="w-full text-left text-sm"><thead><tr><th>Category</th><th>Capacity</th><th>Issued</th><th>Free</th><th>Paid</th><th>Holds</th><th>Remaining</th><th>Admissions</th></tr></thead><tbody>{report.categories.map(row => <tr key={row.category}><td>{row.category}</td><td>{row.capacity}</td><td>{row.tickets_issued}</td><td>{row.free_registrations + row.complimentary_allocations_used}</td><td>{row.paid_sales}</td><td>{row.active_holds}</td><td>{row.remaining_capacity}</td><td>{row.admissions}</td></tr>)}</tbody></table></div></div>}
        </Card>;
      })}
    </div>}
  </PageContainer>;
}
