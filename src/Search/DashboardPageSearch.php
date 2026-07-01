<?php

declare(strict_types=1);

namespace Concrete\Package\DashboardFavoritesManager\Search;

defined('C5_EXECUTE') or die('Access Denied.');

class DashboardPageSearch
{
    public const MIN_LENGTH = 2;

    public const ORDER_BY_NAME = 'name';

    public const ORDER_BY_PATH = 'path';

    public function normalizeText($value)
    {
        return trim((string) preg_replace('/\s+/', ' ', strtolower((string) $value)));
    }

    public function normalizeOrderBy($orderBy)
    {
        return (string) $orderBy === self::ORDER_BY_PATH ? self::ORDER_BY_PATH : self::ORDER_BY_NAME;
    }

    public function getSearchPath($path)
    {
        $path = (string) $path;
        if ($path === '/dashboard') {
            return $path;
        }

        if (str_starts_with($path, '/dashboard/')) {
            return substr($path, strlen('/dashboard')) ?: '/';
        }

        return $path;
    }

    public function preparePage(array $page)
    {
        $path = (string) ($page['path'] ?? '');
        $page['searchName'] = $this->normalizeText((string) ($page['name'] ?? ''));
        $page['searchPath'] = $this->getSearchPath($path);

        return $page;
    }

    public function getSearchValue(array $page, $property)
    {
        if ((string) $property === self::ORDER_BY_PATH) {
            return $this->normalizeText((string) ($page['searchPath'] ?? $this->getSearchPath((string) ($page['path'] ?? ''))));
        }

        return $this->normalizeText((string) ($page['searchName'] ?? $page['name'] ?? ''));
    }

    public function matchesPage(array $page, $query)
    {
        $query = $this->normalizeText($query);
        if ($query === '') {
            return false;
        }

        return str_contains($this->getSearchValue($page, self::ORDER_BY_NAME), $query)
            || str_contains($this->getSearchValue($page, self::ORDER_BY_PATH), $query);
    }

    public function filterPages(array $pages, $query, $orderBy)
    {
        $query = $this->normalizeText($query);
        if (strlen($query) < self::MIN_LENGTH) {
            return [];
        }

        $matches = [];
        foreach ($pages as $page) {
            if ($this->matchesPage($page, $query)) {
                $matches[] = $page;
            }
        }

        return $this->sortPages($matches, $orderBy, $query);
    }

    public function sortPages(array $pages, $orderBy, $query)
    {
        $primaryKey = $this->normalizeOrderBy($orderBy);
        $secondaryKey = $primaryKey === self::ORDER_BY_PATH ? self::ORDER_BY_NAME : self::ORDER_BY_PATH;
        $query = $this->normalizeText($query);

        usort($pages, function ($a, $b) use ($primaryKey, $secondaryKey, $query) {
            $aMatchesPrimary = $query !== '' && str_contains($this->getSearchValue($a, $primaryKey), $query);
            $bMatchesPrimary = $query !== '' && str_contains($this->getSearchValue($b, $primaryKey), $query);
            if ($aMatchesPrimary !== $bMatchesPrimary) {
                return $aMatchesPrimary ? -1 : 1;
            }

            $primaryComparison = strnatcasecmp($this->getSearchValue($a, $primaryKey), $this->getSearchValue($b, $primaryKey));
            if ($primaryComparison !== 0) {
                return $primaryComparison;
            }

            return strnatcasecmp($this->getSearchValue($a, $secondaryKey), $this->getSearchValue($b, $secondaryKey));
        });

        return $pages;
    }
}
