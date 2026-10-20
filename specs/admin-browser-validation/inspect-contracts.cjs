const fs=require('fs');
const keys=v=>v&&typeof v==='object'&&!Array.isArray(v)?Object.keys(v):Array.isArray(v)?{arrayLength:v.length,firstItemKeys:v[0]?Object.keys(v[0]):[]}:typeof v;
(async()=>{
 const login=await fetch('http://localhost:8001/v1/auth/login',{method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify({identifier:'admin@olekard.local',credential:'Admin123!',credential_type:'password',device_fingerprint:'admin-web'})});
 const auth=(await login.json()).data;
 const paths=['/v1/admin-app/roles?page=1&per_page=50','/v1/admin-app/users?page=1&per_page=20','/v1/admin-app/agents?page=1&per_page=20','/v1/admin-app/wallets?page=1&per_page=20','/v1/admin-app/transactions?page=1&per_page=20','/v1/admin-app/audit?per_page=20','/v1/admin/transactions?page=1&per_page=25','/v1/admin/wallets/summary','/v1/admin/transactions/summary','/v1/admin/kyc/summary'];
 const out=[];for(const p of paths){const r=await fetch('http://localhost:8001'+p,{headers:{Authorization:'Bearer '+auth.access_token,Accept:'application/json'}});let j;try{j=await r.json()}catch{j={nonJson:true}};out.push({path:p,status:r.status,rootKeys:keys(j),success:j.success,error:j.error?.code,dataKeys:keys(j.data),nestedDataKeys:keys(j.data?.data),metaKeys:keys(j.meta),nestedMetaKeys:keys(j.data?.meta)});}
 fs.writeFileSync('specs/admin-browser-validation/api-contract-inspection.json',JSON.stringify({loginStatus:login.status,permissionCount:JSON.parse(Buffer.from(auth.access_token.split('.')[1],'base64url')).permissions?.length,results:out},null,2));console.log(JSON.stringify(out));
})();
