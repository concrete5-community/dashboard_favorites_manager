<?php

declare(strict_types=1);

namespace Concrete\Package\DashboardFavoritesManager\Favorites;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\Application\UserInterface\Dashboard\Navigation\FavoritesNavigationCache;
use Concrete\Core\Application\UserInterface\Dashboard\Navigation\FavoritesNavigationFactory;
use Concrete\Core\Page\Page;
use Concrete\Core\User\User;

class DashboardFavoritesService
{
    private $app;

    private $managerPath;

    private $normalizer;

    private $pageCache = [];

    public function __construct($app, $managerPath, DashboardFavoriteNormalizer $normalizer)
    {
        $this->app = $app;
        $this->managerPath = $managerPath;
        $this->normalizer = $normalizer;
    }

    public function getManagerPath()
    {
        return $this->managerPath;
    }

    public function addCurrentUserDashboardFavorite(User $user, Page $page)
    {
        $items = $this->getUserStoredFavoriteItems($user);
        if ($items === null || empty($items)) {
            $items = $this->getDefaultFavoriteItems();
        }

        $items = $this->mergeDashboardFavoritesManagerFavorite(
            $items,
            $this->getDashboardFavoritesManagerFavoriteItem($page)
        );

        $user->saveConfig('DASHBOARD_FAVORITES', json_encode($this->normalizeItems($items)));
        $this->clearFavoritesCache();
    }

    public function removeDashboardFavoritesManagerFavoriteFromAllUsers($page)
    {
        $db = $this->app->make('database')->connection();

        try {
            $rows = $db->fetchAllAssociative(
                'select cfValue, uID from ConfigStore where cfKey = ?',
                ['DASHBOARD_FAVORITES']
            );
        } catch (\Throwable $e) {
            return;
        }

        $managerPageID = $page instanceof Page && !$page->isError() ? (int) $page->getCollectionID() : 0;
        $changed = false;
        foreach ($rows as $row) {
            $value = (string) ($row['cfValue'] ?? '');
            $items = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($items)) {
                continue;
            }

            $removed = false;
            $filteredItems = $this->removeDashboardFavoritesManagerFavoriteItems($items, $managerPageID, $removed);
            if (!$removed) {
                continue;
            }

            $db->executeStatement(
                'update ConfigStore set cfValue = ? where cfKey = ? and uID = ?',
                [json_encode($this->normalizeItems($filteredItems)), 'DASHBOARD_FAVORITES', (int) $row['uID']]
            );
            $changed = true;
        }

