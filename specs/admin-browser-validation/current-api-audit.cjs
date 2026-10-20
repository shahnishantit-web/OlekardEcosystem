const fs = require('fs');
const base = process.env.ADMIN_AUDIT_API || 'http://127.0.0.1:8000';
const inventory = JSON.parse(fs.readFileSync(__dirname+'/current-inventory.json','utf8'));
(async () => {
  const login = await fetch(base+'/v1/auth/login', {method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({identifier:'admin@olekard.local',credential:'Admin123!',credential_type:'password',device_fingerprint:'admin-web'})});
  const auth = (await login.json()).data;
  if (!auth?.access_token) throw Error('Admin login failed: '+login.status);
  const paths = inventory.routes.filter(r=>r.methods.includes('GET')&&!r.uri.includes('{')&&r.middleware.includes('identity.auth')).map(r=>r.uri);
  const s=inventory.samples||{};
  const details={
    [`/v1/admin/users/${s.admin}/profile`]:s.admin,
    [`/v1/admin/users/${s.admin}/profile/status`]:s.admin,
    [`/v1/admin/users/${s.admin}/devices`]:s.admin,
    [`/v1/admin/users/${s.admin}/sessions`]:s.admin,
    [`/v1/admin/customers/${s.customer}`]:s.customer,
    [`/v1/admin/wallets/${s.wallet}`]:s.wallet,
    [`/v1/admin/wallets/${s.wallet}/ledger`]:s.wallet,
    [`/v1/admin/transactions/${s.transaction}`]:s.transaction,
    [`/v1/admin-app/users/${s.customer}`]:s.customer,
    [`/v1/admin-app/roles/${s.role}`]:s.role,
    [`/v1/admin-app/kyc/${s.kyc}`]:s.kyc,
    [`/v1/admin-app/wallets/${s.wallet}`]:s.wallet,
    [`/v1/admin-app/wallets/${s.wallet}/ledger`]:s.wallet,
    [`/v1/admin-app/transactions/${s.transaction}`]:s.transaction,
    [`/v1/admin-app/agents/${s.agent}`]:s.agent,
    [`/v1/admin-app/reconciliation/${s.batch}`]:s.batch,
    [`/v1/admin-app/settlement/${s.settlement}`]:s.settlement,
    [`/v1/admin/security/alerts/${s.alert}`]:s.alert,
    [`/v1/admin/security/findings/${s.finding}`]:s.finding,
    [`/v1/admin/support/cases/${s.case}`]:s.case,
    [`/v1/reconciliation/batches/${s.batch}`]:s.batch,
    [`/v1/reconciliation/settlements/${s.settlement}`]:s.settlement,
  };
  paths.push(...Object.keys(details).filter(p=>details[p]));
  const results=[];
  for(const uri of [...new Set(paths)]) {
    try {
      const response=await fetch(base+uri,{headers:{Authorization:'Bearer '+auth.access_token,Accept:'application/json',Origin:'http://localhost:3000'},signal:AbortSignal.timeout(15000)});
      const contentType=response.headers.get('content-type')||'';
      const body=contentType.includes('application/json')?await response.json():{};
      const data=body.data;
      results.push({uri,status:response.status,code:body.error?.code,message:body.error?.message,keys:data&&typeof data==='object'?Object.keys(data):[],count:Array.isArray(data)?data.length:Array.isArray(data?.data)?data.data.length:Array.isArray(data?.items)?data.items.length:null,cors:response.headers.get('access-control-allow-origin')});
    } catch(e) {results.push({uri,error:e.message});}
  }
  const suffix=process.argv[2]||'latest';
  fs.writeFileSync(__dirname+'/current-api-'+suffix+'.json',JSON.stringify(results,null,2));
  console.log(JSON.stringify({total:results.length,statuses:results.reduce((o,r)=>(o[r.status||'error']=(o[r.status||'error']||0)+1,o),{}),failures:results.filter(r=>r.status>=400||r.error)},null,2));
})();
