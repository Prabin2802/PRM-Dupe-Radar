<?php
/**
 * Admin page for duplicate post results.
 *
 * @package PRMDupeRadar
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the plugin admin UI.
 */
class PDR_Admin_Page {

	/**
	 * Duplicate finder service.
	 *
	 * @var PDR_Dupe_Radar
	 */
	private PDR_Dupe_Radar $finder;

	/**
	 * Constructor.
	 *
	 * @param PDR_Dupe_Radar $finder Finder service.
	 */
	public function __construct( PDR_Dupe_Radar $finder ) {
		$this->finder = $finder;

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Register admin menu page.
	 */
	public function register_menu(): void {
		add_menu_page(
			__( 'PRM Dupe Radar', 'prm-dupe-radar' ),
			__( 'PRM Dupe Radar', 'prm-dupe-radar' ),
			'manage_options',
			'prm-dupe-radar',
			array( $this, 'render_page' ),
			'dashicons-admin-page',
			58
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_prm-dupe-radar' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'pdr-admin',
			PDR_PLUGIN_URL . 'assets/css/admin.css',
			array(),
			PDR_VERSION
		);

		wp_enqueue_script(
			'pdr-admin',
			PDR_PLUGIN_URL . 'assets/js/admin.js',
			array(),
			PDR_VERSION,
			true
		);
	}

	/**
	 * Render admin page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'prm-dupe-radar' ) );
		}

		$post_types = $this->finder->get_post_types();
		$selected   = 'all';
		$match_by   = PDR_Dupe_Radar::MATCH_TITLE;
		$search     = '';
		$scanned    = false;
		$results    = array(
			'groups'       => array(),
			'total_groups' => 0,
			'total_posts'  => 0,
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below before reading request data.
		if ( isset( $_GET['scan'] ) && '1' === $_GET['scan'] ) {
			check_admin_referer( 'pdr_scan', 'pdr_nonce' );

			$scanned = true;

			if ( isset( $_GET['post_type'] ) ) {
				$selected = sanitize_key( wp_unslash( (string) $_GET['post_type'] ) );
			}

			if ( isset( $_GET['match_by'] ) ) {
				$match_by = sanitize_key( wp_unslash( (string) $_GET['match_by'] ) );
			}

			if ( isset( $_GET['s'] ) ) {
				$search = sanitize_text_field( wp_unslash( (string) $_GET['s'] ) );
			}

			$results = $this->finder->find_duplicates(
				array(
					'post_type' => $selected,
					'match_by'  => $match_by,
				)
			);

			if ( '' !== $search ) {
				$results['groups'] = $this->filter_groups_by_search( $results['groups'], $search );
				$results['total_groups'] = count( $results['groups'] );
				$results['total_posts']  = array_reduce(
					$results['groups'],
					static function ( int $carry, array $group ): int {
						return $carry + (int) $group['count'];
					},
					0
				);
			}
		}

		?>
		<div class="wrap pdr-wrap">
			<h1><?php esc_html_e( 'PRM Dupe Radar', 'prm-dupe-radar' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Scan your site for duplicate content across posts and all public custom post types.', 'prm-dupe-radar' ); ?>
			</p>

			<div class="pdr-toolbar">
				<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="pdr-filters">
					<input type="hidden" name="page" value="prm-dupe-radar" />
					<input type="hidden" name="scan" value="1" />
					<?php wp_nonce_field( 'pdr_scan', 'pdr_nonce' ); ?>

					<label for="pdr-post-type">
						<span><?php esc_html_e( 'Post type', 'prm-dupe-radar' ); ?></span>
						<select id="pdr-post-type" name="post_type">
							<option value="all" <?php selected( $selected, 'all' ); ?>>
								<?php esc_html_e( 'All post types', 'prm-dupe-radar' ); ?>
							</option>
							<?php foreach ( $post_types as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $selected, $slug ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</label>

					<label for="pdr-match-by">
						<span><?php esc_html_e( 'Match by', 'prm-dupe-radar' ); ?></span>
						<select id="pdr-match-by" name="match_by">
							<option value="<?php echo esc_attr( PDR_Dupe_Radar::MATCH_TITLE ); ?>" <?php selected( $match_by, PDR_Dupe_Radar::MATCH_TITLE ); ?>>
								<?php esc_html_e( 'Title', 'prm-dupe-radar' ); ?>
							</option>
							<option value="<?php echo esc_attr( PDR_Dupe_Radar::MATCH_SLUG ); ?>" <?php selected( $match_by, PDR_Dupe_Radar::MATCH_SLUG ); ?>>
								<?php esc_html_e( 'Slug', 'prm-dupe-radar' ); ?>
							</option>
							<option value="<?php echo esc_attr( PDR_Dupe_Radar::MATCH_TITLE_SLUG ); ?>" <?php selected( $match_by, PDR_Dupe_Radar::MATCH_TITLE_SLUG ); ?>>
								<?php esc_html_e( 'Title + Slug', 'prm-dupe-radar' ); ?>
							</option>
						</select>
					</label>

					<label for="pdr-search">
						<span><?php esc_html_e( 'Filter results', 'prm-dupe-radar' ); ?></span>
						<input
							id="pdr-search"
							type="search"
							name="s"
							value="<?php echo esc_attr( $search ); ?>"
							placeholder="<?php esc_attr_e( 'Search title, author, taxonomy…', 'prm-dupe-radar' ); ?>"
						/>
					</label>

					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Run Radar Scan', 'prm-dupe-radar' ); ?>
					</button>
				</form>
			</div>

			<?php if ( ! $scanned ) : ?>
				<div class="pdr-empty-state">
					<div class="pdr-empty-icon dashicons dashicons-search"></div>
					<h2><?php esc_html_e( 'Ready to scan', 'prm-dupe-radar' ); ?></h2>
					<p><?php esc_html_e( 'Choose your filters and click “Run Radar Scan” to review duplicate posts across your site.', 'prm-dupe-radar' ); ?></p>
				</div>
			<?php elseif ( 0 === (int) $results['total_groups'] ) : ?>
				<div class="pdr-empty-state pdr-empty-state--success">
					<div class="pdr-empty-icon dashicons dashicons-yes-alt"></div>
					<h2><?php esc_html_e( 'No duplicates found', 'prm-dupe-radar' ); ?></h2>
					<p><?php esc_html_e( 'Great news — no duplicate groups matched your current scan settings.', 'prm-dupe-radar' ); ?></p>
				</div>
			<?php else : ?>
				<div class="pdr-summary">
					<div class="pdr-stat">
						<span class="pdr-stat-label"><?php esc_html_e( 'Duplicate groups', 'prm-dupe-radar' ); ?></span>
						<strong><?php echo esc_html( (string) $results['total_groups'] ); ?></strong>
					</div>
					<div class="pdr-stat">
						<span class="pdr-stat-label"><?php esc_html_e( 'Affected posts', 'prm-dupe-radar' ); ?></span>
						<strong><?php echo esc_html( (string) $results['total_posts'] ); ?></strong>
					</div>
					<div class="pdr-stat">
						<span class="pdr-stat-label"><?php esc_html_e( 'Match mode', 'prm-dupe-radar' ); ?></span>
						<strong><?php echo esc_html( $this->get_match_label( $match_by ) ); ?></strong>
					</div>
				</div>

				<div class="pdr-results">
					<?php foreach ( $results['groups'] as $index => $group ) : ?>
						<section class="pdr-group" data-group-index="<?php echo esc_attr( (string) $index ); ?>">
							<button type="button" class="pdr-group-toggle" aria-expanded="true">
								<span class="pdr-group-title">
									<?php
									printf(
										/* translators: 1: duplicate value, 2: number of posts */
										esc_html__( '“%1$s” — %2$d duplicates', 'prm-dupe-radar' ),
										esc_html( (string) $group['match_value'] ),
										(int) $group['count']
									);
									?>
								</span>
								<span class="dashicons dashicons-arrow-up-alt2"></span>
							</button>

