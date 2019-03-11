<?php
defined( 'ABSPATH' ) OR exit;
/**
 * Plugin Name: EDD Software Licensing Updates on Multisite
 * Plugin URI: https://github.com/csalzano/edd-sl-updates-on-multisite
 * Description: A must-use plugin for Easy Digital Downloads Software Licensing that makes updating plugins easier for multisite network admins
 * Version: 1.0.0
 * Author: Corey Salzano
 * Author URI: https://coreysalzano.com/
 * Text Domain: edd-sl-updates-on-multisite
 * Domain Path: /languages
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

class EDD_SL_Updates_On_Multisite_Runner{

	function create_must_use_admin_notice() {

		//only show this notice to users who can actually take action
		if( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf( '<div class="notice notice-error is-dismissible"><p>%s<a href="%s">%s</a>. %s</p></div>',
			__( 'The plugin EDD Software Licensing Updates on Multisite must be installed as a ', 'edd-sl-updates-on-multisite' ),
			'https://codex.wordpress.org/Must_Use_Plugins',
			__( 'must-use plugin', 'edd-sl-updates-on-multisite' ),
			__( 'Please de-activate and move it to the must-use plugins folder.', 'edd-sl-updates-on-multisite' )
		);
	}

	function hooks() {
		/**
		 * If this is not running as a must-use plugin, create an admin notice
		 * explaining that we are aborting all other actions.
		 */
		if ( dirname( dirname( __FILE__ ) ) !== realpath( WPMU_PLUGIN_DIR ) ) {
			add_action( 'admin_notices', array( $this, 'create_must_use_admin_notice' ) );
			return;
		}

		//actually do the things
		include plugin_dir_path( __FILE__ ) . 'includes/class-edd-sl-updates-on-multisite.php';
		include plugin_dir_path( __FILE__ ) . 'includes/class-multisite-plugin-updater.php';
		$instance = new EDD_SL_Updates_On_Multisite();
		$instance->hooks();
	}
}
$edd_sl_updates_on_multisite_20348234 = new EDD_SL_Updates_On_Multisite_Runner();
$edd_sl_updates_on_multisite_20348234->hooks();
