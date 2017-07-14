<?php
/**
 * MANGOPAY WooCommerce plugin filter and action hooks class
 *
 * @author yann@abc.fr
 * @see: https://github.com/Mangopay/wordpress-plugin
 *
 **/
class mangopayWCHooks {
	public static function set_hooks( $mangopayWCMain, $mangopayWCAdmin=NULL ) {
		
		/** SITE WIDE HOOKS **/
		
		/**
		 * Site-wide WP hooks
		 *
		 */
		
		/** Load i18n **/
		add_action( 'plugins_loaded', array( 'mangopayWCPlugin', 'load_plugin_textdomain' ) );
		
		/** Load payment gateway class **/
		add_action( 'plugins_loaded', array( 'mangopayWCPlugin', 'include_payment_gateway' ) );
		
		/** Trigger event when user becomes vendor **/
		add_action( 'set_user_role', array( $mangopayWCMain, 'on_set_user_role' ), 10, 3 );
		
		/** Trigger event when user registers (front & back-office) **/
		add_action( 'user_register', array( $mangopayWCMain, 'on_user_register' ), 10, 1 ); //<- not working for front-end reg
		/** Front-end registration; previous action on same hook hereunder **/
		//add_action( 'woocommerce_created_customer',		array( $mangopayWCMain, 'on_user_register' ), 11, 1 );
		
		/** Passphrase encryption **/
		add_filter( 'pre_update_option_' . mangopayWCConfig::OPTION_KEY, array( $mangopayWCMain, 'encrypt_passphrase' ), 10, 2 );
		add_filter( 'option_' . mangopayWCConfig::OPTION_KEY, array( $mangopayWCMain, 'decrypt_passphrase' ), 10, 1 );
		
		/**
		 * Site-wide WC hooks
		 *
		*/
		
		/** Register MP payment gateway **/
		add_filter( 'woocommerce_payment_gateways', array( 'WC_Gateway_Mangopay', 'add_gateway_class' ) );
		
		/** Do wallet transfers when an order gets completed **/
		add_action( 'woocommerce_order_status_completed', array( $mangopayWCMain, 'on_order_completed' ), 10, 1 );
		
		
		/**
		 * Site-wide WV hooks
		 *
		*/
		
		/** Trigger event when WV store settings are updated **/
		add_action( 'wcvendors_shop_settings_saved',	array( $mangopayWCMain, 'on_shop_settings_saved' ), 10, 1 );
		
		
		/** FRONT END HOOKS **/
		
		/**
		 * Front-end WP hooks
		 * 
		 */
		
		/** Payline form template shortcode **/
		add_shortcode( 'mangopay_payform', array( $mangopayWCMain, 'payform_shortcode' ) );
		
		/**
		 * Front-end WC hooks
		 *
		 */
		
		/** Add required fields to the user registration form **/
		add_action( 'woocommerce_register_form_start',	array( $mangopayWCMain, 'wooc_extra_register_fields' ) );
		add_action( 'woocommerce_register_post',		array( $mangopayWCMain, 'wooc_validate_extra_register_fields' ), 10, 3 );
		add_action( 'woocommerce_created_customer',		array( $mangopayWCMain, 'wooc_save_extra_register_fields' ), 10, 1 );
		
		/** Add required fields on edit-account form **/
		add_action( 'woocommerce_edit_account_form',	array( $mangopayWCMain, 'wooc_extra_register_fields' ) );
		//add_action( 'user_profile_update_errors',		array( $mangopayWCMain, 'wooc_validate_extra_register_fields_user' ), 10, 3 );
		add_filter( 'woocommerce_save_account_details_required_fields', array( $mangopayWCMain, 'wooc_account_details_required' ) );
		add_action( 'woocommerce_save_account_details',	array( $mangopayWCMain, 'wooc_save_extra_register_fields' ), 10, 1 );
        //for edit front
        add_action( 'woocommerce_save_account_details_errors',	array( $mangopayWCMain, 'wooc_validate_extra_register_fields_userfront' ), 10, 2 );

		/** Add required fields on checkout form **/
		add_filter( 'woocommerce_checkout_fields', array( $mangopayWCMain, 'custom_override_checkout_fields' ), 99999 );
        add_action( 'woocommerce_checkout_process', array( $mangopayWCMain, 'wooc_validate_extra_register_fields_checkout' ));
		add_action( 'woocommerce_after_order_notes', array( $mangopayWCMain, 'after_checkout_fields' ) );
		add_action( 'woocommerce_checkout_update_user_meta', array( $mangopayWCMain, 'wooc_save_extra_register_fields' ) );
		
		/** Show MP wallets list on my-account page **/
		//add_action( 'woocommerce_before_my_account', 	array( $mangopayWCMain, 'before_my_account' ) );
		
		/** Process order status after order payment completed **/    
        add_action( 'template_redirect', 			array( $mangopayWCMain, 'order_redirect' ), 1, 1 );
        add_action( 'woocommerce_thankyou', 		array( $mangopayWCMain, 'order_received' ), 1, 1 );
        add_filter( 'woocommerce_add_notice', array( $mangopayWCMain, 'intercept_messages_cancel_order' ), 1,1);
        
		/** When billing address is changed by customer **/
		add_action( 'woocommerce_customer_save_address', array( $mangopayWCMain, 'on_shop_settings_saved' ) );
		
		/** When order received, on thankyou page, display bankwire references if necessary **/
		add_action( 'woocommerce_thankyou_mangopay', array( $mangopayWCMain, 'display_bankwire_ref' ), 10, 1 );
		
		/**
		 * Front-end WV hooks
		 *
		*/
		
		/** Bank account fields on the shop settings **/
		add_action( 'wcvendors_settings_after_paypal', array( $mangopayWCMain, 'bank_account_form' ) );
		//add_action( 'wcvendors_shop_settings_saved', array( $mangopayWCMain, 'save_account_form' ) );
        add_action( 'wcvendors_shop_settings_saved', array( $mangopayWCMain, 'shop_settings_saved' ),10,1 );
		//add_action( 'wcv_pro_store_settings_saved', array( $mangopayWCMain, 'save_account_form' ) );	// Support for WV Pro version's front-end store dashboard
		add_action( 'wcv_pro_store_settings_saved', array( $mangopayWCMain, 'shop_settings_saved' ),10,1 );
        
		//@see: https://github.com/wcvendors/wcvendors/blob/8443c27704e59fd222ba8d65a6438e0251820910/classes/admin/class-admin-users.php#L382
		//this hook fires up randomly in the WV version we used for development
		//add_action( 'wcvendors_update_admin_user', array( $mangopayWCMain, 'shop_settings_admin_saved' ), 10, 1 );
		//this hook is present instead:
		add_action( 'wcvendors_shop_settings_admin_saved', array( $mangopayWCMain, 'shop_settings_admin_saved' ), 10, 1 );
		
		/** Refuse item button in vendor dashboard order list **/
		add_filter( 'wcvendors_order_actions', array( $mangopayWCMain, 'record_current_order' ), 10, 2 );
		add_filter( 'woocommerce_order_items_meta_display', array( $mangopayWCMain, 'refuse_item_button' ), 10, 2 );
		
		
		/** BACK OFFICE HOOKS **/
		
		/**
		 * Back-office WP hooks
		 *
		*/
		if ( !is_admin() )
			return;
		
		/** Load admin CSS stylesheet **/
		add_action( 'admin_enqueue_scripts', array( $mangopayWCAdmin, 'load_admin_styles' ) );
		
		/** Add admin settings menu item **/
		add_action( 'admin_menu',	array( $mangopayWCAdmin, 'settings_menu' ) );
		
		/** Add admin settings options **/
		add_action( 'admin_init',	array( $mangopayWCAdmin, 'register_mysettings' ) );
		
		/** Custom admin notice if config is incomplete **/
		add_action( 'admin_notices', array( $mangopayWCAdmin, 'admin_notices' ) );
		
		/** Failed payouts & refused KYCs admin dashboard widget **/
		add_action( 'wp_dashboard_setup', array( $mangopayWCAdmin, 'add_dashboard_widget' ) );
		
		/** Add required fields to user-edit profile admin page **/
		add_action( 'show_user_profile', 		array( $mangopayWCAdmin, 'user_edit_required' ), 1 );
		add_action( 'edit_user_profile', 		array( $mangopayWCAdmin, 'user_edit_required' ), 1 );
		add_action( 'user_new_form',	 		array( $mangopayWCAdmin, 'user_edit_required' ), 1 );
		add_action( 'personal_options_update',	array( $mangopayWCAdmin, 'user_edit_save' ), 100, 1 );
		add_action( 'edit_user_profile_update',	array( $mangopayWCAdmin, 'user_edit_save' ), 100, 1 );
		add_action( 'user_register',			array( $mangopayWCAdmin, 'user_edit_save' ), 9, 1 );
		add_action( 'user_profile_update_errors', array( $mangopayWCAdmin, 'user_edit_checks' ), 10, 3);
			
		/** Custom column to show if users have an MP account **/
		add_filter( 'manage_users_columns', array( $mangopayWCAdmin, 'manage_users_columns' ) );
		add_filter( 'manage_users_sortable_columns', array( $mangopayWCAdmin, 'manage_sortable_users_columns' ) );
		add_filter( 'manage_users_custom_column', array( $mangopayWCAdmin, 'users_custom_column' ), 10, 3 );
		add_filter( 'pre_user_query', array( $mangopayWCAdmin, 'user_column_orderby' ) );
		
		/**
		 * Back-office WC hooks
		 *
		 */
		
		/** Display custom info on the order admin screen **/
		add_action( 'add_meta_boxes', array( $mangopayWCAdmin, 'add_meta_boxes' ), 20 );
		
		/** Register webhook when activating direct bankwire payment **/
		add_action('woocommerce_update_options_payment_gateways_mangopay', array( $mangopayWCAdmin, 'register_all_webhooks' ) );
		
		/**
		 * Back-office WV hooks
		 *
		 */
		
		/**
		 * Add bulk action to pay commissions
		 *
		 */
		//add_filter( 'bulk_actions-woocommerce_page_pv_admin_commissions', array( $mangopayWCMain, 'bulk_actions' ), 10, 1 );
		add_action( 'admin_footer-woocommerce_page_pv_admin_commissions', array( $mangopayWCAdmin, 'addBulkActionInFooter' ) );
		add_action( 'load-woocommerce_page_pv_admin_commissions', array( $mangopayWCAdmin, 'vendor_payouts' ) );
	}
}
?>