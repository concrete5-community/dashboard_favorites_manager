<?php

declare(strict_types=1);

namespace Concrete\Package\DashboardFavoritesManager\Controller\SinglePage\Dashboard\Welcome;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\Cache\Command\ClearCacheCommand;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\User;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoriteNormalizer;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoritesService;
use Concrete\Package\DashboardFavoritesManager\Message\OverlayMessageQueue;
use Concrete\Package\DashboardFavoritesManager\Toolbar\ToolbarManager;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class FavoritesManager extends DashboardPageController
{
    private const IMPORT_REPORT_SESSION_KEY = 'dashboard_favorites_manager_import_report';

    private const IMPORT_FILE_MAX_BYTES = 65536;

    private $dashboardDirectTargetCache = [];

    private $dashboardParentActionCache = [];

    private $dashboardFavoriteNormalizer;

    private $dashboardFavoritesService;

    public function view()
    {
        $this->requireAsset('dashboard-favorites-manager/dashboard');

        $this->set('favoriteLinks', $this->getDashboardFavoriteLinks());
        $this->set('dashboardPageTree', $this->getDashboardPageTree());
        $packageController = $this->getManagerPackageController();
        $this->set('packageVersion', $packageController->getPackageVersion());
        $this->set('pendingPackageUpdate', $this->getPendingPackageUpdate($packageController));
        $this->set('toolbarFavoritesEnabled', $packageController->isToolbarFavoritesEnabled());
        $this->set('toolbarSearchEnabled', $packageController->isToolbarSearchEnabled());
        $this->set('toolbarClearCacheEnabled', $packageController->isToolbarClearCacheEnabled());
        $this->set('toolbarLogoutEnabled', $packageController->isToolbarLogoutEnabled());
        $this->set('toolbarConcreteVersionEnabled', $packageController->isToolbarConcreteVersionEnabled());
        $this->set('toolbarSearchMaxResults', ToolbarManager::SEARCH_MAX_RESULTS);
        $this->set('canUseToolbarClearCache', $this->canUseToolbarClearCache());
        $this->set('toolbarSettingsToken', $this->app->make('token')->generate('dashboard_favorites_manager_toolbar_settings'));
        $this->set('toggleDashboardPageToken', $this->app->make('token')->generate('dashboard_favorites_manager_toggle_dashboard_page'));
        $this->set('removeFavoritesToken', $this->app->make('token')->generate('dashboard_favorites_manager_remove'));
        $this->set('reorderFavoritesToken', $this->app->make('token')->generate('dashboard_favorites_manager_reorder'));
        $this->set('importExportToken', $this->app->make('token')->generate('dashboard_favorites_manager_import_export'));
        $this->set('importReport', $this->pullImportReport());
        $this->set('overlayMessages', $this->pullOverlayMessages());
    }

    public function save_toolbar_settings()
    {
        if (!$this->app->make('token')->validate('dashboard_favorites_manager_toolbar_settings', $this->request->request->get('ccm_token'))) {
            $this->queueOverlayMessage('error', $this->app->make('token')->getErrorMessage());

            return $this->redirectToManager();
        }

        $toolbarEnabled = (string) $this->request->request->get('toolbar_favorites_enabled') === '1';
        $searchEnabled = $toolbarEnabled && (string) $this->request->request->get('toolbar_search_enabled') === '1';
        $clearCacheEnabled = $toolbarEnabled && $this->canUseToolbarClearCache() && (string) $this->request->request->get('toolbar_clear_cache_enabled') === '1';
        $logoutEnabled = $toolbarEnabled && (string) $this->request->request->get('toolbar_logout_enabled') === '1';
        $concreteVersionEnabled = (string) $this->request->request->get('toolbar_concrete_version_enabled') === '1';
        $this->getManagerPackageController()->setToolbarFavoritesEnabled($toolbarEnabled);
        $this->getManagerPackageController()->setToolbarSearchEnabled($searchEnabled);
        $this->getManagerPackageController()->setToolbarClearCacheEnabled($clearCacheEnabled);
        $this->getManagerPackageController()->setToolbarLogoutEnabled($logoutEnabled);
        $this->getManagerPackageController()->setToolbarConcreteVersionEnabled($concreteVersionEnabled);
        $this->queueOverlayMessage('success', t('Toolbar favorites settings saved.'));

        return $this->redirectToManager();
    }

    public function toolbar_clear_cache()
    {
        if (!$this->app->make('token')->validate('clear_cache', $this->request->request->get('ccm_token'))) {
            return $this->handleToolbarClearCacheResponse(false, $this->app->make('token')->getErrorMessage(), 400);
        }

        if (!$this->canUseToolbarClearCache()) {
            return $this->handleToolbarClearCacheResponse(false, t('You do not have permission to clear the cache.'), 403);
        }

        $command = new ClearCacheCommand();
        if (method_exists($command, 'setLogCacheClear')) {
            $command->setLogCacheClear(true);
        }
        $this->app->executeCommand($command);

        $timestamp = time();
        $config = $this->app->make('config');
        $config->set('concrete.cache.last_cleared', $timestamp);
        $config->save('concrete.cache.last_cleared', $timestamp);
        $this->logToolbarClearCache();

        return $this->handleToolbarClearCacheResponse(true, t('Cached files removed.'));
    }

    public function toggle_dashboard_page()
    {
        if (!$this->app->make('token')->validate('dashboard_favorites_manager_toggle_dashboard_page', $this->request->request->get('ccm_token'))) {
            return $this->handleToggleDashboardPageResponse(false, $this->app->make('token')->getErrorMessage());
        }

        $pageID = (int) $this->request->request->get('page_id');
        $favorite = (string) $this->request->request->get('favorite') === '1';
        if ($pageID <= 0) {
            return $this->handleToggleDashboardPageResponse(false, t('No dashboard page selected.'));
        }

        $result = $favorite ? $this->addDashboardPageFavorite($pageID) : $this->removeDashboardPageFavorite($pageID);

        return $this->handleToggleDashboardPageResponse($result['success'], $result['message'] ?? '', [
            'favorite' => $favorite && $result['success'],
            'favorites' => $this->getDashboardFavoriteLinks(),
            'pageID' => $pageID,
        ]);
    }

    public function search_dashboard_pages()
    {
        if ($this->getCurrentUserID() <= 0) {
            return new JsonResponse([
                'success' => false,
                'message' => t('You must be logged in to search dashboard pages.'),
            ], 403);
        }

        $returnAll = (string) $this->request->query->get('all', '') === '1';
        $query = $this->normalizeSearchText((string) $this->request->query->get('q', ''));
        $orderByParameter = $this->request->query->get('order_by', $this->request->query->get('search_by', 'name'));
        $orderBy = (string) $orderByParameter === 'path' ? 'path' : 'name';
        if (!$returnAll && strlen($query) < 2) {
            return new JsonResponse([
                'success' => true,
                'pages' => [],
            ]);
        }

        $pages = [];
        foreach ($this->getDashboardPageTree() as $page) {
            if (
                !$returnAll
                && !str_contains($this->normalizeSearchText((string) $page['name']), $query)
                && !str_contains($this->normalizeSearchText((string) $page['path']), $query)
            ) {
                continue;
            }

            $pages[] = $page;
        }

        if (!$returnAll) {
            $pages = $this->sortDashboardSearchPages($pages, $orderBy, $query);
            if (count($pages) > ToolbarManager::SEARCH_MAX_RESULTS) {
                $pages = array_slice($pages, 0, ToolbarManager::SEARCH_MAX_RESULTS);
            }
        }

        return new JsonResponse([
            'success' => true,
            'pages' => $pages,
        ]);
    }

    public function remove_favorites()
    {
        if (!$this->app->make('token')->validate('dashboard_favorites_manager_remove', $this->request->request->get('ccm_token'))) {
            return $this->handleRemoveFavoritesResponse(
                false,
                $this->app->make('token')->getErrorMessage(),
                [],
                'error',
                400
            );
        }

        $selected = $this->request->request->get('selected_favorites', []);
        if (!is_array($selected) || empty($selected)) {
            return $this->handleRemoveFavoritesResponse(false, t('No favorites selected.'));
        }

        $result = $this->getDashboardFavoritesService()->removeCurrentUserDashboardFavorites($selected);
        $data = [
            'removed' => (int) $result['removed'],
            'favorites' => $this->getDashboardFavoriteLinks(),
        ];
        if ($result['removed'] > 0) {
            return $this->handleRemoveFavoritesResponse(
                true,
                t('Removed %s dashboard favorites.', $result['removed']),
                $data,
                'success'
            );
        }

        return $this->handleRemoveFavoritesResponse(false, t('No matching dashboard favorites found.'), $data);
    }

    public function export_favorites()
    {
        if (!$this->app->make('token')->validate('dashboard_favorites_manager_import_export', $this->request->request->get('ccm_token'))) {
            $this->queueOverlayMessage('error', $this->app->make('token')->getErrorMessage());

            return $this->redirectToManager();
        }

        $payload = $this->getDashboardFavoritesService()->getDashboardFavoriteExportPayload();
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $this->queueOverlayMessage('error', t('Unable to export dashboard favorites.'));

            return $this->redirectToManager();
        }

        $filename = 'dashboard-favorites-' . date('Ymd-His') . '.json';
        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json; charset=UTF-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        return $response;
    }

    public function import_favorites()
    {
        if (!$this->app->make('token')->validate('dashboard_favorites_manager_import_export', $this->request->request->get('ccm_token'))) {
            $this->queueOverlayMessage('error', $this->app->make('token')->getErrorMessage());

            return $this->redirectToManager();
        }

        $file = $this->request->files->get('favorites_file');
        if (!$file || !$file->isValid()) {
            $this->queueOverlayMessage('warning', t('Select a valid favorites export file.'));

            return $this->redirectToManager();
        }

        if ((int) $file->getSize() > self::IMPORT_FILE_MAX_BYTES) {
            $this->queueOverlayMessage('error', t('The selected file is too large. Maximum size is 64 KB.'));

            return $this->redirectToManager();
        }

        $fileHelper = $this->app->make('helper/file');
        $contents = $fileHelper->getContents($file->getPathname());
        $payload = json_decode((string) $contents, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($payload)) {
            $this->queueOverlayMessage('error', t('The selected file is not a valid favorites export.'));

            return $this->redirectToManager();
        }

        $payloadFavorites = $this->getDashboardFavoritesService()->getImportPayloadFavorites($payload);
        if (empty($payloadFavorites['success'])) {
            $this->queueOverlayMessage('error', (string) ($payloadFavorites['message'] ?? ''));

            return $this->redirectToManager();
        }

        $this->storeImportReport($this->getDashboardFavoritesService()->importDashboardFavorites(
            $payloadFavorites['favorites'],
            function (Page $page) {
                return $this->isSearchableDashboardPage($page) && $this->canViewDashboardPage($page);
            }
        ));

        return $this->redirectToManager();
    }

    public function reorder_favorites()
    {
        if (!$this->app->make('token')->validate('dashboard_favorites_manager_reorder', $this->request->request->get('ccm_token'))) {
            return new JsonResponse([
                'error' => [
                    'message' => $this->app->make('token')->getErrorMessage(),
                ],
            ], 400);
        }

        $favoriteKey = trim((string) $this->request->request->get('favorite_key', ''));
        $direction = trim((string) $this->request->request->get('direction', ''));
        if ($favoriteKey !== '' || $direction !== '') {
            $result = $this->getDashboardFavoritesService()->moveCurrentUserDashboardFavorite($favoriteKey, $direction);
            if (!$result['success']) {
                return new JsonResponse([
                    'error' => [
                        'message' => $result['message'],
                    ],
                ], 400);
            }

            return new JsonResponse([
                'success' => true,
                'favorites' => $this->getDashboardFavoriteLinks(),
            ]);
        }

        $favoriteKeys = $this->request->request->get('favorite_keys', []);
        if (!is_array($favoriteKeys) || empty($favoriteKeys)) {
            return new JsonResponse([
                'error' => [
                    'message' => t('No favorites submitted.'),
                ],
            ], 400);
        }

        $result = $this->getDashboardFavoritesService()->reorderCurrentUserDashboardFavorites($favoriteKeys);
        if (!$result['success']) {
            return new JsonResponse([
                'error' => [
                    'message' => $result['message'],
                ],
            ], 400);
        }

        return new JsonResponse([
            'success' => true,
            'favorites' => $this->getDashboardFavoriteLinks(),
        ]);
    }

    private function getDashboardFavoriteLinks()
    {
        return $this->getDashboardFavoritesService()->getManagerFavoriteLinks();
    }

    private function getManagerPackageController()
    {
        return $this->app->make(PackageService::class)->getClass('dashboard_favorites_manager');
    }

    private function getPendingPackageUpdate($packageController)
    {
        $availableVersion = (string) $packageController->getPackageVersion();
        if ($availableVersion === '') {
            return;
        }

        $packageEntity = $this->app->make(PackageService::class)->getByHandle($packageController->getPackageHandle());
        if (!$packageEntity || !$packageEntity->isPackageInstalled()) {
            return;
        }

        $installedVersion = (string) $packageEntity->getPackageVersion();
        if ($installedVersion === '' || !version_compare($availableVersion, $installedVersion, '>')) {
            return;
        }

        $permissions = new Checker();

        return [
            'installedVersion' => $installedVersion,
            'availableVersion' => $availableVersion,
            'updateUrl' => (string) \URL::to('/dashboard/extend/update'),
            'canInstallPackages' => (bool) $permissions->canInstallPackages(),
        ];
    }

    private function getDashboardPageTree()
    {
        $favoritePageIDs = [];
        foreach ($this->getDashboardFavoriteLinks() as $favorite) {
            $pageID = (int) ($favorite['pageID'] ?? 0);
            if ($pageID > 0) {
                $favoritePageIDs[$pageID] = true;
            }
        }

        $pages = [];
        $addedPageIDs = [];

        $dashboardPage = Page::getByPath('/dashboard');
        if ($dashboardPage instanceof Page) {
            $this->addDashboardPageTreeItem($dashboardPage, $favoritePageIDs, $pages, $addedPageIDs);
        }

        $pageList = new PageList();
        if (method_exists($pageList, 'includeSystemPages')) {
            $pageList->includeSystemPages();
        }
        $pageList->filterByPath('/dashboard');

        foreach ($pageList->getResults() as $page) {
            $this->addDashboardPageTreeItem($page, $favoritePageIDs, $pages, $addedPageIDs, false);
        }

        usort($pages, static function ($a, $b) {
            $nameComparison = strnatcasecmp($a['name'], $b['name']);
            if ($nameComparison !== 0) {
                return $nameComparison;
            }

            return strnatcasecmp($a['path'], $b['path']);
        });

        return $pages;
    }

    private function addDashboardPageTreeItem(Page $page, array $favoritePageIDs, array &$pages, array &$addedPageIDs, $checkPermissions = true)
    {
        if (!$this->isSearchableDashboardPage($page) || ($checkPermissions && !$this->canViewDashboardPage($page))) {
            return;
        }

        $pageID = (int) $page->getCollectionID();
        if (isset($addedPageIDs[$pageID])) {
            return;
        }

        $addedPageIDs[$pageID] = true;
        $normalizer = $this->getDashboardFavoriteNormalizer();
        $path = $normalizer->getPagePath($page);
        $pages[] = [
            'id' => $pageID,
            'name' => (string) $page->getCollectionName(),
            'path' => $path,
            'url' => $normalizer->getDashboardFavoriteUrlFromPath($path),
            'isFavorite' => isset($favoritePageIDs[$pageID]),
        ];
    }

    private function normalizeSearchText($value)
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower((string) $value)));
    }

    private function sortDashboardSearchPages(array $pages, $orderBy, $query)
    {
        $primaryKey = (string) $orderBy === 'path' ? 'path' : 'name';
        $secondaryKey = $primaryKey === 'path' ? 'name' : 'path';
        $query = $this->normalizeSearchText($query);
        usort($pages, function ($a, $b) use ($primaryKey, $secondaryKey, $query) {
            $aMatchesPrimary = $query !== '' && str_contains($this->normalizeSearchText((string) $a[$primaryKey]), $query);
            $bMatchesPrimary = $query !== '' && str_contains($this->normalizeSearchText((string) $b[$primaryKey]), $query);
            if ($aMatchesPrimary !== $bMatchesPrimary) {
                return $aMatchesPrimary ? -1 : 1;
            }

            $primaryComparison = strnatcasecmp((string) $a[$primaryKey], (string) $b[$primaryKey]);
            if ($primaryComparison !== 0) {
                return $primaryComparison;
            }

            return strnatcasecmp((string) $a[$secondaryKey], (string) $b[$secondaryKey]);
        });

        return $pages;
    }

    private function handleToggleDashboardPageResponse($success, $message, array $data = [])
    {
        if ($this->request->isXmlHttpRequest()) {
            return new JsonResponse(array_merge([
                'success' => (bool) $success,
                'message' => (string) $message,
            ], $data), $success ? 200 : 400);
        }

        $this->queueOverlayMessage($success ? 'success' : 'warning', (string) $message);

        return $this->redirectToManager();
    }

    private function handleRemoveFavoritesResponse($success, $message, array $data = [], $type = 'warning', $status = 200)
    {
        if ($this->request->isXmlHttpRequest()) {
            $payload = array_merge([
                'success' => (bool) $success,
                'message' => (string) $message,
            ], $data);
            if (!$success && $type === 'error') {
                $payload['error'] = [
                    'message' => (string) $message,
                ];
            }

            return new JsonResponse($payload, $status);
        }

        $this->queueOverlayMessage($type, (string) $message);

        return $this->redirectToManager();
    }

    private function redirectToManager()
    {
        return new RedirectResponse((string) \URL::to('/dashboard/welcome/favorites_manager'));
    }

    private function addDashboardPageFavorite($pageID)
    {
        if ($this->getCurrentUserID() <= 0) {
            return [
                'success' => false,
                'message' => t('You must be logged in to update dashboard favorites.'),
            ];
        }

        $page = Page::getByID((int) $pageID);
        if (!$this->isSearchableDashboardPage($page) || !$this->canViewDashboardPage($page)) {
            return [
                'success' => false,
                'message' => t('Invalid dashboard page selected.'),
            ];
        }

        return $this->getDashboardFavoritesService()->addCurrentUserDashboardPageFavorite($page);
    }

    private function removeDashboardPageFavorite($pageID)
    {
        return $this->getDashboardFavoritesService()->removeCurrentUserDashboardPageFavorite($pageID);
    }

    private function storeImportReport(array $report)
    {
        try {
            $this->app->make('session')->set(self::IMPORT_REPORT_SESSION_KEY, $report);
        } catch (\Throwable $e) {
            // The import report is only used for optional UI feedback after redirect.
            // Ignore session write failures so the import itself can still complete.
        }
    }

    private function pullImportReport()
    {
        try {
            $session = $this->app->make('session');
            $report = $session->get(self::IMPORT_REPORT_SESSION_KEY);
            $session->remove(self::IMPORT_REPORT_SESSION_KEY);

            return is_array($report) ? $report : null;
        } catch (\Throwable $e) {
            // Import reports are optional UI feedback; missing session data is harmless.
            return;
        }
    }

    private function queueOverlayMessage($type, $message)
    {
        $this->getOverlayMessageQueue()->add($type, $message);
    }

    private function pullOverlayMessages()
    {
        return $this->getOverlayMessageQueue()->pull();
    }

    private function getOverlayMessageQueue()
    {
        return new OverlayMessageQueue($this->app->make('session'));
    }

    private function getDashboardFavoriteNormalizer()
    {
        if (!$this->dashboardFavoriteNormalizer instanceof DashboardFavoriteNormalizer) {
            $this->dashboardFavoriteNormalizer = new DashboardFavoriteNormalizer();
        }

        return $this->dashboardFavoriteNormalizer;
    }

    private function getDashboardFavoritesService()
    {
        if (!$this->dashboardFavoritesService instanceof DashboardFavoritesService) {
            $this->dashboardFavoritesService = new DashboardFavoritesService(
                $this->app,
                '/dashboard/welcome/favorites_manager',
                $this->getDashboardFavoriteNormalizer()
            );
        }

        return $this->dashboardFavoritesService;
    }

    private function isSearchableDashboardPage($page)
    {
        $normalizer = $this->getDashboardFavoriteNormalizer();
        $path = $normalizer->getPagePath($page);
        if (!$normalizer->isDashboardPage($page) || (int) $page->getCollectionID() <= 0 || $path === '') {
            return false;
        }

        return $this->isDirectDashboardFavoriteTarget($page, $path);
    }

    private function isDirectDashboardFavoriteTarget(Page $page, $path)
    {
        $path = (string) $path;
        if (isset($this->dashboardDirectTargetCache[$path])) {
            return $this->dashboardDirectTargetCache[$path];
        }

        $this->dashboardDirectTargetCache[$path] = true;

        if ($path === '/dashboard' || $this->hasDedicatedDashboardController($page)) {
            return true;
        }

        $parentPath = $this->getDashboardParentPath($path);
        if ($parentPath === '' || $parentPath === $path) {
            return true;
        }

        $parentPage = Page::getByPath($parentPath);
        if (!$this->getDashboardFavoriteNormalizer()->isDashboardPage($parentPage)) {
            return true;
        }

        $segment = basename($path);
        if ($segment === '') {
            return true;
        }

        $this->dashboardDirectTargetCache[$path] = !$this->parentDashboardControllerHandlesSegment($parentPage, $segment);

        return $this->dashboardDirectTargetCache[$path];
    }

    private function hasDedicatedDashboardController(Page $page)
    {
        try {
            $controller = $page->getPageController();
        } catch (\Throwable $e) {
            // Pages whose controller cannot be built are not safe direct search targets.
            return false;
        }

        return is_object($controller) && get_class($controller) !== 'Concrete\Core\Page\Controller\PageController';
    }

    private function getDashboardParentPath($path)
    {
        $path = trim((string) $path);
        if ($path === '' || $path === '/dashboard') {
            return '';
        }

        $parentPath = rtrim(dirname($path), '\\/');

        return $parentPath === '' || $parentPath === '.' ? '' : $parentPath;
    }

    private function parentDashboardControllerHandlesSegment(Page $parentPage, $segment)
    {
        $parentPath = $this->getDashboardFavoriteNormalizer()->getPagePath($parentPage);
        $segment = trim((string) $segment);
        if ($parentPath === '' || $segment === '') {
            return false;
        }

        $cacheKey = $parentPath . '|' . $segment;
        if (isset($this->dashboardParentActionCache[$cacheKey])) {
            return $this->dashboardParentActionCache[$cacheKey];
        }

        $this->dashboardParentActionCache[$cacheKey] = false;

        try {
            $controller = $parentPage->getPageController();
        } catch (\Throwable $e) {
            // If the parent controller cannot load, treat the segment as unsupported.
            return false;
        }

        if (!is_object($controller)) {
            return false;
        }

        $normalizedSegment = str_replace('-', '_', $segment);
        $methods = array_unique([
            $segment,
            $normalizedSegment,
            $segment . '_page',
            $normalizedSegment . '_page',
        ]);

        foreach ($methods as $method) {
            if ($this->isPublicDashboardControllerAction($controller, $method)) {
                $this->dashboardParentActionCache[$cacheKey] = true;
                break;
            }
        }

        return $this->dashboardParentActionCache[$cacheKey];
    }

    private function isPublicDashboardControllerAction($controller, $method)
    {
        try {
            $reflection = new \ReflectionMethod(get_class($controller), (string) $method);
        } catch (\ReflectionException $e) {
            // Missing methods simply mean the dashboard segment is not a public action.
            return false;
        }

        $declaringClass = $reflection->getDeclaringClass()->getName();
        if (in_array($declaringClass, [
            'Concrete\Core\Controller\Controller',
            'Concrete\Core\Controller\AbstractController',
            'Concrete\Core\Page\Controller\PageController',
        ], true)) {
            return false;
        }

        return $reflection->isPublic()
            && !$reflection->isConstructor()
            && !str_starts_with((string) $method, 'on_')
            && !str_starts_with((string) $method, '__');
    }

    private function canViewDashboardPage(Page $page)
    {
        try {
            $permissions = new \Permissions($page);

            return (bool) $permissions->canViewPage();
        } catch (\Throwable $e) {
            // Permission failures should hide the page from search instead of breaking it.
            return false;
        }
    }

    private function canUseToolbarClearCache()
    {
        $page = Page::getByPath('/dashboard/system/optimization/clearcache');
        if (!$page instanceof Page || $page->isError()) {
            return false;
        }

        return $this->canViewDashboardPage($page);
    }

    private function handleToolbarClearCacheResponse($success, $message, $status = 200)
    {
        if ($this->isToolbarClearCacheJsonRequest()) {
            return new JsonResponse([
                'success' => (bool) $success,
                'message' => (string) $message,
            ], $status);
        }

        $this->queueOverlayMessage($success ? 'success' : 'error', (string) $message);

        return new RedirectResponse($this->getToolbarClearCacheReturnUrl());
    }

    private function logToolbarClearCache()
    {
        try {
            $user = new User();
            $userID = $user->isRegistered() ? (int) $user->getUserID() : 0;
            $logger = $this->app->make('log/factory')->createLogger('operations');
            $logger->notice(t('Dashboard Favorites Manager cleared cache from the toolbar. User ID: %s', $userID));
        } catch (\Throwable $e) {
            // Logging is optional; never fail the clear-cache action because of it.
        }
    }

    private function isToolbarClearCacheJsonRequest()
    {
        if ($this->request->isXmlHttpRequest()) {
            return true;
        }

        return stripos((string) $this->request->headers->get('accept'), 'application/json') !== false;
    }

    private function getToolbarClearCacheReturnUrl()
    {
        $referer = trim((string) $this->request->headers->get('referer'));
        if ($referer !== '' && !preg_match('/[\x00-\x1F\x7F]/', $referer)) {
            $parts = parse_url($referer);
            if (is_array($parts)) {
                $host = (string) ($parts['host'] ?? '');
                if ($host === '' || strcasecmp($host, (string) $this->request->getHost()) === 0) {
                    $path = (string) ($parts['path'] ?? '/');
                    if ($path !== '' && $path[0] === '/') {
                        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
                        $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';

                        return $path . $query . $fragment;
                    }
                }
            }
        }

        return (string) \URL::to('/dashboard');
    }

    private function getCurrentUserID()
    {
        $user = new User();

        return (int) $user->getUserID();
    }
}
