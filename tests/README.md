# Favorites regression checks

These standalone tests use the real favorites service and normalizer with small doubles for Concrete pages, permissions, users and URL generation. They require PHP 8 and do not connect to a CMS database.

```powershell
php tests/orphan_favorites.php
php tests/regression.php
$env:DFM_TEST_BASE_PATH = '/cms'
php tests/orphan_favorites.php
php tests/regression.php
Remove-Item Env:/DFM_TEST_BASE_PATH
```

The regression runner contains 43 named scenarios covering page IDs and legacy URLs, deleted pages, stale URLs, permissions, invalid URLs, nested items, duplicate removal, reordering, import/export, guests and request caching. Reading the favorites must not write user data. A deleted positive page ID must not silently resolve to a different page reusing the stored URL.

## Isolated browser smoke

The fixture renders the actual manager PHP view, CSS and JavaScript, together with the actual toolbar scripts. Set the path to an existing local jQuery asset (for example Concrete's `concrete/js/jquery.js`), then serve this checkout locally:

```powershell
$env:DFM_TEST_JQUERY = 'C:/path/to/concrete/js/jquery.js'
php -S 127.0.0.1:8765 -t .
```

Run the function in `browser_smoke.js` with a Playwright `page`, or pass this file to the Playwright connector's `browser_run_code_unsafe` tool. The fixture URL is `http://127.0.0.1:8765/tests/smoke_fixture.php`. Stop the local server afterwards.

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

These results cover the stated environments and scenarios. Test PHP entry points are disabled outside CLI and PHP's development server. Keep the `tests` directory out of installable release ZIPs.
