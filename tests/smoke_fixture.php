<?php

declare(strict_types=1);

// Local fixture only: no CMS database, credentials, or production routes.
require __DIR__ . '/bootstrap.php';

use Concrete\Core\Page\Page;
use Concrete\Core\User\User;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoriteNormalizer;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoritesService;

function h($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
$view = new class {
    public function action($name) { return '/fixture-only/' . $name; }
};
Page::$pages = [1 => new Page(1, '/dashboard/current'), 2 => new Page(2, '/dashboard/legacy')];
User::$items = [
    ['pageID' => 1, 'name' => 'Valid moved page', 'url' => '/dashboard/old'],
    ['name' => 'Legacy URL', 'url' => URL::to('/dashboard/legacy')],
    ['pageID' => 99, 'name' => 'Tiny File Manager', 'url' => URL::to('/dashboard/system/files/tiny_file_manager')],
    ['name' => 'Settings', 'url' => URL::to('/dashboard/system/files/tiny_file_manager/settings')],
    ['name' => '<img src=x onerror="window.fixtureXss=1">', 'url' => '/dashboard/<script>alert(1)</script>'],
];
$service = new DashboardFavoritesService(new stdClass(), '/dashboard/manager', new DashboardFavoriteNormalizer());
$favoriteLinks = $service->getManagerFavoriteLinks();
$packageVersion = 'test fixture';
$toolbarFavoritesEnabled = $toolbarSearchEnabled = $toolbarClearCacheEnabled = $toolbarLogoutEnabled = true;
$toolbarConcreteVersionEnabled = $toolbarTooltipsEnabled = $toolbarKeepOpenOnPageChangeEnabled = false;
$canUseToolbarClearCache = true;
$dashboardPageSearchMinLength = 2;
$toolbarSettingsToken = $toggleDashboardPageToken = $removeFavoritesToken = $reorderFavoritesToken = $importExportToken = 'fixture';
$importReport = $pendingPackageUpdate = null;
$overlayMessages = [];
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>DFM isolated smoke fixture</title><link rel="icon" href="data:,">
<link rel="stylesheet" href="/assets/css/dashboard/welcome/favorites_manager.css">
<link rel="stylesheet" href="/assets/css/toolbar_favorites.css">
<style>
body { font: 16px Arial, sans-serif; padding: 16px; color: #222; background: #fff; }
body[data-theme=dark] { color: #ddd; background: #20242a; }
table { width: 100%; border-collapse: collapse; } td, th { padding: 8px; text-align: left; border-bottom: 1px solid #888; }
.text-muted { color: #777; } .card { border: 1px solid #888; padding: 12px; margin: 12px 0; }
.visually-hidden { position: absolute; width: 1px; height: 1px; overflow: hidden; }
.ccm-toolbar-item-list { display: flex; list-style: none; justify-content: flex-end; }
</style></head><body>
<nav id="ccm-toolbar"><ul class="ccm-toolbar-item-list"></ul></nav>
<?php require __DIR__ . '/../single_pages/dashboard/welcome/favorites_manager.php'; ?>
<script>
window.fixtureRows = <?= json_encode($favoriteLinks, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.DashboardFavoritesManagerToolbar = <?= json_encode([
    'enabled' => true, 'favoritesEnabled' => true, 'favorites' => $service->getToolbarFavoriteLinks(),
    'currentPageID' => 0, 'toolbarTooltips' => false, 'title' => 'Dashboard favorites',
    'emptyText' => 'The favorites list is empty.',
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="/assets/js/dashboard_search.js"></script>
<script><?= file_get_contents(getenv('DFM_TEST_JQUERY') ?: __DIR__ . '/../../concrete_cms_development/concrete-cms-9.5.x-github/concrete/js/jquery.js') ?></script>
<script src="/assets/js/toolbar_favorites.js"></script>
<script src="/assets/js/dashboard/welcome/favorites_manager.js"></script>
</body></html>
