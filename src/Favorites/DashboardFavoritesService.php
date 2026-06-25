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
            // Uninstall cleanup is best-effort if user favorites cannot be queried.
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
            if (!$this->hasFavoriteSelectionIdentity($favorite)) {
                continue;
            }

            $selectionKey = $this->getFavoriteSelectionKeyFromItem($favorite);
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

    public function getFavoriteSelectionKeyFromItem(array $item)
    {
        return $this->getFavoriteSelectionKey(
            $this->getFavoriteItemPageID($item),
            $this->getFavoriteItemUrl($item),
            $this->getFavoriteItemName($item)
        );
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

    public function addCurrentUserDashboardPageFavorite(Page $page)
    {
        if ($this->getCurrentUserID() <= 0) {
            return [
                'success' => false,
                'message' => t('You must be logged in to update dashboard favorites.'),
            ];
        }

        $pageID = (int) $page->getCollectionID();
        $items = $this->getCurrentUserDashboardFavoriteItems();
        $name = (string) $page->getCollectionName();
        $url = $this->normalizer->getDashboardFavoriteUrlFromPath($this->normalizer->getPagePath($page));
        $favoriteItem = [
            'name' => $name,
            'url' => $url,
            'pageID' => $pageID,
            'isActive' => false,
            'children' => [],
        ];
        $selectionKey = $this->getFavoriteSelectionKeyFromItem($favoriteItem);

        foreach ($this->normalizer->flattenItems($items) as $item) {
            if ($this->getFavoriteItemPageID($item) === $pageID || $this->getFavoriteSelectionKeyFromItem($item) === $selectionKey) {
                return [
                    'success' => false,
                    'message' => t('That dashboard page is already in your favorites.'),
                ];
            }
        }

        $items[] = $favoriteItem;

        $this->saveCurrentUserDashboardFavoriteItems($items);

        return [
            'success' => true,
            'message' => t('Added "%s" to your dashboard favorites.', $name),
            'name' => $name,
        ];
    }

    public function removeCurrentUserDashboardPageFavorite($pageID)
    {
        if ($this->getCurrentUserID() <= 0) {
            return [
                'success' => false,
                'message' => t('You must be logged in to update dashboard favorites.'),
            ];
        }

        $page = $this->getPageByID((int) $pageID);
        if (!$this->normalizer->isDashboardPage($page)) {
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

        $this->saveCurrentUserDashboardFavoriteItems($filtered);

        return [
            'success' => true,
            'message' => t('Removed "%s" from your dashboard favorites.', (string) $page->getCollectionName()),
        ];
    }

    public function removeCurrentUserDashboardFavorites(array $selectedKeys)
    {
        $selected = array_fill_keys($selectedKeys, true);
        $items = $this->getCurrentUserDashboardFavoriteItems();
        if ($this->getCurrentUserID() <= 0 || empty($items)) {
            return [
                'removed' => 0,
            ];
        }

        $removed = 0;
        $filtered = $this->filterFavoriteItems($items, $selected, $removed);
        if ($removed > 0) {
            $this->saveCurrentUserDashboardFavoriteItems($filtered);
        }

        return [
            'removed' => $removed,
        ];
    }

    public function reorderCurrentUserDashboardFavorites(array $favoriteKeys)
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
        foreach ($this->normalizer->flattenItems($items) as $item) {
            if (!$this->hasFavoriteSelectionIdentity($item)) {
                continue;
            }

            $favoritesByKey[$this->getFavoriteSelectionKeyFromItem($item)] = $item;
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

        $this->saveCurrentUserDashboardFavoriteItems($ordered);

        return [
            'success' => true,
            'message' => '',
        ];
    }

    public function moveCurrentUserDashboardFavorite($favoriteKey, $direction)
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
        foreach ($this->normalizer->flattenItems($items) as $item) {
            if (!$this->hasFavoriteSelectionIdentity($item)) {
                continue;
            }

            $ordered[] = $item;
            if ($this->getFavoriteSelectionKeyFromItem($item) === $favoriteKey) {
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
        $this->saveCurrentUserDashboardFavoriteItems($ordered);

        return [
            'success' => true,
            'message' => '',
        ];
    }

    private function getResolvedFavoriteItems()
    {
        $favorites = [];
        foreach ($this->normalizer->flattenItems($this->getCurrentUserDashboardFavoriteItems()) as $item) {
            $pageID = $this->getFavoriteItemPageID($item);
            $page = $pageID > 0 ? $this->getPageByID($pageID) : null;
            $pagePath = $this->normalizer->isDashboardPage($page) ? $this->normalizer->getPagePath($page) : '';

            $favorites[] = [
                'pageID' => $pageID,
                'page' => $page,
                'pagePath' => $pagePath,
                'name' => $this->getFavoriteItemName($item),
                'url' => $this->getFavoriteItemUrl($item),
                'urlPath' => $this->normalizer->sanitizeFavoriteUrl($this->getFavoriteItemUrl($item)),
            ];
        }

        return $favorites;
    }

    private function getPageByID($pageID)
    {
        $pageID = (int) $pageID;
        if ($pageID <= 0) {
            return;
        }

        if (!array_key_exists($pageID, $this->pageCache)) {
            $this->pageCache[$pageID] = Page::getByID($pageID);
        }

        return $this->pageCache[$pageID];
    }

    private function hasFavoriteSelectionIdentity(array $item)
    {
        return $this->getFavoriteItemPageID($item) > 0 || $this->getFavoriteItemUrl($item) !== '';
    }

    private function getFavoriteItemPageID(array $item)
    {
        return (int) ($item['pageID'] ?? 0);
    }

    private function getFavoriteItemUrl(array $item)
    {
        return (string) ($item['url'] ?? '');
    }

    private function getFavoriteItemName(array $item)
    {
        return (string) ($item['name'] ?? '');
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

            if (isset($selected[$this->getFavoriteSelectionKeyFromItem($item)])) {
                $removed++;
                continue;
            }

            if (!empty($item['children']) && is_array($item['children'])) {
                $item['children'] = array_values($this->filterFavoriteItems(
                    $item['children'],
                    $selected,
                    $removed
                ));
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

            if ($this->getFavoriteItemPageID($item) === (int) $pageID) {
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
        if ($this->normalizer->normalizeUrlPath($this->getFavoriteItemUrl($item)) === $this->managerPath) {
            return true;
        }

        if ($this->getFavoriteItemPageID($item) === $this->getFavoriteItemPageID($favoriteItem)) {
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

            $path = $this->normalizer->normalizeUrlPath($this->getFavoriteItemUrl($item));
            $pageID = $this->getFavoriteItemPageID($item);
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
            // Permission failures should hide the page from search instead of breaking it.
            return false;
        }
    }
}
