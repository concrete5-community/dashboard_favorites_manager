<?php

namespace Concrete\Package\DashboardFavoritesManager;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\Application\UserInterface\Dashboard\Navigation\FavoritesNavigationCache;
use Concrete\Core\Application\UserInterface\Dashboard\Navigation\FavoritesNavigationFactory;
use Concrete\Core\Asset\AssetList;
use Concrete\Core\Page\View\PageView;
use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Support\Facade\Events;
use Concrete\Core\User\User;
use Concrete\Core\View\View;

class Controller extends Package
{
    private const USER_CONFIG_TOOLBAR_ENABLED = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_ENABLED';
    private const USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_CLEAR_CACHE_ENABLED';
    private const USER_CONFIG_TOOLBAR_LOGOUT_ENABLED = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_LOGOUT_ENABLED';
    private const MANAGER_PATH = '/dashboard/welcome/favorites_manager';
    private const DASHBOARD_FAVORITES_REPAIR_VERSION = '2';
    private const CONFIG_DASHBOARD_FAVORITES_REPAIR_VERSION = 'repair.dashboard_favorites.version';

    protected $pkgHandle = 'dashboard_favorites_manager';
    protected $appVersionRequired = '9.2.0';
    protected $pkgVersion = '1.0.9';

    public function getPackageName()
    {
        return t('Dashboard Favorites Manager');
    }

    public function getPackageDescription()
    {
        return t('Manage, reorder, and remove Concrete CMS dashboard favorites. - Author: DigitMaster');
    }

    public function on_start()
    {
        $this->repairDashboardFavoritesOnce();

        $assetList = AssetList::getInstance();
        $assetList->register('css', 'dashboard-favorites-manager/dashboard', 'assets/css/dashboard/welcome/favorites_manager.css', [], $this);
        $assetList->register('javascript', 'dashboard-favorites-manager/dashboard', 'assets/js/dashboard/welcome/favorites_manager.js', [], $this);
        $assetList->register('css', 'dashboard-favorites-manager/toolbar', 'assets/css/toolbar_favorites.css', [], $this);
        $assetList->register('javascript', 'dashboard-favorites-manager/toolbar', 'assets/js/toolbar_favorites.js', [], $this);
        $assetList->registerGroup('dashboard-favorites-manager/dashboard', [
            ['css', 'dashboard-favorites-manager/dashboard'],
            ['javascript', 'dashboard-favorites-manager/dashboard'],
        ]);
        $assetList->registerGroup('dashboard-favorites-manager/toolbar', [
            ['css', 'dashboard-favorites-manager/toolbar'],
            ['javascript', 'dashboard-favorites-manager/toolbar'],
        ]);

        if ($this->isToolbarFavoritesEnabled()) {
            Events::addListener('on_before_render', function ($event) {
                $view = method_exists($event, 'getArgument') ? $event->getArgument('view') : View::getInstance();
                if (!$view instanceof PageView) {
                    return;
                }
                if (!$this->shouldRenderToolbarFavorites($view)) {
                    return;
                }

                $toolbarConfig = [
                    'enabled' => true,
                    'favorites' => $this->getToolbarFavoriteLinks(),
                    'emptyText' => t('No dashboard favorites found.'),
                    'title' => t('Dashboard favorites'),
                ];
                if ($this->isToolbarClearCacheEnabled() && $this->canUseToolbarClearCache()) {
                    $toolbarConfig['clearCache'] = [
                        'url' => (string) \URL::to(self::MANAGER_PATH, 'toolbar_clear_cache'),
                        'token' => $this->app->make('token')->generate('clear_cache'),
                        'label' => t('Clear cache now!'),
                    ];
                }
                if ($this->isToolbarLogoutEnabled()) {
                    $toolbarConfig['logout'] = [
                        'url' => (string) \URL::to('/login', 'do_logout', $this->app->make('token')->generate('do_logout')),
                        'label' => t('Log out'),
                    ];
                }

                $view->addFooterItem('<script>window.DashboardFavoritesManagerToolbar=' . json_encode($toolbarConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>');
                $view->requireAsset('dashboard-favorites-manager/toolbar');
            });
        }
    }

    private function shouldRenderToolbarFavorites(PageView $view)
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return false;
        }

        $page = $view->getCollectionObject();
        if (!$page instanceof Page || $page->isError()) {
            return false;
        }

        return true;
    }

