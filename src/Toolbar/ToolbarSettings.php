<?php

namespace Concrete\Package\DashboardFavoritesManager\Toolbar;

defined('C5_EXECUTE') or die('Access Denied.');

use Concrete\Core\User\User;

class ToolbarSettings
{
    private const USER_CONFIG_TOOLBAR_ENABLED = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_ENABLED';
    private const USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_CLEAR_CACHE_ENABLED';
    private const USER_CONFIG_TOOLBAR_LOGOUT_ENABLED = 'DASHBOARD_FAVORITES_MANAGER_TOOLBAR_LOGOUT_ENABLED';

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

    public function enableDefaultsForUser(User $user)
    {
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_ENABLED, 1);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED, 1);
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_LOGOUT_ENABLED, 1);
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

        $legacyClearCacheEnabled = $config->get('toolbar.clear_cache.enabled');
        $user->saveConfig(
            self::USER_CONFIG_TOOLBAR_CLEAR_CACHE_ENABLED,
            $legacyClearCacheEnabled === null || (int) $legacyClearCacheEnabled === 1 ? 1 : 0
        );
        $user->saveConfig(self::USER_CONFIG_TOOLBAR_LOGOUT_ENABLED, 1);
    }
}
