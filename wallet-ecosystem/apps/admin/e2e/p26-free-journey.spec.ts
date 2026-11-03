import { createHmac } from 'node:crypto';
import { expect, request as playwrightRequest, test, type APIRequestContext, type Locator, type Page, type Request, type Response } from '@playwright/test';

const apiBase = process.env.P26_API_BASE_URL;
const adminEmail = process.env.P26_E2E_ADMIN_EMAIL;
const adminPassword = process.env.P26_E2E_ADMIN_PASSWORD;
const totpSecret = process.env.P26_E2E_ADMIN_TOTP_SECRET;
const customerPin = process.env.P26_E2E_CUSTOMER_PIN;
const operatorPin = process.env.P26_E2E_OPERATOR_PIN;
const eventId = process.env.P26_E2E_EVENT_ID;
const organisationId = process.env.P26_E2E_ORGANISATION_ID;
const complimentaryCategoryId = process.env.P26_E2E_COMPLIMENTARY_CATEGORY_ID;
const freeCategoryId = process.env.P26_E2E_FREE_CATEGORY_ID;
const gateId = process.env.P26_E2E_GATE_ID;
const customerUrl = process.env.P26_CUSTOMER_URL;
const agentUrl = process.env.P26_AGENT_URL;
const customerPhone = '+26771009991';
const otherCustomerPhone = '+26771009992';
const operatorPhone = '+26771009993';
const capacityCustomerPhone = '+26771009994';
const overflowCustomerPhone = '+26771009995';

function requireEnv(value: string | undefined, name: string): string {
  if (value === undefined || value.length === 0) throw new Error(`Missing required P26 isolated test setting: ${name}`);
  return value;
}

function annotateJourney(description: string): void {
  test.info().annotations.push({ type: 'p26-journey', description });
}

async function typeFlutterField(field: Locator, value: string): Promise<void> {
  await field.focus();
  await expect(field).toBeFocused();
  await field.pressSequentially(value, { delay: 20, timeout: 10_000 });
}

function originForAdmin(): string {
  return new URL(requireEnv(process.env.P26_ADMIN_URL, 'P26_ADMIN_URL')).origin;
}

function base32Decode(value: string): Buffer {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bits = '';
  for (const char of value.replace(/=+$/u, '')) {
    const digit = alphabet.indexOf(char);
    if (digit < 0) throw new Error('Invalid test TOTP secret encoding.');
    bits += digit.toString(2).padStart(5, '0');
  }
  const bytes: number[] = [];
  for (let index = 0; index + 8 <= bits.length; index += 8) {
    bytes.push(Number.parseInt(bits.slice(index, index + 8), 2));
  }
  return Buffer.from(bytes);
}

function currentTotp(secret: string): string {
  const counter = Math.floor(Date.now() / 30_000);
  const message = Buffer.alloc(8);
  message.writeBigUInt64BE(BigInt(counter));
  const digest = createHmac('sha1', base32Decode(secret)).update(message).digest();
  const offset = digest[digest.length - 1]! & 0x0f;
  const value = (digest.readUInt32BE(offset) & 0x7fffffff) % 1_000_000;
  return value.toString().padStart(6, '0');
}

async function loginCustomer(request: APIRequestContext, phone: string, pin: string): Promise<string> {
  const challenge = await request.post(`${apiBase}/v1/customer/auth/login`, {
    data: { phone, method: 'pin' },
  });
  expect(challenge.status()).toBe(200);
  const challengeToken = (await challenge.json() as { challenge_token: string }).challenge_token;
  const verified = await request.post(`${apiBase}/v1/customer/auth/login/verify`, {
    data: { challenge_token: challengeToken, pin },
  });
  expect(verified.status()).toBe(200);
  return (await verified.json() as { access_token: string }).access_token;
}

