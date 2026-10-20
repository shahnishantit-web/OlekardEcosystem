import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

const artifactDir = path.resolve(__dirname, '../../../../specs/admin-browser-validation');

test.beforeEach(async ({ page, context }) => {
  await context.clearCookies();
  await page.goto('/login');
  await page.locator('#email-input').fill('admin@olekard.local');
  await page.locator('#password-input').fill('Admin123!');
  const login = page.waitForResponse(response => response.url().includes(':8001/v1/auth/login') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  const loginResponse = await login;
  expect(loginResponse.status()).toBe(200);
  const loginBody = await loginResponse.json();
  expect(loginBody.data?.access_token).toBeTruthy();
  await page.goto('/dashboard');
  await expect(page).toHaveURL(/dashboard|mfa-enrollment/, { timeout: 20_000 });
  fs.mkdirSync(path.join(artifactDir, '.auth'), { recursive: true });
  await context.storageState({ path: path.join(artifactDir, '.auth/browser.json') });
});

test('authenticated user list reaches API and detail renders its read model', async ({ page }) => {
  const errors: Array<{ path: string; status: number }> = [];
  const detailReads: Array<{ path: string; status: number; dataLength?: number }> = [];
  const statusRequests: Array<{ method: string; path: string; authorization: boolean }> = [];
  const userApiRequests: Array<{ url: string; method: string; authenticated?: boolean; failed?: string | undefined }> = [];
  page.on('request', request => {
    if (request.url().includes('/v1/admin/customers')) {
      userApiRequests.push({ url: request.url(), method: request.method(), authenticated: Boolean(request.headers()['authorization']) });
    }
  });
  page.on('requestfailed', request => {
    if (request.url().includes('/v1/admin/customers')) {
      userApiRequests.push({ url: request.url(), method: request.method(), failed: request.failure()?.errorText });
    }
  });
  page.on('response', response => {
    if (response.url().includes(':8001/v1/admin-app/') &&
        (response.url().includes('/wallets?') || response.url().includes('/transactions?') || response.url().includes('/status'))) {
      void response.json().then(body => detailReads.push({
        path: new URL(response.url()).pathname,
        status: response.status(),
        dataLength: Array.isArray(body.data) ? body.data.length : undefined,
      })).catch(() => detailReads.push({ path: new URL(response.url()).pathname, status: response.status() }));
    }
    if (response.url().includes(':8001/') && response.status() >= 400) {
      errors.push({ path: new URL(response.url()).pathname, status: response.status() });
    }
  });
  page.on('request', request => {
    if (request.url().includes(`/v1/admin-app/users/`) && request.url().includes('/status')) {
      statusRequests.push({ method: request.method(), path: new URL(request.url()).pathname, authorization: Boolean(request.headers()['authorization']) });
    }
  });
  const listResponse = page.waitForResponse(response =>
    response.url().includes(':8001/v1/admin/customers') && response.request().method() === 'GET',
  );
  await page.goto('/admin-portal/users');
  const response = await listResponse;
  expect(response.status()).toBe(200);
  expect(Boolean((await response.request().allHeaders())['authorization'])).toBe(true);
  expect(userApiRequests.some(request => request.method === 'GET' && request.authenticated)).toBe(true);
  const body = await response.json();
  expect(body).toHaveProperty('meta.total');
  expect(Array.isArray(body.data)).toBe(true);

  test.skip(body.data.length === 0, 'The isolated QA dataset has no customer to exercise the detail and action workflow.');
  const user = body.data.find((candidate: { status: string }) => candidate.status === 'active');
  test.skip(!user, 'The isolated QA dataset has no active customer for reversible status-action verification.');
  expect(user).toHaveProperty('id');
  expect(user).toHaveProperty('status');
  expect(user).toHaveProperty('profile');
  const userDetail = page.waitForResponse(r => r.url().includes(`/v1/admin-app/users/${user.id}`) && r.request().method() === 'GET');
  await page.locator(`a[href="/admin-portal/users/${user.id}"]`).click();
  const userDetailResponse = await userDetail;
  expect(userDetailResponse.status()).toBe(200);
  expect((await userDetailResponse.json()).data.user_id).toBe(user.id);
  await expect(page).toHaveURL(new RegExp(`/admin-portal/users/${user.id}$`));
  await expect(page.getByRole('heading', { name: 'User Detail' })).toBeVisible();
  await expect.poll(() => detailReads.filter(read => read.path.endsWith('/wallets') || read.path.endsWith('/transactions')).length).toBe(2);
  expect(detailReads.filter(read => read.path.endsWith('/wallets') || read.path.endsWith('/transactions')).map(read => read.status)).toEqual([200, 200]);
  await page.getByRole('button', { name: 'Suspend this user' }).click();
  await page.getByLabel('Reason').fill('Browser QA reversible status verification');
  const suspend = page.waitForResponse(r => r.url().includes(`/v1/admin-app/users/${user.id}/status`) && r.request().method() === 'PATCH');
  await page.getByRole('button', { name: 'Confirm suspension' }).click();
  expect((await suspend).status()).toBe(200);
  await expect(page.getByRole('button', { name: 'Reactivate this user' })).toBeVisible();
  await page.getByRole('button', { name: 'Reactivate this user' }).click();
  await page.getByLabel('Reason').fill('Restore after browser QA status verification');
  const reactivate = page.waitForResponse(r => r.url().includes(`/v1/admin-app/users/${user.id}/status`) && r.request().method() === 'PATCH');
  await page.getByRole('button', { name: 'Confirm reactivation' }).click();
  const reactivateResponse = await reactivate;
  expect(reactivateResponse.status()).toBe(200);
  expect(statusRequests.filter(request => request.method === 'PATCH').every(request => request.authorization)).toBe(true);
  expect(statusRequests.filter(request => request.method === 'PATCH')).toHaveLength(2);
  const userWorkflowPaths = [
    '/v1/admin/customers',
    `/v1/admin-app/users/${user.id}`,
    `/v1/admin-app/users/${user.id}/status`,
  ];
  expect(errors.filter(error => userWorkflowPaths.some(path => error.path === path))).toEqual([]);
  if (errors.length > 0) {
    await test.info().attach('unrelated-detail-api-errors.json', {
      body: JSON.stringify(errors, null, 2),
      contentType: 'application/json',
    });
  }
});

test('authenticated agent list reaches API, detail renders, and reversible status action succeeds', async ({ page }) => {
  const errors: Array<{ path: string; status: number }> = [];
  page.on('response', response => {
    if (response.url().includes(':8001/') && response.status() >= 400) {
      errors.push({ path: new URL(response.url()).pathname, status: response.status() });
    }
  });
  const listResponse = page.waitForResponse(response =>
    response.url().includes(':8001/v1/admin-app/agents') && response.request().method() === 'GET',
  );
  await page.goto('/admin-portal/agents');
  const response = await listResponse;
  expect(response.status()).toBe(200);
  expect(Boolean((await response.request().allHeaders())['authorization'])).toBe(true);
  const body = await response.json();
  expect(body).toHaveProperty('meta.total');
  expect(Array.isArray(body.data)).toBe(true);

  test.skip(body.data.length === 0, 'The isolated QA dataset has no agent to exercise the detail and action workflow.');
  const agent = body.data[0];
  expect(agent).toHaveProperty('agent_id');
  expect(agent).toHaveProperty('user_id');
  const activeAgent = body.data.find((candidate: { agent_status: string }) => candidate.agent_status === 'active') ?? body.data[0];
  const agentLink = page.locator(`a[href="/admin-portal/agents/${activeAgent.agent_id}"]`);
  const agentDetail = page.waitForResponse(r => r.url().includes(`/v1/admin-app/agents/${activeAgent.agent_id}`) && r.request().method() === 'GET');
  await agentLink.click();
  const agentDetailResponse = await agentDetail;
  expect(agentDetailResponse.status()).toBe(200);
  expect((await agentDetailResponse.json()).data.agent_id).toBe(activeAgent.agent_id);
  const selectedAgent = body.data.find((candidate: { agent_id: string }) => candidate.agent_id === activeAgent.agent_id)!;
  await expect(page).toHaveURL(new RegExp(`/admin-portal/agents/${selectedAgent.agent_id}$`));
  await expect(page.getByRole('heading', { name: 'Float Balance' })).toBeVisible();

  if (selectedAgent.agent_status === 'active') {
    await page.getByRole('button', { name: 'Suspend Agent' }).click();
    await page.getByLabel('Reason').fill('Browser QA reversible status verification');
    const suspend = page.waitForResponse(r => r.url().includes(`/v1/admin-app/agents/${selectedAgent.agent_id}/status`) && r.request().method() === 'PATCH');
    await page.getByRole('button', { name: 'Confirm', exact: true }).click();
    const suspendResponse = await suspend;
    expect(suspendResponse.status()).toBe(200);
    expect((await suspendResponse.json()).data.agent_status).toBe('suspended');
    await expect(page.getByRole('button', { name: 'Suspend Agent' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Reactivate Agent' })).toBeVisible();
    await page.getByRole('button', { name: 'Reactivate Agent' }).click();
    await page.getByLabel('Reason').fill('Restore after browser QA status verification');
    const reactivate = page.waitForResponse(r => r.url().includes(`/v1/admin-app/agents/${selectedAgent.agent_id}/status`) && r.request().method() === 'PATCH');
    await page.getByRole('button', { name: 'Confirm', exact: true }).click();
    const reactivateResponse = await reactivate;
    expect(reactivateResponse.status()).toBe(200);
    expect((await reactivateResponse.json()).data.agent_status).toBe('active');
    await expect(page.getByRole('button', { name: 'Suspend Agent' })).toBeVisible();
  }
  expect(errors).toEqual([]);
});
