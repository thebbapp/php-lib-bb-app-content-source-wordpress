<?php

declare(strict_types=1);

namespace BbApp\ContentSource\WordPress;

use BbApp\ContentSource\ContentSourceCallbacks;

/**
 * Resolves WordPress category terms from URLs and query parameters.
 */
class WordPressTermResolver
{
    protected $callbacks;

	/**
	 * Initializes resolver with URL matching callbacks.
	 */
    public function __construct(ContentSourceCallbacks $callbacks)
    {
        $this->callbacks = $callbacks;
    }

	/**
	 * Resolves category term from URL path segments.
	 */
    public function get_term_by_path(string $url)
    {
        if (!$this->callbacks->url_match_checker($url)) {
            return null;
        }

        $rawPath = wp_parse_url($url, PHP_URL_PATH);

        if (!is_string($rawPath) || $rawPath === '' || $rawPath === '/') {
            return null;
        }

        $segments = $this->get_path_segments_without_home($url);
        $segments = $this->strip_prefix_segments($segments, $this->get_category_base_segments());
        return $this->resolve_parent_child_terms($segments);
    }

	/**
	 * Resolves category term from URL query parameters.
	 */
    public function get_term_by_query_params(string $url)
    {
        if (!$this->callbacks->url_match_checker($url)) {
            return null;
        }

        parse_str(wp_parse_url($url, PHP_URL_QUERY), $query_params);

        if (!empty($query_params['cat'])) {
            $cat_id = absint($query_params['cat']);

            if ($cat_id > 0) {
                $term = get_term($cat_id, 'category');

                if ($term && !is_wp_error($term)) {
                    return $term;
                }
            }
        }

        if (!empty($query_params['category_name'])) {
            $category_name = sanitize_text_field($query_params['category_name']);
            $segments = array_values(array_filter(explode('/', trim(urldecode($category_name), '/'))));
            return $this->resolve_parent_child_terms($segments);
        }

        return null;
    }

	/**
	 * Extracts URL path segments excluding home path prefix.
	 */
    private function get_path_segments_without_home(string $url): array
    {
        if (!$this->callbacks->url_match_checker($url)) {
            return [];
        }

        $path = wp_parse_url($url, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return [];
        }

        $home_path = parse_url(home_url(), PHP_URL_PATH);

        if (is_string($home_path) && $home_path !== '/' && str_starts_with($path, $home_path)) {
            $path = substr($path, strlen($home_path));
        }

        $trimmed = trim($path, '/');

        if ($trimmed === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $trimmed)));
    }

	/**
	 * Gets configured category base path segments.
	 */
    private function get_category_base_segments(): array
    {
        $category_base = (string) get_option('category_base');

        if ($category_base === '') {
            $category_base = 'category';
        }

        return array_values(array_filter(explode('/', trim($category_base, '/'))));
    }

	/**
	 * Removes prefix segments from path if they match.
	 */
    private function strip_prefix_segments(array $segments, array $prefix): array
    {
        $prefixCount = count($prefix);

        if ($prefixCount === 0 || count($segments) < $prefixCount) {
            return $segments;
        }

        for ($i = 0; $i < $prefixCount; $i++) {
            if ($segments[$i] !== $prefix[$i]) {
                return $segments;
            }
        }

        return array_slice($segments, $prefixCount);
    }

	/**
	 * Looks up category term by URL slug.
	 */
    private function lookup_category_by_slug(string $slug)
    {
        $slug = urldecode($slug);

        if ($slug === '') {
            return null;
        }

        $term = get_term_by('slug', $slug, 'category');
        return ($term && !is_wp_error($term)) ? $term : null;
    }

	/**
	 * Resolves category term from path segments considering parent-child hierarchy.
	 */
    private function resolve_parent_child_terms(array $segments)
    {
        $count = count($segments);

        if ($count >= 2) {
            $parent_slug = $segments[$count - 2];
            $child_slug = $segments[$count - 1];
            $child_term = $this->lookup_category_by_slug($child_slug);

            if ($child_term && $child_term->parent > 0) {
                $actual_parent = get_term($child_term->parent, 'category');

                if ($actual_parent && !is_wp_error($actual_parent) && $actual_parent->slug === urldecode($parent_slug)) {
                    return $child_term;
                }
            }

            $parent_term = $this->lookup_category_by_slug($parent_slug);

            if ($parent_term) {
                return $parent_term;
            }
        } elseif ($count === 1) {
            $only_term = $this->lookup_category_by_slug((string) $segments[0]);

            if ($only_term) {
                return $only_term;
            }
        }

        return null;
    }
}
