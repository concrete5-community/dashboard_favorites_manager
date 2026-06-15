<?php

namespace Concrete\Package\DashboardFavoritesManager\Favorites;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\Page\Page;

class DashboardFavoriteNormalizer
{
    public function normalizeUrlPath($url)
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

    public function stripApplicationBasePath($path)
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

    public function sanitizeFavoriteUrl($url)
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

        $path = $this->normalizeUrlPath($url);
        if ($path !== '/dashboard' && strpos($path, '/dashboard/') !== 0) {
            return null;
        }

        return $path;
    }

    public function getDashboardFavoriteUrlFromPath($path)
    {
        return (string) \URL::to((string) $path);
    }

    public function normalizeItems(array $items)
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
                $item['children'] = $this->normalizeItems($item['children']);
            } else {
                $item['children'] = [];
            }

            $normalized[] = $item;
        }

        return $normalized;
    }

    public function flattenItems(array $items)
    {
        $flattened = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $flattened[] = $item;
            if (!empty($item['children']) && is_array($item['children'])) {
                $flattened = array_merge($flattened, $this->flattenItems($item['children']));
            }
        }

        return $flattened;
    }

    public function isDashboardPage($page)
    {
        if (!$page instanceof Page || $page->isError()) {
            return false;
        }

        $path = $this->getPagePath($page);

        return $path === '/dashboard' || strpos($path, '/dashboard/') === 0;
    }

    public function getPagePath(Page $page)
    {
        return method_exists($page, 'getCollectionPath') ? (string) $page->getCollectionPath() : '';
    }
}
