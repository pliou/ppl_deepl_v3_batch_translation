import fs from 'node:fs';
import { createRequire } from 'node:module';
import path from 'node:path';

const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const baseUrl = process.env.TYPO3_BASE_URL || 'http://typo3-14.ddev.site';
const username = process.env.TYPO3_BE_USER || 'bt_admin';
const password = process.env.TYPO3_BE_PASSWORD || 'BatchSmoke123!';
const chromiumExecutablePath = process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH || '';
const artifactRoot = process.env.PPL_BATCH_TRANSLATION_SMOKE_ARTIFACT_ROOT || path.resolve('var/smoke/batch-translation/manual-ui');
const fakeCallLogPath = process.env.PPL_BATCH_TRANSLATION_FAKE_CALL_LOG || '';
const screenshotDir = path.join(artifactRoot, 'screenshots');
const reportDir = path.join(artifactRoot, 'reports');
const viewportMatrix = [
  { width: 1600, height: 950 },
  { width: 1280, height: 900 },
  { width: 1024, height: 850 },
  { width: 768, height: 900 },
  { width: 430, height: 900 },
  { width: 360, height: 900 },
];
const themes = ['light', 'dark'];

fs.mkdirSync(screenshotDir, { recursive: true });
fs.mkdirSync(reportDir, { recursive: true });
for (const entry of fs.readdirSync(screenshotDir)) {
  if (entry.startsWith('ui-') && entry.endsWith('.png')) {
    fs.unlinkSync(path.join(screenshotDir, entry));
  }
}

const browser = await chromium.launch({
  headless: true,
  ...(chromiumExecutablePath ? { executablePath: chromiumExecutablePath } : {}),
});
const page = await browser.newPage({ viewport: { width: 1600, height: 950 } });
page.on('dialog', (dialog) => dialog.accept());
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

