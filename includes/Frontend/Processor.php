<?php
namespace RemoveUnusedCSS\Frontend;

use RemoveUnusedCSS\Admin\Options\Options_Data;
use RemoveUnusedCSS\Database\Queries\UsedCSS;
use voku\helper\HtmlDomParser;
use Sabberworm\CSS\Parser as CSSParser;
use Sabberworm\CSS\CSSList\Document as CSSDocument;

class Processor {
    /**
     * Options instance
     *
     * @var Options_Data
     */
    private $options;

    /**
     * Used CSS query
     *
     * @var UsedCSS
     */
    private $used_css_query;

    /**
     * Background queue
     *
     * @var BackgroundQueue
     */
    private $queue;

    /**
     * Constructor
     *
     * @param Options_Data $options
     * @param UsedCSS $used_css_query
     */
    public function __construct(Options_Data $options, UsedCSS $used_css_query) {
        $this->options = $options;
        $this->used_css_query = $used_css_query;
        $this->queue = new BackgroundQueue();
        
        add_action('template_redirect', [$this, 'init_processing']);
    }

    /**
     * Initialize processing
     */
    public function init_processing() {
        if (!$this->should_process()) {
            return;
        }

        ob_start([$this, 'process_buffer']);
    }

    /**
     * Check if we should process the page
     *
     * @return bool
     */
    private function should_process() {
        // Don't process in admin or AJAX
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        // Check if feature is enabled
        if (!$this->options->get('enable_rucss', false)) {
            return false;
        }

        // Don't process for logged-in users by default
        if (is_user_logged_in()) {
            return false;
        }

        return true;
    }

    /**
     * Process the output buffer
     *
     * @param string $html
     * @return string
     */
    public function process_buffer($html) {
        if (empty($html)) {
            return $html;
        }

        // Check if we have a cached version
        $url = $this->get_current_url();
        $is_mobile = wp_is_mobile();
        $used_css = $this->used_css_query->get_by_url($url, $is_mobile);

        if ($used_css && 'completed' === $used_css->status) {
            return $this->apply_used_css($html, $used_css->css);
        }

        // Queue for processing if not already done
        $this->queue->push_to_queue([
            'url' => $url,
            'html' => $html,
            'is_mobile' => $is_mobile
        ]);
        $this->queue->save()->dispatch();

        return $html;
    }

    /**
     * Apply optimized CSS to HTML
     *
     * @param string $html
     * @param string $used_css
     * @return string
     */
    private function apply_used_css($html, $used_css) {
        $dom = HtmlDomParser::str_get_html($html);
        
        // Remove all stylesheets
        foreach ($dom->find('link[rel="stylesheet"]') as $link) {
            if ($this->is_excluded($link->href)) {
                continue;
            }
            $link->outertext = '';
        }

        // Remove inline styles
        foreach ($dom->find('style') as $style) {
            $style->outertext = '';
        }

        // Add our optimized CSS
        $head = $dom->find('head', 0);
        if ($head) {
            $head->innertext .= '<style id="rucs-optimized-css">' . $used_css . '</style>';
        }

        return $dom->save();
    }

    /**
     * Check if a CSS file is excluded
     *
     * @param string $url
     * @return bool
     */
    private function is_excluded($url) {
        $exclusions = explode("\n", $this->options->get('excluded_css', ''));
        $exclusions = array_map('trim', $exclusions);
        $exclusions = array_filter($exclusions);

        foreach ($exclusions as $exclusion) {
            if (false !== strpos($url, $exclusion)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get current URL
     *
     * @return string
     */
    private function get_current_url() {
        global $wp;
        return home_url($wp->request);
    }
}