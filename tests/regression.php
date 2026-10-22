<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use Concrete\Core\Page\Page;
use Concrete\Core\User\User;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoriteNormalizer;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoritesService;

$passed = 0;
function scenario($name, callable $test)
{
    global $passed;
    User::$items = [];
    User::$registered = true;
    User::$rawConfig = null;
    User::$writes = 0;
    Page::$idReads = Page::$pathReads = 0;
    Page::$pages = [
        1 => new Page(1, '/dashboard/current'),
        2 => new Page(2, '/dashboard/legacy'),
        3 => new Page(3, '/dashboard/private', false),
        4 => new Page(4, '/outside'),
        5 => new Page(5, '/dashboard/permission-error', 'throw'),
    ];
    $service = new DashboardFavoritesService(new stdClass(), '/dashboard/manager', new DashboardFavoriteNormalizer());
    try {
        $test($service);
    } catch (Throwable $e) {
        throw new RuntimeException($name . ': ' . $e->getMessage(), 0, $e);
    }
    $passed++;
    echo "PASS $name\n";
}

$validCases = [
    'page ID only' => ['pageID' => 1],
    'page ID and absolute URL' => ['pageID' => 1, 'url' => URL::to('/dashboard/current')],
    'page ID with old URL' => ['pageID' => 1, 'url' => '/dashboard/old'],
    'page ID with invalid URL' => ['pageID' => 1, 'url' => 'javascript:alert(1)'],
    'string page ID' => ['pageID' => '1'],
    'legacy relative URL' => ['url' => '/dashboard/current'],
    'legacy absolute URL' => ['url' => URL::to('/dashboard/current')],
    'legacy index.php URL' => ['url' => DIR_REL . '/index.php/dashboard/current'],
    'legacy zero ID' => ['pageID' => 0, 'url' => '/dashboard/current'],
    'legacy URL with query and fragment' => ['url' => '/dashboard/current?old=1#section'],
];
foreach ($validCases as $name => $item) {
    scenario($name, function ($service) use ($item) {
        User::$items = [$item];
        $toolbar = $service->getToolbarFavoriteLinks();
        $rows = $service->getManagerFavoriteLinks();
        check(count($toolbar) === 1 && count($rows) === 1, 'Valid favorite disappeared.');
        check($toolbar[0]['url'] === URL::to('/dashboard/current'), 'Toolbar used a stale URL.');
        check($rows[0]['url'] === URL::to('/dashboard/current') && !$rows[0]['isUnavailable'], 'Manager must link to the verified page.');
        check(User::$writes === 0, 'Read must not write user data.');
        check($rows[0]['selectionKey'] === $service->getFavoriteSelectionKeyFromItem($item), 'Stored identity changed during resolution.');
    });
}

$invalidCases = [
    'deleted page ID' => ['pageID' => 99, 'url' => '/dashboard/deleted'],
    'deleted ID with reused path' => ['pageID' => 99, 'url' => '/dashboard/current'],
    'deleted URL without ID' => ['url' => URL::to('/dashboard/deleted')],
    'deleted URL with zero ID' => ['pageID' => 0, 'url' => '/dashboard/deleted'],
    'non-dashboard page ID' => ['pageID' => 4, 'url' => '/dashboard/current'],
    'non-dashboard URL' => ['url' => '/outside'],
    'denied page ID' => ['pageID' => 3],
    'denied URL without ID' => ['url' => '/dashboard/private'],
    'permission exception by ID' => ['pageID' => 5],
    'permission exception by URL' => ['url' => '/dashboard/permission-error'],
    'javascript URL' => ['url' => 'javascript:alert(1)'],
    'data URL' => ['url' => 'data:text/html,test'],
    'malformed URL' => ['url' => 'http://[bad'],
    'control character in URL' => ['url' => "/dashboard/cur\nrent"],
    'dashboard prefix collision' => ['url' => '/dashboard-evil'],
];
foreach ($invalidCases as $name => $item) {
    scenario($name, function ($service) use ($item) {
        User::$items = [$item];
        check($service->getToolbarFavoriteLinks() === [], 'Unavailable favorite leaked into toolbar.');
        $rows = $service->getManagerFavoriteLinks();
        check(count($rows) === 1, 'Unavailable favorite must remain removable.');
        check($rows[0]['isUnavailable'] && $rows[0]['url'] === '', 'Unavailable favorite is clickable.');
        check($rows[0]['selectionKey'] === $service->getFavoriteSelectionKeyFromItem($item), 'Removal identity changed.');
        check($service->removeCurrentUserDashboardFavorites([$rows[0]['selectionKey']])['removed'] === 1, 'Cannot remove unavailable favorite.');
        check($service->getManagerFavoriteLinks() === [], 'Removed favorite reappeared.');
    });
}

