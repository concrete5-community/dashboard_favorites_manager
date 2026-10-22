(async function (page) {
    const failures = [];
    const errors = [];
    let checks = 0;
    const assert = (value, message) => {
        checks++;
        if (!value) failures.push(message);
    };
    const onError = error => errors.push(error.message);
    page.on('pageerror', onError);
    const baseUrl = 'http://127.0.0.1:8765/tests/smoke_fixture.php';
    const rows = () => page.locator('tr[data-favorite-key]');
    const toolbarRows = () => page.locator('.dashboard-favorites-toolbar-link-row');
    const unavailableRows = () => rows().filter({hasText: 'Page unavailable'});

    try {
        for (const width of [1280, 390]) {
            for (const theme of ['light', 'dark']) {
                const context = `${width}px ${theme}`;
                await page.setViewportSize({width, height: 900});
                await page.goto(baseUrl);
                await page.evaluate(theme => {
                    document.documentElement.setAttribute('data-bs-theme', theme);
                    document.body.setAttribute('data-theme', theme);
                }, theme);
                await rows().first().waitFor();
                assert(await rows().count() === 5, `${context}: missing rows`);
                assert(await unavailableRows().count() === 3, `${context}: missing unavailable labels`);
                assert(await unavailableRows().locator('a').count() === 0, `${context}: orphan is clickable`);
                assert(await rows().first().locator('a').getAttribute('href') === 'https://example.test/index.php/dashboard/current', `${context}: stale URL`);
                assert(await toolbarRows().count() === 2, `${context}: toolbar includes orphans`);
                assert(await page.locator('.dashboard-favorites-manager-table img, .dashboard-favorites-manager-table script').count() === 0, `${context}: stored markup executed`);
                assert(await page.evaluate(() => !window.fixtureXss), `${context}: XSS marker set`);
                assert(await unavailableRows().locator('input[type=checkbox]').count() === 3, `${context}: orphan cannot be selected`);
                const remove = page.locator('[data-dashboard-favorites-remove]');
                assert(await remove.isDisabled(), `${context}: remove enabled without selection`);
                const orphan = rows().filter({hasText: 'Tiny File Manager'});
                await orphan.locator('input[type=checkbox]').check();
                assert(await remove.isEnabled(), `${context}: orphan selection did not enable removal`);
                await remove.click();
                assert(await page.locator('[data-dashboard-favorites-remove-confirm]').isVisible(), `${context}: confirmation not shown`);
                await page.locator('[data-dashboard-favorites-remove-confirm-no]').click();
                assert(await rows().count() === 5, `${context}: cancel removed rows`);
                const selectAll = width < 576
                    ? page.locator('[data-dashboard-favorites-select-all-mobile]')
                    : page.locator('#dashboard-favorites-manager-select-all');
                await selectAll.check();
                assert(await page.locator('.dashboard-favorites-manager-checkbox:checked').count() === 5, `${context}: select all failed`);
                await selectAll.uncheck();
                assert(await remove.isDisabled(), `${context}: unselect all failed`);
                const star = page.locator('[data-dashboard-favorites-toolbar-button]');
                await star.click();
                assert(await star.getAttribute('aria-expanded') === 'true', `${context}: toolbar did not open`);
                assert(await page.locator('.dashboard-favorites-toolbar-link-primary').count() === 2, `${context}: wrong menu links`);
                const newTab = page.locator('[data-dashboard-favorites-toolbar-open-new-tab]').first();
                assert(await newTab.getAttribute('target') === '_blank' && (await newTab.getAttribute('rel')).includes('noopener'), `${context}: new-tab behavior changed`);
                await star.click();
                assert(await star.getAttribute('aria-expanded') === 'false', `${context}: toolbar did not close`);
                await page.evaluate(() => window.DashboardFavoritesManagerToolbarUpdate(window.fixtureRows));
                assert(await toolbarRows().count() === 2, `${context}: refresh restored orphan links`);
                await page.evaluate(() => window.dispatchEvent(new CustomEvent('dashboardFavoritesManager:favoritesChanged', {
                    detail: {favorites: window.fixtureRows}
                })));
                assert(await unavailableRows().locator('a').count() === 0 && await unavailableRows().count() === 3, `${context}: table refresh restored orphan links`);
            }
        }

        await page.goto(baseUrl);
        let model = await page.evaluate(() => window.fixtureRows);
        let selectedKey;
        await page.route('**/fixture-only/remove_favorites', async route => {
            const posted = route.request().postData() || '';
            assert(posted.includes(selectedKey), 'AJAX removal lost the original selection key');
            model = model.filter(row => row.selectionKey !== selectedKey);
            await route.fulfill({json: {success: true, message: 'Removed fixture favorite.', favorites: model}});
        });
        const orphan = rows().filter({hasText: 'Tiny File Manager'});
        selectedKey = await orphan.getAttribute('data-favorite-key');
        await orphan.locator('input[type=checkbox]').check();
        await page.locator('[data-dashboard-favorites-remove]').click();
        await page.locator('[data-dashboard-favorites-remove-confirm-yes]').click();
        await page.waitForFunction(() => document.querySelectorAll('tr[data-favorite-key]').length === 4);
        assert(await rows().filter({hasText: 'Tiny File Manager'}).count() === 0, 'AJAX removal left the orphan row');
        assert(await toolbarRows().count() === 2, 'AJAX removal changed valid toolbar links');
        assert(await unavailableRows().count() === 2, 'AJAX removal lost unrelated unavailable rows');
        await page.unroute('**/fixture-only/remove_favorites');

        await page.goto(baseUrl);
        await page.route('**/fixture-only/remove_favorites', route => route.fulfill({status: 500, json: {message: 'Fixture removal failed.'}}));
        await rows().filter({hasText: 'Settings'}).locator('input[type=checkbox]').check();
        await page.locator('[data-dashboard-favorites-remove]').click();
        await page.locator('[data-dashboard-favorites-remove-confirm-yes]').click();
        await page.locator('[data-dashboard-favorites-overlay-message]').filter({hasText: 'Fixture removal failed.'}).waitFor();
        assert(await rows().count() === 5, 'Failed AJAX removal changed table');
        assert(await page.locator('[data-dashboard-favorites-remove]').isEnabled(), 'Failed AJAX removal left controls disabled');
        await page.unroute('**/fixture-only/remove_favorites');

        await page.setViewportSize({width: 390, height: 900});
        await page.goto(baseUrl);
        model = await page.evaluate(() => window.fixtureRows);
        await page.route('**/fixture-only/reorder_favorites', async route => {
            assert((route.request().postData() || '').includes(model[2].selectionKey), 'Mobile move lost orphan identity');
            [model[1], model[2]] = [model[2], model[1]];
            await route.fulfill({json: {success: true, favorites: model}});
        });
        await rows().filter({hasText: 'Tiny File Manager'}).locator('[data-dashboard-favorites-move=up]').click();
        await page.waitForFunction(() => !document.querySelector('[data-dashboard-favorites-reorder-busy]'));
        assert((await rows().nth(1).innerText()).includes('Tiny File Manager'), 'Mobile move did not move the orphan');
        assert(await unavailableRows().locator('a').count() === 0 && await toolbarRows().count() === 2, 'Mobile move restored orphan links');
        await page.unroute('**/fixture-only/reorder_favorites');

        await page.goto(baseUrl);
        const before = await rows().allTextContents();
        await page.route('**/fixture-only/reorder_favorites', route => route.fulfill({status: 500, json: {message: 'Fixture reorder failed.'}}));
        await page.evaluate(() => { window.ConcreteAlert = {error: error => { window.fixtureError = error.message; }}; });
        await rows().filter({hasText: 'Tiny File Manager'}).locator('[data-dashboard-favorites-move=up]').click();
        await page.waitForFunction(() => window.fixtureError === 'Fixture reorder failed.');
        assert(JSON.stringify(await rows().allTextContents()) === JSON.stringify(before), 'Failed mobile move did not restore order');
        assert(await rows().filter({hasText: 'Tiny File Manager'}).locator('[data-dashboard-favorites-move=up]').isEnabled(), 'Failed mobile move left controls disabled');
        await page.unroute('**/fixture-only/reorder_favorites');

        await page.evaluate(() => {
            window.DashboardFavoritesManagerToolbarUpdate([]);
            window.dispatchEvent(new CustomEvent('dashboardFavoritesManager:favoritesChanged', {detail: {favorites: []}}));
        });
        assert(await rows().count() === 0 && await toolbarRows().count() === 0, 'Empty state retained links');
        assert(await page.locator('.dashboard-favorites-manager-empty-favorites').isVisible(), 'Empty state message missing');
        assert(errors.length === 0, 'Browser JavaScript errors: ' + errors.join('; '));
        if (failures.length) throw new Error(failures.join('\n'));
        return {checks, status: 'passed', variants: ['desktop light', 'desktop dark', 'mobile light', 'mobile dark'], ajax: 'mocked responses; real jQuery and DFM scripts', errors};
    } finally {
        await page.unroute('**/fixture-only/remove_favorites');
        await page.unroute('**/fixture-only/reorder_favorites');
        page.off('pageerror', onError);
    }
})
