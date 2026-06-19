<?php

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

    public function mergeDashboardFavoritesManagerFavorite(array $items, array $favoriteItem)
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
        foreach ($this->normalizer->flattenItems($this->getCurrentUserDashboardFavoriteItems()) as $item) {
            $pageID = (int) ($item['pageID'] ?? 0);
            $name = trim((string) ($item['name'] ?? ''));
            $path = $this->normalizer->sanitizeFavoriteUrl((string) ($item['url'] ?? ''));

            if ($pageID > 0) {
                $page = Page::getByID($pageID);
                if (!$this->normalizer->isDashboardPage($page) || !$this->canViewPage($page)) {
                    continue;
                }

                if ($path === null) {
                    $path = $this->normalizer->getPagePath($page);
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
                'url' => $this->normalizer->getDashboardFavoriteUrlFromPath($path),
            ];
        }

        return $links;
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

        return null;
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
