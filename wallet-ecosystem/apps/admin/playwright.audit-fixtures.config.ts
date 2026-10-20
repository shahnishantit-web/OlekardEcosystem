import {defineConfig,devices} from '@playwright/test';
export default defineConfig({testDir:'./e2e',testMatch:'admin-fixture-*.spec.ts',timeout:60000,workers:1,retries:0,reporter:[['list'],['json',{outputFile:'../../../specs/admin-browser-validation/fixture-results.json'}]],use:{baseURL:'http://localhost:3030',...devices['Desktop Chrome'],viewport:{width:1440,height:1000}}});
