import {test,expect,type BrowserContext,type Page} from '@playwright/test';
import {readTokenCookie} from '@/lib/auth/token-cookies';
import * as fs from 'fs';import * as path from 'path';
const out=path.resolve(__dirname,'../../../../specs/admin-browser-validation'),inventory=JSON.parse(fs.readFileSync(path.join(out,'route-inventory.json'),'utf8')) as {static:string[]};
async function session(page:Page,context:BrowserContext){
 const statePath=path.join(out,'.auth/browser.json');
 if(fs.existsSync(statePath)){
  const state=JSON.parse(fs.readFileSync(statePath,'utf8')) as Awaited<ReturnType<BrowserContext['storageState']>>;
  const tokens=state.cookies.filter(cookie=>cookie.name==='admin_token'||cookie.name.startsWith('admin_token.'));
  const token=readTokenCookie({get:name=>{const cookie=state.cookies.find(item=>item.name===name);return cookie?{value:decodeURIComponent(cookie.value)}:undefined;}});
  let tokenExp=0;try{tokenExp=Number(JSON.parse(Buffer.from(token!.split('.')[1]!, 'base64url').toString('utf8')).exp??0);}catch{}
  if(tokens.length>0&&tokens.every(cookie=>(cookie.expires??0)>Date.now()/1000+120)&&tokenExp>Date.now()/1000+120){await context.addCookies(state.cookies);return;}
 }
 await page.goto('/login');
 await page.locator('#email-input').fill('admin@olekard.local');
 await page.locator('#password-input').fill('Admin123!');
 const login=page.waitForResponse(r=>r.url().includes('/v1/auth/login')&&r.request().method()==='POST');
 await page.getByRole('button',{name:'Sign in',exact:true}).click();
 expect((await login).status()).toBe(200);
 await expect(page).toHaveURL(/dashboard|mfa-enrollment/,{timeout:20000});
 fs.mkdirSync(path.dirname(statePath),{recursive:true});await page.context().storageState({path:statePath});
}
test('real browser login',async({page})=>{
 await page.goto('/login');await page.waitForLoadState('networkidle');
 await page.locator('#email-input').fill('admin@olekard.local');await page.locator('#password-input').fill('Admin123!');
 const response=page.waitForResponse(r=>r.url().includes('/v1/auth/login')&&r.request().method()==='POST');
 await page.getByRole('button',{name:'Sign in',exact:true}).click();expect((await response).status()).toBe(200);
 await expect(page).toHaveURL(/dashboard|mfa-enrollment/,{timeout:20000});
 fs.mkdirSync(path.join(out,'.auth'),{recursive:true});await page.context().storageState({path:path.join(out,'.auth/browser.json')});
 expect((await page.context().cookies()).some(c=>c.name==='admin_token'),'Login must persist the token for navigation and reload').toBe(true);
 await page.reload();await expect(page).not.toHaveURL(/\/login$/);
});
for(const route of inventory.static.filter(p=>p!=='/login')){
 test('live route '+route,async({page,context})=>{
  await session(page,context);const errors:string[]=[],api:{url:string;status:number;code?:string;message?:string}[]=[],failed:string[]=[];
  page.on('pageerror',e=>errors.push(e.message));page.on('requestfailed',r=>{if(!r.failure()?.errorText.includes('ERR_ABORTED'))failed.push(r.url().replace(/\?.*$/,''));});
  page.on('response',async response=>{if(response.url().includes('/v1/')){const record:{url:string;status:number;code?:string;message?:string}={url:new URL(response.url()).pathname,status:response.status()};if(response.status()>=400){try{const data=await response.json();record.code=data.error?.code;record.message=data.error?.message;}catch{record.message='Non-JSON error';}}api.push(record);}});
  const response=await page.goto(route);if(route==='/agents')await expect(page).toHaveURL(/\/admin-portal\/agents\/?$/,{timeout:20000});if(route==='/admin-portal/dashboard')await expect(page).toHaveURL(/\/dashboard\/?$/,{timeout:20000});await page.waitForLoadState('networkidle',{timeout:15000}).catch(()=>{});await page.waitForTimeout(200);
  const text=(await page.locator('body').innerText()).slice(0,6000),headings=await page.locator('h1,h2').allTextContents();
  const slug=route.replace(/[^a-z0-9]/gi,'_')||'root';const report={route,landed:new URL(page.url()).pathname,status:response?.status(),headings,errors,api,failed,links:await page.locator('a[href]').evaluateAll(elements=>elements.map(el=>el.getAttribute('href')).filter(Boolean)),overflow:await page.evaluate(()=>document.documentElement.scrollWidth>innerWidth),text};
  fs.mkdirSync(path.join(out,'routes'),{recursive:true});fs.writeFileSync(path.join(out,'routes',slug+'.json'),JSON.stringify(report,null,2));
  if(errors.length>0||api.some(r=>r.status>=400)||report.overflow||/coming soon|not implemented|placeholder|TODO|failed to load/i.test(text)){fs.mkdirSync(path.join(out,'screenshots'),{recursive:true});await page.screenshot({path:path.join(out,'screenshots',slug+'.png'),fullPage:true});}
  expect(errors,'Uncaught browser errors').toEqual([]);expect(failed,'Failed browser requests').toEqual([]);
  expect(api.filter(r=>r.status>=400),'API errors').toEqual([]); const publicPaths=['/401','/403','/429','/503','/504','/maintenance','/offline','/unsupported-browser','/account-disabled','/account-locked','/forgot-password','/reset-password','/verify-email','/mfa-enrollment'];if(route!=='/'&&!publicPaths.includes(route))expect(report.landed,'Unexpected authentication/permission redirect').not.toMatch(/^\/(login|403)$/);
  if(route.startsWith('/ops/')||route==='/ops'||route==='/qa')expect(text,'Stub functionality').not.toMatch(/coming soon|placeholder|not implemented/i);
 });
}
