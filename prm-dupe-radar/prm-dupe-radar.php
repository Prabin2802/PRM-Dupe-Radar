<?php
/**
 * Plugin Name:       PRM Dupe Radar
 * Description:       PRM Dupe Radar finds and reviews duplicate posts across all custom post types with a detailed admin interface.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            PRM
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       prm-dupe-radar
 *
 * @package PRMDupeRadar
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PDR_VERSION', '1.0.0' );
define( 'PDR_PLUGIN_FILE', __FILE__ );
define( 'PDR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PDR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PDR_PLUGIN_DIR . 'includes/class-dupe-radar.php';
require_once PDR_PLUGIN_DIR . 'includes/class-admin-page.php';

/**
 * Bootstrap the plugin.
 */
function pdr_bootstrap(): void {
	$finder = new PDR_Dupe_Radar();
	new PDR_Admin_Page( $finder );
}
add_action( 'plugins_loaded', 'pdr_bootstrap' );
