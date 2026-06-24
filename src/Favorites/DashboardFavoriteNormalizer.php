<?php

declare(strict_types=1);

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

        if (str_starts_with($path, '/index.php/')) {
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

        if (str_starts_with($path, $basePath . '/')) {
            return substr($path, strlen($basePath));
        }

        return $path;
    }

    public function sanitizeFavoriteUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '' || preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return;
        }

        if (preg_match('/^(?:javascript|data|vbscript):/i', $url)) {
            return;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return;
        }

        $path = $this->normalizeUrlPath($url);
        if ($path !== '/dashboard' && !str_starts_with($path, '/dashboard/')) {
            return;
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

        return $path === '/dashboard' || str_starts_with($path, '/dashboard/');
    }

    public function getPagePath(Page $page)
    {
        return method_exists($page, 'getCollectionPath') ? (string) $page->getCollectionPath() : '';
    }
}
