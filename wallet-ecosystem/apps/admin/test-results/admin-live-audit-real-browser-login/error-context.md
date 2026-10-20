# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: admin-live-audit.spec.ts >> real browser login
- Location: e2e\admin-live-audit.spec.ts:23:5

# Error details

```
Test timeout of 150000ms exceeded.
```

```
Error: page.goto: Test timeout of 150000ms exceeded.
Call log:
  - navigating to "http://localhost:3030/login", waiting until "load"

```

# Test source

```ts
  1  | import {test,expect,type BrowserContext,type Page} from '@playwright/test';
  2  | import {readTokenCookie} from '@/lib/auth/token-cookies';
  3  | import * as fs from 'fs';import * as path from 'path';
  4  | const out=path.resolve(__dirname,'../../../../specs/admin-browser-validation'),inventory=JSON.parse(fs.readFileSync(path.join(out,'route-inventory.json'),'utf8')) as {static:string[]};
  5  | async function session(page:Page,context:BrowserContext){
  6  |  const statePath=path.join(out,'.auth/browser.json');
  7  |  if(fs.existsSync(statePath)){
  8  |   const state=JSON.parse(fs.readFileSync(statePath,'utf8')) as Awaited<ReturnType<BrowserContext['storageState']>>;
  9  |   const tokens=state.cookies.filter(cookie=>cookie.name==='admin_token'||cookie.name.startsWith('admin_token.'));
  10 |   const token=readTokenCookie({get:name=>{const cookie=state.cookies.find(item=>item.name===name);return cookie?{value:decodeURIComponent(cookie.value)}:undefined;}});
  11 |   let tokenExp=0;try{tokenExp=Number(JSON.parse(Buffer.from(token!.split('.')[1]!, 'base64url').toString('utf8')).exp??0);}catch{}
  12 |   if(tokens.length>0&&tokens.every(cookie=>(cookie.expires??0)>Date.now()/1000+120)&&tokenExp>Date.now()/1000+120){await context.addCookies(state.cookies);return;}
  13 |  }
  14 |  await page.goto('/login');
  15 |  await page.locator('#email-input').fill('admin@olekard.local');
  16 |  await page.locator('#password-input').fill('Admin123!');
  17 |  const login=page.waitForResponse(r=>r.url().includes('/v1/auth/login')&&r.request().method()==='POST');
  18 |  await page.getByRole('button',{name:'Sign in',exact:true}).click();
  19 |  expect((await login).status()).toBe(200);
  20 |  await expect(page).toHaveURL(/dashboard|mfa-enrollment/,{timeout:20000});
  21 |  fs.mkdirSync(path.dirname(statePath),{recursive:true});await page.context().storageState({path:statePath});
  22 | }
  23 | test('real browser login',async({page})=>{
> 24 |  await page.goto('/login');await page.waitForLoadState('networkidle');
     |             ^ Error: page.goto: Test timeout of 150000ms exceeded.
  25 |  await page.locator('#email-input').fill('admin@olekard.local');await page.locator('#password-input').fill('Admin123!');
  26 |  const response=page.waitForResponse(r=>r.url().includes('/v1/auth/login')&&r.request().method()==='POST');
  27 |  await page.getByRole('button',{name:'Sign in',exact:true}).click();expect((await response).status()).toBe(200);
  28 |  await expect(page).toHaveURL(/dashboard|mfa-enrollment/,{timeout:20000});
  29 |  fs.mkdirSync(path.join(out,'.auth'),{recursive:true});await page.context().storageState({path:path.join(out,'.auth/browser.json')});
  30 |  expect((await page.context().cookies()).some(c=>c.name==='admin_token'),'Login must persist the token for navigation and reload').toBe(true);
  31 |  await page.reload();await expect(page).not.toHaveURL(/\/login$/);
  32 | });
  33 | for(const route of inventory.static.filter(p=>p!=='/login')){
  34 |  test('live route '+route,async({page,context})=>{
  35 |   await session(page,context);const errors:string[]=[],api:{url:string;status:number;code?:string;message?:string}[]=[],failed:string[]=[];
  36 |   page.on('pageerror',e=>errors.push(e.message));page.on('requestfailed',r=>{if(!r.failure()?.errorText.includes('ERR_ABORTED'))failed.push(r.url().replace(/\?.*$/,''));});
  37 |   page.on('response',async response=>{if(response.url().includes('/v1/')){const record:{url:string;status:number;code?:string;message?:string}={url:new URL(response.url()).pathname,status:response.status()};if(response.status()>=400){try{const data=await response.json();record.code=data.error?.code;record.message=data.error?.message;}catch{record.message='Non-JSON error';}}api.push(record);}});
  38 |   const response=await page.goto(route);if(route==='/agents')await expect(page).toHaveURL(/\/admin-portal\/agents\/?$/,{timeout:20000});if(route==='/admin-portal/dashboard')await expect(page).toHaveURL(/\/dashboard\/?$/,{timeout:20000});await page.waitForLoadState('networkidle',{timeout:15000}).catch(()=>{});await page.waitForTimeout(200);
  39 |   const text=(await page.locator('body').innerText()).slice(0,6000),headings=await page.locator('h1,h2').allTextContents();
  40 |   const slug=route.replace(/[^a-z0-9]/gi,'_')||'root';const report={route,landed:new URL(page.url()).pathname,status:response?.status(),headings,errors,api,failed,links:await page.locator('a[href]').evaluateAll(elements=>elements.map(el=>el.getAttribute('href')).filter(Boolean)),overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),text};
  41 |   fs.mkdirSync(path.join(out,'routes'),{recursive:true});fs.writeFileSync(path.join(out,'routes',slug+'.json'),JSON.stringify(report,null,2));
  42 |   if(errors.length>0||api.some(r=>r.status>=400)||report.overflow||/coming soon|not implemented|placeholder|TODO|failed to load/i.test(text)){fs.mkdirSync(path.join(out,'screenshots'),{recursive:true});await page.screenshot({path:path.join(out,'screenshots',slug+'.png'),fullPage:true});}
  43 |   expect(errors,'Uncaught browser errors').toEqual([]);expect(failed,'Failed browser requests').toEqual([]);
  44 |   expect(api.filter(r=>r.status>=400),'API errors').toEqual([]); const publicPaths=['/401','/403','/429','/503','/504','/maintenance','/offline','/unsupported-browser','/account-disabled','/account-locked','/forgot-password','/reset-password','/verify-email','/mfa-enrollment'];if(route!=='/'&&!publicPaths.includes(route))expect(report.landed,'Unexpected authentication/permission redirect').not.toMatch(/^\/(login|403)$/);
  45 |   if(route.startsWith('/ops/')||route==='/ops'||route==='/qa')expect(text,'Stub functionality').not.toMatch(/coming soon|placeholder|not implemented/i);
  46 |  });
  47 | }
  48 | 
```