							<div class="pdr-group-body">
								<table class="widefat striped pdr-table">
									<thead>
										<tr>
											<th><?php esc_html_e( 'ID', 'prm-dupe-radar' ); ?></th>
											<th><?php esc_html_e( 'Name', 'prm-dupe-radar' ); ?></th>
											<th><?php esc_html_e( 'Post Type', 'prm-dupe-radar' ); ?></th>
											<th><?php esc_html_e( 'Status', 'prm-dupe-radar' ); ?></th>
											<th><?php esc_html_e( 'Categories', 'prm-dupe-radar' ); ?></th>
											<th><?php esc_html_e( 'Taxonomies', 'prm-dupe-radar' ); ?></th>
											<th><?php esc_html_e( 'Published', 'prm-dupe-radar' ); ?></th>
											<th><?php esc_html_e( 'Author', 'prm-dupe-radar' ); ?></th>
											<th><?php esc_html_e( 'Actions', 'prm-dupe-radar' ); ?></th>
										</tr>
									</thead>
									<tbody>
										<?php foreach ( $group['posts'] as $post ) : ?>
											<tr>
												<td data-label="<?php esc_attr_e( 'ID', 'prm-dupe-radar' ); ?>">
													<code>#<?php echo esc_html( (string) $post['id'] ); ?></code>
												</td>
												<td data-label="<?php esc_attr_e( 'Name', 'prm-dupe-radar' ); ?>">
													<strong><?php echo esc_html( $post['title'] ); ?></strong>
													<div class="pdr-meta-line"><?php echo esc_html( $post['slug'] ); ?></div>
												</td>
												<td data-label="<?php esc_attr_e( 'Post Type', 'prm-dupe-radar' ); ?>">
													<span class="pdr-badge"><?php echo esc_html( $post['post_type_label'] ); ?></span>
												</td>
												<td data-label="<?php esc_attr_e( 'Status', 'prm-dupe-radar' ); ?>">
													<span class="pdr-status pdr-status--<?php echo esc_attr( sanitize_html_class( $post['status'] ) ); ?>">
														<?php echo esc_html( $post['status_label'] ); ?>
													</span>
												</td>
												<td data-label="<?php esc_attr_e( 'Categories', 'prm-dupe-radar' ); ?>">
													<?php echo wp_kses_post( $this->render_term_list( $post['categories'] ) ); ?>
												</td>
												<td data-label="<?php esc_attr_e( 'Taxonomies', 'prm-dupe-radar' ); ?>">
													<?php echo wp_kses_post( $this->render_taxonomy_groups( $post['taxonomies'] ) ); ?>
												</td>
												<td data-label="<?php esc_attr_e( 'Published', 'prm-dupe-radar' ); ?>">
													<time datetime="<?php echo esc_attr( $post['published_raw'] ); ?>">
														<?php echo esc_html( $post['published'] ); ?>
													</time>
												</td>
												<td data-label="<?php esc_attr_e( 'Author', 'prm-dupe-radar' ); ?>">
													<?php echo esc_html( $post['author_name'] ); ?>
												</td>
												<td data-label="<?php esc_attr_e( 'Actions', 'prm-dupe-radar' ); ?>" class="pdr-actions">
													<?php if ( ! empty( $post['edit_link'] ) ) : ?>
														<a class="button button-small" href="<?php echo esc_url( $post['edit_link'] ); ?>">
															<?php esc_html_e( 'Edit', 'prm-dupe-radar' ); ?>
														</a>
													<?php endif; ?>
													<?php if ( ! empty( $post['view_link'] ) ) : ?>
														<a class="button button-small" href="<?php echo esc_url( $post['view_link'] ); ?>" target="_blank" rel="noopener noreferrer">
															<?php esc_html_e( 'View', 'prm-dupe-radar' ); ?>
														</a>
													<?php endif; ?>
												</td>
											</tr>
										<?php endforeach; ?>
									</tbody>
								</table>
							</div>
						</section>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Filter duplicate groups by search term.
	 *
	 * @param array<int, array<string, mixed>> $groups Duplicate groups.
	 * @param string                           $search Search term.
	 * @return array<int, array<string, mixed>>
	 */
	private function filter_groups_by_search( array $groups, string $search ): array {
		$needle = strtolower( trim( $search ) );

		if ( '' === $needle ) {
			return $groups;
		}

		$filtered = array();

		foreach ( $groups as $group ) {
			$posts = array();

			foreach ( $group['posts'] as $post ) {
				$haystack = strtolower(
					implode(
						' ',
						array(
							(string) $group['match_value'],
							(string) $post['title'],
							(string) $post['slug'],
							(string) $post['author_name'],
							(string) $post['post_type_label'],
							implode( ' ', $post['categories'] ),
							$this->flatten_taxonomies( $post['taxonomies'] ),
						)
					)
				);

				if ( false !== strpos( $haystack, $needle ) ) {
					$posts[] = $post;
				}
			}

			if ( ! empty( $posts ) ) {
				$group['posts'] = $posts;
				$group['count'] = count( $posts );
				$filtered[]     = $group;
			}
		}

		return $filtered;
	}

