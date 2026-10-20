import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';
import { readTokenCookie, tokenCookieParts } from '@/lib/auth/token-cookies';
const out = path.resolve(__dirname, '../../../../specs/admin-browser-validation');
const inventory = JSON.parse(fs.readFileSync(path.join(out, 'route-inventory.json'), 'utf8')) as {dynamic: string[];static:string[]};
const authStatePath=path.join(out,'.auth/browser.json');
const discoveryPath=path.join(out,'live-detail-discovery-current.json');
const seededRoutes:Record<string,string>={
  '/configuration/approvals/[id]':'/configuration/approvals/d026a4eb-0e40-4fa7-a67b-d815f021ad11',
  '/agents/[id]':'/agents/d026a4eb-0e40-4fa7-a67b-d815f021ad30',
  '/configuration/[domain]/[key]':'/configuration/operational_policy/browser_qa_detail_fixture',
  '/integrations/[id]':'/integrations/d026a4eb-0e40-4fa7-a67b-d815f021ad24',
  '/fees/rules/[id]':'/fees/rules/d026a4eb-0e40-4fa7-a67b-d815f021ad13',
  '/admin-portal/wallets/[id]':'/admin-portal/wallets/d026a4eb-0e40-4fa7-a67b-d815f021ad27',
  '/integrations/providers/[id]/health':'/integrations/providers/d026a4eb-0e40-4fa7-a67b-d815f021ad14/health',
  '/kyc/[id]':'/kyc/d026a4eb-0e40-4fa7-a67b-d815f021ad16',
  '/qa/runs/[id]':'/qa/runs/d026a4eb-0e40-4fa7-a67b-d815f021ad01',
  '/reconciliation/batches/[id]':'/reconciliation/batches/d026a4eb-0e40-4fa7-a67b-d815f021ad17',
  '/reconciliation/settlements/[id]':'/reconciliation/settlements/d026a4eb-0e40-4fa7-a67b-d815f021ad18',
  '/reports/[id]':'/reports/d026a4eb-0e40-4fa7-a67b-d815f021ad19',
  '/security/alerts/[id]':'/security/alerts/d026a4eb-0e40-4fa7-a67b-d815f021ad20',
  '/security/findings/[id]':'/security/findings/d026a4eb-0e40-4fa7-a67b-d815f021ad21',
  '/support/cases/[id]':'/support/cases/d026a4eb-0e40-4fa7-a67b-d815f021ad22',
};
// Start each invocation with a new login rather than trusting a still-unexpired but
// potentially server-revoked token left by a previous QA run.
if(fs.existsSync(authStatePath))fs.rmSync(authStatePath);
function tokenExpiry(token:string|undefined):number{
  try{return Number(JSON.parse(Buffer.from(token!.split('.')[1]!, 'base64url').toString('utf8')).exp??0);}catch{return 0;}
}
test.beforeEach(async ({page,context}) => {
  await context.clearCookies();
  if(fs.existsSync(authStatePath)){
    const state=JSON.parse(fs.readFileSync(authStatePath,'utf8')) as {cookies:Array<{name:string;value:string;expires?:number}>};
    const tokenCookies=state.cookies.filter(cookie=>cookie.name==='admin_token'||cookie.name.startsWith('admin_token.'));
    const token=readTokenCookie({get:name=>{const cookie=state.cookies.find(item=>item.name===name);return cookie?{value:decodeURIComponent(cookie.value)}:undefined;}});
    const sessionUsable=tokenCookies.length>0&&tokenCookies.every(cookie=>(cookie.expires??0)>Date.now()/1000+120)&&tokenExpiry(token)>Date.now()/1000+120;
    if(sessionUsable){
    await context.addCookies(state.cookies);
    return;
    }
  }
  await page.goto('/login');
  await page.locator('#email-input').fill('admin@olekard.local');
  await page.locator('#password-input').fill('Admin123!');
  const login=page.waitForResponse(r=>r.url().includes('/v1/auth/login')&&r.request().method()==='POST');
  await page.getByRole('button',{name:'Sign in',exact:true}).click();
  expect((await login).status()).toBe(200);
  await expect(page).toHaveURL(/\/dashboard$/,{timeout:20000});
  fs.mkdirSync(path.dirname(authStatePath),{recursive:true});
  await context.storageState({path:authStatePath});
});
for (const pattern of inventory.dynamic) test('live detail '+pattern, async ({page}) => {
  const matcher = new RegExp('^'+pattern.replace(/\[[^\]]+\]/g,'[^/?]+')+'$');
  const sourceRoute = pattern === '/integrations/[id]'
    ? '/integrations/clients'
    : pattern === '/qa/runs/[id]'
    ? '/qa'
    : pattern === '/security/alerts/[id]'
      ? '/security/dashboard'
      : pattern.replace(/\/\[[^/]+\].*$/, '') || '/';
  const parentResponses:Array<{url:string;status:number}>=[];
  const parentPayloads:Array<{url:string;count:number;ids:string[]}>=[];
  const parentErrors:string[]=[];
  page.on('response',response=>{if(response.url().includes(':8001/v1/')){
    parentResponses.push({url:new URL(response.url()).pathname,status:response.status()});
    if(response.request().method()==='GET'&&response.url().includes('/kyc'))void response.json().then(body=>{
      const rows=Array.isArray(body?.data)?body.data:[];
      parentPayloads.push({url:new URL(response.url()).pathname,count:rows.length,ids:rows.slice(0,5).map((row:{id?:string;kyc_submission_id?:string})=>row.id??row.kyc_submission_id).filter((id:unknown):id is string=>typeof id==='string')});
    }).catch(()=>{});
  }});
  page.on('pageerror',error=>parentErrors.push(error.message));
  const parentRead=page.waitForResponse(response=>response.url().includes(':8001/v1/')&&response.request().method()==='GET',{timeout:20000}).catch(()=>null);
  await page.goto(sourceRoute);
  await parentRead;
  await page.waitForLoadState('networkidle').catch(()=>{});
  await expect(page.locator('h1').first()).toBeVisible({timeout:10000});
  let route = (await page.locator('a[href]').evaluateAll(elements=>elements.map(el=>el.getAttribute('href')).filter((href):href is string=>Boolean(href))))
    .find(href=>matcher.test(href)&&!inventory.static.includes(href));
  if (!route && pattern === '/agents/[id]') {
    const portalRoute = (await page.locator('a[href]').evaluateAll(elements=>elements.map(el=>el.getAttribute('href')).filter((href):href is string=>Boolean(href))))
      .find(href=>/^\/admin-portal\/agents\/[^/?]+$/.test(href));
    if (portalRoute) route = portalRoute.replace('/admin-portal/agents/', '/agents/');
  }
  if (!route && sourceRoute === '/kyc') {
    const allCases = page.waitForResponse(response=>response.url().includes('/v1/admin-app/kyc')&&response.request().method()==='GET',{timeout:20000}).catch(()=>null);
    await page.getByLabel('Status').selectOption('all');
    await allCases;
    await page.waitForLoadState('networkidle').catch(()=>{});
    route = (await page.locator('a[href]').evaluateAll(elements=>elements.map(el=>el.getAttribute('href')).filter((href):href is string=>Boolean(href))))
      .find(href=>matcher.test(href)&&!inventory.static.includes(href));
  }
  if(!route){
    const detailActions=page.getByRole('button',{name:/^(View|Manage|Open|Show)\b/i});
    if(await detailActions.count()){
      const navigated=page.waitForURL(url=>matcher.test(url.pathname),{timeout:8000}).catch(()=>undefined);
      await detailActions.first().click();
      await navigated;
      const currentPath=new URL(page.url()).pathname;
      if(matcher.test(currentPath))route=currentPath;
      else if(currentPath!==sourceRoute){await page.goBack().catch(()=>{});await page.waitForLoadState('networkidle').catch(()=>{});}
    }
  }
  // Visit the isolated seeded record directly if the parent UI does not expose a link.
  // The parent list/API assertions below remain mandatory and still fail on API errors.
  if(!route&&seededRoutes[pattern])route=seededRoutes[pattern];
  const discoveries=fs.existsSync(discoveryPath)?JSON.parse(fs.readFileSync(discoveryPath,'utf8')) as Array<Record<string,unknown>>:[];
  await page.waitForTimeout(100);
  const parentText=(await page.locator('body').innerText()).slice(0,1200);
  const discovery={pattern,sourceRoute,route:route??null,landed:new URL(page.url()).pathname,parentResponses,parentPayloads,parentErrors,parentText,checkedAt:new Date().toISOString()};
  const existingIndex=discoveries.findIndex(item=>item.pattern===pattern);
  if(existingIndex>=0)discoveries[existingIndex]=discovery;else discoveries.push(discovery);
  fs.writeFileSync(discoveryPath,JSON.stringify(discoveries,null,2));
  expect(parentErrors,`Parent list browser errors for ${sourceRoute}`).toEqual([]);
  expect(parentResponses.filter(response=>response.status>=400),`Parent list API failures for ${sourceRoute}`).toEqual([]);
  test.skip(route===undefined,'No real record link exists in the isolated snapshot; not verified.');
  if (route === undefined) return;
  const errors:string[]=[];const failures:{url:string;status:number}[]=[];const detailResponses:Array<{url:string;status:number;body?:unknown}>=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('response',r=>{if(r.url().includes(':8001/v1/')){if(r.status()>=400)failures.push({url:new URL(r.url()).pathname,status:r.status()});void r.json().then(body=>detailResponses.push({url:new URL(r.url()).pathname,status:r.status(),body})).catch(()=>detailResponses.push({url:new URL(r.url()).pathname,status:r.status()}));}});
  await page.goto(route!);await page.waitForLoadState('networkidle');
  await expect(page.getByRole('status').filter({hasText:/Loading/i})).toHaveCount(0,{timeout:20000});
  fs.mkdirSync(path.join(out,'details'),{recursive:true});
  await page.screenshot({path:path.join(out,'details',pattern.replace(/[^a-z0-9]/gi,'_')+'.png'),fullPage:true});
  const report={pattern,route,landed:new URL(page.url()).pathname,errors,failures,detailResponses,text:(await page.locator('body').innerText()).slice(0,5000)};
  fs.writeFileSync(path.join(out,'details',pattern.replace(/[^a-z0-9]/gi,'_')+'.json'),JSON.stringify(report,null,2));
  const validLanding=route==='/agents/'+route.split('/').pop()?[route,`/admin-portal/agents/${route.split('/').pop()}`]:[route];
  expect(validLanding).toContain(report.landed);expect(errors).toEqual([]);expect(failures).toEqual([]);
  await expect(page.locator('h1').first(),`Detail page must render content for ${pattern}`).toBeVisible({timeout:15000});
});
test('live role creation persists after reload and detail navigation',async({page})=>{
  const name='browser_qa_'+Date.now();await page.goto('/admin-portal/roles');await page.waitForLoadState('networkidle');
  await expect(page.getByRole('button',{name:'Create new role'})).toBeVisible({timeout:5000});
  await page.getByRole('button',{name:'Create new role'}).click();await expect(page.getByRole('heading',{name:'Create Custom Role'})).toBeVisible();await page.getByLabel('Role Name').fill(name);
  const response=page.waitForResponse(r=>r.url().includes('/v1/admin-app/roles')&&r.request().method()==='POST');
  await page.getByRole('button',{name:'Confirm create role'}).click();const result=await response;expect(result.status()).toBe(201);
  const data=await result.json();fs.writeFileSync(path.join(out,'created-role.json'),JSON.stringify({name,id:data.data?.role_id,status:result.status()},null,2));
  await expect(page.getByRole('status').filter({hasText:name})).toBeVisible();
  const listReload=page.waitForResponse(r=>r.url().includes('/v1/admin-app/roles')&&r.request().method()==='GET');
  await page.reload();const listResponse=await listReload;expect(listResponse.status()).toBe(200);
  const listBody=await listResponse.json();
  fs.writeFileSync(path.join(out,'role-list-after-create.json'),JSON.stringify({created:{name,id:data.data?.role_id},list:listBody},null,2));
  expect(Array.isArray(listBody.data)).toBe(true);
  expect(listBody.data.some((role:{role_name?:string})=>role.role_name===name)).toBe(true);
  await expect(page.getByText(name,{exact:true})).toBeVisible();
  const detailRead=page.waitForResponse(r=>r.url().endsWith(`/v1/admin-app/roles/${data.data.role_id}`)&&r.request().method()==='GET');
  await page.locator(`a[href="/admin-portal/roles/${data.data.role_id}"]`).click();
  expect((await detailRead).status()).toBe(200);
  await expect(page).toHaveURL(new RegExp(`/admin-portal/roles/${data.data.role_id}$`));
  await expect(page.getByRole('heading',{name:`Role: ${name}`,exact:true})).toBeVisible();
});
test('live FX unavailable provider stays actionable without replay',async({page})=>{
  await page.goto('/multi-currency/fx-rates');await page.getByLabel('From currency',{exact:true}).fill('BWP');await page.getByLabel('To currency',{exact:true}).fill('USD');await page.getByLabel('Spread %',{exact:true}).fill('1');await page.getByLabel('Rate mode').selectOption('provider');
  let count=0;page.on('request',r=>{if(r.method()==='POST'&&r.url().endsWith('/v1/admin/fx/rates'))count++;});
  const response=page.waitForResponse(r=>r.url().endsWith('/v1/admin/fx/rates')&&r.request().method()==='POST');
  await page.getByRole('button',{name:'Submit for approval'}).click();const r=await response;const data=await r.json();
  fs.writeFileSync(path.join(out,'live-fx-error.json'),JSON.stringify({status:r.status(),code:data.error?.code,count},null,2));
  expect(r.status()).toBe(503);expect(data.error?.code).toBe('FX_PROVIDER_UNAVAILABLE');
  await expect(page.getByRole('status')).toContainText(/provider.*not configured|provider.*unavailable/i);expect(count).toBe(1);
  await expect(page.getByRole('heading',{name:'FX Rate Management'})).toBeVisible();
  fs.mkdirSync(path.join(out,'repair-screenshots'),{recursive:true});await page.screenshot({path:path.join(out,'repair-screenshots/live-fx-error-desktop.png'),fullPage:true});await page.setViewportSize({width:390,height:844});await page.screenshot({path:path.join(out,'repair-screenshots/live-fx-error-mobile.png'),fullPage:true});
});
test('live full-permission session reloads, navigates and clears chunks on logout',async({page,context})=>{
 await page.goto('/dashboard');await expect(page).toHaveURL(/\/dashboard$/);await page.reload();await expect(page).toHaveURL(/\/dashboard$/);
 await page.goto('/security/events');await expect(page).toHaveURL(/\/security\/events$/);
 await page.getByRole('button',{name:'Open profile menu'}).click();await page.getByRole('menuitem',{name:'Sign out'}).click();
 await expect.poll(async()=> (await context.cookies()).filter(c=>c.name==='admin_token'||c.name.startsWith('admin_token.')).length).toBe(0);
 await page.goto('/dashboard');await expect(page).toHaveURL(/\/login/);
});
test('live full-permission MFA enrollment gate remains enforced',async({page,context})=>{
 await context.addCookies([{name:'admin_mfa_setup',value:'required',url:'http://localhost:3030'}]);
 await page.goto('/dashboard');await expect(page).toHaveURL(/\/mfa-enrollment$/);
});
test('live expired chunked session returns to login',async({page,context})=>{
 const expired=(await context.cookies()).filter(c=>c.name==='admin_token'||c.name.startsWith('admin_token.')).map(c=>({...c,expires:1}));
 await context.addCookies(expired);await page.goto('/security/events');await expect(page).toHaveURL(/\/login/);
});
test('live tampered chunked token is rejected by API and cleared',async({page,context})=>{
 const cookies=await context.cookies();const token=readTokenCookie({get:name=>{const c=cookies.find(c=>c.name===name);return c?{value:decodeURIComponent(c.value)}:undefined;}});
 expect(token).toBeTruthy();const index=token!.lastIndexOf('.')+1;
 const invalid=token!.slice(0,index)+(token![index]==='A'?'B':'A')+token!.slice(index+1);
 await context.addCookies(tokenCookieParts(invalid).map(c=>({...c,url:'http://localhost:3030'})));
 const rejected=page.waitForResponse(r=>r.url().includes(':8001/v1/admin/security/events'));
 await page.goto('/security/events');expect((await rejected).status()).toBe(401);
 await expect(page).toHaveURL(/\/login/);
 await expect.poll(async()=> (await context.cookies()).filter(c=>c.name==='admin_token'||c.name.startsWith('admin_token.')).length).toBe(0);
});

