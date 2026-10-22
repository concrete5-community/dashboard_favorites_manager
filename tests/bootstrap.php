<?php

declare(strict_types=1);

namespace {
    if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) {
        http_response_code(404);
        exit;
    }
}

namespace Concrete\Core\Page {
    class Page
    {
        public static $pages = [];
        private $id;
        private $path;
        public $visible;
        public static $idReads = 0;
        public static $pathReads = 0;

        public function __construct($id = 0, $path = '', $visible = true)
        {
            $this->id = $id;
            $this->path = $path;
            $this->visible = $visible;
        }

        public static function getByID($id)
        {
            self::$idReads++;
            return self::$pages[$id] ?? new self();
        }
        public static function getByPath($path)
        {
            self::$pathReads++;
            foreach (self::$pages as $page) {
                if ($page->path === $path) {
                    return $page;
                }
            }
            return new self();
        }
        public function isError() { return $this->id === 0; }
        public function getCollectionID() { return $this->id; }
        public function getCollectionPath() { return $this->path; }
        public function getCollectionName() { return 'Page ' . $this->id; }
    }
}

namespace Concrete\Core\User {
    class User
    {
        public static $items = [];
        public static $registered = true;
        public static $rawConfig;
        public static $writes = 0;
        public function isRegistered() { return self::$registered; }
        public function getUserID() { return self::$registered ? 1 : 0; }
        public function config($key) { return self::$rawConfig ?? json_encode(self::$items); }
        public function saveConfig($key, $value)
        {
            self::$writes++;
            self::$items = json_decode($value, true);
        }
    }
}

namespace {
    define('C5_EXECUTE', true);
    define('DIR_REL', getenv('DFM_TEST_BASE_PATH') ?: '');

    class URL
    {
        public static function to($path) { return 'https://example.test' . DIR_REL . '/index.php' . $path; }
    }
    class Permissions
    {
        private $page;
        public function __construct($page) { $this->page = $page; }
        public function canViewPage()
        {
            if ($this->page->visible === 'throw') {
                throw new \RuntimeException('Permission check failed');
            }
            return $this->page->visible;
        }
    }
    function t($message, ...$args) { return $args ? vsprintf($message, $args) : $message; }
    function check($condition, $message)
    {
        if (!$condition) {
            throw new \RuntimeException($message);
        }
    }

    require __DIR__ . '/../src/Favorites/DashboardFavoriteNormalizer.php';
    require __DIR__ . '/../src/Favorites/DashboardFavoritesService.php';

}