    private function canUseToolbarClearCache()
    {
        $page = Page::getByPath('/dashboard/system/optimization/clearcache');
        if (!$page instanceof Page || $page->isError()) {
            return false;
        }

        return $this->canViewPage($page);
    }

    private function canViewPage(Page $page)
    {
        try {
            $permissions = new \Permissions($page);

            return (bool) $permissions->canViewPage();
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function isToolbarFavoritesEnabled()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return false;
        }

        return (int) $user->config(self::USER_CONFIG_TOOLBAR_ENABLED) === 1;
    }

    public function setToolbarFavoritesEnabled($enabled)
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return;
        }

        $user->saveConfig(self::USER_CONFIG_TOOLBAR_ENABLED, $enabled ? 1 : 0);
    }

    public function isToolbarClearCacheEnabled()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return false;
        }

        $value = $user->config(self::USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED);

        return $value === null ? true : (int) $value === 1;
    }

    public function setToolbarClearCacheEnabled($enabled)
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return;
        }

        $user->saveConfig(self::USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED, $enabled ? 1 : 0);
    }

    public function isToolbarLogoutEnabled()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return false;
        }

        $value = $user->config(self::USER_CONFIG_TOOLBAR_LOGOUT_ENABLED);

        return $value === null ? true : (int) $value === 1;
    }

    public function setToolbarLogoutEnabled($enabled)
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return;
        }

        $user->saveConfig(self::USER_CONFIG_TOOLBAR_LOGOUT_ENABLED, $enabled ? 1 : 0);
    }

    public function install()
    {
        $pkg = parent::install();
        $page = $this->installSinglePages($pkg);
        $this->configureCurrentUserAfterInstall($page);
        $this->markDashboardFavoritesRepairDone();
    }

    public function upgrade()
    {
        $this->migrateLegacyToolbarSettingsToCurrentUser();
        parent::upgrade();
        $this->installSinglePages($this);
        $this->repairDashboardFavorites();
        $this->markDashboardFavoritesRepairDone();
    }

    private function migrateLegacyToolbarSettingsToCurrentUser()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return;
        }

        if ($user->config(self::USER_CONFIG_TOOLBAR_ENABLED) !== null) {
            return;
        }

        $legacyToolbarEnabled = $this->getConfig()->get('toolbar.enabled');
        if ((int) $legacyToolbarEnabled !== 1) {
            return;
        }

        $user->saveConfig(self::USER_CONFIG_TOOLBAR_ENABLED, 1);

        $legacyClearCacheEnabled = $this->getConfig()->get('toolbar.clear_cache.enabled');
        $user->saveConfig(
            self::USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED,
            $legacyClearCacheEnabled === null || (int) $legacyClearCacheEnabled === 1 ? 1 : 0
        );
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_LOGOUT_ENABLED, 1);
    }

    public function uninstall()
    {
        $this->uninstallSinglePages();
        parent::uninstall();
    }

    private function installSinglePages($pkg)
    {
        $path = self::MANAGER_PATH;
        $page = Page::getByPath($path);

        if (!is_object($page) || $page->isError()) {
            $page = SinglePage::add($path, $pkg);
        }

        if (is_object($page) && !$page->isError()) {
            $page->update([
                'cName' => t('Dashboard Favorites Manager'),
                'cDescription' => t('Manage, reorder, and remove dashboard favorites.'),
            ]);
        }

        return $page;
    }

    private function configureCurrentUserAfterInstall($page)
    {
        $user = new User();
        if (!$user->isRegistered() || !$page instanceof Page || $page->isError()) {
            return;
        }

        $user->saveConfig(self::USER_CONFIG_TOOLBAR_ENABLED, 1);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED, 1);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_LOGOUT_ENABLED, 1);
        $this->addCurrentUserDashboardFavorite($user, $page);
    }

    private function addCurrentUserDashboardFavorite(User $user, Page $page)
    {
        $items = [];
        $favorites = $user->config('DASHBOARD_FAVORITES');
        if ($favorites) {
            $decoded = json_decode((string) $favorites, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $items = $decoded;
            }
        }

        if (empty($items)) {
            try {
                $items = json_decode(json_encode($this->app->make(FavoritesNavigationFactory::class)->createNavigation()), true) ?: [];
            } catch (\Throwable $e) {
                $items = [];
            }
        }

        $items = $this->mergeDashboardFavoritesManagerFavorite(
            $items,
            $this->getDashboardFavoritesManagerFavoriteItem($page)
        );

        $user->saveConfig('DASHBOARD_FAVORITES', json_encode($this->normalizeDashboardFavoriteItems($items)));
        try {
            $this->app->make(FavoritesNavigationCache::class)->clear();
        } catch (\Throwable $e) {
            // Cache clearing is best-effort here. The favorite has already been saved,
            // so a cache failure should not make installation/configuration fail.
        }
    }

    private function getDashboardFavoritesManagerFavoriteItem(Page $page)
    {
        return [
            'name' => (string) $page->getCollectionName(),
            'url' => $this->getDashboardFavoriteUrlFromPath(self::MANAGER_PATH),
            'pageID' => (int) $page->getCollectionID(),
            'isActive' => false,
            'children' => [],
        ];
    }

    private function mergeDashboardFavoritesManagerFavorite(array $items, array $favoriteItem)
    {
        $found = false;
        $merged = $this->mergeDashboardFavoritesManagerFavoriteItems($items, $favoriteItem, $found);
        if (!$found) {
            $merged[] = $favoriteItem;
        }

        return $merged;
    }

    private function mergeDashboardFavoritesManagerFavoriteItems(array $items, array $favoriteItem, &$found)
    {
        $merged = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if ($this->isDashboardFavoritesManagerFavoriteItem($item, $favoriteItem)) {
                if (!$found) {
                    $merged[] = $favoriteItem;
                    $found = true;
                }
                continue;
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $item['children'] = $this->mergeDashboardFavoritesManagerFavoriteItems($item['children'], $favoriteItem, $found);
            }

            $merged[] = $item;
        }

        return $merged;
    }

    private function isDashboardFavoritesManagerFavoriteItem(array $item, array $favoriteItem)
    {
        if ($this->normalizeDashboardFavoriteUrlPath((string) ($item['url'] ?? '')) === self::MANAGER_PATH) {
            return true;
        }

        if ((int) ($item['pageID'] ?? 0) === (int) ($favoriteItem['pageID'] ?? 0)) {
            return true;
        }

        return false;
    }

    private function normalizeDashboardFavoriteUrlPath($url)
    {
        $path = (string) (parse_url((string) $url, PHP_URL_PATH) ?: '');
        if ($path === '') {
            return '';
        }

        $path = $this->stripApplicationBasePath($path);

        if (strpos($path, '/index.php/') === 0) {
            $path = substr($path, strlen('/index.php'));
        } elseif ($path === '/index.php') {
            $path = '/';
        }

        return $path;
    }

    private function stripApplicationBasePath($path)
    {
        $basePath = defined('DIR_REL') ? (string) DIR_REL : '';
        if ($basePath === '' || $basePath === '/') {
            return $path;
        }

        $basePath = '/' . trim($basePath, '/');
        if ($path === $basePath) {
            return '/';
        }

        if (strpos($path, $basePath . '/') === 0) {
            return substr($path, strlen($basePath));
        }

        return $path;
    }

    private function uninstallSinglePages()
    {
        $page = Page::getByPath(self::MANAGER_PATH);
        if (is_object($page) && !$page->isError()) {
            $page->delete();
        }
    }

    private function repairDashboardFavorites()
    {
        $db = Application::getFacadeApplication()->make('database')->connection();
        try {
            $rows = $db->fetchAllAssociative(
                'select cfValue, uID from ConfigStore where cfKey = ?',
                ['DASHBOARD_FAVORITES']
            );
        } catch (\Throwable $e) {
            return;
        }

        $repaired = false;
        foreach ($rows as $row) {
            $value = (string) ($row['cfValue'] ?? '');
            $items = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($items)) {
                continue;
            }

            $normalizedItems = $this->normalizeDashboardFavoriteItems($items);
            $page = Page::getByPath(self::MANAGER_PATH);
            if ($page instanceof Page && !$page->isError()) {
                $found = false;
                $normalizedItems = $this->mergeDashboardFavoritesManagerFavoriteItems(
                    $normalizedItems,
                    $this->getDashboardFavoritesManagerFavoriteItem($page),
                    $found
                );
            }

            $normalized = json_encode($normalizedItems);
            if ($normalized === $value) {
                continue;
            }

            $db->executeStatement(
                'update ConfigStore set cfValue = ? where cfKey = ? and uID = ?',
                [$normalized, 'DASHBOARD_FAVORITES', (int) $row['uID']]
            );
            $repaired = true;
        }

        if ($repaired) {
            try {
                Application::getFacadeApplication()->make(FavoritesNavigationCache::class)->clear();
            } catch (\Throwable $e) {
                // Cache clearing is best-effort after repairing stored favorites.
                // If this fails, the repaired data should still remain saved.
            }
        }
    }

    private function repairDashboardFavoritesOnce()
    {
        if ((string) $this->getConfig()->get(self::CONFIG_DASHBOARD_FAVORITES_REPAIR_VERSION) === self::DASHBOARD_FAVORITES_REPAIR_VERSION) {
            return;
        }

        $this->repairDashboardFavorites();
        $this->markDashboardFavoritesRepairDone();
    }

    private function markDashboardFavoritesRepairDone()
    {
        $this->getConfig()->save(
            self::CONFIG_DASHBOARD_FAVORITES_REPAIR_VERSION,
            self::DASHBOARD_FAVORITES_REPAIR_VERSION
        );
    }

    private function getToolbarFavoriteLinks()
    {
        $links = [];
        $seenUrls = [];
        foreach ($this->flattenDashboardFavoriteItems($this->getCurrentUserDashboardFavoriteItems()) as $item) {
            $pageID = (int) ($item['pageID'] ?? 0);
            $name = trim((string) ($item['name'] ?? ''));
            $path = $this->sanitizeFavoriteUrl((string) ($item['url'] ?? ''));
            $page = null;

            if ($pageID > 0) {
                $page = Page::getByID($pageID);
                if (!$this->isDashboardPage($page) || !$this->canViewPage($page)) {
                    continue;
                }

                if ($path === null) {
                    $path = $this->getPagePath($page);
                }

                if ($name === '') {
                    $name = (string) $page->getCollectionName();
                }
            }

            if ($path === null || $path === '' || isset($seenUrls[$path])) {
                continue;
            }

            $seenUrls[$path] = true;
            $links[] = [
                'name' => $name !== '' ? $name : $path,
                'url' => $this->getDashboardFavoriteUrlFromPath($path),
            ];
        }

        return $links;
    }

    private function getCurrentUserDashboardFavoriteItems()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return [];
        }

        $favorites = $user->config('DASHBOARD_FAVORITES');
        if ($favorites !== null && $favorites !== '') {
            $items = json_decode((string) $favorites, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($items)) {
                return $items;
            }
        }

        try {
            return json_decode(json_encode($this->app->make(FavoritesNavigationFactory::class)->createNavigation()), true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function flattenDashboardFavoriteItems(array $items)
    {
        $flattened = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $flattened[] = $item;
            if (!empty($item['children']) && is_array($item['children'])) {
                $flattened = array_merge($flattened, $this->flattenDashboardFavoriteItems($item['children']));
            }
        }

        return $flattened;
    }

    private function isDashboardPage($page)
    {
        if (!$page instanceof Page || $page->isError()) {
            return false;
        }

        $path = $this->getPagePath($page);

        return $path === '/dashboard' || strpos($path, '/dashboard/') === 0;
    }

    private function getPagePath(Page $page)
    {
        return method_exists($page, 'getCollectionPath') ? (string) $page->getCollectionPath() : '';
    }

    private function sanitizeFavoriteUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return null;
        }

        if (preg_match('/^(?:javascript|data|vbscript):/i', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $path = $this->normalizeDashboardFavoriteUrlPath($url);
        if ($path !== '/dashboard' && strpos($path, '/dashboard/') !== 0) {
            return null;
        }

        return $path;
    }

    private function getDashboardFavoriteUrlFromPath($path)
    {
        return (string) \URL::to((string) $path);
    }

    private function normalizeDashboardFavoriteItems(array $items)
    {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $item['pageID'] = (int) ($item['pageID'] ?? 0);
            $path = $this->sanitizeFavoriteUrl($item['url'] ?? '');
            if ($path === null && $item['pageID'] > 0) {
                $page = Page::getByID($item['pageID']);
                if ($this->isDashboardPage($page)) {
                    $path = $this->getPagePath($page);
                }
            }
            $item['url'] = $path === null ? '' : $this->getDashboardFavoriteUrlFromPath($path);
            $item['name'] = (string) ($item['name'] ?? '');
            $item['isActive'] = (bool) ($item['isActive'] ?? false);

            if (!empty($item['children']) && is_array($item['children'])) {
                $item['children'] = $this->normalizeDashboardFavoriteItems($item['children']);
            } else {
                $item['children'] = [];
            }

            $normalized[] = $item;
        }

        return $normalized;
    }
}
