<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Concrete\Core\Page\Page;
use Concrete\Core\User\User;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoriteNormalizer;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoritesService;

Page::$pages = [
    1 => new Page(1, '/dashboard/current'),
    2 => new Page(2, '/dashboard/legacy'),
    3 => new Page(3, '/dashboard/private', false),
];
$normalizer = new DashboardFavoriteNormalizer();
$service = new DashboardFavoritesService(new \stdClass(), '/dashboard/manager', $normalizer);
User::$items = [
    ['pageID' => 1, 'name' => 'Moved', 'url' => '/dashboard/old'],
    ['name' => 'Legacy', 'url' => URL::to('/dashboard/legacy')],
    ['name' => 'Removed URL', 'url' => URL::to('/dashboard/removed')],
    ['pageID' => 99, 'name' => 'Removed ID', 'url' => '/dashboard/current'],
    ['name' => 'Forbidden', 'url' => '/dashboard/private'],
    ['name' => 'Invalid', 'url' => 'javascript:alert(1)'],
    ['name' => 'Container', 'children' => [
        ['pageID' => 100, 'name' => 'Nested orphan', 'url' => '/dashboard/nested'],
    ]],
];
$original = User::$items;
$toolbar = $service->getToolbarFavoriteLinks();
check(array_column($toolbar, 'name') === ['Moved', 'Legacy'], 'Toolbar must exclude orphan, forbidden and invalid favorites.');
check($toolbar[0]['path'] === '/dashboard/current', 'Existing page ID must override a stale URL.');
check($toolbar[1]['path'] === '/dashboard/legacy', 'Legacy URL must resolve to a real page.');

$rows = $service->getManagerFavoriteLinks();
check(count($rows) === 7, 'Unavailable favorites must remain removable, including nested orphans.');
check($rows[0]['url'] === URL::to('/dashboard/current'), 'Manager must use the current page URL.');
check($rows[2]['path'] === '/dashboard/removed', 'Orphan must show a normalized path.');
foreach (array_slice($rows, 2) as $row) {
    check($row['url'] === '' && $row['isUnavailable'], 'Unavailable row must not contain a clickable URL.');
}
check(User::$items === $original, 'Reading favorites must not rewrite stored data.');
$removed = $service->removeCurrentUserDashboardFavorites(array_column(array_slice($rows, 2), 'selectionKey'));
check($removed['removed'] === 5, 'Bulk removal must remove unavailable rows using their original identity.');
check(array_column($service->getManagerFavoriteLinks(), 'name') === ['Moved', 'Legacy'], 'Bulk removal must preserve valid favorites.');
echo "Orphan favorites regression checks passed (base path: " . (DIR_REL ?: '/') . ").\n";
