import { AuthenticatedApiClient } from '@/lib/api/authenticated-client';
import { getQaDashboard, listQaRuns, triggerQaRun } from '@/src/modules/qa/api/qa';

describe('QA API response contracts', () => {
  const originalFetch = global.fetch;
  const client = new AuthenticatedApiClient('test-token', 'http://localhost:8000');
  const respond = (body: unknown) => {
    global.fetch = jest.fn().mockResolvedValue({ ok: true, status: 200, json: async () => body });
  };
  afterEach(() => { global.fetch = originalFetch; });

  it('reads the dashboard from the single data wrapper returned by Laravel', async () => {
    const dashboard = { summary: { total_categories: 18, categories_with_run: 0 }, categories: [] };
    respond({ data: dashboard });
    expect(await getQaDashboard(client)).toEqual(dashboard);
  });

  it('keeps run pagination metadata alongside the returned array', async () => {
    const runs = [{ id: 'run-1', status: 'pending' }];
    const meta = { total: 21, per_page: 20, current_page: 2, last_page: 2 };
    respond({ data: runs, meta });
    expect(await listQaRuns(client, { page: '2' })).toEqual({ data: runs, meta });
  });

  it('returns the accepted run without looking for a second data wrapper', async () => {
    const run = { id: 'run-1', category: 'api-authorization-testing', status: 'pending' };
    respond({ data: run });
    expect(await triggerQaRun(client, 'api-authorization-testing', 'test-key')).toEqual(run);
  });
});
