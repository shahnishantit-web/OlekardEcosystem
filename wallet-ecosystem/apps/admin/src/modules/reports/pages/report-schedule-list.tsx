'use client';

import { useCallback, useEffect, useId, useState } from 'react';
import { PageContainer } from '@/components/layout/PageContainer';
import { PageHeader } from '@/components/layout/PageHeader';
import { Badge } from '@/components/ui/Badge';
import { Button } from '@/components/ui/Button';
import { Card } from '@/components/ui/Card';
import { ErrorState } from '@/components/ui/ErrorState';
import { Loading } from '@/components/ui/Loading';
import { Select } from '@/components/ui/Select';
import { Sheet } from '@/components/ui/Sheet';
import { Table } from '@/components/ui/Table';
import { Input } from '@/components/ui/Input';
import { useAuth } from '@/lib/auth/context';
import { updateSchedule, type ReportSchedule } from '@/src/modules/reports/api/reports';

interface CreateFormData {
  name: string;
  report_type: string;
  cadence: 'daily' | 'weekly' | 'monthly';
  cadence_time: string;
  cadence_day_of_week: string;
  cadence_day_of_month: string;
  email_notification_enabled: boolean;
}

const REPORT_TYPE_OPTIONS = [
  { value: 'wallet-report',        label: 'Wallet Report' },
  { value: 'transaction-report',   label: 'Transaction Report' },
  { value: 'user-report',          label: 'User Report' },
  { value: 'agent-report',         label: 'Agent Report' },
  { value: 'commission-report',    label: 'Commission Report' },
  { value: 'kyc-report',           label: 'KYC Report' },
  { value: 'reconciliation-report',label: 'Reconciliation Report' },
  { value: 'settlement-report',    label: 'Settlement Report' },
  { value: 'risk-report',          label: 'Risk Report' },
  { value: 'financial-summary',    label: 'Financial Summary' },
];

