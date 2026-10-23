// Start the reference server (port 5502) and fixture Vite server (5503) first.
import { chromium } from 'playwright-core';
import { writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
const dir=fileURLToPath(new URL('../../../planning/evidence/m3a/',import.meta.url));
const browser=await chromium.launch({headless:true});
const metrics=[];
for(const width of [1440,1024,390]) {
 const page=await browser.newPage({viewport:{width,height:1000},deviceScaleFactor:1,locale:'en-US',timezoneId:'Asia/Manila',colorScheme:'light'});
 for(const [portal,reference] of [['partner','partner-my-leads'],['brand','brand-partner-hub']]) {
  for(const mode of ['reference','vue']) {
   await page.goto(mode==='reference'?`http://127.0.0.1:5502/${reference}.html`:`http://127.0.0.1:5503/tests/Visual/index.html?portal=${portal}`);
   if(mode==='vue')await page.waitForSelector('.portal-ui');
   await page.evaluate(()=>document.fonts.ready);
   await page.screenshot({path:`${dir}${mode}-${mode==='reference'?reference:portal}-${width}.png`,fullPage:true});
   metrics.push({mode,portal,viewportWidth:width,...await page.evaluate(()=>({height:document.documentElement.scrollHeight,width:document.documentElement.scrollWidth}))});
  }
 }
 await page.close();
}
writeFileSync(`${dir}visual-metrics.json`,JSON.stringify(metrics,null,2));
writeFileSync(`${dir}render-settings.json`,JSON.stringify({browser:await browser.version(),widths:[1440,1024,390],height:1000,deviceScaleFactor:1,locale:'en-US',timezoneId:'Asia/Manila',colorScheme:'light',fonts:'local reference woff2, document.fonts.ready'},null,2));
await browser.close();
