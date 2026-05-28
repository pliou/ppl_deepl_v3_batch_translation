import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const baseUrl = process.env.TYPO3_BASE_URL || 'http://typo3-12.ddev.site';
const username = process.env.TYPO3_BE_USER || 'bt_admin';
const password = process.env.TYPO3_BE_PASSWORD || 'BatchSmoke123!';
const artifactRoot = process.env.PPL_BATCH_TRANSLATION_SMOKE_ARTIFACT_ROOT || path.resolve('var/smoke/batch-translation/manual-ui');
const screenshotDir = path.join(artifactRoot, 'screenshots');
const reportDir = path.join(artifactRoot, 'reports');

fs.mkdirSync(screenshotDir, { recursive: true });
fs.mkdirSync(reportDir, { recursive: true });
for (const entry of fs.readdirSync(screenshotDir)) {
  if (entry.startsWith('ui-') && entry.endsWith('.png')) {
    fs.unlinkSync(path.join(screenshotDir, entry));
  }
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage({ viewport: { width: 1600, height: 950 } });
const report = {
  startedAt: new Date().toISOString(),
  baseUrl,
  username,
  screenshots: [],
  assertions: [],
};

function screenshot(name) {
  const file = path.join(screenshotDir, name);
  report.screenshots.push(path.relative(artifactRoot, file));
  return page.screenshot({ path: file, fullPage: true });
}

function assert(ok, message, extra = {}) {
  report.assertions.push({ ok, message, ...extra });
  if (!ok) {
    throw new Error(message);
  }
}

async function locatorCount(selector) {
  let count = await page.locator(selector).count();
  for (const frame of page.frames()) {
    if (frame === page.mainFrame()) {
      continue;
    }
    count += await frame.locator(selector).count().catch(() => 0);
  }
  return count;
}

async function firstLocator(selector) {
  const topLevel = page.locator(selector).first();
  if (await topLevel.count()) {
    return topLevel;
  }
  for (const frame of page.frames()) {
    if (frame === page.mainFrame()) {
      continue;
    }
    const candidate = frame.locator(selector).first();
    if (await candidate.count().catch(() => 0)) {
      return candidate;
    }
  }
  throw new Error(`Locator not found: ${selector}`);
}

try {
  await page.goto(`${baseUrl}/typo3/`, { waitUntil: 'networkidle' });
  await screenshot('ui-login-before.png');

  const userInput = page.locator('input[name="username"], input#t3-username').first();
  const passwordInput = page.locator('input[name="password"], input#t3-password').first();
  await userInput.fill(username);
  await passwordInput.fill(password);
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/typo3/module/ppl-deepl-v3/batch-translation`, { waitUntil: 'networkidle' });
  await screenshot('ui-module-open.png');

  await assert(await locatorCount('text=PPL DeepL V3 Batch Translation') > 0, 'batch translation module opens');
  await assert(await locatorCount('button:has-text("Scan"), button:has-text("Restart scan")') === 1, 'top row has exactly one scan action');
  await assert(await locatorCount('text=Run preflight') === 0, 'manual Run preflight action is absent');

  const scan = await firstLocator('button:has-text("Scan"), button:has-text("Restart scan")');
  await scan.click();
  await page.waitForLoadState('networkidle');
  await screenshot('ui-after-scan.png');

  report.finishedAt = new Date().toISOString();
  report.status = 'PASS';
} catch (error) {
  report.finishedAt = new Date().toISOString();
  report.status = 'FAIL';
  report.error = error instanceof Error ? error.message : String(error);
  await screenshot('ui-failure.png').catch(() => {});
  process.exitCode = 1;
} finally {
  fs.writeFileSync(path.join(reportDir, 'ui-smoke.json'), JSON.stringify(report, null, 2));
  await browser.close();
}
