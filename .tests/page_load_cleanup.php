<?php

declare(strict_types=1);

namespace {
    require __DIR__ . '/bootstrap.php';
}

namespace Concrete\Core\Package {
    class Package
    {
        protected $app;
        public function __construct($app) { $this->app = $app; }
        public function getConfig() { return $this->app->make('config'); }
    }
}

namespace Concrete\Core\Support\Facade {
    class Events
    {
        public static $listeners = [];
        public static function addListener($name, $listener) { self::$listeners[$name][] = $listener; }
        public static function dispatch($name, $event)
        {
            foreach (self::$listeners[$name] ?? [] as $listener) {
                $listener($event);
            }
        }
    }
}

namespace Concrete\Core\Asset {
    class AssetList
    {
        public static function getInstance() { return new self(); }
        public function register(...$args) {}
        public function registerGroup(...$args) {}
    }
}

namespace Concrete\Core\Page\View {
    class PageView
    {
        public $footer = [];
        public $assets = [];
        private $page;
        public function __construct($page) { $this->page = $page; }
        public function getCollectionObject() { return $this->page; }
        public function addFooterItem($item) { $this->footer[] = $item; }
        public function requireAsset($asset) { $this->assets[] = $asset; }
    }
}

namespace {
    use Concrete\Core\Page\Page;
    use Concrete\Core\Page\View\PageView;
    use Concrete\Core\Support\Facade\Events;
    use Concrete\Core\User\User;
    use Concrete\Package\DashboardFavoritesManager\Controller;

    require __DIR__ . '/../src/Favorites/DashboardFavoritesRepairer.php';
    require __DIR__ . '/../src/Search/DashboardPageSearch.php';
    require __DIR__ . '/../src/Message/OverlayMessageQueue.php';
    require __DIR__ . '/../src/Toolbar/ToolbarSettings.php';
    require __DIR__ . '/../src/Toolbar/ToolbarManager.php';
    require __DIR__ . '/../controller.php';

    foreach ([false, true] as $toolbarEnabled) {
        Events::$listeners = [];
        User::$writes = 0;
        User::$settings = [
            'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_ENABLED' => (int) $toolbarEnabled,
            'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_SEARCH_ENABLED' => 0,
            'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_CLEAR_CACHE_ENABLED' => 0,
            'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_LOGOUT_ENABLED' => 0,
        ];
        Page::$pages = [1 => new Page(1, '/dashboard/welcome'), 2 => new Page(2, '/dashboard/private', false)];
        Page::$idReads = Page::$pathReads = 0;
        User::$items = [
            ['pageID' => 99, 'name' => 'Deleted ID'],
            ['url' => '/dashboard/deleted', 'name' => 'Deleted URL'],
            ['pageID' => 1, 'name' => 'Welcome'],
            ['pageID' => 2, 'name' => 'Private'],
        ];
        $app = new class {
            public $clears = 0;
            public function make($name)
            {
                if ($name === 'config') {
                    return new class {
                        public function get($key) { return $key === 'repair.dashboard_favorites.version' ? '2' : false; }
                    };
                }
                if ($name === 'session') {
                    return new class {
                        public function get($key, $default = null)
                        {
                            return $key === 'dashboard_favorites_manager.favorites_cache_user_id' ? 1 : $default;
                        }
                        public function remove($key) {}
                    };
                }
                if ($name === 'Concrete\Core\Application\UserInterface\Dashboard\Navigation\FavoritesNavigationCache') {
                    return new class($this) {
                        private $app;
                        public function __construct($app) { $this->app = $app; }
                        public function clear() { $this->app->clears++; }
                    };
                }
                throw new \RuntimeException('Unexpected service: ' . $name);
            }
        };
        $controller = new Controller($app);
        $controller->on_start();
        check(User::$writes === 0, 'Startup must wait for the page view.');
        $event = new class {
            public $page;
            public function __construct() { $this->page = new Page(10, '/ordinary-page'); }
            public function getPageObject() { return $this->page; }
        };
        if (!$toolbarEnabled) {
            Events::dispatch('on_page_view', $event);
            check(User::$writes === 0 && Page::$idReads === 0, 'Disabled toolbar must not scan favorites outside the dashboard.');
        }
        $event->page = new Page(10, '/dashboard/welcome');
        Events::dispatch('on_page_view', $event);
        // Concrete fires this event before DashboardPageController::on_start().
        check(array_column(User::$items, 'pageID') === [1, 2], 'Dashboard still receives orphans with toolbar ' . (int) $toolbarEnabled);
        check(User::$writes === 1 && $app->clears === 1, 'Cleanup must save and invalidate navigation once.');
        $reads = [Page::$idReads, Page::$pathReads];
        $view = new PageView($event->page);
        $renderEvent = new class($view) {
            private $view;
            public function __construct($view) { $this->view = $view; }
            public function getArgument($key) { return $this->view; }
        };
        Events::dispatch('on_before_render', $renderEvent);
        if ($toolbarEnabled) {
            check($view->assets === ['dashboard-favorites-manager/toolbar'], 'Enabled star menu stopped loading.');
            preg_match('/window\.DashboardFavoritesManagerToolbar=(.*);<\/script>/', $view->footer[0], $match);
            $config = json_decode($match[1], true);
            check(array_column($config['favorites'], 'name') === ['Welcome'], 'Star menu did not receive the cleaned, permission-filtered list.');
            check([Page::$idReads, Page::$pathReads] === $reads, 'Star menu repeated cleanup page lookups.');
        } else {
            check($view->footer === [] && $view->assets === [], 'Disabled star menu was reenabled.');
        }
        Events::dispatch('on_page_view', $event);
        check(User::$writes === 1, 'Clean dashboard load wrote favorites again.');
        echo 'PASS dashboard cleanup with star menu ' . ($toolbarEnabled ? 'enabled' : 'disabled') . "\n";
    }
}
