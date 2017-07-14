<?php
/** Can't be called outside WP **/
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce Payment Gateway class for MANGOPAY
 * 
 * @author yann@abc.fr
 * @see: https://github.com/Mangopay/wordpress-plugin
 *
 */
class WC_Gateway_Mangopay extends WC_Payment_Gateway {

	/** 
	 * Required class variables : standard WC Gateway
	 * 
	 *
	public $id; 					// Unique ID for your gateway. e.g. ‘your_gateway’
	public $icon;					// If you want to show an image next to the gateway’s name on the frontend, enter a URL to an image.
	public $has_fields;				// Bool. Can be set to true if you want payment fields to show on the checkout (if doing a direct integration).
	public $method_title;			// Title of the payment method shown on the admin page.
	public $method_description;		// Description for the payment method shown on the admin page.
	public $title;					// Appears in the WC Admin Checkout tab >> Gateway Display
	public $form_fields;
	*/
	
	/** @var bool Whether or not logging is enabled */
	public static $log_enabled = false;
	
	/** @var WC_Logger Logger instance */
	public static $log = false;
	
	/**
	 * MANGOPAY specific class variables
	 * 
	 * @see: https://docs.mangopay.com/api-references/payins/payins-card-web/
	 * 
	 */
	private $supported_locales = array(
		'de', 'en', 'da', 'es', 'et', 'fi', 'fr', 'el', 'hu', 'it', 'nl', 'no', 'pl', 'pt', 'sk', 'sv', 'cs'
	);
	private $allowed_currencies = array(
		'EUR', 'GBP', 'USD', 'CHF', 'NOK', 'PLN', 'SEK', 'DKK', 'CAD', 'ZAR'
	);
	private $available_card_types = array(
		'CB_VISA_MASTERCARD'	=> 'CB/Visa/Mastercard',
		'MAESTRO'				=> 'Maestro', 
		'BCMC'					=> 'Bancontact/Mister Cash', 
		'P24'					=> 'Przelewy24', 
		'DINERS'				=> 'Diners', 
		'PAYLIB'				=> 'PayLib', 
		'IDEAL'					=> 'iDeal', 
		'MASTERPASS'			=> 'MasterPass',
		'BANK_WIRE'				=> 'Bankwire Direct'	// This is not actually a card
	);
	private $default_card_types = array(
		'CB_VISA_MASTERCARD',
		'BCMC',
		'PAYLIB'
	);
	
	/**
	 * Class constructor (required)
	 *
	 */
	public function __construct() {

		/** Initialize payment gateway **/
		$this->wcGatewayInit();
		$this->init_form_fields();
		$this->init_settings();
		
		/** Admin hooks **/
		if( !is_admin() )
			return;
		
		/** Inherited class hook, mandatory **/
		add_action( 
			'woocommerce_update_options_payment_gateways_' . $this->id, 
			array( $this, 'process_admin_options' ) 
		);
	}
	
	/**
	 * Register the WC Payment gateway
	 *
	 * @param array $methods
	 * @return array $methods
	 *
	 */
	public static function add_gateway_class( $methods ) {
	
		$methods[] = 'WC_Gateway_Mangopay';
	
		return $methods;
	}

