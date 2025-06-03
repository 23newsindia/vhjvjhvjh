<?php
namespace RemoveUnusedCSS\Manager;

use RemoveUnusedCSS\Database\Queries\UsedCSS;
use RemoveUnusedCSS\Frontend\Filesystem;

class CacheManager {
    /**
     * Used CSS query
     *
     * @var UsedCSS
     */
    private $used_css_query;

    /**
     * Filesystem handler
     *
     * @var Filesystem
     */
    private $filesystem;

    /**
     * Constructor
     *
     * @param UsedCSS $used_css_query
     * @param Filesystem $filesystem
     */
    public function __construct(UsedCSS $used_css_query, Filesystem $filesystem) {
        $this->used_css_query = $used_css_query;
        $this->filesystem = $filesystem;
    }

    /**
     * Clear all cached CSS
     *
     * @return bool
     */
    public function clear_all_cache() {
        // Clear database entries
        $result = $this->used_css_query->query([
            'status' => 'completed',
            'fields' => 'ids'
        ]);

        if (!empty($result)) {
            foreach ($result as $id) {
                $this->used_css_query->delete_item($id);
            }
        }

        // Clear filesystem cache
        return $this->filesystem->clear_cache();
    }

    /**
     * Clear cache for specific URL
     *
     * @param string $url
     * @return bool
     */
    public function clear_url_cache($url) {
        $items = $this->used_css_query->query([
            'url' => $url,
            'fields' => 'ids'
        ]);

        if (empty($items)) {
            return false;
        }

        foreach ($items as $id) {
            $this->used_css_query->delete_item($id);
        }

        return true;
    }

    /**
     * Get cache stats
     *
     * @return array
     */
    public function get_stats() {
        return [
            'total' => $this->used_css_query->count(),
            'completed' => $this->used_css_query->get_completed_count(),
            'pending' => $this->used_css_query->query([
                'status' => 'pending',
                'count' => true
            ]),
            'size' => $this->filesystem->get_cache_size()
        ];
    }
}