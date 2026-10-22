# Favorites regression checks

These standalone tests use the real favorites service and normalizer with small doubles for Concrete pages, permissions, users and URL generation. They require PHP 8 and do not connect to a CMS database.

```powershell
php .tests/orphan_favorites.php
php .tests/regression.php
php .tests/page_load_cleanup.php
$env:DFM_TEST_BASE_PATH = '/cms'
php .tests/orphan_favorites.php
php .tests/regression.php
php .tests/page_load_cleanup.php
Remove-Item Env:/DFM_TEST_BASE_PATH
```

The regression runner contains 50 named scenarios covering page IDs and legacy URLs, deleted pages, stale URLs, permissions, invalid URLs, nested items, duplicate removal, reordering, import/export, guests, request caching and automatic cleanup. The read methods do not write user data. The separate page-view cleanup removes only confirmed missing pages from explicitly stored favorites, saves only after a removal, and preserves existing pages even without view permission. A deleted positive page ID must not silently resolve to a different page reusing the stored URL.

`page_load_cleanup.php` exercises the actual package startup and toolbar callbacks with small core doubles. It verifies dashboard cleanup with the star menu enabled and disabled, navigation cache invalidation before dashboard controller startup, continued toolbar rendering, shared page lookups, and no extra writes for a clean list. No HTTP cleanup request is added.

Automatic cleanup was also verified on 2026-10-22 in the selected `testcms_c5_955` instance (Concrete CMS 9.5.5, PHP 8.2.35):

- Real installation and uninstallation of the disposable package, with three dashboard pages.
- Two dedicated users, one with the star menu enabled and one disabled; existing sessions populated the core favorites cache before uninstall.
- 22 real HTTP assertions across login, initial manager, first dashboard load after uninstall, and subsequent manager load. Both users' three orphans were removed by the dashboard load, before visiting the manager. The enabled star retained its assets and received the two valid favorites; the disabled star stayed disabled.
- 15 native assertions confirming persistence, unchanged toolbar settings, preservation of denied ID/URL favorites, and unchanged existing users' favorites/settings digest.
- Temporary users, fixture package, cookie jars, and credential state were removed. The two modified DFM files remain installed for review; their prior versions are backed up in `/tmp/dfm-auto-cleanup-20261022/before.tar.gz`.

This run used actual HTTP responses and persisted CMS data; it did not run browser JavaScript interactions. The scripts are `live/gate.php` (actions `automatic-seed`, `automatic-assert`) and `live/automatic_http.php` (actions `before`, `dashboard`, `manager`). Keep credentials and cookie jars in the selected container. Run CMS CLI commands as `www-data`: running fixture installation as root created an unwritable cache directory, which was corrected before the HTTP assertions. The first HTTP harness also used an incorrect manager selector; it was corrected to match the actual view.

## Isolated browser smoke

The fixture renders the actual manager PHP view, CSS and JavaScript, together with the actual toolbar scripts. Set the path to an existing local jQuery asset (for example Concrete's `concrete/js/jquery.js`), then serve this checkout locally:

```powershell
$env:DFM_TEST_JQUERY = 'C:/path/to/concrete/js/jquery.js'
php -S 127.0.0.1:8765 -t .
```

Run the function in `browser_smoke.js` with a Playwright `page`, or pass this file to the Playwright connector's `browser_run_code_unsafe` tool. The fixture URL is `http://127.0.0.1:8765/.tests/smoke_fixture.php`. Stop the local server afterwards.

The smoke makes 94 assertions across desktop/mobile and light/dark modes. It checks rendering, escaping stored markup, selection, removal confirmation/cancellation, toolbar refresh, mobile move, empty states, and recovery from removal/reorder errors. It uses real jQuery; AJAX endpoint responses are mocked by Playwright. It does not load Concrete's full theme or exercise desktop jQuery UI drag-and-drop.

## CMS integration

On an isolated Concrete CMS installation, add favorites for a disposable package's dashboard pages, uninstall that package, reload and verify that the toolbar excludes the links and the manager marks them unavailable. Remove the orphans, reload again, and verify persistence. Repeat for another user, with cache enabled, and for a user lacking permission to a previously bookmarked page. Verify add/remove, import/export, desktop drag-and-drop and mobile ordering on the installed package. Restore test users and favorites afterwards.

Live verification completed on 2026-10-22 on the explicitly selected disposable `testcms_c5_955` container, Concrete CMS 9.5.5, PHP 8.2.35, with caching enabled:

| Coverage | Result |
| --- | --- |
| Before fix, real package uninstall | Reproduced: URL-only favorite remained in the toolbar |
| After fix, three deleted package pages, two users | 12 live assertions passed; no orphan toolbar links; manager rows unavailable and removable |
| Native mutations, import/export, restricted user, user isolation | 14 assertions passed |
| Actual CMS browser, desktop drag-and-drop | HTTP 200/success; order persisted after reload; three orphan rows remained nonclickable |
| Actual CMS browser, mobile and dark mode, move, cancel, bulk removal, reload | 10 assertions passed, using real controller responses |
| Cleanup | Three dedicated accounts and disposable package removed; existing users' favorites/settings digest matched the original |

The native live runner is `live/gate.php`; `live/fixture` contains the disposable package. The runner is deliberately restricted to the selected test container and requires `DFM_DISPOSABLE_TEST=1`. Credential/state files are temporary and are removed after the run. The fixed three DFM files remain installed on the test instance; the previous package is backed up under `/tmp/dfm-orphans-20261022/dfm-before.tar.gz`.

The initial live harness needed two corrections: use Concrete's custom request user when switching CLI users, and normalize fixture favorites so `isActive` is boolean before loading the real core navigation. The latter caused the reported `Item::setIsActive(null)` exception only for the dedicated fixture account; the corrected fixture loaded with HTTP 200.

These results cover the stated environments and scenarios. Test PHP entry points are disabled outside CLI and PHP's development server. The `/.tests export-ignore` rule in `.gitattributes` excludes this directory from installable release ZIPs created with `git archive`.
