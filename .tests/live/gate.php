<?php

use Concrete\Core\Page\Page;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfo;
use Concrete\Core\User\Group\Group;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoriteNormalizer;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoritesService;

if (PHP_SAPI !== 'cli' || getenv('DFM_DISPOSABLE_TEST') !== '1' || trim(file_get_contents('/etc/hostname')) !== '84915c73cb61') {
    throw new RuntimeException('Run only through c5:exec on the explicitly selected disposable container.');
}
$dir = __DIR__;
$stateFile = $dir . '/state.json';
$db = app('database')->connection();
$action = $args[0] ?? 'inventory';
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : [];
$checks = [];
$assert = function ($condition, $name) use (&$checks) {
    $checks[] = ['name' => $name, 'passed' => (bool) $condition];
    if (!$condition) {
        throw new RuntimeException($name);
    }
};
$saveState = function () use (&$state, $stateFile) {
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));
    chmod($stateFile, 0600);
};
$digest = function () use ($db, &$state) {
    $rows = $db->fetchAllAssociative("select cfKey, uID, cfValue from ConfigStore where cfKey like 'DASHBOARD_FAVORITES%' order by uID, cfKey");
    $ids = array_column($state['users'] ?? [], 'id');
    $rows = array_values(array_filter($rows, function ($row) use ($ids) { return !in_array((int) $row['uID'], $ids, true); }));
    return hash('sha256', json_encode($rows));
};
$asUser = function ($id) {
    $user = User::getByUserID($id);
    Concrete\Core\Http\Request::getInstance()->setCustomRequestUser($user);
    app()->instance(User::class, $user);
    app('cache/request')->flush();
    return new DashboardFavoritesService(app(), '/dashboard/welcome/favorites_manager', new DashboardFavoriteNormalizer());
};
$seed = function ($id) use ($asUser, &$state) {
    $service = $asUser($id);
    $valid = Page::getByPath('/dashboard/files/search');
    $manager = Page::getByPath('/dashboard/welcome/favorites_manager');
    $items = [$service->getDashboardFavoritesManagerFavoriteItem($manager), [
        'pageID' => (int) $valid->getCollectionID(), 'name' => 'File Manager', 'url' => (string) URL::to('/dashboard/files/search'),
    ]];
    foreach ($state['pages'] as $i => $page) {
        $item = ['name' => 'Disposable ' . $i, 'url' => (string) URL::to($page['path'])];
        if ($i !== 1) $item['pageID'] = $page['id'];
        $items[] = $item;
    }
    $user = new User();
    $user->saveConfig('DASHBOARD_FAVORITES', json_encode($service->normalizeItems($items)));
    $user->saveConfig('DASHBOARD_FAVORITES_MANAGER_TOOLBAR_ENABLED', 1);
    $service->clearFavoritesCache();
    return $service;
};

