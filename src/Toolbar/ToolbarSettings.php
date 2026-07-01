<?php

declare(strict_types=1);

namespace Concrete\Package\DashboardFavoritesManager\Toolbar;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\User\User;
use Concrete\Package\DashboardFavoritesManager\Search\DashboardPageSearch;

class ToolbarSettings
{
    public const SEARCH_MAX_RESULTS_DEFAULT = 15;

    public const SEARCH_MAX_RESULTS_MIN = 1;

    public const SEARCH_MAX_RESULTS_MAX = 50;

    public const SEARCH_MIN_LENGTH = DashboardPageSearch::MIN_LENGTH;

    private const USER_CONFIG_TOOLBAR_ENABLED = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_ENABLED';

    private const USER_CONFIG_TOOLBAR_SEARCH_ENABLED = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_SEARCH_ENABLED';

    private const USER_CONFIG_TOOLBAR_SEARCH_MAX_RESULTS = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_SEARCH_MAX_RESULTS';

    private const USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_CLEAR_CACHE_ENABLED';

    private const USER_CONFIG_TOOLBAR_LOGOUT_ENABLED = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_LOGOUT_ENABLED';

    private const USER_CONFIG_TOOLBAR_CONCRETE_VERSION_ENABLED = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_CONCRETE_VERSION_ENABLED';

    private const USER_CONFIG_KEYS = [
        self::USER_CONFIG_TOOLBAR_ENABLED,
        self::USER_CONFIG_TOOLBAR_SEARCH_ENABLED,
        self::USER_CONFIG_TOOLBAR_SEARCH_MAX_RESULTS,
        self::USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED,
        self::USER_CONFIG_TOOLBAR_LOGOUT_ENABLED,
        self::USER_CONFIG_TOOLBAR_CONCRETE_VERSION_ENABLED,
    ];

    public function isFavoritesEnabled()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return false;
        }

        return (int) $user->config(self::USER_CONFIG_TOOLBAR_ENABLED) === 1;
    }

    public function setFavoritesEnabled($enabled)
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return;
        }

        $user->saveConfig(self::USER_CONFIG_TOOLBAR_ENABLED, $enabled ? 1 : 0);
    }

    public function isSearchEnabled()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return false;
        }

        $value = $user->config(self::USER_CONFIG_TOOLBAR_SEARCH_ENABLED);

        return $value === null ? true : (int) $value === 1;
    }

    public function setSearchEnabled($enabled)
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return;
        }

        $user->saveConfig(self::USER_CONFIG_TOOLBAR_SEARCH_ENABLED, $enabled ? 1 : 0);
    }

    public function getSearchMaxResults()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return self::SEARCH_MAX_RESULTS_DEFAULT;
        }

        return $this->normalizeSearchMaxResults($user->config(self::USER_CONFIG_TOOLBAR_SEARCH_MAX_RESULTS));
    }

    public function setSearchMaxResults($maxResults)
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return;
        }

        $user->saveConfig(
            self::USER_CONFIG_TOOLBAR_SEARCH_MAX_RESULTS,
            $this->normalizeSearchMaxResults($maxResults)
        );
    }

    public function isClearCacheEnabled()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return false;
        }

        $value = $user->config(self::USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED);

        return $value === null ? true : (int) $value === 1;
    }

    public function setClearCacheEnabled($enabled)
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return;
        }

        $user->saveConfig(self::USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED, $enabled ? 1 : 0);
    }

    public function isLogoutEnabled()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return false;
        }

        $value = $user->config(self::USER_CONFIG_TOOLBAR_LOGOUT_ENABLED);

        return $value === null ? true : (int) $value === 1;
    }

    public function setLogoutEnabled($enabled)
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return;
        }

        $user->saveConfig(self::USER_CONFIG_TOOLBAR_LOGOUT_ENABLED, $enabled ? 1 : 0);
    }

    public function isConcreteVersionEnabled()
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return false;
        }

        return (int) $user->config(self::USER_CONFIG_TOOLBAR_CONCRETE_VERSION_ENABLED) === 1;
    }

    public function setConcreteVersionEnabled($enabled)
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return;
        }

        $user->saveConfig(self::USER_CONFIG_TOOLBAR_CONCRETE_VERSION_ENABLED, $enabled ? 1 : 0);
    }

    public function enableDefaultsForUser(User $user)
    {
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_ENABLED, 1);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_SEARCH_ENABLED, 1);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_SEARCH_MAX_RESULTS, self::SEARCH_MAX_RESULTS_DEFAULT);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED, 1);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_LOGOUT_ENABLED, 1);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_CONCRETE_VERSION_ENABLED, 1);
    }

    public function clearAllUserSettings($app)
    {
        $db = $app->make('database')->connection();
        $placeholders = implode(', ', array_fill(0, count(self::USER_CONFIG_KEYS), '?'));

        $db->executeStatement(
            'delete from ConfigStore where cfKey in (' . $placeholders . ')',
            self::USER_CONFIG_KEYS
        );
    }

    public function migrateLegacySettingsToCurrentUser($config)
    {
        $user = new User();
        if (!$user->isRegistered()) {
            return;
        }

        if ($user->config(self::USER_CONFIG_TOOLBAR_ENABLED) !== null) {
            return;
        }

        $legacyToolbarEnabled = $config->get('toolbar.enabled');
        if ((int) $legacyToolbarEnabled !== 1) {
            return;
        }

        $user->saveConfig(self::USER_CONFIG_TOOLBAR_ENABLED, 1);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_SEARCH_ENABLED, 1);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_SEARCH_MAX_RESULTS, self::SEARCH_MAX_RESULTS_DEFAULT);

        $legacyClearCacheEnabled = $config->get('toolbar.clear_cache.enabled');
        $user->saveConfig(
            self::USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED,
            $legacyClearCacheEnabled === null || (int) $legacyClearCacheEnabled === 1 ? 1 : 0
        );
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_LOGOUT_ENABLED, 1);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_CONCRETE_VERSION_ENABLED, 0);
    }

    private function normalizeSearchMaxResults($maxResults)
    {
        if ($maxResults === null || $maxResults === '') {
            return self::SEARCH_MAX_RESULTS_DEFAULT;
        }

        $maxResults = filter_var($maxResults, FILTER_VALIDATE_INT);
        if ($maxResults === false) {
            return self::SEARCH_MAX_RESULTS_DEFAULT;
        }

        if ($maxResults < self::SEARCH_MAX_RESULTS_MIN) {
            return self::SEARCH_MAX_RESULTS_MIN;
        }
        if ($maxResults > self::SEARCH_MAX_RESULTS_MAX) {
            return self::SEARCH_MAX_RESULTS_MAX;
        }

        return $maxResults;
    }
}