        if ($changed) {
            $this->clearFavoritesCache();
        }
    }

    public function getDashboardFavoritesManagerFavoriteItem(Page $page)
    {
        return [
            'name' => (string) $page->getCollectionName(),
            'url' => $this->normalizer->getDashboardFavoriteUrlFromPath($this->managerPath),
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

    public function mergeDashboardFavoritesManagerFavoriteItems(array $items, array $favoriteItem, &$found)
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

    public function normalizeItems(array $items)
    {
        return $this->normalizer->normalizeItems($items);
    }

    public function getToolbarFavoriteLinks()
    {
        $links = [];
        $seenUrls = [];
        foreach ($this->getResolvedFavoriteItems() as $favorite) {
            $name = trim($favorite['name']);
            $path = $favorite['urlPath'];

            if ($favorite['pageID'] > 0) {
                if (!$this->normalizer->isDashboardPage($favorite['page']) || !$this->canViewPage($favorite['page'])) {
                    continue;
                }

                if ($path === null) {
                    $path = $favorite['pagePath'];
                }

                if ($name === '') {
                    $name = (string) $favorite['page']->getCollectionName();
                }
            }

            if ($path === null || $path === '' || isset($seenUrls[$path])) {
                continue;
            }

            $seenUrls[$path] = true;
            $links[] = [
                'name' => $name !== '' ? $name : $path,
                'path' => $path,
                'url' => $this->normalizer->getDashboardFavoriteUrlFromPath($path),
            ];
        }

        return $links;
    }

    public function getManagerFavoriteLinks()
    {
        $links = [];
        foreach ($this->getResolvedFavoriteItems() as $favorite) {
            if ($favorite['pageID'] <= 0 && $favorite['url'] === '') {
                continue;
            }

            $selectionKey = $this->getFavoriteSelectionKey($favorite['pageID'], $favorite['url'], $favorite['name']);
            if (isset($links[$selectionKey])) {
                continue;
            }

            $urlPath = $favorite['urlPath'];
            if ($urlPath === null && $favorite['pagePath'] !== '') {
                $urlPath = $favorite['pagePath'];
            }

            $links[$selectionKey] = [
                'selectionKey' => $selectionKey,
                'pageID' => $favorite['pageID'],
                'name' => $favorite['name'],
                'path' => $favorite['pagePath'],
                'url' => $urlPath === null ? '' : $this->normalizer->getDashboardFavoriteUrlFromPath($urlPath),
            ];
        }

        return array_values($links);
    }

    public function getFavoriteSelectionKey($pageID, $url, $name)
    {
        return hash('sha256', (int) $pageID . '|' . (string) $url . '|' . (string) $name);
    }

    public function getCurrentUserDashboardFavoriteItems()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return [];
        }

        $items = $this->getUserStoredFavoriteItems($user);
        if ($items !== null) {
            return $items;
        }

        return $this->getDefaultFavoriteItems();
    }

    public function saveCurrentUserDashboardFavoriteItems(array $items)
    {
        $user = new User();
        $user->saveConfig('DASHBOARD_FAVORITES', json_encode($this->normalizeItems($items)));
        $this->clearFavoritesCache();
    }

    private function getResolvedFavoriteItems()
    {
        $favorites = [];
        foreach ($this->normalizer->flattenItems($this->getCurrentUserDashboardFavoriteItems()) as $item) {
            $pageID = (int) ($item['pageID'] ?? 0);
            $page = $pageID > 0 ? $this->getPageByID($pageID) : null;
            $pagePath = $this->normalizer->isDashboardPage($page) ? $this->normalizer->getPagePath($page) : '';

            $favorites[] = [
                'pageID' => $pageID,
                'page' => $page,
                'pagePath' => $pagePath,
                'name' => (string) ($item['name'] ?? ''),
                'url' => (string) ($item['url'] ?? ''),
                'urlPath' => $this->normalizer->sanitizeFavoriteUrl((string) ($item['url'] ?? '')),
            ];
        }

        return $favorites;
    }

    private function getPageByID($pageID)
    {
        $pageID = (int) $pageID;
        if ($pageID <= 0) {
            return null;
        }

        if (!array_key_exists($pageID, $this->pageCache)) {
            $this->pageCache[$pageID] = Page::getByID($pageID);
        }

        return $this->pageCache[$pageID];
    }

    public function clearFavoritesCache()
    {
        try {
            $this->app->make(FavoritesNavigationCache::class)->clear();
        } catch (\Throwable $e) {
            // Cache clearing is best-effort after favorite changes.
        }
    }

    private function getUserStoredFavoriteItems(User $user)
    {
        $favorites = $user->config('DASHBOARD_FAVORITES');
        if ($favorites !== null && $favorites !== '') {
            $items = json_decode((string) $favorites, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($items)) {
                return $items;
            }
        }
    }

    private function getDefaultFavoriteItems()
    {
        try {
            $this->clearFavoritesCache();

            return json_decode(json_encode($this->app->make(FavoritesNavigationFactory::class)->createNavigation()), true) ?: [];
        } catch (\Throwable $e) {
            // Fall back to no favorites if Concrete cannot build the navigation.
            return [];
        }
    }

    private function isDashboardFavoritesManagerFavoriteItem(array $item, array $favoriteItem)
    {
        if ($this->normalizer->normalizeUrlPath((string) ($item['url'] ?? '')) === $this->managerPath) {
            return true;
        }

        if ((int) ($item['pageID'] ?? 0) === (int) ($favoriteItem['pageID'] ?? 0)) {
            return true;
        }

        return false;
    }

    private function removeDashboardFavoritesManagerFavoriteItems(array $items, $managerPageID, &$removed)
    {
        $filtered = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $path = $this->normalizer->normalizeUrlPath((string) ($item['url'] ?? ''));
            $pageID = (int) ($item['pageID'] ?? 0);
            if ($path === $this->managerPath || ($managerPageID > 0 && $pageID === $managerPageID)) {
                $removed = true;
                continue;
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $item['children'] = $this->removeDashboardFavoritesManagerFavoriteItems(
                    $item['children'],
                    $managerPageID,
                    $removed
                );
            }

            $filtered[] = $item;
        }

        return $filtered;
    }

    private function canViewPage($page)
    {
        if (!$page instanceof Page) {
            return false;
        }

        try {
            $permissions = new \Permissions($page);

            return (bool) $permissions->canViewPage();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