	/**
	 * Performs all initialization for a standard WooCommerce payment gateway
	 *
	 */
	private function wcGatewayInit() {
		
		$form_fields = array();
		
		$form_fields['enabled'] = array(
			'title'		=> __( 'Enable/Disable', 'mangopay' ),
			'type'		=> 'checkbox',
			'label'		=> __( 'Enable MANGOPAY Payment', 'mangopay' ),
			'default'	=> 'yes'
		);
		
		/** Fields to choose available credit card types **/
		$first = true;
		foreach( $this->available_card_types as $type=>$label ) {
			$default = 'no';
			if( 'CB_VISA_MASTERCARD'==$type )
				$default = 'yes';
			$star = '<span class="note star" title="' . __('Needs activation','mangopay') . '">*</span>';
			if( in_array( $type, $this->default_card_types ) )
				$star = '';
			$title = $first?__( 'Choose available credit card types:', 'mangopay' ):'';
			if( 'BANK_WIRE' == $type )
				$title = __( 'Choose available direct payment types:', 'mangopay' );
			$form_fields['enabled_' . $type] = array(
				'title'		=> $title,
				'type'		=> 'checkbox',
				'label'		=> sprintf( __( 'Enable %s payment', 'mangopay' ), __( $label, 'mangopay' ) ) . $star,
				'default'	=> $default,
				'class'		=> 'mp_payment_method'
			);
			$first = false;
		}
		
		$args = array(
			'sort_column'      => 'menu_order',
			'sort_order'       => 'ASC',
		);
		$options = array( NULL=>' ' );
		$pages = get_pages( $args );
		foreach( $pages as $page ) {
			$prefix = str_repeat( '&nbsp;', count( get_post_ancestors( $page ) )*3 );
			$options[$page->ID] = $prefix . $page->post_title;
		}
		
		$form_fields['custom_template_page_id'] = array(
			'title'				=> __( 'Use this page for payment template', 'mangopay' ),
			'description'		=> __( 'The page needs to be secured with https', 'mangopay' ),
			'id'				=> 'custom_template_page_id',
			'type'				=> 'select',
			'label'				=> __( 'Use this page for payment template', 'mangopay' ),
			'default'			=> '',
			'class'				=> 'wc-enhanced-select-nostd',
			'css'				=> 'min-width:300px;',
			'desc_tip' 			=> __( 'Page contents:', 'woocommerce' ) . ' [' . apply_filters( 'mangopay_payform_shortcode_tag', 'mangopay_payform' ) . ']',
			'placeholder'		=> __( 'Select a page&hellip;', 'woocommerce' ),
			'options'			=> $options,
			'custom_attributes'	=> array( 'placeholder'	=> __( 'Select a page&hellip;', 'woocommerce' ) )
		);

		$this->id					= 'mangopay';
		$this->icon					= ''; //plugins_url( '/img/card-icons.gif', dirname( __FILE__ ) );
		$this->has_fields			= true;		// Payment on third-party site with redirection
		$this->method_title			= __( 'MANGOPAY', 'mangopay' );
		$this->method_description	= __( 'MANGOPAY', 'mangopay' );
		$this->method_description	.= '<br/>' . __( 'Payment types marked with a * will need to be activated for your account. Please contact MANGOPAY.', 'mangopay' );
		$this->title				= __( 'Online payment', 'mangopay' );
		$this->form_fields			= $form_fields;
		$this->supports 			= array( 'refunds' );	// || default_credit_card_form
	}
	
	/**
	 * Payform health-check
	 * 
	 */
	public function validate_custom_template_page_id_field( $key ) {
		// get the posted value
		$value = $_POST[ $this->plugin_id . $this->id . '_' . $key ];
		
		if( !$value )
			return $value;
		
		$url = get_permalink( $value );
		
		if( !preg_match( '/^https/', $url ) )
			$url = preg_replace( '/^http/', 'https', $url );
		
		$response = wp_remote_get( $url, array( 'timeout'=>2, 'sslverify'=>true ) );
		
		if( is_wp_error( $response ) ) {
			$this->error_notice_display( 'The payment template page cannot be reached with https.' );
			return '';
		}
		
		if( $page = get_post( $value ) ) {
			if( !preg_match( '/[mangopay_payform]/', $page->post_content ) ) {
				/** Add the shortcode **/
				$page->post_content = $page->post_content . '[mangopay_payform]';
				wp_update_post( $page );
			}
		}
		
		return $value;
	}
	
	/**
	 * Error notice display function
	 * 
	 */
	private function error_notice_display( $msg ) {
		$class = 'notice notice-error';
		$message = __( $msg, 'mangopay' );
		printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
	}
	
	/**
	 * Logging method.
	 * @param string $message
	 */
	public static function log( $message ) {
		if ( self::$log_enabled ) {
			if ( empty( self::$log ) ) {
				self::$log = new WC_Logger();
			}
			self::$log->add( 'paypal', $message );
		}
	}

	/**
	 * Check if the gateway is available for use
	 *
	 * @return bool
	 */
	public function is_available() {

		$is_available = ( 'yes' === $this->enabled ) ? true : false;
		
		/** This payment method can't be used for unsupported curencies **/
		$currency	= get_woocommerce_currency();
		if( !in_array( $currency, $this->allowed_currencies ) )
			$is_available = false;

		/** This payment method can't be used if a Vendor does not have a MP account **/
		if( $items = WC()->cart->cart_contents ) {
			foreach( $items as $item ) {
				$item_object	= $item['data'];
				$vendor_id		= $item_object->post->post_author;
				
				/** We store a different mp_user_id for production and sandbox environments **/
				$umeta_key = 'mp_user_id';
				if( !mpAccess::getInstance()->is_production() )
					$umeta_key .= '_sandbox';
				if( !get_user_meta( $vendor_id, $umeta_key, true ) ) 
					$is_available = false;
			}
		}
		return $is_available;
	}
	
