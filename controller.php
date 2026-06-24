<?php

declare(strict_types=1);

namespace Concrete\Package\DashboardFavoritesManager;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\Package\Package;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\Single as SinglePage;
use Concrete\Core\User\User;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoriteNormalizer;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoritesRepairer;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoritesService;
use Concrete\Package\DashboardFavoritesManager\Toolbar\ToolbarManager;
use Concrete\Package\DashboardFavoritesManager\Toolbar\ToolbarSettings;

class Controller extends Package
{
    private const MANAGER_PATH = '/dashboard/welcome/favorites_manager';

    private const DASHBOARD_FAVORITES_REPAIR_VERSION = '2';

    private const CONFIG_DASHBOARD_FAVORITES_REPAIR_VERSION = 'repair.dashboard_favorites.version';

    private const SESSION_FAVORITES_CACHE_USER_ID = 'dashboard_favorites_manager.favorites_cache_user_id';

    protected $pkgHandle = 'dashboard_favorites_manager';

    protected $appVersionRequired = '9.2.0';

    protected $pkgVersion = '1.2.0-rc5';

    public function getPackageName()
    {
        return t('Dashboard Favorites Manager');
    }

    public function getPackageDescription()
    {
        return t('Manage, reorder, and remove Concrete CMS dashboard favorites. - Author: DigitMaster');
    }

    public function getPackageAutoloaderRegistries()
    {
        return [
            'src' => 'Concrete\\Package\\DashboardFavoritesManager',
        ];
    }

    public function on_start()
    {
        $this->clearFavoritesCacheWhenUserChanges();
        $this->getDashboardFavoritesRepairer()->repairOnce();
        $this->getToolbarManager()->start($this);
    }

    public function isToolbarFavoritesEnabled()
    {
        return $this->getToolbarSettings()->isFavoritesEnabled();
    }

    public function setToolbarFavoritesEnabled($enabled)
    {
        $this->getToolbarSettings()->setFavoritesEnabled($enabled);
    }

    public function isToolbarSearchEnabled()
    {
        return $this->getToolbarSettings()->isSearchEnabled();
    }

    public function setToolbarSearchEnabled($enabled)
    {
        $this->getToolbarSettings()->setSearchEnabled($enabled);
    }

    public function isToolbarClearCacheEnabled()
    {
        return $this->getToolbarSettings()->isClearCacheEnabled();
    }

    public function setToolbarClearCacheEnabled($enabled)
    {
        $this->getToolbarSettings()->setClearCacheEnabled($enabled);
    }

    public function isToolbarLogoutEnabled()
    {
        return $this->getToolbarSettings()->isLogoutEnabled();
    }

    public function setToolbarLogoutEnabled($enabled)
    {
        $this->getToolbarSettings()->setLogoutEnabled($enabled);
    }

    public function isToolbarConcreteVersionEnabled()
    {
        return $this->getToolbarSettings()->isConcreteVersionEnabled();
    }

    public function setToolbarConcreteVersionEnabled($enabled)
    {
        $this->getToolbarSettings()->setConcreteVersionEnabled($enabled);
    }

    public function install()
    {
        $pkg = parent::install();
        $page = $this->installSinglePages($pkg);
        $this->configureCurrentUserAfterInstall($page);
        $this->getDashboardFavoritesRepairer()->markDone();
    }

    public function upgrade()
    {
        $this->getDashboardFavoritesRepairer()->migrateLegacyToolbarSettingsToCurrentUser();
        parent::upgrade();
        $this->installSinglePages($this);
        $this->getDashboardFavoritesRepairer()->repair();
        $this->getDashboardFavoritesRepairer()->markDone();
    }

    public function uninstall()
    {
        $page = Page::getByPath(self::MANAGER_PATH);
        $this->getDashboardFavoritesService()->removeDashboardFavoritesManagerFavoriteFromAllUsers($page);
        $this->getToolbarSettings()->clearAllUserSettings($this->app);
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

        $this->getToolbarSettings()->enableDefaultsForUser($user);
        $this->getDashboardFavoritesService()->addCurrentUserDashboardFavorite($user, $page);
    }

    private function clearFavoritesCacheWhenUserChanges()
    {
        try {
            $user = new User();
            if (!$user->isRegistered()) {
                return;
            }

            $session = $this->app->make('session');
            $userID = (int) $user->getUserID();
            if ((int) $session->get(self::SESSION_FAVORITES_CACHE_USER_ID, -1) !== $userID) {
                $this->getDashboardFavoritesService()->clearFavoritesCache();
                $session->set(self::SESSION_FAVORITES_CACHE_USER_ID, $userID);
            }
        } catch (\Throwable $e) {
            // Favorites cache protection is best-effort during package startup.
        }
    }

    private function uninstallSinglePages()
    {
        $page = Page::getByPath(self::MANAGER_PATH);
        if (is_object($page) && !$page->isError()) {
            $page->delete();
        }
    }

    private function getToolbarManager()
    {
        return new ToolbarManager(
            $this->app,
            $this->getToolbarSettings(),
            $this->getDashboardFavoritesService(),
            self::MANAGER_PATH
        );
    }

    private function getToolbarSettings()
    {
        return new ToolbarSettings();
    }

    private function getDashboardFavoritesRepairer()
    {
        return new DashboardFavoritesRepairer(
            $this->app,
            $this->getConfig(),
            $this->getDashboardFavoritesService(),
            $this->getToolbarSettings(),
            self::DASHBOARD_FAVORITES_REPAIR_VERSION,
            self::CONFIG_DASHBOARD_FAVORITES_REPAIR_VERSION
        );
    }

    private function getDashboardFavoritesService()
    {
        return new DashboardFavoritesService(
            $this->app,
            self::MANAGER_PATH,
            $this->getDashboardFavoriteNormalizer()
        );
    }

    private function getDashboardFavoriteNormalizer()
    {
        return new DashboardFavoriteNormalizer();
    }
}
