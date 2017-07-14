<?php
/**
 * MANGOPAY WooCommerce plugin maintenance class
 *
 * @author yann@abc.fr
 * @see: https://github.com/Mangopay/wordpress-plugin
 *
 **/
class mangopayWCPlugin {
	
	/**
	 * Upon plugin version number change
	 *
	 * @param string $old_version
	 * @param string $new_version
	 */
	public static function upgrade_plugin( $old_version, $new_version, $options ) {
	
		/** Release 0.3.0 **/
		if( version_compare( $old_version, '0.3.0' ) < 0 && version_compare( $new_version, '0.3.0' ) >= 0 )
			include_once( dirname( __FILE__ ) . '/upgrades/set_legacy_users_mp_status.php' );
	
		$options['plugin_version'] = $new_version;
		update_option ( mangopayWCConfig::OPTION_KEY, $options );
	}
	
	/**
	 * Upon plugin activation
	 * must be a static function
	 *
	 * @see: http://codex.wordpress.org/Function_Reference/register_activation_hook
	 *
	 */
	public static function on_plugin_activation() {
		//Static stuff
		mangopayWCWebHooks::addRewriteRule();
		flush_rewrite_rules();
	}
	
	/**
	 * Load the text translation files
	 *
	 */
	public static function load_plugin_textdomain() {
		load_plugin_textdomain( 'mangopay', false, plugin_basename( dirname( dirname( __FILE__ ) ) ) . '/languages' );
	}

	/**
	 * Load the payment gateway class file after all plugin initializations
	 * @see: https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#loading-text-domain
	 *
	 */
	public static function include_payment_gateway() {
		if( class_exists( 'WC_Payment_Gateway' ) )
			include_once( dirname( __FILE__ ) . '/gateway.inc.php' );	// WC Gateway class
	}
}
?>