async function loginFlutterApp(page: Page, baseUrl: string, phone: string, pin: string, loginButton: string): Promise<string> {
  const network: string[] = [];
  const pageErrors: string[] = [];
  let verificationRequestCount = 0;
  let loginIdentityMatchesFixture = false;
  let loginIdentityLength = -1;
  let loginIdentityStartsWithPlus = false;
  let loginIdentityMismatchAt = -1;
  let submittedPinMatchesFixture = false;
  page.on('console', (message) => {
    if (message.type() === 'error') pageErrors.push(message.text().replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/gu, '[email]').slice(0, 200));
  });
  page.on('response', (response) => {
    if (response.status() >= 400) network.push(`${response.request().method()} ${new URL(response.url()).pathname}: HTTP ${response.status()}`);
  });
  page.on('requestfailed', (request) => {
    const path = new URL(request.url()).pathname;
    network.push(`${request.method()} ${path}: ${request.failure()?.errorText ?? 'network failure'}`);
  });
  page.on('request', (request) => {
    const path = new URL(request.url()).pathname;
    if (request.method() === 'POST' && path === '/v1/customer/auth/login/verify') {
      verificationRequestCount++;
    }
    if (path === '/v1/customer/auth/login') {
      const body = request.postDataJSON() as { phone?: unknown; method?: unknown } | null;
      loginIdentityMatchesFixture = body?.phone === phone;
      if (typeof body?.phone === 'string') {
        const submittedPhone = body.phone;
        loginIdentityLength = submittedPhone.length;
        loginIdentityStartsWithPlus = submittedPhone.startsWith('+');
        loginIdentityMismatchAt = [...Array(Math.max(submittedPhone.length, phone.length)).keys()]
          .find((index) => submittedPhone[index] !== phone[index]) ?? -1;
      }
    }
    if (path === '/v1/customer/auth/login/verify' || path === '/v1/agent/auth/login') {
      const body = request.postDataJSON() as { phone?: unknown; pin?: unknown } | null;
      if (body?.phone !== undefined) loginIdentityMatchesFixture = body.phone === phone;
      submittedPinMatchesFixture = body?.pin === pin;
    }
    if (new URL(request.url()).origin === new URL(requireEnv(apiBase, 'P26_API_BASE_URL')).origin) {
      network.push(`${request.method()} ${path}: sent`);
    }
  });
  page.on('response', (response) => {
    const path = new URL(response.url()).pathname;
    if (path.startsWith('/v1/')) network.push(`${response.request().method()} ${path}: HTTP ${response.status()}`);
  });
  page.on('pageerror', (error) => pageErrors.push(error.message.slice(0, 200)));
  const navigation = await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
  const phoneField = page.getByLabel('Phone Number');
  try {
    await phoneField.waitFor({ state: 'visible', timeout: 20_000 });
  } catch (error) {
    const pageState = await page.evaluate(() => ({
      path: location.pathname,
      title: document.title,
      readyState: document.readyState,
      text: document.body.innerText.slice(0, 240),
      bodyHtml: document.body.innerHTML.slice(0, 600),
      htmlLength: document.documentElement.outerHTML.length,
      inputs: Array.from(document.querySelectorAll('input')).map((input) => ({
        type: input.type, label: input.getAttribute('aria-label'), placeholder: input.placeholder,
      })),
      flutterSurface: document.querySelectorAll('flt-glass-pane').length,
      canvases: document.querySelectorAll('canvas').length,
      customElements: Array.from(document.querySelectorAll('*')).map((element) => element.tagName.toLowerCase())
        .filter((tag, index, tags) => tag.includes('flutter') || tag.startsWith('flt-') || tags.indexOf(tag) === index).slice(-25),
      scripts: Array.from(document.scripts).map((script) => script.src.split('/').pop() ?? 'inline'),
      flutterResources: performance.getEntriesByType('resource')
        .filter((entry): entry is PerformanceResourceTiming => entry instanceof PerformanceResourceTiming)
        .filter((entry) => /flutter|main\.dart|bootstrap/u.test(entry.name))
        .map((entry) => ({ name: entry.name.split('/').pop()?.split('?')[0], duration: Math.round(entry.duration), transfer: entry.transferSize })),
    }));
    throw new Error(`Flutter login UI did not expose the phone field: ${String(error)}; HTTP=${navigation?.status() ?? 'none'}; page=${JSON.stringify(pageState)}; page-errors=${pageErrors.join(' | ') || 'none'}; network=${network.join(' | ') || 'none'}`);
  }
  await phoneField.evaluate((element) => {
    const input = element as HTMLInputElement;
    const trace: string[] = [];
    for (const eventName of ['focus', 'keydown', 'beforeinput', 'input', 'keyup', 'blur']) {
      input.addEventListener(eventName, () => trace.push(`${eventName}:${input.value.length}`), true);
    }
    (window as typeof window & { __p26PhoneInputTrace?: string[] }).__p26PhoneInputTrace = trace;
  });
  await phoneField.focus();
  await expect(phoneField).toBeFocused();
  await phoneField.pressSequentially(phone.slice(1), { delay: 150, timeout: 5_000 });
  await phoneField.press('Home');
  await phoneField.press('Shift+Equal', { timeout: 5_000 });
  let visiblePhone = await phoneField.inputValue();
  if (visiblePhone === `${phone[0]}${phone.slice(2)}`) {
    await phoneField.press(phone[1]!, { timeout: 5_000 });
    visiblePhone = await phoneField.inputValue();
  }
  if (visiblePhone !== phone) {
    // Flutter Web can rebuild its proxy input during keyboard composition.
    // Retry the same real keyboard interaction once, with a longer key cadence.
    await phoneField.press('Control+A');
    await phoneField.press('Backspace');
    await expect(phoneField).toHaveValue('');
    await phoneField.pressSequentially(phone.slice(1), { delay: 250, timeout: 8_000 });
    await phoneField.press('Home');
    await phoneField.press('Shift+Equal', { timeout: 5_000 });
    visiblePhone = await phoneField.inputValue();
    if (visiblePhone === `${phone[0]}${phone.slice(2)}`) {
      await phoneField.press(phone[1]!, { timeout: 5_000 });
      visiblePhone = await phoneField.inputValue();
    }
  }
  expect(visiblePhone.length, `phone field length mismatch (actual=${visiblePhone.length}, expected=${phone.length})`)
    .toBe(phone.length);
  expect(visiblePhone === phone, 'the visible phone field must match the fixture exactly').toBe(true);
  const challengePromise = page.waitForResponse((response) =>
    new URL(response.url()).pathname.endsWith('/v1/customer/auth/login') ||
    new URL(response.url()).pathname.endsWith('/v1/agent/auth/login'), { timeout: 20_000 });
  await page.getByRole('button', { name: loginButton }).last().click({ timeout: 5_000 });
  const challengeResponse = await challengePromise.catch(async (error: unknown) => {
    const uiState = await page.evaluate(() => ({
      path: location.pathname,
      visibleText: document.body.innerText.split('\n').map((text) => text.trim())
        .filter((text) => text.length > 0 && text !== 'Phone Number').slice(0, 20),
      semanticsText: Array.from(document.querySelectorAll('flt-semantics'))
        .map((element) => element.getAttribute('aria-label')).filter((label): label is string => label !== null).slice(0, 40),
      labels: Array.from(document.querySelectorAll('flt-semantics[aria-label]'))
        .map((element) => element.getAttribute('aria-label')).filter((label): label is string => label !== null).slice(0, 40),
      buttons: Array.from(document.querySelectorAll('button'))
        .map((button) => ({ label: button.getAttribute('aria-label'), disabled: button.disabled })).slice(0, 12),
      inputs: Array.from(document.querySelectorAll('input')).map((input) => ({
        type: input.type, label: input.getAttribute('aria-label'), valueLength: input.value.length,
        active: input === document.activeElement,
      })),
      phoneEvents: (window as typeof window & { __p26PhoneInputTrace?: string[] }).__p26PhoneInputTrace ?? [],
    }));
    throw new Error(`Flutter login control did not submit an auth challenge: ${String(error)}; ui=${JSON.stringify(uiState)}; network=${network.join(' | ') || 'none'}; page-errors=${pageErrors.join(' | ') || 'none'}`);
  });
  expect(challengeResponse.status()).toBe(200);
  expect(loginIdentityMatchesFixture,
    `login form must submit the exact fixture phone (length=${loginIdentityLength}, expected-length=${phone.length}, leading-plus=${loginIdentityStartsWithPlus}, first-mismatch-index=${loginIdentityMismatchAt})`).toBe(true);
  try {
    await page.getByText(/Enter your PIN|Enter your 6-digit PIN/u).waitFor({ state: 'visible', timeout: 20_000 });
  } catch (error) {
    const uiState = await page.evaluate(() => ({
      path: location.pathname,
      labels: Array.from(document.querySelectorAll('flt-semantics[aria-label]'))
        .map((element) => element.getAttribute('aria-label')).filter((label): label is string => label !== null).slice(0, 40),
    }));
    throw new Error(`Flutter customer login did not advance to PIN entry: ${String(error)}; ui=${JSON.stringify(uiState)}; network=${network.join(' | ') || 'none'}; page-errors=${pageErrors.join(' | ') || 'none'}`);
  }
  // Target Flutter's actual editable proxy input and use focus + keyboard
  // events. Do not set the input's DOM value with fill/evaluate.
  const pinField = page.locator('input[type="password"]');
  await expect(pinField).toHaveCount(1);
  await pinField.waitFor({ state: 'attached', timeout: 10_000 });
  await pinField.evaluate((element) => {
    const input = element as HTMLInputElement;
    const eventNames = ['focus', 'keydown', 'beforeinput', 'input', 'keyup', 'blur'];
    const trace: string[] = [];
    for (const eventName of eventNames) {
      input.addEventListener(eventName, () => trace.push(`${eventName}:${input.value.length}`), true);
    }
    (window as typeof window & { __p26PinInputTrace?: string[] }).__p26PinInputTrace = trace;
  });
  const verified = page.waitForResponse((response) =>
    response.url().endsWith('/v1/customer/auth/login/verify') || response.url().endsWith('/v1/agent/auth/login'), { timeout: 20_000 });
  await pinField.focus();
  await expect(pinField).toBeFocused();
  await pinField.pressSequentially(pin, { delay: 100, timeout: 10_000 });
  const response = await verified.catch(async (error: unknown) => {
    const uiState = await page.evaluate(() => ({
      path: location.pathname,
      labels: Array.from(document.querySelectorAll('flt-semantics[aria-label]'))
        .map((element) => element.getAttribute('aria-label')).filter((label): label is string => label !== null).slice(0, 60),
      inputs: Array.from(document.querySelectorAll('input')).map((input) => ({
        type: input.type, label: input.getAttribute('aria-label'), placeholder: input.placeholder,
        maxLength: input.maxLength, valueLength: input.value.length, active: input === document.activeElement,
      })),
      pinEvents: (window as typeof window & { __p26PinInputTrace?: string[] }).__p26PinInputTrace ?? [],
    }));
    throw new Error(`Flutter login verification response did not arrive: ${String(error)}; ui=${JSON.stringify(uiState)}; network=${network.join(' | ') || 'none'}; page-errors=${pageErrors.join(' | ') || 'none'}`);
  });
  expect(submittedPinMatchesFixture, 'PIN field must submit the exact fixture PIN').toBe(true);
  if (!response.ok()) {
    const body = await response.json().catch(() => ({})) as { error?: { code?: unknown } };
    const errorCode = typeof body.error?.code === 'string' ? body.error.code : 'unavailable';
    throw new Error(`Flutter login verification returned HTTP ${response.status()} (code: ${errorCode}).`);
  }
  expect(verificationRequestCount, 'six PIN digits must submit exactly once').toBe(1);
  return (await response.json() as { access_token: string }).access_token;
}

