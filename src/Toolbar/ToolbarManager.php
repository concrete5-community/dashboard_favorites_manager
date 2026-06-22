<?php

namespace Concrete\Package\DashboardFavoritesManager\Toolbar;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\Asset\AssetList;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\View\PageView;
use Concrete\Core\Support\Facade\Events;
use Concrete\Core\User\User;
use Concrete\Core\View\View;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoritesService;

class ToolbarManager
{
    private $app;
    private $settings;
    private $favoritesService;
    private $managerPath;

    public function __construct(
        $app,
        ToolbarSettings $settings,
        DashboardFavoritesService $favoritesService,
        $managerPath
    ) {
        $this->app = $app;
        $this->settings = $settings;
        $this->favoritesService = $favoritesService;
        $this->managerPath = $managerPath;
    }

    public function start($package)
    {
        $this->registerAssets($package);

        $favoritesEnabled = $this->settings->isFavoritesEnabled();
        $concreteVersionEnabled = $this->settings->isConcreteVersionEnabled();
        if (!$favoritesEnabled && !$concreteVersionEnabled) {
            return;
        }

        Events::addListener('on_before_render', function ($event) use ($favoritesEnabled, $concreteVersionEnabled) {
            $view = method_exists($event, 'getArgument') ? $event->getArgument('view') : View::getInstance();
            if (!$view instanceof PageView) {
                return;
            }
            if (!$this->shouldRenderToolbarFavorites($view)) {
                return;
            }

            $toolbarConfig = [
                'enabled' => true,
                'favoritesEnabled' => $favoritesEnabled,
                'favorites' => $favoritesEnabled ? $this->favoritesService->getToolbarFavoriteLinks() : [],
                'emptyText' => t('No dashboard favorites found.'),
                'title' => t('Dashboard favorites'),
                'dismissText' => t('Dismiss message'),
            ];
            if ($concreteVersionEnabled) {
                $toolbarConfig['concreteVersion'] = [
                    'name' => 'ConcreteCMS',
                    'version' => $this->getConcreteCmsVersion(),
                ];
            }
            if ($favoritesEnabled && $this->settings->isSearchEnabled()) {
                $toolbarConfig['search'] = [
                    'url' => (string) \URL::to($this->managerPath, 'search_dashboard_pages'),
                    'toggleUrl' => (string) \URL::to($this->managerPath, 'toggle_dashboard_page'),
                    'token' => $this->app->make('token')->generate('dashboard_favorites_manager_toggle_dashboard_page'),
                    'placeholder' => t('Search dashboard pages'),
                    'emptyText' => t('No dashboard pages found.'),
                    'errorText' => t('Unable to search dashboard pages.'),
                    'clearText' => t('Clear search'),
                    'addText' => t('Add to favorites'),
                    'removeText' => t('Remove from favorites'),
                    'openText' => t('Open page'),
                ];
            }
            if ($favoritesEnabled && $this->settings->isClearCacheEnabled() && $this->canUseToolbarClearCache()) {
                $toolbarConfig['clearCache'] = [
                    'url' => (string) \URL::to($this->managerPath, 'toolbar_clear_cache'),
                    'token' => $this->app->make('token')->generate('clear_cache'),
                    'label' => t('Clear cache now!'),
                    'errorText' => t('Unable to clear cache.'),
                ];
            }
            if ($favoritesEnabled && $this->settings->isLogoutEnabled()) {
                $toolbarConfig['logout'] = [
                    'url' => (string) \URL::to('/login', 'do_logout', $this->app->make('token')->generate('do_logout')),
                    'label' => t('Log out'),
                ];
            }

            $view->addFooterItem('<script>window.DashboardFavoritesManagerToolbar=' . json_encode($toolbarConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>');
            $view->requireAsset('dashboard-favorites-manager/toolbar');
        });
    }

    private function registerAssets($package)
    {
        $assetList = AssetList::getInstance();
        $assetList->register('css', 'dashboard-favorites-manager/dashboard', 'assets/css/dashboard/welcome/favorites_manager.css', [], $package);
        $assetList->register('javascript', 'dashboard-favorites-manager/dashboard', 'assets/js/dashboard/welcome/favorites_manager.js', [], $package);
        $assetList->register('css', 'dashboard-favorites-manager/toolbar', 'assets/css/toolbar_favorites.css', [], $package);
        $assetList->register('javascript', 'dashboard-favorites-manager/toolbar', 'assets/js/toolbar_favorites.js', [], $package);
        $assetList->registerGroup('dashboard-favorites-manager/dashboard', [
            ['css', 'dashboard-favorites-manager/dashboard'],
            ['javascript', 'dashboard-favorites-manager/dashboard'],
        ]);
        $assetList->registerGroup('dashboard-favorites-manager/toolbar', [
            ['css', 'dashboard-favorites-manager/toolbar'],
            ['javascript', 'dashboard-favorites-manager/toolbar'],
        ]);
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

    private function getConcreteCmsVersion()
    {
        return defined('APP_VERSION') ? (string) APP_VERSION : '';
    }
}