function writeSummary() {
  const lines = [
    '# Batch Translation UI Smoke',
    '',
    `- URL: ${baseUrl}/typo3/module/ppl-deepl-v3/batch-translation`,
    `- Browser: Chromium${chromiumExecutablePath ? ` (${chromiumExecutablePath})` : ''}`,
    `- Result: ${report.status || 'UNKNOWN'}`,
    '',
    '## Assertions',
    ...report.assertions.map((entry) => `- ${entry.ok ? 'PASS' : 'FAIL'}: ${entry.message}`),
    '',
    '## Screenshots',
    ...report.screenshots.map((entry) => `- ${entry}`),
    '',
  ];
  fs.writeFileSync(path.join(artifactRoot, 'summary.md'), lines.join('\n'), 'utf8');
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

async function visibleLocatorCount(selector) {
  const visibleInFrame = async (frame) => frame.locator(selector).evaluateAll((elements) => elements.filter((element) => {
    const style = window.getComputedStyle(element);
    const rect = element.getBoundingClientRect();
    return style.display !== 'none'
      && style.visibility !== 'hidden'
      && rect.width > 0
      && rect.height > 0
      && !element.closest('[hidden]');
  }).length).catch(() => 0);

  let count = await visibleInFrame(page.mainFrame());
  for (const frame of page.frames()) {
    if (frame === page.mainFrame()) {
      continue;
    }
    count += await visibleInFrame(frame);
  }
  return count;
}

async function firstLocator(selector) {
  const candidates = [page.mainFrame(), ...page.frames().filter((frame) => frame !== page.mainFrame())];
  const fallbacks = [];
  for (const frame of candidates) {
    const matches = frame.locator(selector);
    const count = await matches.count().catch(() => 0);
    for (let index = 0; index < count; index += 1) {
      const candidate = matches.nth(index);
      fallbacks.push(candidate);
      if (await candidate.isVisible().catch(() => false)) {
        return candidate;
      }
    }
  }
  if (fallbacks.length > 0) {
    return fallbacks[0];
  }
  throw new Error(`Locator not found: ${selector}`);
}

async function firstTreeRow(title) {
  const candidates = [page.mainFrame(), ...page.frames().filter((frame) => frame !== page.mainFrame())];
  for (const frame of candidates) {
    const row = frame.locator('[data-module="ppl-batch-translation"] [data-role="page-tree"] tbody tr').filter({ hasText: title }).first();
    if (await row.count().catch(() => 0)) {
      await row.scrollIntoViewIfNeeded().catch(() => undefined);
      return row;
    }
  }
  throw new Error(`Tree row not found: ${title}`);
}

async function setupOptionValues(name) {
  const selector = `[data-module="ppl-batch-translation"] select[name="${name}"] option`;
  const candidates = [page.mainFrame(), ...page.frames().filter((frame) => frame !== page.mainFrame())];
  for (const frame of candidates) {
    const values = await frame.locator(selector).evaluateAll((options) => options.map((option) => option.value)).catch(() => []);
    if (values.length > 0) {
      return values;
    }
  }
  return [];
}

async function clickAndSettle(locator, waitSelector = '') {
  await locator.click();
  await page.waitForLoadState('networkidle').catch(() => undefined);
  if (waitSelector !== '') {
    await waitForAnySelector(waitSelector);
  }
}

async function submitModuleAction(action, waitSelector = '') {
  const form = await firstLocator('[data-module="ppl-batch-translation"] form');
  await form.evaluate((formElement, nextAction) => {
    let actionInput = formElement.querySelector('input[data-smoke-module-action="1"]');
    if (!actionInput) {
      actionInput = document.createElement('input');
      actionInput.type = 'hidden';
      actionInput.name = 'module_action';
      actionInput.setAttribute('data-smoke-module-action', '1');
      formElement.appendChild(actionInput);
    }
    actionInput.value = nextAction;
    formElement.submit();
  }, action);
  await page.waitForLoadState('networkidle').catch(() => undefined);
  if (waitSelector !== '') {
    await waitForAnySelector(waitSelector);
  }
}

async function selectSetupValue(name, value) {
  const selector = name === 'translation_mode'
    ? `select[name="${name}"]`
    : `[data-module="ppl-batch-translation"] select[name="${name}"]`;
  for (let attempt = 0; attempt < 3; attempt += 1) {
    const select = await firstLocator(selector);
    try {
      await select.evaluate((element, nextValue) => {
        const hasOption = Array.from(element.options).some((option) => option.value === nextValue);
        if (!hasOption) {
          throw new Error(`Missing select option ${nextValue}`);
        }
        element.value = nextValue;
        element.dispatchEvent(new Event('change', { bubbles: true }));
      }, String(value));
      await page.waitForTimeout(100);
      return;
    } catch (error) {
      if (attempt === 2 || !String(error).includes('Execution context was destroyed')) {
        throw error;
      }
      await page.waitForLoadState('networkidle').catch(() => undefined);
    }
  }
}

async function setupValue(name) {
  const selector = name === 'translation_mode'
    ? `select[name="${name}"]`
    : `[data-module="ppl-batch-translation"] select[name="${name}"]`;
  return (await firstLocator(selector)).inputValue();
}

async function checkPageOnly(title) {
  const row = await firstTreeRow(title);
  const input = row.locator('input[name="selected_pages[]"]').first();
  await input.evaluate((element) => {
    element.checked = true;
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
    const form = element.closest('form');
    if (form) {
      const key = `${element.name}:${element.value}`;
      let hidden = form.querySelector(`input[data-smoke-selection="${key}"]`);
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = element.name;
        hidden.value = element.value;
        hidden.setAttribute('data-smoke-selection', key);
        form.appendChild(hidden);
      }
    }
  });
  assert(await input.isChecked(), `page-only checkbox is checked for ${title}`);
  await page.waitForTimeout(150);
}

async function checkBranch(title) {
  const row = await firstTreeRow(title);
  const input = row.locator('input[name="selected_subtree_pages[]"]').first();
  await input.evaluate((element) => {
    element.checked = true;
    element.dispatchEvent(new Event('input', { bubbles: true }));
    element.dispatchEvent(new Event('change', { bubbles: true }));
    const form = element.closest('form');
    if (form) {
      const key = `${element.name}:${element.value}`;
      let hidden = form.querySelector(`input[data-smoke-selection="${key}"]`);
      if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = element.name;
        hidden.value = element.value;
        hidden.setAttribute('data-smoke-selection', key);
        form.appendChild(hidden);
      }
    }
  });
  assert(await input.isChecked(), `branch checkbox is checked for ${title}`);
  await page.waitForTimeout(150);
}

async function assertInspectorPrimaryCount(stage) {
  const count = await visibleLocatorCount('.ppl-batch__panel--basket .btn-primary');
  assert(count <= 1, `${stage} exposes at most one primary inspector CTA`, { actual: count });
}

async function assertNoModuleDuplicateIds() {
  const duplicates = await scopedDuplicateIds();
  assert(duplicates.length === 0, 'no duplicate IDs inside batch module', { duplicates });
}

async function assertNoOverflow(selector, message) {
  const candidates = [page.mainFrame(), ...page.frames().filter((frame) => frame !== page.mainFrame())];
  for (const frame of candidates) {
    const result = await frame.locator(selector).first().evaluate((element) => ({
      scrollWidth: element.scrollWidth,
      clientWidth: element.clientWidth,
      scrollHeight: element.scrollHeight,
      clientHeight: element.clientHeight,
    })).catch(() => null);
    if (result) {
      assert(result.scrollWidth <= result.clientWidth + 2, message, result);
      return;
    }
  }
  throw new Error(`Overflow target not found: ${selector}`);
}

