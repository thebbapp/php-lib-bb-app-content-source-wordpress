<?php

declare(strict_types=1);

namespace BbApp\ContentSource\WordPress;

use BbApp\ContentSource\WordPressBase\WordPressBaseContentSource;
use WP_Post, WP_Comment, WP_Term, WP_REST_Server, WP_REST_Request, WP_REST_Response;
use UnexpectedValueException;

/**
 * WordPress content source implementation for posts, comments, and categories.
 *
 * @var WordPressTermResolver $term_resolver
 */
class WordPressContentSource extends WordPressBaseContentSource
{
	public $id = 'wordpress';

	private $term_resolver;

	/**
	 * Initializes WordPress content source with entity types and capabilities.
	 */
	public function __construct()
	{
		$this->capabilities = [
			'section' => ['view' => 'read_post', 'post' => 'publish_posts'],
			'post' => ['view' => 'read_post', 'edit' => 'edit_post', 'comment' => 'edit_posts'],
			'comment' => ['edit' => 'edit_comment']
		];

		$this->entity_types = [
			'section' => 'category',
			'post' => 'post',
			'comment' => 'comment'
		];

		parent::__construct();

		$this->term_resolver = new WordPressTermResolver($this->callbacks);
	}

	/**
	 * Validates expected content IDs and adds rejection headers for missing items.
	 */
	public function rest_post_dispatch(
		$response,
		WP_REST_Server $server,
		WP_REST_Request $request
	): WP_REST_Response {
		$outer_request = $this->get_current_request();

		if (
			str_starts_with($outer_request->get_route(), '/batch/v1') &&
			!str_starts_with($request->get_route(), '/batch/v1')
		) {
			return $response;
		}

		global $wpdb;

		$categoryIds = $this->parse_json_int_array($request->get_header('Bb-App-Expects-WordPress-Category-Ids'));
		$postIds = $this->parse_json_int_array($request->get_header('Bb-App-Expects-WordPress-Post-Ids'));
		$commentIds = $this->parse_json_int_array($request->get_header('Bb-App-Expects-WordPress-Comment-Ids'));

		if (!empty($categoryIds)) {
			$placeholders = $this->build_in_placeholders($categoryIds);

			$sql = $wpdb->prepare(
				"SELECT DISTINCT tt.term_id FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id IN ({$placeholders}) AND tt.taxonomy = 'category'",
				$categoryIds
			);

			$found = array_map('intval', $wpdb->get_col($sql));
			$rejects = array_values(array_diff($categoryIds, $found));

			if (!empty($rejects)) {
				$response->header('Bb-App-Rejects-WordPress-Category-Ids', wp_json_encode($rejects));
			}
		}

		if (!empty($postIds)) {
			$placeholders = $this->build_in_placeholders($postIds);

			$sql = $wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p WHERE p.ID IN ({$placeholders}) AND p.post_type = 'post' AND p.post_status = 'publish'",
				$postIds
			);

			$found = array_map('intval', $wpdb->get_col($sql));
			$rejects = array_values(array_diff($postIds, $found));

			if (!empty($rejects)) {
				$response->header('Bb-App-Rejects-WordPress-Post-Ids', wp_json_encode($rejects));
			}
		}

		if (!empty($commentIds)) {
			$placeholders = $this->build_in_placeholders($commentIds);

			$sql = $wpdb->prepare(
				"SELECT DISTINCT c.comment_ID FROM {$wpdb->comments} c WHERE c.comment_ID IN ({$placeholders}) AND c.comment_approved = '1'",
				$commentIds
			);

			$found = array_map('intval', $wpdb->get_col($sql));
			$rejects = array_values(array_diff($commentIds, $found));

			if (!empty($rejects)) {
				$response->header('Bb-App-Rejects-WordPress-Comment-Ids', wp_json_encode($rejects));
			}
		}

