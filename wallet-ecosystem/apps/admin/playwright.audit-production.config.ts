import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
 testDir:'./e2e',testMatch:['admin-live-audit.spec.ts','admin-live-workflows.spec.ts','admin-status-regression.spec.ts'],
 timeout:60000,workers:1,retries:0,
 reporter:[['list'],['json',{outputFile:'../../../specs/admin-browser-validation/repair-production-results.json'}]],
 use:{baseURL:'http://localhost:3030',...devices['Desktop Chrome'],channel:'chrome',viewport:{width:1440,height:1000}},
 webServer:{command:'node .next/standalone/server.js',url:'http://localhost:3030/login',timeout:120000,reuseExistingServer:false,env:{PORT:'3030',HOSTNAME:'127.0.0.1',NEXT_PUBLIC_API_URL:'http://localhost:8001',MAINTENANCE_MODE:'false'}},
});
