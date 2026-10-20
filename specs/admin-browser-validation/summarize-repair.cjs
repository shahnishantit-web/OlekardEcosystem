const fs=require('fs'),path=require('path'),base=__dirname;
const result=JSON.parse(fs.readFileSync(path.join(base,'repair-live-results.json'),'utf8')),cases=[];
function walk(s){for(const spec of s.specs||[])for(const t of spec.tests||[]){const r=t.results.at(-1);cases.push({title:spec.title,file:spec.file,status:r?.status,error:r?.error?.message});}for(const c of s.suites||[])walk(c);}
walk(result);const group=c=>c.file.includes('admin-fixture-')?'fixture':c.title.startsWith('live detail ')?'detail':c.file.includes('admin-live-workflows')?'workflow':'static';
const groups={};for(const c of cases){const name=group(c);groups[name]??={total:0,passed:0,failed:0,skipped:0};groups[name].total++;groups[name][c.status==='passed'?'passed':c.status==='skipped'?'skipped':'failed']++;}
const routes=fs.readdirSync(path.join(base,'routes')).filter(n=>n.endsWith('.json')).map(n=>JSON.parse(fs.readFileSync(path.join(base,'routes',n),'utf8')));
const failures=routes.filter(r=>r.errors.length||r.failed.length||r.api.some(a=>a.status>=400)).map(r=>({route:r.route,landed:r.landed,browserErrors:[...new Set(r.errors)],api:[...new Map(r.api.filter(a=>a.status>=400).map(a=>[a.url+':'+a.status,a])).values()]}));
const summary={groups,unexpectedLoginRedirects:routes.filter(r=>r.landed==='/login'&&!['/mfa-enrollment','/reset-password','/verify-email'].includes(r.route)).map(r=>r.route),routeFailures:failures,failedTests:cases.filter(c=>c.status!=='passed'&&c.status!=='skipped'),unverifiedDetails:cases.filter(c=>group(c)==='detail'&&c.status==='skipped').map(c=>c.title)};
const productionFile=path.join(base,'repair-production-results.json');
if(fs.existsSync(productionFile)){
 const production=JSON.parse(fs.readFileSync(productionFile,'utf8')),details=[];
 function read(s){for(const spec of s.specs||[])if(spec.title.startsWith('live detail '))details.push({title:spec.title,status:spec.tests?.[0]?.results?.at(-1)?.status});for(const c of s.suites||[])read(c);}
 read(production);summary.correctedDetailRetest=details;summary.unverifiedDetails=details.filter(d=>d.status==='skipped').map(d=>d.title);
}
fs.writeFileSync(path.join(base,'repair-summary.json'),JSON.stringify(summary,null,2));
console.log(JSON.stringify(groups));for(const c of summary.failedTests.filter(c=>group(c)==='workflow'))console.log(c.title,c.error);