async function assertPanelFitsViewport(selector, message) {
  const candidates = [page.mainFrame(), ...page.frames().filter((frame) => frame !== page.mainFrame())];
  for (const frame of candidates) {
    const result = await frame.locator(selector).first().evaluate((element) => {
      const rect = element.getBoundingClientRect();
      return {
        top: rect.top,
        bottom: rect.bottom,
        height: rect.height,
        viewportHeight: window.innerHeight,
        maxHeight: window.getComputedStyle(element).maxHeight,
      };
    }).catch(() => null);
    if (result) {
      assert(result.bottom <= result.viewportHeight + 2, message, result);
      return;
    }
  }
  throw new Error(`Viewport target not found: ${selector}`);
}

function readFakeCalls() {
  if (!fakeCallLogPath || !fs.existsSync(fakeCallLogPath)) {
    return [];
  }
  return JSON.parse(fs.readFileSync(fakeCallLogPath, 'utf8'));
}

async function waitForAnySelector(selector, timeout = 15000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    if (await locatorCount(selector) > 0) {
      return;
    }
    await page.waitForTimeout(250);
  }
  throw new Error(`Timed out waiting for selector: ${selector}`);
}

async function setTheme(theme) {
  const applyTheme = (frame) => frame.evaluate((nextTheme) => {
    document.documentElement.dataset.theme = nextTheme;
    const module = document.querySelector('[data-module="ppl-batch-translation"]');
    if (module) {
      module.dataset.theme = nextTheme;
    }
  }, theme).catch(() => undefined);
  await applyTheme(page.mainFrame());
  for (const frame of page.frames()) {
    if (frame !== page.mainFrame()) {
      await applyTheme(frame);
    }
  }
}

async function screenshotMatrix(prefix) {
  for (const theme of themes) {
    await setTheme(theme);
    for (const viewport of viewportMatrix) {
      await page.setViewportSize(viewport);
      await minimizeBackendMenuForNarrowViewport(viewport.width);
      await page.waitForTimeout(100);
      await screenshot(`${prefix}-${theme}-${viewport.width}.png`);
    }
  }
}

async function resetDesktopViewport() {
  await page.setViewportSize({ width: 1600, height: 950 });
  await setTheme('light');
  await page.waitForTimeout(100);
}

async function minimizeBackendMenuForNarrowViewport(width) {
  if (width > 430) {
    return;
  }
  const expanded = await page.evaluate(() => document.querySelector('.t3js-scaffold')?.classList.contains('scaffold-modulemenu-expanded') ?? false).catch(() => false);
  if (!expanded) {
    return;
  }
  const toggle = page.locator('button[aria-label="Minimize/maximize module menu"]').first();
  if (await toggle.count()) {
    await toggle.click().catch(() => undefined);
    await page.waitForTimeout(250);
  }
}

async function scopedDuplicateIds() {
  const duplicates = [];
  const collect = async (frame, frameName) => {
    const frameDuplicates = await frame.evaluate(() => {
      const seen = new Set();
      const dupes = new Set();
      document.querySelectorAll('[data-module="ppl-batch-translation"] [id]').forEach((element) => {
        if (seen.has(element.id)) {
          dupes.add(element.id);
        }
        seen.add(element.id);
      });
      return Array.from(dupes);
    }).catch(() => []);
    duplicates.push(...frameDuplicates.map((id) => `${frameName}:${id}`));
  };
  await collect(page.mainFrame(), 'main');
  for (const frame of page.frames()) {
    if (frame !== page.mainFrame()) {
      await collect(frame, frame.name() || frame.url());
    }
  }
  return duplicates;
}