	/**
	 * Injects some jQuery to improve credit card selection admin
	 *
	 */
	public function admin_options() {
		parent::admin_options();
		?>
		<script>
		(function($) {
			$(document).ready(function() {
				if( $('#woocommerce_mangopay_enabled').is(':checked') ){
					//enable checkboxes
					checkboxSwitch( true );
				} else {
					//disable checkboxes
					checkboxSwitch( false );
				}
				$('#woocommerce_mangopay_enabled').on( 'change', function( e ){
					checkboxSwitch($(this).is(':checked'));
				});
				$('.mp_payment_method.readonly').live('click', function( e ) {
					e.preventDefault();
					//console.log('clicked');
				});
			});
			function checkboxSwitch( current ) {
				//console.log( current );
				if( current ) {
					//console.log( 'yes' );
					$('.mp_payment_method').removeAttr('readonly').removeClass('readonly');
				} else {
					//console.log( 'no' );
					$('.mp_payment_method').attr('readonly', true).addClass('readonly');
				}
			}
		})( jQuery );
		</script>
		<?php
	}
	
	/**
	 * Display our payment-related fields
	 * 
	 */
	public function payment_fields() {
		$selected = null;
		if( !empty( $_POST['mp_card_type'] ) ) {
			$selected = $_POST['mp_card_type'];
		} elseif( !empty( $_POST['post_data'] ) ) {
			parse_str( $_POST['post_data'], $post_data );
			if( !empty( $post_data['mp_card_type'] ) )
				$selected = $post_data['mp_card_type'];
		}
		?>
		<div class="mp_payment_fields">
		
		<?php if( 'yes' == $this->get_option('enabled_BANK_WIRE') ) : ?>
			<div class="mp_pay_method_wrap">
			<div class="mp_card_dropdown_wrap">
			<input type="radio" name="mp_payment_type" class="mp_payment_type card" value="card" checked="checked" />
			<label for="mp_payment_type"><?php _e( 'Use a credit card', 'mangopay' ); ?>&nbsp;</label>
		<?php else: ?>
			<label for="mp_card_type"><?php _e( 'Credit card type:', 'mangopay' ); ?>&nbsp;</label>
		<?php endif; ?>
		
		<select name="mp_card_type" id="mp_card_type">
		<?php foreach( $this->available_card_types as $type=>$label ) : if( 'yes' == $this->get_option('enabled_'.$type) ) : ?>
			<?php if( 'BANK_WIRE' == $type ) continue; ?>
			<option value="<?php echo $type; ?>" <?php selected( $type, $selected ); ?>><?php _e( $label, 'mangopay' ); ?></option>
		<?php endif; endforeach; ?>
		</select>
		
		<?php if( 'yes' == $this->get_option('enabled_BANK_WIRE') ) : ?>
			</div>
			<div class="mp_spacer">&nbsp;</div>
			<div class="mp_direct_dropdown_wrap">
			<input type="radio" name="mp_payment_type" value="bank_wire" />
			<label for="mp_payment_type"><?php _e( 'Use a direct bank wire', 'mangopay' ); ?></label>
			</div>
			</div>
		<?php endif; ?>
		
		<script>
		(function($) {
			$(document).ready(function() {
				$('#mp_card_type').on('change click', function( e ){
					$('.mp_payment_type.card').attr('checked','checked');
				});
			});
		})( jQuery );
		</script>
		
		</div>
		<?php
	}

	/**
	 * Redirects to MP card payment form
	 * 
	 * @param int $order_id
	 * @return array status
	 */
	public function process_payment( $order_id ) {

		if( isset( $_POST['mp_card_type'] ) && $_POST['mp_card_type'] ) {
			$mp_card_type = $_POST['mp_card_type'];
		} else {
			$mp_card_type = 'CB_VISA_MASTERCARD';
		}

		if( 'BANK_WIRE' == $mp_card_type || 'bank_wire' == $_POST['mp_payment_type'] )
			return $this->process_bank_wire( $order_id );

		$order		= wc_get_order( $order_id );
		
		if( !$wp_user_id = get_current_user_id() )
			$wp_user_id	= WC_Session_Handler::generate_customer_id();
																			
		$return_url	= $this->get_return_url( $order );
		
		$locale = 'EN';
		list( $locale_minor, $locale_major ) = preg_split( '/_/', get_locale() );
		if( in_array( $locale_minor, $this->supported_locales ) )
			$locale = strtoupper( $locale_minor );
		
		$mp_template_url = false;
		if( $custom_template_page_id = $this->get_option( 'custom_template_page_id' ) ) {
			if( $url = get_permalink( $custom_template_page_id ) ) {
				if( !preg_match( '/^https/', $url ) )
					$url = preg_replace( '/^http/', 'https', $url );
				$mp_template_url = $url;
			}
		}
    
		$return = mpAccess::getInstance()->card_payin_url(
			$order_id,						// Used to fill-in the "Tag" optional info
			$wp_user_id, 					// WP User ID
			($order->order_total * 100),	// Amount
			$order->order_currency,			// Currency
			0,								// Fees
			$return_url,					// Return URL
			$locale,						// For "Culture" attribute
			$mp_card_type,					// CardType
			$mp_template_url				// Optional template URL
		);
		
		
		if( false === $return ) {
			$error_message = __( 'Could not create the MANGOPAY payment URL', 'mangopay' );
			wc_add_notice( __( 'Payment error:', 'mangopay' ) . ' ' . $error_message, 'error' );
			//throw new Exception( $error_message );
			return;
		}
    
		$transaction_id = $return['transaction_id'];
		update_post_meta( $order_id, 'mangopay_payment_type', 'card' );
		update_post_meta( $order_id, 'mangopay_payment_ref', $return );
		update_post_meta( $order_id, 'mp_transaction_id', $transaction_id );
		
		/** update the history of transaction ids for this order **/
		if( 
			( $transaction_ids = get_post_meta( $order_id, 'mp_transaction_ids', true ) ) &&
			is_array( $transaction_ids )
		) {
			$transaction_ids[] = $transaction_id;
		} else {
			$transaction_ids = array( $transaction_id );
		}
		update_post_meta( $order_id, 'mp_transaction_ids', $transaction_ids );
		
		return array(
			'result'	=> 'success',
			'redirect'	=> $return['redirect_url']
		);
	}
		
