<?php
namespace RemoveUnusedCSS\Frontend;

class Filesystem {
    /**
     * Cache directory
     *
     * @var string
     */
    private $cache_dir;

    public function __construct() {
        $this->cache_dir = WP_CONTENT_DIR . '/cache/remove-unused-css/';
    }

    public function clear_cache() {
        if (!file_exists($this->cache_dir)) {
            return true;
        }

        $files = glob($this->cache_dir . '*.css');
        $success = true;

        foreach ($files as $file) {
            if (!unlink($file)) {
                $success = false;
            }
        }

        return $success;
    }

    public function get_cache_size() {
        if (!file_exists($this->cache_dir)) {
            return '0 MB';
        }

        $size = 0;
        $files = glob($this->cache_dir . '*.css');

        foreach ($files as $file) {
            $size += filesize($file);
        }

        return size_format($size);
    }

    public function maybe_create_cache_dir() {
        if (!file_exists($this->cache_dir)) {
            return wp_mkdir_p($this->cache_dir);
        }
        return true;
    }
}