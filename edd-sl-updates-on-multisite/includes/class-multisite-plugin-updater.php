<?php

if ( ! class_exists('Multisite_Plugin_Updater') ) {

	/**
	 * Hits external APIs to check for plugin updates. Designed for plugins that
	 * are not hosted on wordpress.org.
	 *
	 * This class was originally created by Bill Seddon and is based on a the
	 * EDD_SL_Plugin_Updater class by Pippin Williamson.
	 */
	class Multisite_Plugin_Updater {

		private $api_url  = '';
		private $api_data = array();
		private $name     = '';
		private $slug     = '';
		private $version  = '';
		private $author   = '';
		private $blog_url = '';
		private $plugin   = '';
		private $item_name = '';

		/**
		 * Class constructor.
		 *
		 * @uses plugin_basename()
		 * @uses hook()
		 *
		 * @param string $api_url The URL pointing to the custom API endpoint.
		 * @param string $plugin_file Path to the plugin file.
		 * @param array $api_data Optional data to send with API calls.
		 * @return void
		 */
		function __construct( $api_url, $plugin_file, $api_data = null ) {

			//Extract header information from the plugin's default PHP file
			$plugin_header = get_file_data( $plugin_file, array(
				'plugin_name' => 'Plugin Name',
				'plugin_uri'  => 'Plugin URI',
				'version'     => 'Version',
				'author'      => 'Author',
			) );

			$this->api_url   = empty( $api_url ) ? $plugin_header['plugin_uri'] : trailingslashit( $api_url );
			$this->api_data  = $api_data;
			$this->name	     = plugin_basename( $plugin_file );
			$this->slug	     = basename( $plugin_file, '.php');
			$this->version   = isset( $api_data['version'] ) ? $api_data['version'] : $plugin_header['version'];
			$this->author    = isset( $api_data['author'] ) ? $api_data['author'] : $plugin_header['author'];
			$this->blog_url  = isset( $api_data['blog_url'] ) ? $api_data['blog_url'] : '';
			$this->item_name = $plugin_header['plugin_name'];

			// Set up hooks.
			$this->hook();
		}

		/**
		 * Set up Wordpress filters to hook into WP's update process.
		 *
		 * @uses add_filter()
		 *
		 * @return void
		 */
		private function hook() {

			//Hook name pre_site_transient_{$transient}
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'pre_set_site_transient_update_plugins_filter' ), 11 );

			add_filter( 'plugins_api', array( $this, 'plugins_api_filter' ), 10, 3 );
		}

		/**
		 * Check for Updates at the defined API endpoint and modify the update array.
		 *
		 * This function dives into the update api just when Wordpress creates its update array,
		 * then adds a custom API call and injects the custom plugin data retrieved from the API.
		 * It is reassembled from parts of the native Wordpress plugin update code.
		 * See wp-includes/update.php line 121 for the original wp_update_plugins() function.
		 *
		 * @uses api_request()
		 *
		 * @param array $transient_data Update array build by Wordpress.
		 * @return array Modified update array with custom plugin data.
		 */
		function pre_set_site_transient_update_plugins_filter( $transient_data ) {


			if( empty( $transient_data )
				|| (
					isset( $transient_data->response )
					&& isset( $transient_data->response[$this->name] )
					&& ! empty( $transient_data->response[$this->name]->package )
					)
			) {
				return $transient_data;
			}

			$to_send = array(
				'api_url'   => $this->api_url,
				'item_name' => $this->item_name,
				'slug'      => $this->slug,
				'version'   => $this->version,
			);
			if ( isset( $this->blog_url ) ) {
				$to_send['url'] = $this->blog_url;
			}

			$api_response = $this->api_request( 'plugin_latest_version', $to_send );

			if( false !== $api_response
				&& is_object( $api_response )
				&& isset( $api_response->new_version )
				&& version_compare( $this->version, $api_response->new_version, '<' ) )
			{
				$transient_data->response[$this->name] = $api_response;
			}

			return $transient_data;
		}


		/**
		 * Updates information on the "View version x.x details" page with custom data.
		 *
		 * @uses api_request()
		 *
		 * @param mixed $data
		 * @param string $action
		 * @param object $args
		 * @return object $data
		 */
		function plugins_api_filter( $data, $action = '', $args = null ) {

			if ( $action != 'plugin_information' || !isset( $args->slug ) || $args->slug != $this->slug ) {
				return $data;
			}

			$to_send = array(
				'api_url'   => $this->api_url,
				'item_name' => $this->item_name,
				'slug'      => $this->slug,
				'version'   => $this->version,
			);
			if ( isset( $this->blog_url ) ) {
				$to_send['url'] = $this->blog_url;
			}

			$api_response = $this->api_request( 'plugin_information', $to_send );
			if ( false === $api_response )
			{
				return $data;
			}

			if ( isset( $api_response->compatibility ) ) {
				$api_response->compatibility = maybe_unserialize( $api_response->compatibility );
			}

			return $api_response;
		}

		/**
		 * This is a workaround for needing the value of is_network_admin() outside
		 * the context of where that function is useful--inside an AJAX callback.
		 *
		 * @return boolean True if the referer to the request is the network admin plugins page
		 */
		function referer_is_network_admin_plugins_page() {

			if( ! isset( $_SERVER['HTTP_REFERER'] ) ) {
				return false;
			}
			return 'wp-admin/network/plugins.php' == substr( $_SERVER['HTTP_REFERER'], -28 );
		}

		/**
		 * Calls the API and, if successful, returns the object delivered by the API.
		 *
		 * @uses get_bloginfo()
		 * @uses wp_remote_post()
		 * @uses is_wp_error()
		 *
		 * @param string $action The requested action.
		 * @param array $data Parameters for the API action.
		 * @return false||object
		 */
		private function api_request( $action, $data ) {

			set_time_limit(100);

			$data = array_merge( $this->api_data, $data );

			if( $data['slug'] != $this->slug ) {
				return false;
			}

			// If you are running the 'Network' site in a multi-site configuration
			// and the application is activated only for one site then its not
			// active for the network and so not licensed for the network.
			// As a result the license will be empty and it's necessary to find the
			// license from one of the constituent blogs.

			$is_network_admin = ( function_exists( 'is_network_admin' ) && is_network_admin() || $this->referer_is_network_admin_plugins_page() );

			if ( is_multisite() && $is_network_admin && ( empty( $data['license']) || empty($data['item_name']) || empty( $this->api_url ) ) ) {

				global $wpdb;

				$blogs = $wpdb->get_results( $wpdb->prepare(
					"
					SELECT		blog_id,
								domain,
								path

					FROM		$wpdb->blogs

					WHERE 		site_id = %d
								AND public = '1'
								AND archived = '0'
								AND mature = '0'
								AND spam = '0'
								AND deleted = '0'

					ORDER BY 	registered ASC
					",
					$wpdb->siteid
				), ARRAY_A );

				// The process now is to set the blog id of the $wpdb instance and
				// retrieve options for each one in turn looking for a license key.
				// Keep a copy of the current blog so it's options can be skipped
				// as the answer is already known and so the current blog id can be
				// reset at the end.
				foreach ( (array) $blogs as $details ) {

					// Clear an existing options cache or the existing options will
					// be returned
					wp_cache_set( 'alloptions', null, 'options' );

					$active_plugins = $this->get_active_plugins( $details['blog_id'] );
					if ( ! is_array( $active_plugins ) || ! in_array( $this->name, $active_plugins ) ) {
						continue;
					}

					//Move filtered $data into $result. Use this filter hook to populate the license key
					$result = apply_filters( 'edd_sl_multisite_updater_' . $this->slug, $data, $details['blog_id'] );

					// No license or product name or url? Then move along, nothing to see
					if ( empty( $result['license'] )
						|| empty( $result['item_name'] )
						|| empty( $result['version'] )
						|| ( empty( $this->api_url ) && empty( $result['api_url'] ) ) )
					{
						continue;
					}

					// Yes?  The grab it and get out of here. Only one license is needed to force the update.
					if ( empty( $this->api_url ) ) {
						$this->api_url = $result['api_url'];
					}

					$data['license'] = $result['license'];
					$data['item_name'] = $result['item_name'];
					$data['version'] = $this->version = $result['version'];
					$data['author'] = $this->author = (isset($result['author']) ? $result['author'] : $this->author);

					$data['blogid'] = $details['blog_id'];

					// Grab the url of the blog as well at it forms the user-agent header
					$data['url'] = get_bloginfo( 'url' );
					break;
				}

				wp_cache_set( 'alloptions', null, 'options' );

				unset( $blogs );
			}

			if( ! isset( $data['license'] ) ) {
				//we can't continue without a license key for this EDD item
				return false;
			}

			$api_params = array(
				'edd_action' => 'get_version',
				'license'    => $data['license'],
				'name'       => htmlentities( $data['item_name'] ),
				'slug'       => $this->slug,
				'author'     => $this->author,
			);

			if ( ! isset( $data['url'] ) || empty( $data['url'] ) ) {
				$data['url'] = get_bloginfo('url');
			}

			// Create a user-agent header because WordPress uses the 'network' blog url by default
			global $wp_version;
			$useragent = apply_filters( 'http_headers_useragent', 'WordPress/' . $wp_version . '; ' . $data['url'] );
			$args = array(
				'user-agent' => $useragent,
				'timeout'    => 15,
				'sslverify'  => false,
				'body'       => $api_params,
			);

			$request = wp_remote_post( $this->api_url, $args );

			/* If successful the response will be an array which contains a
			 * [body] element which is a serialized array containing:
			 *
			 *  new_version
			 *  name
			 *  version
			 *  slug
			 *  author
			 *  url
			 *  homepage
			 *  package			Link to a download
			 *  sections []
			 *    description	The download content
			 *    changelog
			 */

			if ( ! is_wp_error( $request ) ) {
				$request = json_decode( wp_remote_retrieve_body( $request ) );
				if( $request && isset( $request->sections ) ) {
					$request->sections = maybe_unserialize( $request->sections );
				}

				//stuff the blog ID in
				$request->blog_id = $details['blog_id'];

				return $request;
			}

			return false;
		}

		/**
		 * Queries the database and builds a list of the plugins that are
		 * explicitly active on the provided site ID and the plugins that are
		 * Network Activate across all sites in the multisite network.
		 *
		 * @param int $site_id The integer ID of the current site in the multisite network
		 * @return array An array of plugin folder names (or file slugs)
		 */
		private function get_active_plugins( $site_id ) {

			global $wpdb;
			$reset_site_id = $wpdb->blogid;
			$wpdb->set_blog_id( $site_id );
			$row = $wpdb->get_row(
				"
				SELECT 		option_value
				FROM 		$wpdb->options
				WHERE 		option_name = 'active_plugins'
				LIMIT 		1
				"
			);

			$plugins = array();

			if ( is_object( $row ) ) {
				$plugins = maybe_unserialize( $row->option_value );
			}

			//combine with plugins that are Network Active
			$row = $wpdb->get_row( $wpdb->prepare(
				"
				SELECT 		meta_value
				FROM 		$wpdb->sitemeta
				WHERE 		meta_key = %s
				",
				'active_sitewide_plugins'
			) );

			$wpdb->set_blog_id( $reset_site_id );

			if ( ! is_object( $row ) ) {
				return $plugins;
			}

			return array_merge( $plugins, array_keys( maybe_unserialize( $row->meta_value ) ) );
		}

		public static function perform_post( $api_url, $data ) {

			if ( empty( $data['url'] ) ) {
				$data['url'] = get_bloginfo('url');
			}

			// Create a user-agent header because WordPress uses the 'network' blog url by default
			global $wp_version;
			$args = array(
				'user-agent' => apply_filters( 'http_headers_useragent', 'WordPress/' . $wp_version . '; ' . $data['url'] ),
				'timeout'    => 15,
				'sslverify'  => false,
				'body'       => array(
					'edd_action' => 'get_version',
					'license'    => $data['license'],
					'name'       => htmlentities( $data['item_name'] ),
					'slug'       => $data['slug'],
					'author'     => $data['author'],
				),
			);

			$request = wp_remote_post( apply_filters( 'edd_sl_multisite_updater_api_url', $api_url ), $args );

			/* If successful the response will be an array which contains a
			 * [body] element which is a serialized array containing:
			 *
			 *  new_version
			 *  name
			 *  version
			 *  slug
			 *  author
			 *  url
			 *  homepage
			 *  package			Link to a download
			 *  sections []
			 *    description	The download content
			 *    changelog
			 */

			if ( ! is_wp_error( $request ) ) {
				$request = json_decode( wp_remote_retrieve_body( $request ) );
				if( $request && isset( $request->sections ) ) {
					$request->sections = maybe_unserialize( $request->sections );
				}
				return $request;
			}

			return false;
		}
	}
}