async function loginFlutterAgent(page: Page, baseUrl: string, phone: string, pin: string): Promise<string[]> {
  let loginRequestCount = 0;
  let submittedCredentialsMatch = false;
  const agentNetwork: string[] = [];
  page.on('request', (request) => {
    const url = new URL(request.url());
    if (url.origin === new URL(requireEnv(apiBase, 'P26_API_BASE_URL')).origin && url.pathname.startsWith('/v1/')) {
      agentNetwork.push(`${request.method()} ${url.pathname}: sent`);
    }
  });
  page.on('response', (response) => {
    const url = new URL(response.url());
    if (url.origin === new URL(requireEnv(apiBase, 'P26_API_BASE_URL')).origin && url.pathname.startsWith('/v1/')) {
      agentNetwork.push(`${response.request().method()} ${url.pathname}: HTTP ${response.status()}`);
    }
  });
  page.on('request', (request) => {
    if (request.method() !== 'POST' || new URL(request.url()).pathname !== '/v1/agent/auth/login') return;
    loginRequestCount++;
    const body = request.postDataJSON() as { phone?: unknown; pin?: unknown } | null;
    submittedCredentialsMatch = body?.phone === phone && body.pin === pin;
  });
  await page.goto(`${baseUrl}/login`, { waitUntil: 'domcontentloaded', timeout: 20_000 });
  const phoneField = page.getByLabel('Phone Number');
  await expect(phoneField).toBeVisible({ timeout: 20_000 });
  await phoneField.focus();
  await expect(phoneField).toBeFocused();
  await phoneField.pressSequentially(phone.slice(1), { delay: 150 });
  await phoneField.press('Home');
  await phoneField.press('Shift+Equal');
  let enteredPhone = await phoneField.inputValue();
  if (enteredPhone === `${phone[0]}${phone.slice(2)}`) {
    await phoneField.press(phone[1]!);
    enteredPhone = await phoneField.inputValue();
  }
  if (enteredPhone !== phone) {
    await phoneField.press('Control+A');
    await phoneField.press('Backspace');
    await expect(phoneField).toHaveValue('');
    await phoneField.pressSequentially(phone.slice(1), { delay: 250 });
    await phoneField.press('Home');
    await phoneField.press('Shift+Equal');
    enteredPhone = await phoneField.inputValue();
    if (enteredPhone === `${phone[0]}${phone.slice(2)}`) {
      await phoneField.press(phone[1]!);
      enteredPhone = await phoneField.inputValue();
    }
  }
  expect(enteredPhone === phone, 'Agent phone field must match the isolated fixture').toBe(true);
  await page.getByRole('button', { name: 'Login' }).click();
  await expect(page.getByText('Enter your 6-digit PIN')).toBeVisible();
  const pinField = page.locator('input[type="password"]');
  await expect(pinField).toBeVisible();
  const responsePromise = page.waitForResponse((response) =>
    response.request().method() === 'POST' &&
    new URL(response.url()).pathname === '/v1/agent/auth/login', { timeout: 20_000 });
  await pinField.focus();
  await expect(pinField).toBeFocused();
  await pinField.pressSequentially(pin, { delay: 180 });
  const response = await responsePromise;
  expect(response.status()).toBe(200);
  expect(loginRequestCount, 'six PIN digits must submit exactly one Agent login').toBe(1);
  expect(submittedCredentialsMatch, 'Agent login must submit the exact fixture credentials').toBe(true);
  await expect(page).toHaveURL(/\/dashboard\/?$/u, { timeout: 20_000 });
  return agentNetwork;
}

