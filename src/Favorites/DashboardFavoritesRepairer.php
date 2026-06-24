<?php

declare(strict_types=1);

namespace Concrete\Package\DashboardFavoritesManager\Favorites;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\Page\Page;
use Concrete\Package\DashboardFavoritesManager\Toolbar\ToolbarSettings;

class DashboardFavoritesRepairer
{
    private $app;

    private $config;

    private $favoritesService;

    private $toolbarSettings;

    private $repairVersion;

    private $repairConfigKey;

    public function __construct(
        $app,
        $config,
        DashboardFavoritesService $favoritesService,
        ToolbarSettings $toolbarSettings,
        $repairVersion,
        $repairConfigKey
    ) {
        $this->app = $app;
        $this->config = $config;
        $this->favoritesService = $favoritesService;
        $this->toolbarSettings = $toolbarSettings;
        $this->repairVersion = $repairVersion;
        $this->repairConfigKey = $repairConfigKey;
    }

    public function migrateLegacyToolbarSettingsToCurrentUser()
    {
        $this->toolbarSettings->migrateLegacySettingsToCurrentUser($this->config);
    }

    public function repair()
    {
        $db = $this->app->make('database')->connection();
        try {
            $rows = $db->fetchAllAssociative(
                'select cfValue, uID from ConfigStore where cfKey = ?',
                ['DASHBOARD_FAVORITES']
            );
        } catch (\Throwable $e) {
            // Dashboard favorites repair is best-effort.
            return;
        }

        $repaired = false;
        foreach ($rows as $row) {
            $value = (string) ($row['cfValue'] ?? '');
            $items = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($items)) {
                continue;
            }

            $normalizedItems = $this->favoritesService->normalizeItems($items);
            $page = Page::getByPath($this->favoritesService->getManagerPath());
            if ($page instanceof Page && !$page->isError()) {
                $found = false;
                $normalizedItems = $this->favoritesService->mergeDashboardFavoritesManagerFavoriteItems(
                    $normalizedItems,
                    $this->favoritesService->getDashboardFavoritesManagerFavoriteItem($page),
                    $found
                );
            }

            $normalized = json_encode($normalizedItems);
            if ($normalized === $value) {
                continue;
            }

            $db->executeStatement(
                'update ConfigStore set cfValue = ? where cfKey = ? and uID = ?',
                [$normalized, 'DASHBOARD_FAVORITES', (int) $row['uID']]
            );
            $repaired = true;
        }

        if ($repaired) {
            $this->favoritesService->clearFavoritesCache();
        }
    }

    public function repairOnce()
    {
        if ((string) $this->config->get($this->repairConfigKey) === (string) $this->repairVersion) {
            return;
        }

        $this->repair();
        $this->markDone();
    }

    public function markDone()
    {
        $this->config->save(
            $this->repairConfigKey,
            $this->repairVersion
        );
    }
}
