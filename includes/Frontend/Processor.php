<?php
namespace RemoveUnusedCSS\Frontend;

use RemoveUnusedCSS\Admin\Options\Options_Data;
use RemoveUnusedCSS\Database\Queries\UsedCSS;
use voku\helper\HtmlDomParser;
use Sabberworm\CSS\Parser as CSSParser;
use Sabberworm\CSS\CSSList\Document as CSSDocument;
use Sabberworm\CSS\RuleSet\DeclarationBlock;
use Sabberworm\CSS\Property\Selector;

class Processor {
    private $options;
    private $used_css_query;
    private $queue;
    private $cache_dir;

    public function __construct(Options_Data $options, UsedCSS $used_css_query) {
        $this->options = $options;
        $this->used_css_query = $used_css_query;
        $this->queue = new BackgroundQueue();
        $this->cache_dir = WP_CONTENT_DIR . '/cache/remove-unused-css/';
        
        // Ensure cache directory exists
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }
        
        add_action('template_redirect', [$this, 'init_processing']);
    }

    public function init_processing() {
        if (!$this->should_process()) {
            return;
        }

        ob_start([$this, 'process_buffer']);
    }

    private function should_process() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }

        if (!$this->options->get('enable_rucss', false)) {
            return false;
        }

        // Don't process if it's a POST request
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            return false;
        }

        return true;
    }

    public function process_buffer($html) {
        if (empty($html)) {
            return $html;
        }

        try {
            $url = $this->get_current_url();
            $is_mobile = wp_is_mobile();
            $used_css = $this->used_css_query->get_by_url($url, $is_mobile);

            if ($used_css && 'completed' === $used_css->status && !empty($used_css->css)) {
                return $this->apply_used_css($html, $used_css->css);
            }

            // Process immediately instead of queuing
            $optimized_css = $this->process_html_immediately($html);
            
            if ($optimized_css) {
                // Save to database
                $this->used_css_query->update_or_insert(
                    [
                        'url' => $url,
                        'is_mobile' => $is_mobile
                    ],
                    [
                        'css' => $optimized_css,
                        'status' => 'completed',
                        'hash' => md5($optimized_css),
                        'modified' => current_time('mysql')
                    ]
                );

                return $this->apply_used_css($html, $optimized_css);
            }
        } catch (\Exception $e) {
            error_log('RemoveUnusedCSS Error: ' . $e->getMessage());
        }

        return $html;
    }

    private function process_html_immediately($html) {
        try {
            $dom = HtmlDomParser::str_get_html(
                $html,
                true,
                true,
                'UTF-8',
                false
            );
            
            if (!$dom) {
                return false;
            }

            $used_selectors = $this->extract_used_selectors($dom);
            $combined_css = '';

            // Process external stylesheets
            foreach ($dom->find('link[rel="stylesheet"]') as $link) {
                if (!$this->is_excluded($link->href)) {
                    $css_content = $this->fetch_stylesheet($link->href);
                    if ($css_content) {
                        $combined_css .= $this->process_css_content($css_content, $used_selectors);
                    }
                }
            }

            // Process inline styles
            foreach ($dom->find('style') as $style) {
                if (!empty($style->innertext)) {
                    $combined_css .= $this->process_css_content($style->innertext, $used_selectors);
                }
            }

            return $combined_css;
        } catch (\Exception $e) {
            error_log('RemoveUnusedCSS Processing Error: ' . $e->getMessage());
            return false;
        }
    }

    private function process_css_content($css_content, $used_selectors) {
        try {
            $parser = new CSSParser($css_content);
            $css_doc = $parser->parse();
            $this->remove_unused_rules($css_doc, $used_selectors);
            return $css_doc->render();
        } catch (\Exception $e) {
            error_log('RemoveUnusedCSS CSS Parse Error: ' . $e->getMessage());
            return '';
        }
    }

    private function extract_used_selectors($dom) {
        $selectors = [];

        // Extract classes
        foreach ($dom->find('*[class]') as $element) {
            $classes = explode(' ', $element->getAttribute('class'));
            foreach ($classes as $class) {
                if ($class = trim($class)) {
                    $selectors['.' . $class] = true;
                }
            }
        }

        // Extract IDs
        foreach ($dom->find('*[id]') as $element) {
            if ($id = trim($element->getAttribute('id'))) {
                $selectors['#' . $id] = true;
            }
        }

        // Extract element types
        foreach ($dom->find('*') as $element) {
            $selectors[$element->tag] = true;
        }

        // Add common pseudo-classes and states
        $base_selectors = array_keys($selectors);
        foreach ($base_selectors as $selector) {
            $selectors[$selector . ':hover'] = true;
            $selectors[$selector . ':active'] = true;
            $selectors[$selector . ':focus'] = true;
            $selectors[$selector . ':visited'] = true;
        }

        return array_keys($selectors);
    }

    private function remove_unused_rules($css_doc, $used_selectors) {
        if (!method_exists($css_doc, 'getAllDeclarationBlocks')) {
            return;
        }

        $blocks = $css_doc->getAllDeclarationBlocks();
        foreach ($blocks as $block) {
            if (!($block instanceof DeclarationBlock)) {
                continue;
            }

            $keep = false;
            $selectors = $block->getSelectors();
            
            foreach ($selectors as $selector) {
                if ($this->is_selector_used($selector, $used_selectors)) {
                    $keep = true;
                    break;
                }
            }
            
            if (!$keep && method_exists($block, 'getParent') && method_exists($block->getParent(), 'remove')) {
                $block->getParent()->remove($block);
            }
        }
    }

    private function is_selector_used($selector, $used_selectors) {
        $selector_string = $selector->getSelector();
        
        // Check direct match
        if (in_array($selector_string, $used_selectors)) {
            return true;
        }

        // Check each part of compound selectors
        $parts = explode(' ', $selector_string);
        foreach ($parts as $part) {
            $part = trim($part);
            if (in_array($part, $used_selectors)) {
                return true;
            }
        }

        return false;
    }

    private function apply_used_css($html, $used_css) {
    try {
        $dom = HtmlDomParser::str_get_html(
            $html,
            true,
            true,
            'UTF-8',
            false
        );
        
        if (!$dom) {
            return $html;
        }

        // Remove existing stylesheets
        foreach ($dom->find('link[rel="stylesheet"]') as $link) {
            if (!$this->is_excluded($link->href)) {
                $link->outertext = '';
            }
        }

        // Remove inline styles
        foreach ($dom->find('style') as $style) {
            if (!$this->is_excluded('inline')) {
                $style->outertext = '';
            }
        }

        // Add optimized CSS after the title tag
        $title = $dom->find('title', 0);
        if ($title) {
            $style_html = '<style id="rucs-optimized-css" type="text/css">' . $used_css . '</style>';
            $title->outertext = $title->outertext . $style_html;
        } else {
            // If no title tag, add to head
            $head = $dom->find('head', 0);
            if ($head) {
                $style_html = '<style id="rucs-optimized-css" type="text/css">' . $used_css . '</style>';
                $head->innertext = $style_html . $head->innertext;
            }
        }

        return $dom->save();
    } catch (\Exception $e) {
        error_log('RemoveUnusedCSS Apply CSS Error: ' . $e->getMessage());
        return $html;
    }
}


    private function fetch_stylesheet($url) {
        if (strpos($url, '//') === 0) {
            $url = 'https:' . $url;
        }

        $response = wp_remote_get($url);
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            return wp_remote_retrieve_body($response);
        }

        return false;
    }

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

    private function get_current_url() {
        global $wp;
        return home_url($wp->request);
    }
}