export function ReportScheduleListPage() {
  const { authenticatedClient, hasPermission } = useAuth();
  const headingId = useId();
  const formId    = useId();

  const canManage = hasPermission('reports.schedule');

  const [schedules, setSchedules]     = useState<ReportSchedule[]>([]);
  const [loading, setLoading]         = useState(false);
  const [error, setError]             = useState<string | null>(null);
  const [panelOpen, setPanelOpen]     = useState(false);
  const [submitting, setSubmitting]   = useState(false);
  const [reactivating, setReactivating] = useState<string | null>(null);

  const [form, setForm] = useState<CreateFormData>({
    name:                       '',
    report_type:                'wallet-report',
    cadence:                    'daily',
    cadence_time:               '06:00',
    cadence_day_of_week:        '1',
    cadence_day_of_month:       '1',
    email_notification_enabled: false,
  });

  const fetchSchedules = useCallback(async () => {
    if (!authenticatedClient) return;
    setLoading(true);
    setError(null);
    try {
      const res = await authenticatedClient.get<ReportSchedule[]>('/v1/reports/schedules');
      setSchedules(Array.isArray(res.data) ? res.data : []);
    } catch {
      setError('Failed to load schedules.');
    } finally {
      setLoading(false);
    }
  }, [authenticatedClient]);

  useEffect(() => { void fetchSchedules(); }, [fetchSchedules]);

  const handleCreate = useCallback(async () => {
    if (!authenticatedClient) return;
    setSubmitting(true);
    try {
      const body: Record<string, unknown> = {
        name:                       form.name,
        report_type:                form.report_type,
        cadence:                    form.cadence,
        cadence_time:               form.cadence_time,
        email_notification_enabled: form.email_notification_enabled,
      };
      if (form.cadence === 'weekly')  body.cadence_day_of_week  = parseInt(form.cadence_day_of_week, 10);
      if (form.cadence === 'monthly') body.cadence_day_of_month = parseInt(form.cadence_day_of_month, 10);

      await authenticatedClient.post('/v1/reports/schedules', body);
      setPanelOpen(false);
      void fetchSchedules();
    } catch {
      setError('Failed to create schedule.');
    } finally {
      setSubmitting(false);
    }
  }, [authenticatedClient, form, fetchSchedules]);

  const handleDeactivate = useCallback(async (scheduleId: string, scheduleName: string) => {
    if (!authenticatedClient) return;
    try {
      await authenticatedClient.delete(`/v1/reports/schedules/${scheduleId}`);
      void fetchSchedules();
    } catch {
      setError(`Failed to deactivate schedule "${scheduleName}".`);
    }
  }, [authenticatedClient, fetchSchedules]);

  const handleReactivate = useCallback(async (schedule: ReportSchedule) => {
    if (!authenticatedClient) return;
    setReactivating(schedule.id);
    try {
      await updateSchedule(authenticatedClient, schedule.id, { ...schedule, status: 'active' });
      void fetchSchedules();
    } catch {
      setError(`Failed to re-activate schedule "${schedule.name}".`);
    } finally {
      setReactivating(null);
    }
  }, [authenticatedClient, fetchSchedules]);

  return (
    <PageContainer>
      <PageHeader
        title="Report Schedules"
        actions={
          canManage ? (
            <Button onClick={() => setPanelOpen(true)} aria-label="Create Schedule">
              Create Schedule
            </Button>
          ) : undefined
        }
      />

      {loading && <Loading aria-label="Loading schedules" />}
      {error && <ErrorState message={error} onRetry={() => void fetchSchedules()} />}

      {!loading && !error && (
        <Card>
          <Table aria-labelledby={headingId}>
            <thead>
              <tr>
                <th scope="col">Name</th>
                <th scope="col">Type</th>
                <th scope="col">Cadence</th>
                <th scope="col">Next Run</th>
                <th scope="col">Status</th>
                <th scope="col">Actions</th>
              </tr>
            </thead>
            <tbody>
              {schedules.length === 0 && (
                <tr><td colSpan={6}>No schedules configured.</td></tr>
              )}
              {schedules.map((s) => (
                <tr key={s.id}>
                  <td>{s.name}</td>
                  <td>{s.report_type}</td>
                  <td>{s.cadence} @ {s.cadence_time}</td>
                  <td>{s.next_run_at ? new Date(s.next_run_at).toLocaleString() : '—'}</td>
                  <td>
                    <Badge variant={s.status === 'active' ? 'success' : 'neutral'} label={s.status.toUpperCase()} />
                  </td>
                  <td style={{ display: 'flex', gap: '0.5rem' }}>
                    {canManage && s.status === 'active' && (
                      <Button
                        onClick={() => void handleDeactivate(s.id, s.name)}
                        aria-label={`Deactivate schedule ${s.name}`}
                      >
                        Deactivate
                      </Button>
                    )}
                    {canManage && s.status === 'inactive' && (
                      <Button
                        onClick={() => void handleReactivate(s)}
                        disabled={reactivating === s.id}
                        aria-label={`Re-activate schedule ${s.name}`}
                      >
                        {reactivating === s.id ? 'Re-activating…' : 'Re-activate'}
                      </Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </Table>
        </Card>
      )}

      <Sheet open={panelOpen} onClose={() => setPanelOpen(false)} aria-labelledby={formId}>
        <h2 id={formId}>Create Report Schedule</h2>

        <div style={{ display: 'flex', flexDirection: 'column', gap: '1rem' }}>
          <label htmlFor="sched-name">Name</label>
          <Input
            id="sched-name"
            value={form.name}
            onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))}
            aria-required="true"
          />

          <label htmlFor="sched-type">Report Type</label>
          <Select
            id="sched-type"
            value={form.report_type}
            onChange={(e) => setForm((f) => ({ ...f, report_type: e.target.value }))}
            aria-label="Report type"
          >
            {REPORT_TYPE_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </Select>

          <label htmlFor="sched-cadence">Cadence</label>
          <Select
            id="sched-cadence"
            value={form.cadence}
            onChange={(e) => setForm((f) => ({ ...f, cadence: e.target.value as CreateFormData['cadence'] }))}
            aria-label="Cadence"
          >
            <option value="daily">Daily</option>
            <option value="weekly">Weekly</option>
            <option value="monthly">Monthly</option>
          </Select>

          <label htmlFor="sched-time">Time (HH:MM)</label>
          <Input
            id="sched-time"
            value={form.cadence_time}
            onChange={(e) => setForm((f) => ({ ...f, cadence_time: e.target.value }))}
            placeholder="06:00"
            aria-required="true"
          />

          {form.cadence === 'weekly' && (
            <>
              <label htmlFor="sched-dow">Day of Week (0=Sun, 6=Sat)</label>
              <Input
                id="sched-dow"
                type="number"
                min={0}
                max={6}
                value={form.cadence_day_of_week}
                onChange={(e) => setForm((f) => ({ ...f, cadence_day_of_week: e.target.value }))}
                aria-required="true"
              />
            </>
          )}

          {form.cadence === 'monthly' && (
            <>
              <label htmlFor="sched-dom">Day of Month (1–28)</label>
              <Input
                id="sched-dom"
                type="number"
                min={1}
                max={28}
                value={form.cadence_day_of_month}
                onChange={(e) => setForm((f) => ({ ...f, cadence_day_of_month: e.target.value }))}
                aria-required="true"
              />
            </>
          )}

          <label>
            <input
              type="checkbox"
              checked={form.email_notification_enabled}
              onChange={(e) => setForm((f) => ({ ...f, email_notification_enabled: e.target.checked }))}
              aria-label="Enable email notifications"
            />
            {' '}Email notification on completion
          </label>

          <div style={{ display: 'flex', gap: '0.5rem', marginTop: '0.5rem' }}>
            <Button
              onClick={() => void handleCreate()}
              disabled={submitting || !form.name}
              aria-label="Save schedule"
            >
              {submitting ? 'Saving…' : 'Save'}
            </Button>
            <Button onClick={() => setPanelOpen(false)} aria-label="Cancel">
              Cancel
            </Button>
          </div>
        </div>
      </Sheet>
    </PageContainer>
  );
}