	/**
	 * Flatten taxonomy labels into a searchable string.
	 *
	 * @param array<string, string[]> $taxonomies Taxonomy groups.
	 * @return string
	 */
	private function flatten_taxonomies( array $taxonomies ): string {
		$parts = array();

		foreach ( $taxonomies as $taxonomy => $terms ) {
			$parts[] = $taxonomy . ' ' . implode( ' ', $terms );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Render a comma-separated term list.
	 *
	 * @param string[] $terms Term names.
	 * @return string
	 */
	private function render_term_list( array $terms ): string {
		if ( empty( $terms ) ) {
			return '<span class="pdr-muted">' . esc_html__( 'None', 'prm-dupe-radar' ) . '</span>';
		}

		$items = array();

		foreach ( $terms as $term ) {
			$items[] = '<span class="pdr-chip">' . esc_html( $term ) . '</span>';
		}

		return implode( ' ', $items );
	}

	/**
	 * Render grouped taxonomy labels.
	 *
	 * @param array<string, string[]> $taxonomies Taxonomy groups.
	 * @return string
	 */
	private function render_taxonomy_groups( array $taxonomies ): string {
		if ( empty( $taxonomies ) ) {
			return '<span class="pdr-muted">' . esc_html__( 'None', 'prm-dupe-radar' ) . '</span>';
		}

		$output = array();

		foreach ( $taxonomies as $taxonomy => $terms ) {
			$chips = array();

			foreach ( $terms as $term ) {
				$chips[] = '<span class="pdr-chip">' . esc_html( $term ) . '</span>';
			}

			$output[] = '<div class="pdr-tax-group"><strong>' . esc_html( $taxonomy ) . ':</strong> ' . implode( ' ', $chips ) . '</div>';
		}

		return implode( '', $output );
	}

	/**
	 * Get readable match mode label.
	 *
	 * @param string $match_by Match mode slug.
	 * @return string
	 */
	private function get_match_label( string $match_by ): string {
		switch ( $match_by ) {
			case PDR_Dupe_Radar::MATCH_SLUG:
				return __( 'Slug', 'prm-dupe-radar' );
			case PDR_Dupe_Radar::MATCH_TITLE_SLUG:
				return __( 'Title + Slug', 'prm-dupe-radar' );
			default:
				return __( 'Title', 'prm-dupe-radar' );
		}
	}
}
