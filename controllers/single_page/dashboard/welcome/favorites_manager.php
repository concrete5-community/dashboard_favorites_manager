<?php

declare(strict_types=1);

namespace Concrete\Package\DashboardFavoritesManager\Controller\SinglePage\Dashboard\Welcome;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\Application\UserInterface\Dashboard\Navigation\FavoritesNavigationCache;
use Concrete\Core\Application\UserInterface\Dashboard\Navigation\FavoritesNavigationFactory;
use Concrete\Core\Cache\Command\ClearCacheCommand;
use Concrete\Core\Package\PackageService;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Permission\Checker;
use Concrete\Core\User\User;
use Concrete\Package\DashboardFavoritesManager\Favorites\DashboardFavoriteNormalizer;
use Concrete\Package\DashboardFavoritesManager\Message\OverlayMessageQueue;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

class FavoritesManager extends DashboardPageController
{
    private const IMPORT_REPORT_SESSION_KEY = 'dashboard_favorites_manager_import_report';

    private const IMPORT_FILE_MAX_BYTES = 65536;

    private const EXPORT_FORMAT = 'dashboard_favorites_manager';

    private const EXPORT_VERSION = 1;

    private $dashboardDirectTargetCache = [];

    private $dashboardParentActionCache = [];

    private $dashboardFavoriteNormalizer;

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
        if (!$returnAll && strlen($query) < 2) {
            return new JsonResponse([
                'success' => true,
                'pages' => [],
            ]);
        }

        $pages = [];
        foreach ($this->getDashboardPageTree() as $page) {
            if (!$returnAll && !str_contains($this->normalizeSearchText((string) $page['name']), $query)) {
                continue;
            }

            $pages[] = $page;
            if (!$returnAll && count($pages) >= 12) {
                break;
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
            $this->queueOverlayMessage('error', $this->app->make('token')->getErrorMessage());

            return $this->redirectToManager();
        }

        $selected = $this->request->request->get('selected_favorites', []);
        if (!is_array($selected) || empty($selected)) {
            $this->queueOverlayMessage('warning', t('No favorites selected.'));

            return $this->redirectToManager();
        }

        $result = $this->removeDashboardFavorites($selected);
        if ($result['removed'] > 0) {
            $this->queueOverlayMessage('success', t('Removed %s dashboard favorites.', $result['removed']));
        } else {
            $this->queueOverlayMessage('warning', t('No matching dashboard favorites found.'));
        }

        return $this->redirectToManager();
    }

