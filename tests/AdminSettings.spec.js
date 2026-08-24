const { test, expect } = require('@playwright/test');

const baseUrl = process.env.TYPECHO_BASE_URL || 'http://typecho13';
const username = process.env.TYPECHO_ADMIN_USER;
const password = process.env.TYPECHO_ADMIN_PASSWORD;

test.use({ channel: 'msedge' });

test('settings keep table selection outside destination tabs', async ({ page }) => {
    test.skip(!username || !password, 'Typecho administrator credentials are required');

    await page.goto(`${baseUrl}/admin/login.php`);
    await page.getByLabel('用户名或邮箱').fill(username);
    await page.getByLabel('密码').fill(password);
    await page.getByRole('button', { name: '登录' }).click();

    await page.goto(`${baseUrl}/admin/options-plugin.php?config=AutoBackup`);
    await expect(page.locator('.autobackup-settings-shell')).toBeVisible();
    await expect(page.locator('.autobackup-promo')).toBeVisible();
    await expect(page.locator('.autobackup-cron-links a')).toHaveCount(3);
    const backupUrl = await page.locator('#autobackup-backup-url').textContent();
    await page.evaluate(() => {
        document.execCommand = command => {
            window.__autoBackupCopy = command === 'copy' ? document.activeElement.value : null;
            return command === 'copy';
        };
    });
    await page.getByRole('button', { name: '复制备份调用地址' }).click();
    await expect(page.getByRole('button', { name: '复制备份调用地址' })).toHaveText('已复制');
    expect(await page.evaluate(() => window.__autoBackupCopy)).toBe(backupUrl);
    await expect(page.locator('.fix-for-tables')).toBeVisible();
    await expect(page.locator('.autobackup-common-field').filter({ hasText: '调试模式' })).toBeVisible();

    const tablesBox = await page.locator('.fix-for-tables').boundingBox();
    const tabsBox = await page.locator('.autobackup-tabs').boundingBox();
    expect(tablesBox.y + tablesBox.height).toBeLessThanOrEqual(tabsBox.y + 1);

    await page.getByRole('tab', { name: 'WebDAV' }).click();
    await expect(page.locator('input[name="webdavUrl"]')).toBeVisible();
    await expect(page.locator('.fix-for-tables')).toBeVisible();
    await expect(page.locator('.autobackup-common-field').filter({ hasText: '调试模式' })).toBeVisible();
    await expect(page.locator('#autobackup-panel-email')).toBeHidden();

    let overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);

    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.locator('.fix-for-tables')).toBeVisible();
    await expect(page.locator('.autobackup-tabs')).toBeVisible();
    overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    expect(overflow).toBeLessThanOrEqual(0);
});