try {
    if ($action === 'inventory') {
        $pkg = Concrete\Core\Package\Package::getByHandle('dashboard_favorites_manager');
        $state['beforeDigest'] = $digest();
        $state['users'] = [];
        $saveState();
        echo json_encode(['cms' => APP_VERSION, 'php' => PHP_VERSION, 'dfm' => $pkg ? $pkg->getPackageVersion() : null,
            'existingFavoritesDigest' => $state['beforeDigest'], 'cache' => app('config')->get('concrete.cache')], JSON_PRETTY_PRINT), "\n";
    } elseif ($action === 'provision') {
        $assert(empty($state['users']), 'No previous fixture users');
        foreach (['a', 'b', 'restricted'] as $suffix) {
            $name = 'dfm_orphan_20261022_' . $suffix;
            $assert(!UserInfo::getByUserName($name), 'Disposable username is unused: ' . $suffix);
            $password = bin2hex(random_bytes(18)) . 'Aa!7';
            $info = app('user/registration')->create(['uName' => $name, 'uEmail' => $name . '@example.invalid',
                'uPassword' => $password, 'uIsValidated' => 1, 'uIsActive' => 1]);
            $assert($info && $info->getUserID() !== 1, 'Separate fixture user created: ' . $suffix);
            $state['users'][$suffix] = ['id' => (int) $info->getUserID(), 'name' => $name, 'password' => $password];
            $saveState();
            $info->activate();
            if ($suffix !== 'restricted') $info->getUserObject()->enterGroup(Group::getByName('Administrators'));
        }
        echo json_encode(['provisioned' => array_keys($state['users'])]), "\n";
    } elseif ($action === 'seed') {
        $state['pages'] = [];
        foreach (['/dashboard/dfm_orphan_fixture', '/dashboard/dfm_orphan_fixture/one', '/dashboard/dfm_orphan_fixture/two'] as $path) {
            $page = Page::getByPath($path);
            $assert($page && !$page->isError(), 'Package fixture page exists: ' . $path);
            $state['pages'][] = ['id' => (int) $page->getCollectionID(), 'path' => $path];
        }
        $saveState();
        foreach (['a', 'b'] as $suffix) {
            $service = $seed($state['users'][$suffix]['id']);
            $assert(count($service->getToolbarFavoriteLinks()) === 5, 'Before uninstall: five live favorites for user ' . $suffix);
            $assert(count(array_filter($service->getManagerFavoriteLinks(), function ($row) { return $row['isUnavailable'] ?? false; })) === 0,
                'Before uninstall: no orphan rows for user ' . $suffix);
        }
        echo json_encode(['checks' => $checks, 'pageCount' => count($state['pages'])], JSON_PRETTY_PRINT), "\n";
    } elseif ($action === 'assert-orphans') {
        foreach ($state['pages'] as $page) {
            $deleted = Page::getByID($page['id']);
            $assert(!$deleted || $deleted->isError(), 'Package uninstall deleted page ' . $page['path']);
        }
        foreach (['a', 'b'] as $suffix) {
            $service = $asUser($state['users'][$suffix]['id']);
            $storedBefore = (new User())->config('DASHBOARD_FAVORITES');
            $assert(count($service->getToolbarFavoriteLinks()) === 2, 'After uninstall: only valid toolbar links for user ' . $suffix);
            $rows = $service->getManagerFavoriteLinks();
            $orphans = array_filter($rows, function ($row) { return $row['isUnavailable'] ?? false; });
            $assert(count($rows) === 5 && count($orphans) === 3, 'After uninstall: all three orphans remain selectable for user ' . $suffix);
            $assert(count(array_filter($orphans, function ($row) { return $row['url'] !== '' || strpos($row['path'], '/dashboard/') !== 0; })) === 0,
                'After uninstall: normalized paths and no orphan URLs for user ' . $suffix);
            $assert((new User())->config('DASHBOARD_FAVORITES') === $storedBefore, 'Reads preserve stored favorites for user ' . $suffix);
        }
        $assert($digest() === $state['beforeDigest'], 'Existing users favorites and settings unchanged');
        echo json_encode(['checks' => $checks], JSON_PRETTY_PRINT), "\n";
    } elseif ($action === 'native-regression') {
        $a = $state['users']['a']['id'];
        $b = $state['users']['b']['id'];
        $service = $asUser($a);
        $rows = $service->getManagerFavoriteLinks();
        $keys = array_reverse(array_column($rows, 'selectionKey'));
        $assert($service->reorderCurrentUserDashboardFavorites($keys)['success'], 'Native reorder accepts mixed live and orphan favorites');
        $assert(array_column($service->getManagerFavoriteLinks(), 'name') === array_reverse(array_column($rows, 'name')), 'Native reorder persists all rows');
        $orphans = array_filter($service->getManagerFavoriteLinks(), function ($row) { return $row['isUnavailable']; });
        $assert($service->removeCurrentUserDashboardFavorites(array_column($orphans, 'selectionKey'))['removed'] === 3, 'Native bulk removal deletes three real orphans');
        $assert(count($asUser($a)->getManagerFavoriteLinks()) === 2, 'Native removal survives fresh service');
        $assert(count($asUser($b)->getManagerFavoriteLinks()) === 5, 'User A removal leaves user B untouched');
        $service = $asUser($a);
        $page = Page::getByPath('/dashboard/sitemap/full');
        $assert($service->addCurrentUserDashboardPageFavorite($page)['success'], 'Native add existing dashboard page');
        $assert(!$service->addCurrentUserDashboardPageFavorite($page)['success'], 'Native duplicate favorite rejected');
        $payload = $service->getDashboardFavoriteExportPayload();
        $assert($service->getImportPayloadFavorites($payload)['success'], 'Native export accepted for import');
        $assert($service->removeCurrentUserDashboardPageFavorite($page->getCollectionID())['success'], 'Native remove existing dashboard page');
        $import = $service->importDashboardFavorites($payload['favorites'], function ($page) { return (new Permissions($page))->canViewPage(); });
        $assert($import['imported'] === 1 && $import['skippedExisting'] === 2, 'Native import restores page without duplicates');
        $service = $asUser($state['users']['restricted']['id']);
        $private = Page::getByPath('/dashboard/system/basics/name');
        $assert(!(new Permissions($private))->canViewPage(), 'Restricted fixture cannot view private dashboard page');
        (new User())->saveConfig('DASHBOARD_FAVORITES', json_encode($service->normalizeItems([
            ['pageID' => $private->getCollectionID(), 'url' => (string) URL::to('/dashboard/system/basics/name')],
            ['url' => (string) URL::to('/dashboard/system/basics/name')],
        ])));
        $assert($service->getToolbarFavoriteLinks() === [], 'Native permissions exclude ID and URL-only private favorites');
        $assert(count(array_filter($service->getManagerFavoriteLinks(), function ($row) { return $row['isUnavailable'] && $row['url'] === ''; })) === 2,
            'Native permissions disable both private manager links');
        $assert($digest() === $state['beforeDigest'], 'Native tests preserve existing users');
        echo json_encode(['checks' => $checks], JSON_PRETTY_PRINT), "\n";
    } elseif ($action === 'automatic-seed') {
        foreach (['a' => 1, 'b' => 0] as $suffix => $enabled) {
            $service = $seed($state['users'][$suffix]['id']);
            (new User())->saveConfig('DASHBOARD_FAVORITES_MANAGER_TOOLBAR_ENABLED', $enabled);
        }
        $assert($digest() === $state['beforeDigest'], 'Automatic cleanup setup preserves existing users');
        echo json_encode(['checks' => $checks]), "\n";
    } elseif ($action === 'automatic-assert') {
        foreach (['a' => 1, 'b' => 0] as $suffix => $enabled) {
            $service = $asUser($state['users'][$suffix]['id']);
            $rows = $service->getManagerFavoriteLinks();
            $assert(count($rows) === 2, 'HTTP page load removed all three orphans for user ' . $suffix);
            $assert(count(array_filter($rows, function ($row) { return $row['isUnavailable']; })) === 0, 'Valid favorites retained for user ' . $suffix);
            $assert((int) (new User())->config('DASHBOARD_FAVORITES_MANAGER_TOOLBAR_ENABLED') === $enabled, 'Toolbar setting retained for user ' . $suffix);
            $stored = (new User())->config('DASHBOARD_FAVORITES');
            $assert($service->removeCurrentUserOrphanedFavorites() === 0, 'Clean list needs no further cleanup for user ' . $suffix);
            $assert((new User())->config('DASHBOARD_FAVORITES') === $stored, 'Clean list is unchanged for user ' . $suffix);
        }
        $service = $asUser($state['users']['restricted']['id']);
        $private = Page::getByPath('/dashboard/system/basics/name');
        $assert(!(new Permissions($private))->canViewPage(), 'Restricted user lacks page permission');
        $service->saveCurrentUserDashboardFavoriteItems([
            ['pageID' => $private->getCollectionID(), 'name' => 'Private ID'],
            ['url' => (string) URL::to('/dashboard/system/basics/name'), 'name' => 'Private URL'],
        ]);
        $stored = (new User())->config('DASHBOARD_FAVORITES');
        $assert($service->removeCurrentUserOrphanedFavorites() === 0, 'Denied pages are not deleted');
        $assert((new User())->config('DASHBOARD_FAVORITES') === $stored, 'Denied favorites remain stored');
        $assert($service->getToolbarFavoriteLinks() === [], 'Denied favorites remain hidden');
        $assert($digest() === $state['beforeDigest'], 'Automatic cleanup tests preserve existing users');
        echo json_encode(['checks' => $checks], JSON_PRETTY_PRINT), "\n";
    } elseif ($action === 'reset-ui') {
        $seed($state['users']['a']['id']);
        echo "UI fixture favorites reset.\n";
    } elseif ($action === 'cleanup') {
        foreach ($state['users'] ?? [] as $record) {
            $info = UserInfo::getByID($record['id']);
            if ($info) {
                $assert($info->getUserName() === $record['name'] && $record['id'] !== 1, 'Cleanup targets only disposable user');
                $info->delete();
                $db->executeStatement('delete from ConfigStore where uID = ?', [$record['id']]);
            }
        }
        $assert($digest() === $state['beforeDigest'], 'Cleanup restores original favorites and settings digest');
        echo json_encode(['checks' => $checks, 'cleaned' => true], JSON_PRETTY_PRINT), "\n";
    } else {
        throw new RuntimeException('Unknown gate action');
    }
} catch (Throwable $e) {
    file_put_contents($dir . '/failed-' . $action . '.json', json_encode(['action' => $action, 'checks' => $checks, 'error' => $e->getMessage()], JSON_PRETTY_PRINT));
    throw $e;
}
return 0;
