'use client';

import { useState, useCallback } from 'react';
import { useAuth } from '@/lib/auth/context';

type ExportType = 'receipts' | 'orders' | 'refunds';
type JobStatus = 'queued' | 'processing' | 'completed' | 'failed';

interface ExportJob {
  job_id: string;
  status: JobStatus;
  expires_at: string | null;
  download_url?: string;
}



const STATUS_LABELS: Record<JobStatus, string> = {
  queued: 'Queued',
  processing: 'Processing…',
  completed: 'Ready',
  failed: 'Failed',
};

const STATUS_COLOURS: Record<JobStatus, string> = {
  queued:     'bg-yellow-100 text-yellow-800',
  processing: 'bg-blue-100 text-blue-800',
  completed:  'bg-green-100 text-green-800',
  failed:     'bg-red-100 text-red-800',
};

export function ExportPage() {
  const { authenticatedClient: client } = useAuth();
  const [exportType, setExportType] = useState<ExportType>('receipts');
  const [fromDate, setFromDate]     = useState('');
  const [toDate, setToDate]         = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [jobs, setJobs]             = useState<ExportJob[]>([]);
  const [error, setError]           = useState<string | null>(null);
  const [polling, setPolling]       = useState<Record<string, boolean>>({});

  async function handleRequestExport(e: React.FormEvent) {
    e.preventDefault();
    if (!client) return;
    setSubmitting(true);
    setError(null);
    try {
      const resp = await client.post('/v1/community/admin/receipts/export', {
        export_type: exportType,
        filters: {
          ...(fromDate ? { from: fromDate } : {}),
          ...(toDate ? { to: toDate } : {}),
        },
      });
      const job = resp.data as ExportJob;
      setJobs((prev) => [job, ...prev]);
      // Start polling for this job
      startPolling(job.job_id);
    } catch {
      setError('Failed to request export.');
    } finally {
      setSubmitting(false);
    }
  }

  const startPolling = useCallback((jobId: string) => {
    if (!client) return;
    setPolling((p) => ({ ...p, [jobId]: true }));

    const interval = setInterval(async () => {
      try {
        const resp = await client.get(`/v1/community/admin/exports/${jobId}`);
        const updated = resp.data as ExportJob;
        setJobs((prev) =>
          prev.map((j) => (j.job_id === jobId ? { ...j, ...updated } : j)),
        );
        if (updated.status === 'completed' || updated.status === 'failed') {
          clearInterval(interval);
          setPolling((p) => ({ ...p, [jobId]: false }));
        }
      } catch {
        clearInterval(interval);
        setPolling((p) => ({ ...p, [jobId]: false }));
      }
    }, 3000);
  }, [client]);

  function isExpired(expiresAt: string | null): boolean {
    if (!expiresAt) return false;
    return new Date(expiresAt) < new Date();
  }

  return (
    <main className="p-6 max-w-4xl mx-auto">
      <h1 className="text-2xl font-semibold text-gray-900 mb-1">Export Data</h1>
      <p className="text-sm text-gray-500 mb-6">
        Exports are asynchronous, scoped to your organisation, and expire after 24 hours.
        CSV output is formula-injection safe.
      </p>

      {/* Request form */}
      <form
        onSubmit={handleRequestExport}
        className="mb-8 rounded-lg border border-gray-200 bg-gray-50 p-6"
        aria-label="Request data export"
      >
        <h2 className="text-base font-semibold text-gray-800 mb-4">New Export</h2>
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <label htmlFor="export-type" className="block text-xs font-medium text-gray-700 mb-1">
              Export Type <span aria-hidden="true">*</span>
            </label>
            <select
              id="export-type"
              required
              value={exportType}
              onChange={(e) => setExportType(e.target.value as ExportType)}
              className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="receipts">Receipts</option>
              <option value="orders">Orders</option>
              <option value="refunds">Refunds</option>
            </select>
          </div>

          <div>
            <label htmlFor="export-from" className="block text-xs font-medium text-gray-700 mb-1">
              From Date
            </label>
            <input
              id="export-from"
              type="date"
              value={fromDate}
              onChange={(e) => setFromDate(e.target.value)}
              className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>

          <div>
            <label htmlFor="export-to" className="block text-xs font-medium text-gray-700 mb-1">
              To Date
            </label>
            <input
              id="export-to"
              type="date"
              value={toDate}
              onChange={(e) => setToDate(e.target.value)}
              min={fromDate}
              className="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
        </div>

        {error && (
          <div role="alert" className="mt-4 rounded border border-red-300 bg-red-50 p-3 text-sm text-red-700">
            {error}
          </div>
        )}

        <div className="mt-4 flex justify-end">
          <button
            type="submit"
            disabled={submitting || !client}
            className="rounded bg-blue-600 px-5 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50 focus:outline-none focus:ring-2 focus:ring-blue-500"
            aria-label="Request export"
          >
            {submitting ? 'Requesting…' : 'Request Export'}
          </button>
        </div>
      </form>

      {/* Job list */}
      {jobs.length > 0 && (
        <section aria-label="Export jobs">
          <h2 className="text-base font-semibold text-gray-800 mb-3">Export Jobs</h2>
          <div className="space-y-3">
            {jobs.map((job) => (
              <div
                key={job.job_id}
                className="flex items-center justify-between rounded-lg border border-gray-200 bg-white px-4 py-3"
              >
                <div className="flex items-center gap-3">
                  <span
                    className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${STATUS_COLOURS[job.status]}`}
                    aria-label={`Status: ${STATUS_LABELS[job.status]}`}
                  >
                    {STATUS_LABELS[job.status]}
                  </span>
                  <span className="text-sm text-gray-600 font-mono">{job.job_id}</span>
                  {polling[job.job_id] && (
                    <span className="text-xs text-gray-400 animate-pulse">Polling…</span>
                  )}
                </div>

                <div className="flex items-center gap-3">
                  {job.expires_at && (
                    <span className="text-xs text-gray-400">
                      Expires {new Date(job.expires_at).toLocaleString()}
                    </span>
                  )}
                  {job.status === 'completed' && job.download_url && !isExpired(job.expires_at) && (
                    <a
                      href={job.download_url}
                      download
                      className="rounded bg-green-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                      aria-label={`Download export ${job.job_id}`}
                    >
                      Download CSV
                    </a>
                  )}
                  {job.status === 'completed' && isExpired(job.expires_at) && (
                    <span className="text-xs text-red-500">Link expired</span>
                  )}
                </div>
              </div>
            ))}
          </div>
        </section>
      )}
    </main>
  );
}

export default ExportPage;