    public function export_favorites()
    {
        if (!$this->app->make('token')->validate('dashboard_favorites_manager_import_export', $this->request->request->get('ccm_token'))) {
            $this->queueOverlayMessage('error', $this->app->make('token')->getErrorMessage());

            return $this->redirectToManager();
        }

        $payload = [
            'format' => self::EXPORT_FORMAT,
            'version' => self::EXPORT_VERSION,
            'exportedAt' => gmdate('c'),
            'favorites' => $this->getDashboardFavoriteExportItems(),
        ];
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

        if (($payload['format'] ?? '') !== self::EXPORT_FORMAT || (int) ($payload['version'] ?? 0) !== self::EXPORT_VERSION) {
            $this->queueOverlayMessage('error', t('The selected file is not a supported dashboard favorites export.'));

            return $this->redirectToManager();
        }

        $favorites = $payload['favorites'] ?? null;
        if (!is_array($favorites)) {
            $this->queueOverlayMessage('error', t('The selected file does not contain dashboard favorites.'));

            return $this->redirectToManager();
        }

        $this->storeImportReport($this->importDashboardFavorites($favorites));

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
            $result = $this->moveCurrentUserDashboardFavorite($favoriteKey, $direction);
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

        $result = $this->reorderCurrentUserDashboardFavorites($favoriteKeys);
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
        $normalizer = $this->getDashboardFavoriteNormalizer();
        $favorites = [];
        foreach ($normalizer->flattenItems($this->getCurrentUserDashboardFavoriteItems()) as $item) {
            $pageID = (int) ($item['pageID'] ?? 0);
            $url = (string) ($item['url'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($pageID <= 0 && $url === '') {
                continue;
            }

            $selectionKey = $this->getFavoriteSelectionKey($pageID, $url, $name);
            if (!isset($favorites[$selectionKey])) {
                $path = '';
                if ($pageID > 0) {
                    $page = Page::getByID($pageID);
                    if ($normalizer->isDashboardPage($page)) {
                        $path = $normalizer->getPagePath($page);
                    }
                }
                $urlPath = $normalizer->sanitizeFavoriteUrl($url);
                if ($urlPath === null && $path !== '') {
                    $urlPath = $path;
                }

                $favorites[$selectionKey] = [
                    'selectionKey' => $selectionKey,
                    'pageID' => $pageID,
                    'name' => $name,
                    'path' => $path,
                    'url' => $urlPath === null ? '' : $normalizer->getDashboardFavoriteUrlFromPath($urlPath),
                ];
            }
        }

        return array_values($favorites);
    }

    private function getDashboardFavoriteExportItems()
    {
        $normalizer = $this->getDashboardFavoriteNormalizer();
        $favorites = [];
        foreach ($this->getDashboardFavoriteLinks() as $favorite) {
            $pageID = (int) ($favorite['pageID'] ?? 0);
            $path = '';
            if ($pageID > 0) {
                $page = Page::getByID($pageID);
                if ($normalizer->isDashboardPage($page)) {
                    $path = $normalizer->getPagePath($page);
                }
            }

            $favorites[] = [
                'name' => (string) ($favorite['name'] ?? ''),
                'path' => $path,
            ];
        }

        return $favorites;
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

    private function redirectToManager()
    {
        return new RedirectResponse((string) \URL::to('/dashboard/welcome/favorites_manager'));
    }

    private function removeDashboardFavorites(array $selectedKeys)
    {
        $selected = array_fill_keys($selectedKeys, true);
        $items = $this->getCurrentUserDashboardFavoriteItems();
        if ($this->getCurrentUserID() <= 0 || empty($items)) {
            return ['removed' => 0];
        }

        $removed = 0;
        $filtered = $this->filterFavoriteItems($items, $selected, $removed);
        if ($removed > 0) {
            $this->saveCurrentUserDashboardFavorites($filtered);
        }

        return ['removed' => $removed];
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

        $items = $this->getCurrentUserDashboardFavoriteItems();
        $normalizer = $this->getDashboardFavoriteNormalizer();
        $name = (string) $page->getCollectionName();
        $url = $normalizer->getDashboardFavoriteUrlFromPath($normalizer->getPagePath($page));
        $selectionKey = $this->getFavoriteSelectionKey((int) $pageID, $url, $name);

        foreach ($normalizer->flattenItems($items) as $item) {
            $existingPageID = (int) ($item['pageID'] ?? 0);
            $existingUrl = (string) ($item['url'] ?? '');
            $existingName = (string) ($item['name'] ?? '');
            if ($existingPageID === (int) $pageID || $this->getFavoriteSelectionKey($existingPageID, $existingUrl, $existingName) === $selectionKey) {
                return [
                    'success' => false,
                    'message' => t('That dashboard page is already in your favorites.'),
                ];
            }
        }

        $items[] = [
            'name' => $name,
            'url' => $url,
            'pageID' => (int) $pageID,
            'isActive' => false,
            'children' => [],
        ];

        $this->saveCurrentUserDashboardFavorites($items);

        return [
            'success' => true,
            'message' => t('Added "%s" to your dashboard favorites.', $name),
            'name' => $name,
        ];
    }

    private function removeDashboardPageFavorite($pageID)
    {
        if ($this->getCurrentUserID() <= 0) {
            return [
                'success' => false,
                'message' => t('You must be logged in to update dashboard favorites.'),
            ];
        }

        $page = Page::getByID((int) $pageID);
        if (!$this->getDashboardFavoriteNormalizer()->isDashboardPage($page)) {
            return [
                'success' => false,
                'message' => t('Invalid dashboard page selected.'),
            ];
        }

        $removed = 0;
        $filtered = $this->filterFavoriteItemsByPageID($this->getCurrentUserDashboardFavoriteItems(), (int) $pageID, $removed);
        if ($removed <= 0) {
            return [
                'success' => false,
                'message' => t('That dashboard page is not in your favorites.'),
            ];
        }

        $this->saveCurrentUserDashboardFavorites($filtered);

        return [
            'success' => true,
            'message' => t('Removed "%s" from your dashboard favorites.', (string) $page->getCollectionName()),
        ];
    }

    private function reorderCurrentUserDashboardFavorites(array $favoriteKeys)
    {
        $items = $this->getCurrentUserDashboardFavoriteItems();
        if ($this->getCurrentUserID() <= 0 || empty($items)) {
            return [
                'success' => false,
                'message' => t('The favorites list is empty.'),
            ];
        }

        $submittedKeys = [];
        foreach ($favoriteKeys as $favoriteKey) {
            $favoriteKey = (string) $favoriteKey;
            if ($favoriteKey === '' || isset($submittedKeys[$favoriteKey])) {
                return [
                    'success' => false,
                    'message' => t('Invalid favorite order.'),
                ];
            }

            $submittedKeys[$favoriteKey] = true;
        }

        $favoritesByKey = [];
        foreach ($this->getDashboardFavoriteNormalizer()->flattenItems($items) as $item) {
            $pageID = (int) ($item['pageID'] ?? 0);
            $url = (string) ($item['url'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($pageID <= 0 && $url === '') {
                continue;
            }

            $favoritesByKey[$this->getFavoriteSelectionKey($pageID, $url, $name)] = $item;
        }

        $ordered = [];
        foreach (array_keys($submittedKeys) as $selectionKey) {
            if (!isset($favoritesByKey[$selectionKey])) {
                return [
                    'success' => false,
                    'message' => t('Invalid favorite order.'),
                ];
            }

            $ordered[] = $favoritesByKey[$selectionKey];
        }

        foreach ($favoritesByKey as $selectionKey => $item) {
            if (!isset($submittedKeys[$selectionKey])) {
                $ordered[] = $item;
            }
        }

        $this->saveCurrentUserDashboardFavorites($ordered);

        return [
            'success' => true,
            'message' => '',
        ];
    }

    private function moveCurrentUserDashboardFavorite($favoriteKey, $direction)
    {
        $items = $this->getCurrentUserDashboardFavoriteItems();
        if ($this->getCurrentUserID() <= 0 || empty($items)) {
            return [
                'success' => false,
                'message' => t('The favorites list is empty.'),
            ];
        }

        $favoriteKey = (string) $favoriteKey;
        if ($favoriteKey === '' || !in_array($direction, ['up', 'down'], true)) {
            return [
                'success' => false,
                'message' => t('Invalid favorite order.'),
            ];
        }

        $ordered = [];
        $currentIndex = null;
        foreach ($this->getDashboardFavoriteNormalizer()->flattenItems($items) as $item) {
            $pageID = (int) ($item['pageID'] ?? 0);
            $url = (string) ($item['url'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if ($pageID <= 0 && $url === '') {
                continue;
            }

            $ordered[] = $item;
            if ($this->getFavoriteSelectionKey($pageID, $url, $name) === $favoriteKey) {
                $currentIndex = count($ordered) - 1;
            }
        }

        if ($currentIndex === null) {
            return [
                'success' => false,
                'message' => t('Invalid favorite order.'),
            ];
        }

        $targetIndex = $direction === 'up' ? $currentIndex - 1 : $currentIndex + 1;
        if (!isset($ordered[$targetIndex])) {
            return [
                'success' => true,
                'message' => '',
            ];
        }

        $current = $ordered[$currentIndex];
        $ordered[$currentIndex] = $ordered[$targetIndex];
        $ordered[$targetIndex] = $current;
        $this->saveCurrentUserDashboardFavorites($ordered);

        return [
            'success' => true,
            'message' => '',
        ];
    }

    private function importDashboardFavorites(array $favorites)
    {
        $items = $this->getCurrentUserDashboardFavoriteItems();
        $existingPaths = [];
        $existingSelectionKeys = [];
        $normalizer = $this->getDashboardFavoriteNormalizer();

        foreach ($normalizer->flattenItems($items) as $item) {
            $pageID = (int) ($item['pageID'] ?? 0);
            $url = $normalizer->sanitizeFavoriteUrl($item['url'] ?? '') ?? '';
            $name = (string) ($item['name'] ?? '');
            if ($pageID > 0) {
                $page = Page::getByID($pageID);
                if ($normalizer->isDashboardPage($page)) {
                    $existingPaths[$normalizer->getPagePath($page)] = true;
                }
            }
            $existingSelectionKeys[$this->getFavoriteSelectionKey($pageID, $url, $name)] = true;
        }

        $result = [
            'imported' => 0,
            'skippedExisting' => 0,
            'skippedInvalid' => 0,
            'message' => empty($favorites) ? t('No favorites found in the selected file.') : '',
            'rows' => [],
        ];

        foreach ($favorites as $favorite) {
            $reportedName = is_array($favorite) ? trim((string) ($favorite['name'] ?? '')) : '';
            $reportedPath = is_array($favorite) ? trim((string) ($favorite['path'] ?? '')) : '';
            $item = $this->resolveImportedFavoriteItem($favorite);
            if ($item === null) {
                $result['skippedInvalid']++;
                $result['rows'][] = [
                    'name' => $reportedName,
                    'path' => $reportedPath,
                    'status' => 'unavailable',
                    'message' => t('Dashboard page not found or not visible.'),
                ];
                continue;
            }

            $pageID = (int) $item['pageID'];
            $url = (string) $item['url'];
            $path = '';
            if ($pageID > 0) {
                $page = Page::getByID($pageID);
                if ($normalizer->isDashboardPage($page)) {
                    $path = $normalizer->getPagePath($page);
                }
            }
            $selectionKey = $this->getFavoriteSelectionKey($pageID, $url, (string) $item['name']);
            if (($path !== '' && isset($existingPaths[$path])) || isset($existingSelectionKeys[$selectionKey])) {
                $result['skippedExisting']++;
                $result['rows'][] = [
                    'name' => (string) $item['name'],
                    'path' => $path,
                    'status' => 'existing',
                    'message' => t('Already in favorites.'),
                ];
                continue;
            }

            $items[] = $item;
            if ($path !== '') {
                $existingPaths[$path] = true;
            }
            $existingSelectionKeys[$selectionKey] = true;
            $result['imported']++;
            $result['rows'][] = [
                'name' => (string) $item['name'],
                'path' => $path,
                'status' => 'imported',
                'message' => t('Imported.'),
            ];
        }

        if ($result['imported'] > 0) {
            $this->saveCurrentUserDashboardFavorites($items);
        }

        return $result;
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

    private function resolveImportedFavoriteItem($favorite)
    {
        if (!is_array($favorite)) {
            return;
        }

        $page = null;
        $path = trim((string) ($favorite['path'] ?? ''));
        if ($path !== '' && ($path === '/dashboard' || str_starts_with($path, '/dashboard/'))) {
            $page = Page::getByPath($path);
        }

        if ($this->isSearchableDashboardPage($page) && $this->canViewDashboardPage($page)) {
            $normalizer = $this->getDashboardFavoriteNormalizer();

            return [
                'name' => (string) $page->getCollectionName(),
                'url' => $normalizer->getDashboardFavoriteUrlFromPath($normalizer->getPagePath($page)),
                'pageID' => (int) $page->getCollectionID(),
                'isActive' => false,
                'children' => [],
            ];
        }
    }

    private function getFavoriteSelectionKey($pageID, $url, $name)
    {
        return hash('sha256', (int) $pageID . '|' . (string) $url . '|' . (string) $name);
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

    private function getCurrentUserDashboardFavoriteItems()
    {
        $user = new User();
        $favorites = $user->config('DASHBOARD_FAVORITES');
        if ($favorites) {
            $items = json_decode((string) $favorites, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($items)) {
                return $items;
            }
        }

        try {
            $this->clearFavoritesCache();

            return json_decode(json_encode($this->app->make(FavoritesNavigationFactory::class)->createNavigation()), true) ?: [];
        } catch (\Throwable $e) {
            // Fall back to no favorites if Concrete cannot build the navigation.
            return [];
        }
    }

    private function saveCurrentUserDashboardFavorites(array $items)
    {
        $user = new User();
        $user->saveConfig('DASHBOARD_FAVORITES', json_encode($this->getDashboardFavoriteNormalizer()->normalizeItems($items)));
        $this->clearFavoritesCache();
    }

    private function getCurrentUserID()
    {
        $user = new User();

        return (int) $user->getUserID();
    }

    private function filterFavoriteItems(array $items, array $selected, &$removed)
    {
        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $filtered[] = $item;
                continue;
            }

            $pageID = (int) ($item['pageID'] ?? 0);
            $url = (string) ($item['url'] ?? '');
            $name = (string) ($item['name'] ?? '');
            if (isset($selected[$this->getFavoriteSelectionKey($pageID, $url, $name)])) {
                $removed++;
                continue;
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $item['children'] = array_values($this->filterFavoriteItems($item['children'], $selected, $removed));
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    private function filterFavoriteItemsByPageID(array $items, $pageID, &$removed)
    {
        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                $filtered[] = $item;
                continue;
            }

            if ((int) ($item['pageID'] ?? 0) === (int) $pageID) {
                $removed++;
                continue;
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $item['children'] = array_values($this->filterFavoriteItemsByPageID($item['children'], $pageID, $removed));
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    private function clearFavoritesCache()
    {
        try {
            $this->app->make(FavoritesNavigationCache::class)->clear();
        } catch (\Throwable $e) {
            // Cache clearing is best-effort after saving favorites.
            // A failure here should not block the user's requested change.
        }
    }
}