		return $response;
	}

	/**
	 * Retrieves WordPress term, post, or comment by content type and ID.
	 */
	public function get_content(string $content_type, int $id)
	{
		switch ($content_type) {
			case 'section':
				return get_term($id);
			case 'post':
				return get_post($id);
			case 'comment':
				return get_comment($id);
			default:
				throw new UnexpectedValueException();
		}
	}

	/**
	 * Determines content type from WordPress object instance.
	 */
	public function get_content_type($object): string
	{
		if ($object instanceof WP_Term) {
			return 'section';
		} else if ($object instanceof WP_Post) {
			return 'post';
		} else if ($object instanceof WP_Comment) {
			return 'comment';
		} else {
			throw new UnexpectedValueException();
		}
	}

	/**
	 * Gets permalink for WordPress content by type and ID.
	 */
	public function get_link(string $content_type, int $id): string
	{
		switch ($content_type) {
			case 'section':
				return get_category_link($id) ?: '';
			case 'post':
				return get_permalink($id) ?: '';
			case 'comment':
				return get_comment_link($id) ?: '';
			default:
				throw new UnexpectedValueException();
		}
	}

	/**
	 * Resolves WordPress URL to content type and ID.
	 */
	public function resolve_incoming_url(string $url): ?array
	{
		if (!$this->callbacks->url_match_checker($url)) {
			return null;
		}

		$post_id = url_to_postid($url);

		if ($post_id > 0) {
			$post = get_post($post_id);

			if ($post->post_type === $this->get_entity_types('post')) {
				if (
					($fragment = parse_url($url, PHP_URL_FRAGMENT)) &&
					preg_match('/^comment-(\d+)$/', $fragment, $comment_match)
				) {
					$comment_id = (int) $comment_match[1];

					if ($comment_id > 0 && get_comment($comment_id)) {
						return ['content_type' => 'comment', 'id' => $comment_id];
					}
				}

				return ['content_type' => 'post', 'id' => $post_id];
			}
		}

		if (($term = $this->term_resolver->get_term_by_path($url))) {
			return ['content_type' => 'section', 'id' => $term->term_id];
		}

		if (($term = $this->term_resolver->get_term_by_query_params($url))) {
			return ['content_type' => 'section', 'id' => $term->term_id];
		}

		return null;
	}

	/**
	 * Checks if user has permission for action on WordPress content.
	 */
	public function user_can(
		int $user_id,
		string $intent,
		string $content_type,
		int $content_id
	): bool {
		if ($content_type === 'section' && $intent === 'view') {
			return true;
		}

		if ($content_type === 'post' && $intent === 'comment') {
			$post = get_post($content_id);

			if (empty($post)) {
				return false;
			}

			if (!comments_open($post) && !current_user_can('edit_post', $content_id)) {
				return false;
			}
		}

		if (
			$user_id === 0 &&
			$content_type === 'post' &&
			$intent === 'comment' &&
			!get_option('comment_registration')
		) {
			return true;
		}

		if (
			$content_type === 'post' &&
			$intent === 'view'
		) {
			$post = get_post($content_id);

			if (!$post) {
				return false;
			}

			return ($post->post_status === 'publish') ||
				current_user_can('read_post', $post->ID);
		}

		if (
			$content_type === 'comment' &&
			$intent === 'view'
		) {
			$comment = get_comment($content_id);

			if (!$comment) {
				return false;
			}

			return ($comment->comment_approved === '1') ||
				current_user_can('edit_comment', $comment->comment_ID);
		}

		return parent::user_can($user_id, $intent, $content_type, $content_id);
	}

	/**
	 * Gets configured root category ID from WordPress options.
	 */
	public function get_root_section_id(): int
	{
		return (int) get_option('bb_app_wordpress_root_section_id', '0');
	}

	/**
	 * Gets parent ID of the configured root category.
	 */
	public function get_root_parent_id(): int
	{
		$root_section_id = $this->get_root_section_id();

		if (empty($root_section_id)) {
			return -1;
		}

		$root_parent_id = get_term_field('parent', $root_section_id, 'category');

		if (empty($root_parent_id) || is_wp_error($root_parent_id)) {
			return -1;
		}

		return (int) $root_parent_id;
	}

	public function register(): void
	{
		parent::register();

		add_filter('rest_prepare_post', [$this, 'rest_prepare_post'], 20, 3);
		add_filter('rest_prepare_comment', [$this, 'rest_prepare_comment'], 20, 3);
		add_filter('rest_post_dispatch', [$this, 'rest_post_dispatch'], 100, 3);
	}
}
