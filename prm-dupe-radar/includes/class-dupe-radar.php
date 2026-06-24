<?php
/**
 * Duplicate detection service.
 *
 * @package PRMDupeRadar
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds duplicate posts across post types.
 */
class PDR_Dupe_Radar {

	public const MATCH_TITLE      = 'title';
	public const MATCH_SLUG       = 'slug';
	public const MATCH_TITLE_SLUG = 'title_slug';

	private const CACHE_GROUP = 'prm_dupe_radar';

	/**
	 * Post types excluded from duplicate scans.
	 *
	 * @var string[]
	 */
	private const EXCLUDED_POST_TYPES = array(
		'revision',
		'nav_menu_item',
		'custom_css',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'attachment',
	);

	/**
	 * Post statuses included in duplicate scans.
	 *
	 * @var string[]
	 */
	private const INCLUDED_STATUSES = array(
		'publish',
		'future',
		'draft',
		'pending',
		'private',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'save_post', array( $this, 'clear_cache' ) );
		add_action( 'deleted_post', array( $this, 'clear_cache' ) );
		add_action( 'trashed_post', array( $this, 'clear_cache' ) );
	}

	/**
	 * Clear cached duplicate scan results.
	 */
	public function clear_cache(): void {
		wp_cache_delete( 'post_types', self::CACHE_GROUP );
	}

	/**
	 * Get scannable public post types.
	 *
	 * @return array<string, string> Post type slug => label.
	 */
	public function get_post_types(): array {
		$cached = wp_cache_get( 'post_types', self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$post_types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);

		$options = array();

		foreach ( $post_types as $post_type ) {
			if ( in_array( $post_type->name, self::EXCLUDED_POST_TYPES, true ) ) {
				continue;
			}

			$options[ $post_type->name ] = $post_type->labels->singular_name;
		}

		asort( $options );

		wp_cache_set( 'post_types', $options, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $options;
	}

	/**
	 * Find duplicate groups.
	 *
	 * @param array<string, mixed> $args Scan arguments.
	 * @return array<string, mixed>
	 */
	public function find_duplicates( array $args = array() ): array {
		$defaults = array(
			'post_type'  => 'all',
			'match_by'   => self::MATCH_TITLE,
			'post_types' => array(),
		);

		$args = wp_parse_args( $args, $defaults );

		$post_types = $this->resolve_post_types( (string) $args['post_type'], (array) $args['post_types'] );

		if ( empty( $post_types ) ) {
			return $this->empty_results();
		}

		$match_by  = $this->sanitize_match_by( (string) $args['match_by'] );
		$cache_key = 'scan_' . md5( wp_json_encode( array( $post_types, $match_by ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post_types,
				'post_status'            => self::INCLUDED_STATUSES,
				'posts_per_page'         => -1,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => true,
				'fields'                 => 'all',
			)
		);

		$grouped_posts = array();

		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$match_key = $this->get_match_key( $post, $match_by );

			if ( '' === $match_key ) {
				continue;
			}

			if ( ! isset( $grouped_posts[ $match_key ] ) ) {
				$grouped_posts[ $match_key ] = array(
					'match_value' => $this->get_match_value( $post, $match_by ),
					'posts'       => array(),
				);
			}

			$grouped_posts[ $match_key ]['posts'][] = $this->format_post_result( $post );
		}

		$groups      = array();
		$total_posts = 0;

		foreach ( $grouped_posts as $match_key => $group_data ) {
			if ( count( $group_data['posts'] ) < 2 ) {
				continue;
			}

			$groups[] = array(
				'key'         => $match_key,
				'match_value' => $group_data['match_value'],
				'match_by'    => $match_by,
				'count'       => count( $group_data['posts'] ),
				'posts'       => $group_data['posts'],
			);

			$total_posts += count( $group_data['posts'] );
		}

		usort(
			$groups,
			static function ( array $a, array $b ): int {
				return $b['count'] <=> $a['count'];
			}
		);

		$results = array(
			'groups'       => $groups,
			'total_groups' => count( $groups ),
			'total_posts'  => $total_posts,
		);

		wp_cache_set( $cache_key, $results, self::CACHE_GROUP, 5 * MINUTE_IN_SECONDS );

		return $results;
	}

	/**
	 * Return an empty result set.
	 *
	 * @return array<string, mixed>
	 */
	private function empty_results(): array {
		return array(
			'groups'       => array(),
			'total_groups' => 0,
			'total_posts'  => 0,
		);
	}

	/**
	 * Resolve selected post types.
	 *
	 * @param string   $selection Selected filter value.
	 * @param string[] $explicit  Explicit post type list.
	 * @return string[]
	 */
	private function resolve_post_types( string $selection, array $explicit ): array {
		if ( 'all' === $selection || '' === $selection ) {
			return array_keys( $this->get_post_types() );
		}

		if ( ! empty( $explicit ) ) {
			$allowed = array_keys( $this->get_post_types() );
			return array_values( array_intersect( $explicit, $allowed ) );
		}

		if ( post_type_exists( $selection ) ) {
			return array( $selection );
		}

		return array_keys( $this->get_post_types() );
	}

	/**
	 * Sanitize match strategy.
	 *
	 * @param string $match_by Match field.
	 * @return string
	 */
	private function sanitize_match_by( string $match_by ): string {
		$allowed = array(
			self::MATCH_TITLE,
			self::MATCH_SLUG,
			self::MATCH_TITLE_SLUG,
		);

		return in_array( $match_by, $allowed, true ) ? $match_by : self::MATCH_TITLE;
	}

	/**
	 * Build a normalized match key for a post.
	 *
	 * @param WP_Post $post     Post object.
	 * @param string  $match_by Match strategy.
	 * @return string
	 */
	private function get_match_key( WP_Post $post, string $match_by ): string {
		$title = strtolower( trim( $post->post_title ) );
		$slug  = strtolower( trim( $post->post_name ) );

		switch ( $match_by ) {
			case self::MATCH_SLUG:
				return '' === $slug ? '' : $slug;

			case self::MATCH_TITLE_SLUG:
				if ( '' === $title || '' === $slug ) {
					return '';
				}
				return $title . '::' . $slug;

			default:
				return '' === $title ? '' : $title;
		}
	}

	/**
	 * Build a readable duplicate value label.
	 *
	 * @param WP_Post $post     Post object.
	 * @param string  $match_by Match strategy.
	 * @return string
	 */
	private function get_match_value( WP_Post $post, string $match_by ): string {
		switch ( $match_by ) {
			case self::MATCH_SLUG:
				return $post->post_name;

			case self::MATCH_TITLE_SLUG:
				return sprintf( '%s / %s', $post->post_title, $post->post_name );

			default:
				return $post->post_title;
		}
	}

	/**
	 * Format a post for the admin results table.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	private function format_post_result( WP_Post $post ): array {
		$post_type = $post->post_type;
		$author_id = (int) $post->post_author;

		return array(
			'id'              => (int) $post->ID,
			'title'           => $post->post_title,
			'slug'            => $post->post_name,
			'post_type'       => $post_type,
			'post_type_label' => $this->get_post_type_label( $post_type ),
			'status'          => $post->post_status,
			'status_label'    => $this->get_status_label( $post->post_status ),
			'published'       => mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post->post_date ),
			'published_raw'   => $post->post_date,
			'author_id'       => $author_id,
			'author_name'     => $this->get_author_name( $author_id ),
			'categories'      => $this->get_category_labels( (int) $post->ID, $post_type ),
			'taxonomies'      => $this->get_taxonomy_labels( (int) $post->ID, $post_type ),
			'edit_link'       => get_edit_post_link( $post, 'raw' ),
			'view_link'       => get_permalink( $post ),
		);
	}

	/**
	 * Get a readable post type label.
	 *
	 * @param string $post_type Post type slug.
	 * @return string
	 */
	private function get_post_type_label( string $post_type ): string {
		$object = get_post_type_object( $post_type );

		if ( $object instanceof WP_Post_Type ) {
			return (string) $object->labels->singular_name;
		}

		return $post_type;
	}

	/**
	 * Get a readable status label.
	 *
	 * @param string $status Post status slug.
	 * @return string
	 */
	private function get_status_label( string $status ): string {
		$status_object = get_post_status_object( $status );

		if ( $status_object instanceof stdClass && ! empty( $status_object->label ) ) {
			return (string) $status_object->label;
		}

		return ucfirst( $status );
	}

	/**
	 * Get author display name.
	 *
	 * @param int $author_id Author user ID.
	 * @return string
	 */
	private function get_author_name( int $author_id ): string {
		$user = get_userdata( $author_id );

		if ( ! $user instanceof WP_User ) {
			return __( 'Unknown author', 'prm-dupe-radar' );
		}

		return (string) $user->display_name;
	}

	/**
	 * Get category term names for a post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type slug.
	 * @return string[]
	 */
	private function get_category_labels( int $post_id, string $post_type ): array {
		if ( ! is_object_in_taxonomy( $post_type, 'category' ) ) {
			return array();
		}

		$terms = get_the_terms( $post_id, 'category' );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		$labels = array();

		foreach ( $terms as $term ) {
			if ( $term instanceof WP_Term ) {
				$labels[] = $term->name;
			}
		}

		sort( $labels );

		return $labels;
	}

	/**
	 * Get all taxonomy term labels grouped by taxonomy.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type slug.
	 * @return array<string, string[]>
	 */
	private function get_taxonomy_labels( int $post_id, string $post_type ): array {
		$taxonomies = get_object_taxonomies( $post_type, 'objects' );
		$output     = array();

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! $taxonomy instanceof WP_Taxonomy ) {
				continue;
			}

			$terms = get_the_terms( $post_id, $taxonomy->name );

			if ( ! is_array( $terms ) || empty( $terms ) ) {
				continue;
			}

			$labels = array();

			foreach ( $terms as $term ) {
				if ( $term instanceof WP_Term ) {
					$labels[] = $term->name;
				}
			}

			if ( empty( $labels ) ) {
				continue;
			}

			sort( $labels );
			$output[ $taxonomy->labels->name ] = $labels;
		}

		return $output;
	}
}
