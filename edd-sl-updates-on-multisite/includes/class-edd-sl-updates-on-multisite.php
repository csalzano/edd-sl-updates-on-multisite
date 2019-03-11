<?php

class EDD_SL_Updates_On_Multisite{

	function hooks() {
		//Filter the value of the `active_plugins` option before it is retrieved
		add_filter( 'pre_option_active_plugins', array( $this, 'get_options' ), 10, 3 );

		add_filter( 'site_transient_update_plugins', array( $this, 'filter_update_plugins_transient' ), 10, 2 );
	}

	function filter_update_plugins_transient( $value, $transient ) {

		if( ! is_object( $value ) ) {
			return $value;
		}

		if( ! isset( $value->response ) || ! is_array( $value->response ) ) {
			return $value;
		}

		foreach( $value->response as $file => $obj ) {

			if( ! empty( $obj->package ) || empty( $obj->blog_id ) ) {
				continue;
			}

			$data = array(
				'license'   => '',
				'item_name' => $obj->name,
				'slug'      => $obj->slug,
				'author'    => '',
			);

			$data = apply_filters( 'edd_sl_multisite_updater_' . $obj->slug, $data, $obj->blog_id );

			$api_response = Multisite_Plugin_Updater::perform_post( $obj->url, $data );
		}
		return $value;
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

	function get_options( $pre_option, $option, $default )
	{
		if( ! is_multisite() ) {
			return false;
		}

		/**
		 * When the user clicks the Update link to update the plugin, this
		 * function runs in an admin AJAX callback and is_network_admin() is not
		 * available.
		 */
		if( ( ! function_exists( 'is_network_admin' ) || ! is_network_admin() ) && ! $this->referer_is_network_admin_plugins_page() ) {
			return false;
		}

		/**
		 * Maybe change this to a transient for even more short-circuiting. We
		 * might have to also store the option value in that case.
		 */
		if ( isset( $GLOBALS["{$option}_processed"] ) ) {
			return $GLOBALS["{$option}_processed"];
		}

		wp_cache_add( $option, null, 'options' );

		/**
		 * Get the list of active plugins. If this plugin is running on the
		 * network administrator site, this will return an empty array. If
		 * the network admin is blog ID 1 (and an actual site), this will find
		 * the plugins active on that site.
		 */
		$current_blog_plugins = array();
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"
			SELECT 		option_value
			FROM 		$wpdb->options
			WHERE 		option_name = %s
			LIMIT 1
			",
			$option
		) );
		if ( is_object( $row ) )
		{
			$current_blog_plugins = maybe_unserialize( $row->option_value );
		}

		//TODO put this in a transient?
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

		/**
		 * Set the blog id of the $wpdb instance and retrieve options for each
		 * one in turn looking for a license key. Keep a copy of the current
		 * blog so it's options can be skipped as the answer is already known
		 * and so the current blog id can be reset at the end.
		 */
		$current_blog_id = $wpdb->blogid;

		$other_blog_options = array();
		$plugins_encountered = array();
		foreach ( (array) $blogs as $details ) {


			// Causes all subsequent wpdb requests to use table names
			// appropriate for the blog
			$wpdb->set_blog_id( $details['blog_id'] );

			/**
			 * Is this the network admin site ID? If so, get the plugins that
			 * are Network Activated from wp_sitemeta instead of an option.
			 */
			if ( $current_blog_id == $details['blog_id'] ) {
				$row = $wpdb->get_row( $wpdb->prepare(
					"
					SELECT 		meta_value
					FROM 		$wpdb->sitemeta
					WHERE 		meta_key = %s
					",
					'active_sitewide_plugins'
				) );
				$this_blog_plugins = array_keys( maybe_unserialize( $row->meta_value ) );
			} else {
				//Otherwise what plugins are active on this site?
				$row = $wpdb->get_row( $wpdb->prepare(
					"
					SELECT 		option_value
					FROM 		$wpdb->options
					WHERE 		option_name = %s
					LIMIT 		1
					",
					$option
				) );
				$this_blog_plugins = maybe_unserialize( $row->option_value );
			}

			if ( ! is_array( $this_blog_plugins ) ) {
				continue;
			}

			$value = array_filter(
				$this_blog_plugins,
				function($item) use(&$plugins_encountered) {

					// Only process each plugin once
					if ( in_array( $item, $plugins_encountered ) ) {
						return false;
					}
					$plugins_encountered[] = $item;

					//This plugin might be active but not actually exist anymore
					if ( ! file_exists( WP_PLUGIN_DIR . '/' . $item ) ) {
						return false;
					}

					/**
					 * TODO: redesign this
					 * We look for a specific file name in each plugin folder
					 * or a specific string in the plugin file comment header.
					 * I think a better way would be to look at the plugins that
					 * do not get a response from the .org plugin repo when
					 * wp_update_plugins() checks for new versions. That might
					 * be a smarter way to identify which ones may be hosted
					 * elsewhere.
					 */
					if ( ! file_exists( WP_PLUGIN_DIR . '/' . dirname($item) . '/edd_mu_updater.php' )
						&& ! file_exists( WP_PLUGIN_DIR . '/' .dirname( $item ) . '/includes/class-updater.php' ) )
					{
						// ...or if marked as SL updateable
						$headers = get_file_data(WP_PLUGIN_DIR . '/' . $item, array('updateable' => 'Updateable'));

						if (!isset($headers['updateable']) || !(boolean)$headers['updateable']) {
							return false;
						}
					}

					//Initialize a new updater
					$updater = new Multisite_Plugin_Updater(
						apply_filters( 'edd_sl_multisite_updater_api_url', '' ),
						WP_PLUGIN_DIR . '/' . $item,
						array(
							'blog_url' => get_bloginfo( 'url' ),
						)
					);

					return true;
				}
			);

			$other_blog_options = array_unique( array_merge( $other_blog_options, $value ) );
		}

		//Reset the blog id in the $wpdb object to the value we saved earlier
		$wpdb->set_blog_id( $current_blog_id );
		wp_cache_set( 'alloptions', null, 'options' );

		unset( $blogs );

		$result = array_unique( array_merge( $current_blog_plugins, $other_blog_options ) );

		sort( $result );

		wp_cache_add( $option, $result, 'options' );

		$GLOBALS["{$option}_processed"] = $result;

		return $result;
	}
}
