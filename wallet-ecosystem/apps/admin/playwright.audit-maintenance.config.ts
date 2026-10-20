import { defineConfig, devices } from '@playwright/test';
export default defineConfig({
 testDir:'./e2e',testMatch:'p2001-maintenance.spec.ts',timeout:60000,workers:1,
 reporter:[['list'],['json',{outputFile:'../../../specs/admin-browser-validation/repair-maintenance-results.json'}]],
 use:{baseURL:'http://localhost:3024',...devices['Desktop Chrome'],viewport:{width:1440,height:1000}},
 webServer:{command:'node node_modules/next/dist/bin/next start -p 3024',url:'http://localhost:3024/unsupported-browser',timeout:120000,reuseExistingServer:false,env:{MAINTENANCE_MODE:'true',MAINTENANCE_RETURN_AT:'2026-10-17T10:00:00Z',MAINTENANCE_BYPASS_SECRET:'browser-test-maintenance-secret-at-least32chars'}},
});