scenario('empty favorites', function ($service) {
    check($service->getToolbarFavoriteLinks() === [] && $service->getManagerFavoriteLinks() === [], 'Empty list rendered favorites.');
});
scenario('guest reads and mutations', function ($service) {
    User::$registered = false;
    User::$items = [['pageID' => 1]];
    check($service->getToolbarFavoriteLinks() === [] && $service->getManagerFavoriteLinks() === [], 'Guest saw user favorites.');
    check(!$service->addCurrentUserDashboardPageFavorite(Page::$pages[1])['success'], 'Guest added a favorite.');
    check($service->removeCurrentUserDashboardFavorites(['x'])['removed'] === 0 && User::$writes === 0, 'Guest changed favorites.');
});
scenario('malformed stored JSON fallback', function ($service) {
    User::$rawConfig = '{broken';
    check($service->getToolbarFavoriteLinks() === [], 'Malformed JSON caused unsafe output.');
});
scenario('non-array entries and identity-free rows', function ($service) {
    User::$items = [null, false, 'junk', 12, [], ['name' => 'No identity'], ['pageID' => 1]];
    check(count($service->getManagerFavoriteLinks()) === 1 && count($service->getToolbarFavoriteLinks()) === 1, 'Junk affected valid favorites.');
});
scenario('duplicate favorite removal', function ($service) {
    User::$items = [['pageID' => 99, 'url' => '/dashboard/gone'], ['pageID' => 99, 'url' => '/dashboard/gone']];
    $rows = $service->getManagerFavoriteLinks();
    check(count($rows) === 1, 'Duplicate row displayed twice.');
    check($service->removeCurrentUserDashboardFavorites([$rows[0]['selectionKey']])['removed'] === 2, 'Duplicate orphan survived removal.');
});
scenario('canonical URL deduplication', function ($service) {
    User::$items = [['pageID' => 1, 'url' => '/dashboard/old'], ['url' => URL::to('/dashboard/current')]];
    check(count($service->getToolbarFavoriteLinks()) === 1, 'Canonical destination duplicated in toolbar.');
    check(count($service->getManagerFavoriteLinks()) === 2, 'Distinct stored identities were lost.');
});
scenario('nested children survive orphan parent', function ($service) {
    User::$items = [['pageID' => 99, 'children' => [['pageID' => 1], ['url' => '/dashboard/gone']]]];
    check(count($service->getManagerFavoriteLinks()) === 3, 'Nested rows disappeared.');
    check(array_column($service->getToolbarFavoriteLinks(), 'pageID') === [1], 'Orphan parent hid valid child.');
});
scenario('nested orphan removal preserves siblings', function ($service) {
    User::$items = [['children' => [['pageID' => 99], ['pageID' => 1]]]];
    $rows = $service->getManagerFavoriteLinks();
    check($service->removeCurrentUserDashboardFavorites([$rows[0]['selectionKey']])['removed'] === 1, 'Nested orphan not removed.');
    check(array_column($service->getManagerFavoriteLinks(), 'pageID') === [1], 'Valid sibling lost.');
});
scenario('unknown selection does not write', function ($service) {
    User::$items = [['pageID' => 1]];
    check($service->removeCurrentUserDashboardFavorites(['unknown'])['removed'] === 0 && User::$writes === 0, 'Unknown selection changed data.');
});
scenario('reorder valid and orphan favorites', function ($service) {
    User::$items = [['pageID' => 1], ['pageID' => 99, 'url' => '/dashboard/gone'], ['pageID' => 2]];
    $keys = array_reverse(array_column($service->getManagerFavoriteLinks(), 'selectionKey'));
    check($service->reorderCurrentUserDashboardFavorites($keys)['success'], 'Reorder failed.');
    check(array_column($service->getManagerFavoriteLinks(), 'pageID') === [2, 99, 1], 'Reorder lost or changed favorites.');
    check(count($service->getToolbarFavoriteLinks()) === 2, 'Reorder restored orphan link.');
});
scenario('invalid reorder does not write', function ($service) {
    User::$items = [['pageID' => 1]];
    $key = $service->getManagerFavoriteLinks()[0]['selectionKey'];
    check(!$service->reorderCurrentUserDashboardFavorites(['unknown'])['success'], 'Unknown key accepted.');
    check(!$service->reorderCurrentUserDashboardFavorites([$key, $key])['success'], 'Duplicate key accepted.');
    check(User::$writes === 0, 'Invalid reorder wrote data.');
});
scenario('move orphan up and down', function ($service) {
    User::$items = [['pageID' => 1], ['pageID' => 99]];
    $key = $service->getManagerFavoriteLinks()[1]['selectionKey'];
    check($service->moveCurrentUserDashboardFavorite($key, 'up')['success'], 'Move up failed.');
    $rows = $service->getManagerFavoriteLinks();
    check(array_column($rows, 'pageID') === [99, 1], 'Move up lost identity.');
    check($service->moveCurrentUserDashboardFavorite($rows[0]['selectionKey'], 'down')['success'], 'Move down failed.');
    check(array_column($service->getManagerFavoriteLinks(), 'pageID') === [1, 99], 'Move down lost identity.');
});
scenario('name fallback', function ($service) {
    User::$items = [['pageID' => 1, 'name' => ' ']];
    check($service->getToolbarFavoriteLinks()[0]['name'] === 'Page 1', 'Missing toolbar label.');
});
scenario('lookup cache within request', function ($service) {
    User::$items = [['url' => '/dashboard/current'], ['url' => '/dashboard/current'], ['pageID' => 2], ['pageID' => 2]];
    $service->getToolbarFavoriteLinks();
    $service->getManagerFavoriteLinks();
    check(Page::$idReads === 1 && Page::$pathReads === 1, 'Repeated page queries within one request.');
});
scenario('fresh request sees removed package', function ($service) {
    User::$items = [['url' => '/dashboard/current'], ['pageID' => 2]];
    check(count($service->getToolbarFavoriteLinks()) === 2, 'Initial pages unavailable.');
    Page::$pages = [];
    $fresh = new DashboardFavoritesService(new stdClass(), '/dashboard/manager', new DashboardFavoriteNormalizer());
    check($fresh->getToolbarFavoriteLinks() === [], 'Removed package retained live toolbar links.');
    check(count(array_filter($fresh->getManagerFavoriteLinks(), function ($row) { return $row['isUnavailable']; })) === 2, 'Fresh request did not mark orphans.');
});
scenario('add remove and duplicate prevention', function ($service) {
    check($service->addCurrentUserDashboardPageFavorite(Page::$pages[1])['success'], 'Add failed.');
    check(!$service->addCurrentUserDashboardPageFavorite(Page::$pages[1])['success'], 'Duplicate added.');
    check(count($service->getToolbarFavoriteLinks()) === 1, 'Added favorite not visible.');
    check($service->removeCurrentUserDashboardPageFavorite(1)['success'], 'Remove failed.');
    check($service->getToolbarFavoriteLinks() === [], 'Removed favorite still visible.');
});
scenario('export import round trip and unavailable report', function ($service) {
    User::$items = [['pageID' => 1, 'name' => 'Valid'], ['pageID' => 99, 'name' => 'Orphan']];
    $payload = $service->getDashboardFavoriteExportPayload();
    check($service->getImportPayloadFavorites($payload)['success'], 'Own export rejected.');
    User::$items = [];
    $report = $service->importDashboardFavorites($payload['favorites'], function ($page) { return $page->visible === true; });
    check($report['imported'] === 1 && $report['skippedInvalid'] === 1, 'Import counts wrong.');
    check(count($service->getToolbarFavoriteLinks()) === 1, 'Import restored an orphan.');
    check($service->importDashboardFavorites($payload['favorites'], function () { return true; })['skippedExisting'] === 1, 'Repeated import duplicated favorite.');
});
scenario('import rejects invalid format and permissions', function ($service) {
    check(!$service->getImportPayloadFavorites(['format' => 'other', 'version' => 1, 'favorites' => []])['success'], 'Wrong format accepted.');
    check(!$service->getImportPayloadFavorites(['format' => 'dashboard_favorites_manager', 'version' => 99, 'favorites' => []])['success'], 'Wrong version accepted.');
    check(!$service->getImportPayloadFavorites(['format' => 'dashboard_favorites_manager', 'version' => 1, 'favorites' => 'bad'])['success'], 'Malformed list accepted.');
    $report = $service->importDashboardFavorites([['path' => '/dashboard/private'], ['path' => '/outside'], ['path' => '/dashboard/gone']], function ($page) { return $page->visible === true; });
    check($report['skippedInvalid'] === 3 && User::$writes === 0, 'Unavailable import changed data.');
});

echo "$passed scenarios passed (base path: " . (DIR_REL ?: '/') . ").\n";
