// Trusted operations only: deliver output privately to the authorized superadmin.
// The secret is read from the environment and never printed.
const crypto=require('node:crypto');
const secret=process.env.MAINTENANCE_BYPASS_SECRET;
if(!secret||secret.length<32)throw Error('Configure a server secret of at least32 characters.');
const lifetime=Number(process.argv[2]??900);
if(!Number.isInteger(lifetime)||lifetime<1||lifetime>3600)throw Error('Lifetime must be1..3600 seconds.');
const payload=Buffer.from(JSON.stringify({purpose:'maintenance',role:'superadmin',exp:Math.floor(Date.now()/1000)+lifetime})).toString('base64url');
process.stdout.write(payload+'.'+crypto.createHmac('sha256',secret).update(payload).digest('base64url')+'\n');