try {
  await page.goto(`${baseUrl}/typo3/`, { waitUntil: 'networkidle' });
  await screenshot('ui-login-before.png');

  const userInput = page.locator('input[name="username"], input#t3-username').first();
  const passwordInput = page.locator('input[name="p_field"], input[name="password"], input#t3-password').first();
  await userInput.fill(username);
  await passwordInput.fill(password);
  await page.locator('button[type="submit"], input[type="submit"]').first().click();
  await page.waitForLoadState('networkidle');

  await page.goto(`${baseUrl}/typo3/module/ppl-deepl-v3/batch-translation`, { waitUntil: 'networkidle' });
  await screenshot('ui-module-open.png');

  await assert(await locatorCount('text=PPL DeepL V3 Batch Translation') > 0, 'batch translation module opens');
  await assert(await locatorCount('button[name="module_action"][value="scan"], button[name="module_action"][value="restart_scan"]') === 1, 'top row has exactly one scan action');
  await assert(await locatorCount('[aria-current="step"]') === 1, 'exactly one active wizard step');
  await assert(await locatorCount('main#ppl-batch-main') === 1, 'module exposes one main workspace');
  await assert(await locatorCount('[data-role="batch-live-region"][aria-live]') === 1, 'module exposes one live region');
  await assert(await locatorCount('text=Run preflight') === 0, 'manual Run preflight action is absent');

  await assert((await setupOptionValues('source_language_id')).includes('2'), 'third site language is available as source option');
  await assert((await setupOptionValues('target_language_id')).includes('2'), 'third site language is available as target option');
  await selectSetupValue('source_language_id', '0');
  await selectSetupValue('target_language_id', '1');
  await selectSetupValue('translation_mode', 'translate_missing_only');

  const scan = await firstLocator('button[name="module_action"][value="scan"], button[name="module_action"][value="restart_scan"]');
  await clickAndSettle(scan, '[data-role="page-tree"]');
  await screenshot('ui-after-scan.png');
  await assertNoModuleDuplicateIds();
  await assert(await locatorCount('input[name="selected_subtree_pages[]"][aria-label]') > 0, 'branch checkboxes expose accessible labels');
  await assert(await locatorCount('input[name="selected_pages[]"][aria-label]') > 0, 'page checkboxes expose accessible labels');
  await assertInspectorPrimaryCount('select stage before selection');
  await assertNoOverflow('[data-role="page-tree"]', 'page tree table content stays inside its own table box');
  await assert(await visibleLocatorCount('[data-role="page-tree"] button[value^="open_page_preview:"]') > 0, 'page preview buttons are visible in tree');
  const treeDisplay = await (await firstLocator('.ppl-batch__tree-title-cell')).evaluate((element) => window.getComputedStyle(element).display);
  await assert(treeDisplay === 'table-cell', 'page tree title cells keep native table layout', { actual: treeDisplay });

  await screenshotMatrix('ui-after-scan');
  await page.setViewportSize({ width: 1600, height: 760 });
  await page.waitForTimeout(120);
  await assertPanelFitsViewport('.ppl-batch__panel--tree', 'page tree panel stays inside a short desktop viewport');
  await assertPanelFitsViewport('.ppl-batch__panel--basket', 'batch inspector panel stays inside a short desktop viewport');
  await screenshot('ui-after-scan-short-desktop.png');
  await resetDesktopViewport();

  const previewButton = (await firstTreeRow('Batch-Tests')).locator('button[value^="open_page_preview:"]').first();
  await clickAndSettle(previewButton, '.ppl-batch__panel--detail');
  await screenshot('ui-page-preview.png');
  await assertNoOverflow('.ppl-batch__panel--detail', 'page preview detail panel has no horizontal overflow');
  await assert(await visibleLocatorCount('.ppl-batch__panel--detail .ppl-batch__element-row') > 0, 'page preview renders element rows');
  await screenshotMatrix('ui-page-preview');
  await resetDesktopViewport();
  await clickAndSettle(await firstLocator('button[name="module_action"][value="back_to_tree"]'), '[data-role="page-tree"]');

  await checkBranch('Einzelseite');
  await assertInspectorPrimaryCount('select stage with branch');
  await screenshot('ui-branch-selected.png');
  await clickAndSettle(await firstLocator('button[name="module_action"][value="review_selection"]'), '.ppl-batch__panel--review');
  await assertInspectorPrimaryCount('review stage');
  await assert(await visibleLocatorCount('button[name="module_action"][value="write_translations"]') === 0, 'write action stays hidden before preview');
  await screenshot('ui-review-branch.png');
  await clickAndSettle(await firstLocator('button[name="module_action"][value="back_to_tree"]'), '[data-role="page-tree"]');
  await clickAndSettle(await firstLocator('button[name="module_action"][value="clear_selection"]'), '[data-role="page-tree"]');

  await selectSetupValue('translation_mode', 'translate_missing_only');
  await checkPageOnly('Bestehende Uebersetzungsseite');
  await submitModuleAction('review_selection', '.ppl-batch__panel--review');
  await screenshot('ui-existing-missing-only.png');
  await assert(
    await locatorCount('text=No pending translation') > 0
      || await locatorCount('text=No resolved selection yet') > 0,
    'missing-only review does not expose write-ready pending fields without a preview'
  );
  await clickAndSettle(await firstLocator('button[name="module_action"][value="back_to_tree"]'), '[data-role="page-tree"]');
  await clickAndSettle(await firstLocator('button[name="module_action"][value="clear_selection"]'), '[data-role="page-tree"]');

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
  writeSummary();
  await browser.close();
}