test('P26 focused customer login submits PIN from the Flutter Web UI', async ({ browser }) => {
  const customerBase = requireEnv(customerUrl, 'P26_CUSTOMER_URL');
  const pin = requireEnv(customerPin, 'P26_E2E_CUSTOMER_PIN');
  const context = await browser.newContext();
  try {
    const page = await context.newPage();
    const token = await loginFlutterApp(page, customerBase, customerPhone, pin, 'Log In');
    expect(token.length).toBeGreaterThan(0);
    await expect(page).toHaveURL(/\/home\/?$/u);
  } finally {
    await context.close();
  }
});

async function loginAdmin(page: Page): Promise<void> {
  const browserNetworkDiagnostics: string[] = [];
  const authPageErrors: string[] = [];
  page.on('pageerror', (error) => authPageErrors.push(error.message.slice(0, 200)));
  page.on('request', (request) => {
    const path = new URL(request.url()).pathname;
    if (path.includes('/v1/') || path.startsWith('/api/')) {
      browserNetworkDiagnostics.push(`${request.method()} ${path}: sent`);
    }
  });
  page.on('requestfailed', (request) => {
    const path = new URL(request.url()).pathname;
    if (path.includes('/v1/') || path.startsWith('/api/')) {
      browserNetworkDiagnostics.push(`${request.method()} ${path}: ${request.failure()?.errorText ?? 'network failure'}`);
    }
  });
  page.on('response', (response) => {
    const path = new URL(response.url()).pathname;
    if (path.includes('/v1/') || path.startsWith('/api/')) {
      browserNetworkDiagnostics.push(`${response.request().method()} ${path}: HTTP ${response.status()}`);
    }
  });
  page.on('console', (message) => {
    if (message.type() === 'error' && /CORS|fetch|network/iu.test(message.text())) {
      browserNetworkDiagnostics.push(message.text().replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/gu, '[email]').slice(0, 240));
    }
  });
  await page.goto('/login');
  await page.getByLabel(/email/i).fill(requireEnv(adminEmail, 'P26_E2E_ADMIN_EMAIL'));
  await page.locator('#password-input').fill(requireEnv(adminPassword, 'P26_E2E_ADMIN_PASSWORD'));
  const loginResponsePromise = page.waitForResponse((response) => new URL(response.url()).pathname.endsWith('/v1/auth/login'), { timeout: 45_000 });
  await page.getByRole('button', { name: 'Sign in' }).click();
  const loginResponse = await loginResponsePromise.catch(async (error: unknown) => {
    const alertText = await page.getByRole('alert').innerText().catch(() => 'no alert');
    throw new Error(`Admin login response did not arrive: ${String(error)}; alert=${alertText.slice(0, 120)}; network=${browserNetworkDiagnostics.join(' | ') || 'none recorded'}`);
  });
  if (!loginResponse.ok()) {
    const body = await loginResponse.json().catch(() => ({})) as { error?: { code?: string } };
    throw new Error(`Isolated Admin login endpoint returned HTTP ${loginResponse.status()} (${body.error?.code ?? 'no error code'}).`);
  }
  const envelope = await loginResponse.json() as { data?: { mfa_required?: boolean; access_token?: string } };
  if (envelope.data?.mfa_required !== true && envelope.data?.access_token === undefined) {
    throw new Error('Admin login response did not contain the expected MFA challenge or session.');
  }
  await page.locator('#mfa-input').waitFor({ state: 'visible' });
  await page.locator('#mfa-input').fill(currentTotp(requireEnv(totpSecret, 'P26_E2E_ADMIN_TOTP_SECRET')));
  const mfaNavigationPromise = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 30_000 });
  const mfaRequestPromise = page.waitForRequest((request) =>
    request.method() === 'POST' && new URL(request.url()).pathname.includes('/auth/mfa/verify'), { timeout: 15_000 });
  await page.getByRole('button', { name: 'Verify' }).click();
  let mfaRequest: Request;
  try {
    mfaRequest = await mfaRequestPromise;
  } catch (error) {
    await page.locator('#mfa-input').fill('').catch(() => undefined);
    throw new Error(`Admin MFA request did not reach the browser network layer: ${String(error)}; auth-network=${browserNetworkDiagnostics.join(' | ') || 'none'}`);
  }
  let mfaResponse: Response;
  try {
    const response = await mfaRequest.response();
    if (!response) throw new Error('MFA request has no HTTP response.');
    mfaResponse = response;
  } catch (error) {
    await page.locator('#mfa-input').fill('').catch(() => undefined);
    throw new Error(`Admin MFA request response failed: ${String(error)}; auth-network=${browserNetworkDiagnostics.join(' | ') || 'none'}`);
  }
  if (!mfaResponse.ok()) {
    const body = await mfaResponse.json().catch(() => ({})) as { error?: { code?: string } };
    await page.locator('#mfa-input').fill('');
    throw new Error(`Isolated Admin MFA verification returned HTTP ${mfaResponse.status()} (${body.error?.code ?? 'no error code'}).`);
  }
  const mfaHeaders = await mfaResponse.allHeaders();
  if (mfaHeaders['access-control-allow-origin'] !== originForAdmin()) {
    throw new Error('Successful MFA response did not expose the configured Admin origin through CORS.');
  }
  // The successful login immediately performs a full navigation, so Chromium
  // may discard the response body. Reaching the authenticated callback page
  // proves the app could consume and persist the response; API integration
  // tests assert the token/session JSON contract separately.
  await mfaNavigationPromise.catch((error: unknown) => {
    throw new Error(`Admin MFA response succeeded but the app did not complete its authenticated navigation: ${String(error)}; page-errors=${authPageErrors.join(' | ') || 'none'}; network=${browserNetworkDiagnostics.join(' | ') || 'none'}`);
  });
}