	/**
	 * Process Direct Bank Wire payment types
	 * 
	 */
	private function process_bank_wire( $order_id ) {

		$order		= wc_get_order( $order_id );

		if( !$wp_user_id = get_current_user_id() )
			$wp_user_id	= WC_Session_Handler::generate_customer_id();
	
		$return_url	= $this->get_return_url( $order );

		$ref = mpAccess::getInstance()->bankwire_payin_ref(
			$order_id,						// Used to fill-in the "Tag" optional info
			$wp_user_id, 					// WP User ID
			($order->order_total * 100),	// Amount
			$order->order_currency,			// Currency
			0								// Fees
		);

		if( !$ref ) {
			$error_message = __( 'MANGOPAY Bankwire Direct payin failed', 'mangopay' );
			wc_add_notice( __( 'Payment error:', 'mangopay' ) . ' ' . $error_message, 'error' );
			//throw new Exception( $error_message );
			return;
		}
		
		$transaction_id = $ref->Id;
		update_post_meta( $order_id, 'mangopay_payment_type', 'bank_wire' );
		update_post_meta( $order_id, 'mangopay_payment_ref', $ref );
		update_post_meta( $order_id, 'mp_transaction_id', $transaction_id );
		
		/** update the history of transaction ids for this order **/
		if(
			( $transaction_ids = get_post_meta( $order_id, 'mp_transaction_ids', true ) ) &&
			is_array( $transaction_ids )
		) {
			$transaction_ids[] = $transaction_id;
		} else {
			$transaction_ids = array( $transaction_id );
		}
		update_post_meta( $order_id, 'mp_transaction_ids', $transaction_ids );
		
		return array(
			'result'	=> 'success',
			'redirect'	=> $return_url
		);
	}
	
	/**
	 * Process refund.
	 *
	 * If the gateway declares 'refunds' support, this will allow it to refund.
	 * a passed in amount.
	 *
	 * @param  int $order_id
	 * @param  float $amount
	 * @param  string $reason
	 * @return bool|WP_Error True or false based on success, or a WP_Error object.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		
		if( !$mp_transaction_id = get_post_meta( $order_id, 'mp_transaction_id', true ) ) {
			$this->log( 'Refund Failed: No MP transaction ID' );
			return new WP_Error( 'error', __( 'Refund Failed: No transaction ID', 'woocommerce' ) );
		}
		
		/** If there is a recorded successful transaction id, take it instead **/
		if( $mp_success_transaction_id = get_post_meta( $order_id, 'mp_success_transaction_id', true ) )
			$mp_transaction_id = $mp_success_transaction_id;
		
		$order 		= new WC_Order( $order_id );
		$wp_user_id = $order->customer_user;
		
		$result = mpAccess::getInstance()->card_refund(
			$order_id,				// Order_id
			$mp_transaction_id, 	// transaction_id
			$wp_user_id, 			// wp_user_id
			($amount * 100),		// Amount
			$order->order_currency, // Currency
			$reason					// Reason
		);
		
		if( $result && 'SUCCEEDED' == $result->Status ) {

			$this->log( 'Refund Result: ' . print_r( $result, true ) );

			$order->add_order_note( sprintf( 
				__( 'Refunded %s - Refund ID: %s', 'woocommerce' ), 
				( $result->CreditedFunds->Amount / 100 ), 
				$result->Id 
			) );

			return true;
			
		} else {

			$this->log( 'Refund Failed: ' . $result->ResultCode . ' - ' . $result->ResultMessage );
			return new WP_Error( 'error', sprintf( 
				__( 'Refund failed: %s - %s', 'mangopay' ),
				$result->ResultCode,
				$result->ResultMessage 
			) );
		}
	}
}
?>