test('Admin, customer and gate operator journey preserves scope and financial invariants', async ({ page, browser }) => {
  requireEnv(apiBase, 'P26_API_BASE_URL');
  const event = requireEnv(eventId, 'P26_E2E_EVENT_ID');
  requireEnv(organisationId, 'P26_E2E_ORGANISATION_ID');
  const compCategory = requireEnv(complimentaryCategoryId, 'P26_E2E_COMPLIMENTARY_CATEGORY_ID');
  const freeCategory = requireEnv(freeCategoryId, 'P26_E2E_FREE_CATEGORY_ID');
  const gate = requireEnv(gateId, 'P26_E2E_GATE_ID');
  const pin = requireEnv(customerPin, 'P26_E2E_CUSTOMER_PIN');
  const customerBase = requireEnv(customerUrl, 'P26_CUSTOMER_URL');
  const agentBase = requireEnv(agentUrl, 'P26_AGENT_URL');

  // Exercise MFA through Chromium so its same-origin policy, preflight and
  // credential handling are tested by the browser itself.
  await loginAdmin(page);
  await page.goto('/community/events');
  await page.getByLabel('Event ID').fill(event);
  const draftResponsePromise = page.waitForResponse((response) =>
    new URL(response.url()).pathname === `/v1/events/${event}`);
  await page.getByRole('button', { name: 'Load draft' }).click();
  const draftResponse = await draftResponsePromise;
  if (!draftResponse.ok()) {
    const body = await draftResponse.json().catch(() => ({})) as { error?: { code?: string } };
    throw new Error(`Isolated event draft request returned HTTP ${draftResponse.status()} (${body.error?.code ?? 'no error code'}).`);
  }
  const draftBody = await draftResponse.json() as { data?: { id?: unknown; title?: unknown } };
  if (draftBody.data?.id !== event || draftBody.data.title !== 'P26 isolated browser event') {
    throw new Error(`Isolated event draft response contract mismatch (top-level keys: ${Object.keys(draftBody).join(',') || 'none'}; data keys: ${Object.keys(draftBody.data ?? {}).join(',') || 'none'}).`);
  }
  await expect(page.getByRole('heading', { name: /P26 isolated browser event/u })).toBeVisible();
  annotateJourney('Admin login/MFA and event draft verified.');
  await expect(page.getByText(/Publication, ticket sales/u)).toBeVisible();

  const eventReaders = await playwrightRequest.newContext();
  const customerContext = await browser.newContext();
  const otherCustomerContext = await browser.newContext();
  try {
    const customerPage = await customerContext.newPage();
    const customerToken = await loginFlutterApp(customerPage, customerBase, customerPhone, pin, 'Log In');
    await expect(customerPage).toHaveURL(/\/home\/?$/u, { timeout: 20_000 });
    const otherCustomerPage = await otherCustomerContext.newPage();
    const otherCustomerToken = await loginFlutterApp(otherCustomerPage, customerBase, otherCustomerPhone, pin, 'Log In');
    await expect(otherCustomerPage).toHaveURL(/\/home\/?$/u, { timeout: 20_000 });
    annotateJourney('Customer and second-customer UI login verified.');
    const capacityCustomerToken = await loginCustomer(eventReaders, capacityCustomerPhone, pin);
    const overflowCustomerToken = await loginCustomer(eventReaders, overflowCustomerPhone, pin);

    const expiry = new Date(Date.now() + 60 * 60_000).toISOString().slice(0, 16);
    await page.getByLabel('Free/complimentary category ID').fill(compCategory);
    await page.getByLabel('Recipient customer actor ID').fill(process.env.P26_E2E_CUSTOMER_ID!);
    await page.getByLabel('Expiry').fill(expiry);
    await page.getByLabel('Maximum uses').fill('1');
    const invitationResponsePromise = page.waitForResponse((response) =>
      response.request().method() === 'POST' &&
      new URL(response.url()).pathname === `/v1/events/${event}/invitations`);
    await page.getByRole('button', { name: 'Create invitation' }).click();
    const invitationResponse = await invitationResponsePromise;
    if (!invitationResponse.ok()) {
      const body = await invitationResponse.json().catch(() => ({})) as { error?: { code?: unknown } };
      const errorCode = typeof body.error?.code === 'string' ? body.error.code : 'unavailable';
      throw new Error(`Invitation issuance returned HTTP ${invitationResponse.status()} (code: ${errorCode}).`);
    }
    annotateJourney('Admin invitation issued through the UI.');
    const tokenField = page.getByLabel('New invitation token');
    await expect(tokenField).toBeVisible();
    const invitationToken = await tokenField.inputValue();
    expect(invitationToken.length).toBeGreaterThan(20);
    await tokenField.evaluate((element) => element.remove());

    const register = async (accessToken: string, categoryId: string, key: string, token?: string) =>
      eventReaders.post(`${apiBase}/v1/events/${event}/free-registrations`, {
        headers: { Authorization: `Bearer ${accessToken}`, 'Idempotency-Key': key },
        data: { category_id: categoryId, ...(token !== undefined && token.length > 0 ? { invitation_token: token } : {}) },
      });

    await expect(customerPage.getByRole('tab', { name: 'Services' })).toBeVisible();
    await customerPage.getByRole('tab', { name: 'Services' }).click();
    await customerPage.getByRole('button', { name: 'Free Event Registration' }).click();
    try {
      await expect(customerPage.getByLabel('Event ID')).toBeVisible({ timeout: 20_000 });
    } catch (error) {
      const customerUi = await customerPage.evaluate(() => ({
        path: location.pathname,
        text: document.body.innerText.slice(0, 300),
        inputLabels: Array.from(document.querySelectorAll('input'))
          .map((input) => input.getAttribute('aria-label')).filter((label): label is string => label !== null),
        flutterSurface: document.querySelectorAll('flt-glass-pane').length,
      }));
      throw new Error(`Customer free-registration UI did not load: ${String(error)}; ui=${JSON.stringify(customerUi)}`);
    }
    await typeFlutterField(customerPage.getByLabel('Event ID'), event);
    await typeFlutterField(customerPage.getByLabel('Free category ID'), compCategory);
    await typeFlutterField(customerPage.getByLabel('Invitation token (if required)'), invitationToken);
    const firstResponsePromise = customerPage.waitForResponse((response) =>
      response.url().endsWith(`/v1/events/${event}/free-registrations`));
    await customerPage.getByRole('button', { name: 'Register for free' }).click();
    const firstResponse = await firstResponsePromise;
    expect(firstResponse.status()).toBe(201);
    annotateJourney('Customer invitation registration UI returned HTTP 201.');
    const firstBody = await firstResponse.json() as { data: { registration_id: string } };
    const registrationKey = (await firstResponse.request().allHeaders())['idempotency-key'];
    expect(registrationKey).toBeTruthy();
    await expect(customerPage).toHaveURL(/events\/tickets/u);
    // Flutter Web exposes the ticket card as one semantic textbox label.
    // Assert the accessible card content instead of querying separate DOM text nodes.
    const issuedTicket = customerPage.getByRole('textbox', {
      name: /P26 isolated browser event.*Complimentary fixture.*qr code.*Ready for entry/u,
    });
    await expect(issuedTicket).toBeVisible();

    const browserRetry = await eventReaders.post(`${apiBase}/v1/events/${event}/free-registrations`, {
      headers: { Authorization: `Bearer ${customerToken}`, 'Idempotency-Key': registrationKey! },
      data: { category_id: compCategory, invitation_token: invitationToken },
    });
    expect(browserRetry.status()).toBe(200);
    expect((await browserRetry.json() as { data: { registration_id: string } }).data.registration_id)
      .toBe(firstBody.data.registration_id);

    const customerHeaders = { Authorization: `Bearer ${customerToken}` };
    const ownTickets = await eventReaders.get(`${apiBase}/v1/events/tickets`, { headers: customerHeaders });
    expect(ownTickets.status()).toBe(200);
    const tickets = (await ownTickets.json() as { data: Array<{ ticket_id: string; credential: string }> }).data;
    expect(tickets.map((ticket) => ticket.ticket_id)).toHaveLength(1);
    expect(tickets[0]!.credential).toMatch(/^[A-Za-z0-9_-]{43}$/u);
    const credential = tickets[0]!.credential;
    expect(customerPage.url()).not.toContain(credential);
    await customerPage.goto('about:blank');
    annotateJourney('Purchaser ticket ownership and credential retrieval verified.');
    const otherTickets = await eventReaders.get(`${apiBase}/v1/events/tickets`, {
      headers: { Authorization: `Bearer ${otherCustomerToken}` },
    });
    expect(otherTickets.status()).toBe(200);
    expect((await otherTickets.json() as { data: unknown[] }).data).toHaveLength(0);
    await otherCustomerPage.getByRole('tab', { name: 'Services' }).click();
    await otherCustomerPage.getByRole('button', { name: 'My Event Tickets' }).click();
    await expect(otherCustomerPage.getByText('Your event tickets will appear here.')).toBeVisible();

    await page.getByRole('button', { name: 'Revoke this invitation' }).click();
    await expect(page.getByText(/Previously issued tickets remain valid/u)).toBeVisible();
    const historicalReplay = await register(customerToken, compCategory, registrationKey!, invitationToken);
    expect(historicalReplay.status()).toBe(200);
    expect((await historicalReplay.json() as { data: { registration_id: string } }).data.registration_id)
      .toBe(firstBody.data.registration_id);

    const mismatched = await register(otherCustomerToken, compCategory, crypto.randomUUID(), invitationToken);
    expect(mismatched.status()).toBe(409);
    const invalidInvitation = await register(customerToken, compCategory, crypto.randomUUID(), 'invalid-p26-token');
    expect([409, 422]).toContain(invalidInvitation.status());

    await page.getByLabel('Recipient customer actor ID').fill(process.env.P26_E2E_OVERFLOW_CUSTOMER_ID!);
    await page.getByLabel('Expiry').fill(expiry);
    await page.getByRole('button', { name: 'Create invitation' }).click();
    const secondTokenField = page.getByLabel('New invitation token');
    await expect(secondTokenField).toBeVisible();
    const revokedToken = await secondTokenField.inputValue();
    await secondTokenField.evaluate((element) => element.remove());
    const revokeSecondInvitation = page.waitForResponse((response) =>
      response.request().method() === 'DELETE' &&
      /\/v1\/events\/invitations\/[0-9a-f-]+$/iu.test(new URL(response.url()).pathname));
    await page.getByRole('button', { name: 'Revoke this invitation' }).click();
    expect((await revokeSecondInvitation).ok()).toBeTruthy();
    await expect(page.getByText('Invitation revoked. Previously issued tickets remain valid.')).toBeVisible();
    const revokedBeforeRegistration = await register(overflowCustomerToken, compCategory, crypto.randomUUID(), revokedToken);
    expect([409, 422]).toContain(revokedBeforeRegistration.status());

    const freeOne = await register(capacityCustomerToken, freeCategory, crypto.randomUUID());
    expect(freeOne.status()).toBe(201);
    const freeTwo = await register(overflowCustomerToken, freeCategory, crypto.randomUUID());
    expect(freeTwo.status()).toBe(201);
    const soldOut = await register(otherCustomerToken, freeCategory, crypto.randomUUID());
    expect(soldOut.status()).toBe(409);
    annotateJourney('Invitation replay, scope denial, revocation and capacity verified.');

    const agentPage = await customerContext.newPage();
    const agentNetwork = await loginFlutterAgent(agentPage, agentBase, operatorPhone,
      requireEnv(operatorPin, 'P26_E2E_OPERATOR_PIN'));
  const agentPageErrors: string[] = [];
  const agentConsoleErrors: string[] = [];
  agentPage.on('pageerror', (error) => agentPageErrors.push(error.message.slice(0, 200)));
  agentPage.on('console', (message) => {
    if (message.type() === 'error') agentConsoleErrors.push(message.text().slice(0, 200));
  });
    annotateJourney('Assigned gate operator login verified.');
  // Flutter virtualizes semantics for off-screen cards. Scroll the real
  // dashboard viewport so its keyboard/accessibility controls are exposed.
  await agentPage.mouse.wheel(0, 1200);
  const eventGateShortcut = agentPage.getByRole('button', { name: 'Open Event Gate scanner' });
    try {
      await expect(eventGateShortcut).toBeVisible();
    } catch (error) {
      const agentUi = await agentPage.evaluate(() => ({
        path: location.pathname,
        phoneEntryVisible: Array.from(document.querySelectorAll('input')).some((input) =>
          input.getAttribute('aria-label')?.toLowerCase().includes('phone') ?? false),
        accessTokenPresent: Object.keys(localStorage).some((key) => key.includes('agent_access_token')),
        labels: Array.from(document.querySelectorAll('flt-semantics[aria-label]'))
          .map((element) => ({ role: element.getAttribute('role'), label: element.getAttribute('aria-label') }))
          .filter((item) => /event|gate|cash in|cash out|dashboard|offline/iu.test(item.label ?? ''))
          .slice(0, 30),
        visibleText: document.body.innerText.split('\n').map((line) => line.trim())
          .filter((line) => /event|gate|cash in|cash out|dashboard|offline/iu.test(line)).slice(0, 20),
      }));
      const apiTrace = agentNetwork.length > 0 ? agentNetwork.join(' | ') : 'no API calls';
      throw new Error(`Assigned Agent dashboard did not expose the Event Gate control: ${String(error)}; ui=${JSON.stringify(agentUi)}; api=${apiTrace}; page-errors=${agentPageErrors.join(' | ') || 'none'}; console-errors=${agentConsoleErrors.join(' | ') || 'none'}`);
    }
    await eventGateShortcut.click();
    await typeFlutterField(agentPage.getByLabel('Event ID'), event);
    await typeFlutterField(agentPage.getByLabel('Gate ID'), gate);
    await typeFlutterField(agentPage.getByLabel('Credential (manual fallback)'), credential);
    const firstAdmissionResponse = agentPage.waitForResponse((response) =>
      response.request().method() === 'POST' &&
      new URL(response.url()).pathname === `/v1/events/${event}/gates/${gate}/validate`);
    await agentPage.getByRole('button', { name: 'Validate credential' }).click();
    const admissionResponse = await firstAdmissionResponse;
    if (!admissionResponse.ok()) {
      const body = await admissionResponse.json().catch(() => ({})) as { error?: { code?: unknown } };
      const code = typeof body.error?.code === 'string' ? body.error.code : 'unavailable';
      throw new Error(`Assigned gate validation returned HTTP ${admissionResponse.status()} (${code}).`);
    }
    const admissionBody = await admissionResponse.json() as { data?: { status?: unknown } };
    expect(admissionBody.data?.status).toBe('admitted');
    await expect(agentPage.getByText('Admit attendee')).toBeVisible();
    await expect(agentPage.getByText('Category: Complimentary fixture')).toBeVisible();
    await expect(agentPage.locator('body')).not.toContainText(customerPhone);
    await typeFlutterField(agentPage.getByLabel('Credential (manual fallback)'), credential);
    await agentPage.getByRole('button', { name: 'Validate credential' }).click();
    await expect(agentPage.getByText('Already admitted')).toBeVisible();
    await agentPage.getByLabel('Gate ID').press('Control+A');
    await agentPage.getByLabel('Gate ID').pressSequentially('f2600000-0000-7000-8000-000000000099', { delay: 20 });
    await typeFlutterField(agentPage.getByLabel('Credential (manual fallback)'), credential);
    await agentPage.getByRole('button', { name: 'Validate credential' }).click();
    await expect(agentPage.getByText('Admission denied')).toBeVisible();
    annotateJourney('Admission, repeat-scan and wrong-gate denial verified.');

    await page.getByRole('button', { name: 'Load aggregate attendance' }).click();
    await expect(page.getByRole('region', { name: 'Aggregate attendance' })).toBeVisible();
    await expect(page.getByText(/No attendee names, ticket identifiers or scan records/u)).toBeVisible();
    await expect(page.getByText(customerPhone)).toHaveCount(0);
    annotateJourney('Aggregate report privacy verified.');
  } finally {
    await eventReaders.dispose();
    await customerContext.close();
    await otherCustomerContext.close();
  }
});
