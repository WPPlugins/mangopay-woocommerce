<?php
/**
 * MANGOPAY WooCommerce plugin main class
 * 
 * @author yann@abc.fr
 * @see: https://github.com/Mangopay/wordpress-plugin
 *
 * Comment shorthand notations:
 * WP = WordPress core
 * WC = WooCommerce plugin
 * WV = WC-Vendor plugin
 * DB = MANGOPAY dashboard
 *
 */
class mangopayWCMain {
	
	/** Configuration variables loaded from conf.inc.php by load_config() **/
	private $defaults;				// Will hold plugin default values
	private $allowed_currencies;
	private $account_types;
	private $mangopayWCValidation;	// Will hold user profile validation class
		
	/** Class variables **/
	private $mp;				// This will store our mpAccess class instance
	private $_current_order;	// This stores the current order when listing orders in the WV dashboard
	private $instapay = false;	// WV feature: Instantly pay vendors their commission when an order is made
	public $options;			// Public because shared with mangopayWCAdmin. TODO: refactor
	//TODO: options should not be public because they contain the decrypted passphrase!
	
	/**
	 * Class constructor
	 *
	 */
	public function __construct( $version='0.2.2' ) {
	
		/** Load configuration values from config.inc.php **/
		$this->load_config();
		
		/** Switch PHP debug mode on/off **/
		if( mangopayWCConfig::DEBUG ) {
			error_reporting( -1 );	// to enable all errors
			ini_set( 'display_errors', 1 );
			ini_set( 'display_startup_errors', 1 );
		}
		        
        if(preg_match('#shop_settings#',$_SERVER['REQUEST_URI'])){
        add_action( 'wp_enqueue_scripts', array($this,'enqueue_mango_scripts' ));
        }
        
		/** Instantiate mpAccess (need to do before decrypt options because need tmp dir) **/
		$this->mp = mpAccess::getInstance();
	
		/** Get stored plugin settings **/
		$this->defaults['plugin_version'] = $version;
		$this->options = $this->decrypt_passphrase( get_option( mangopayWCConfig::OPTION_KEY, $this->defaults ) );
		
		/** Set the MANGOPAY environment ( production or sandbox + login and passphrase ) **/
		//TODO: move details of this logic outside the constructor
		if( isset( $this->options['prod_or_sandbox'] ) )
			if( 'prod' == $this->options['prod_or_sandbox'] ) {
				$this->mp->setEnv( 
					'prod',
					$this->options['prod_client_id'],
					$this->options['prod_passphrase'],
					$this->options['default_buyer_status'],
					$this->options['default_vendor_status'],
					$this->options['default_business_type'],
				 	mangopayWCConfig::DEBUG
				);
			} else {
				$this->mp->setEnv(
					'sandbox',
					$this->options['sand_client_id'],
					$this->options['sand_passphrase'],
					$this->options['default_buyer_status'],
					$this->options['default_vendor_status'],
					$this->options['default_business_type'],
					mangopayWCConfig::DEBUG
				);
			}
			
		/** Get WV instapay option status **/
		$wv_options = get_option( mangopayWCConfig::WV_OPTION_KEY );
		if( isset( $wv_options['instapay'] ) && $wv_options['instapay'] )
			$this->instapay = true;
			
		/** The activation hook must be a static function **/
		register_activation_hook( __FILE__, array( 'mangopayWCPlugin', 'on_plugin_activation' ) );

		/** Instantiate user profile field validations class **/
		$this->mangopayWCValidation = new mangopayWCValidation( $this );
		
		/** Instantiate admin interface class if necessary **/
		$mangopayWCAdmin = null;
		if( is_admin() )
			$mangopayWCAdmin = new mangopayWCAdmin( $this );
		
		/** Instantiate incoming webhooks class if necessary **/
		if( !is_admin() )
			$mangopayWCWebHooks = new mangopayWCWebHooks( $this );
		
		/** Setup all our WP/WC/WV hooks **/
		mangopayWCHooks::set_hooks( $this, $mangopayWCAdmin );
		
		/** Manage plugin upgrades **/
		if( empty( $this->options['plugin_version'] ) ) {
			mangopayWCPlugin::upgrade_plugin( '0.2.2', $version, $this->options );
		} elseif( $this->options['plugin_version'] != $version ) {
			mangopayWCPlugin::upgrade_plugin( $this->options['plugin_version'], $version, $this->options );
		}
        
	}
        
    public function intercept_messages_cancel_order($message ){
        $all_notices  = WC()->session->get( 'wc_notices', array() );
        foreach($all_notices as $notices){
            foreach($notices as $notice){
                if(preg_match('#span class\=\"cancelmessagealone#',$notice)){
                    return false;
                }
            }
        }
        return $message;
    }
    
    public function enqueue_mango_scripts(){
        $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$assets_path          = str_replace( array( 'http:', 'https:' ), '', WC()->plugin_url() ) . '/assets/';
        
//        wp_enqueue_style( 
//            'wc-country-select2-css', 
//             $assets_path.'css/select2.css',
//            array(),
//            '2.6.4'
//        );         
        wp_enqueue_style( 
            'wc-country-select2admin-css', 
             $assets_path.'css/admin.css'
        );         
        
        wp_enqueue_script( 
            'wc-country-select2', 
             $assets_path.'js/select2/select2' . $suffix . '.js', 
            array( 'jquery')
        );  
        wp_enqueue_script( 
            'wc-country-select', 
            plugins_url( 'js/country-select.js', dirname( __FILE__ ) ) , 
            array( 'jquery'), 
            WC_VERSION, 
            true 
        );
    }

	/**
	 * Load plugin configuration and default values from config.inc.php
	 * 
	 */
	private function load_config() {
		$this->defaults				= mangopayWCConfig::$defaults;
		$this->allowed_currencies	= mangopayWCConfig::$allowed_currencies;
		$this->account_types		= mangopayWCConfig::$account_types;
	}
	
	/**
	 * Add new register fields for WooCommerce registration.
	 * We need this to enforce mandatory/required fields that we need for createMangoUser
	 * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
	 * This is a WC 'woocommerce_register_form_start' action hook - must be a public method
	 *
	 * @return string Register fields HTML.
	 *
	 */
	public function wooc_extra_register_fields() {
    
		wp_enqueue_script('jquery-ui-datepicker');
		$this->localize_datepicker();
		
		//wp_register_style('jquery-ui', '//ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery-ui.css');
		wp_register_style(
			'jquery-ui',
			plugins_url( '/css/jquery-ui.css', dirname( __FILE__ ) ),
			array(), 
			'1.8'
		);
		wp_enqueue_style( 'jquery-ui' );
		        
        $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$assets_path          = str_replace( array( 'http:', 'https:' ), '', WC()->plugin_url() ) . '/assets/';
		$frontend_script_path = $assets_path . 'js/frontend/';

        wp_enqueue_script( 
            'wc-country-select', 
            $frontend_script_path . 'country-select' . $suffix . '.js', 
            array( 'jquery'), 
            WC_VERSION, 
            true 
        );      
        
		/**
		 * For country drop-down
		 * @see: https://wordpress.org/support/topic/woocommerce-country-registration-field-in-my-account-page-not-working
		 *
		 */
		$countries_obj = new WC_Countries();
		$countries = $countries_obj->__get('countries');
		?>
		
		<?php if( !is_wc_endpoint_url( 'edit-account' ) ) : ?>
			
			<p class="form-row form-row-first">
				<label for="reg_billing_first_name"><?php _e( 'First name', 'woocommerce' ); ?> <span class="required">*</span></label>
				<input type="text" class="input-text" name="billing_first_name" id="reg_billing_first_name" value="<?php if ( ! empty( $_POST['billing_first_name'] ) ) esc_attr_e( $_POST['billing_first_name'] ); ?>" />
			</p>
		
			<p class="form-row form-row-last">
				<label for="reg_billing_last_name"><?php _e( 'Last name', 'woocommerce' ); ?> <span class="required">*</span></label>
				<input type="text" class="input-text" name="billing_last_name" id="reg_billing_last_name" value="<?php if ( ! empty( $_POST['billing_last_name'] ) ) esc_attr_e( $_POST['billing_last_name'] ); ?>" />
			</p>
			
		<?php endif; ?>
			
		<div class="clear"></div>
		
		<?php
		$value = '';
		if( ! empty( $_POST['user_birthday'] ) ) 
			$value = esc_attr( $_POST['user_birthday'] );
		if( 
			is_wc_endpoint_url( 'edit-account' ) && 
			( $wp_user_id = get_current_user_id() )
		)
			$value = date_i18n( $this->supported_format( get_option( 'date_format' ) ), strtotime( get_user_meta( $wp_user_id, 'user_birthday', true ) ) );
		?>
		<p class="form-row form-row-wide">
			<label for="reg_user_birthday"><?php _e( 'Birthday', 'mangopay' ); ?> <span class="required">*</span></label>
			<input type="text" class="input-text calendar" name="user_birthday" id="reg_user_birthday" value="<?php echo $value; ?>" />
		</p>
				
		<?php
		$cur_value = '';
		if( ! empty( $_POST['user_nationality'] ) ) 
			$cur_value = esc_attr( $_POST['user_nationality'] );
		if( 
			is_wc_endpoint_url( 'edit-account' ) && 
			( $wp_user_id = get_current_user_id() )
		)
			$cur_value = get_user_meta( $wp_user_id, 'user_nationality', true );
		?>
		<p class="form-row form-row-wide">
			<label for="reg_user_nationality"><?php _e( 'Nationality', 'mangopay' ); ?> <span class="required">*</span></label>
			<select class="nationality_select" name="user_nationality" id="reg_user_nationality">
				<option value=""><?php _e( 'Select a country...', 'mangopay' ); ?></option>
			<?php foreach ($countries as $key => $value): $selected=($key==$cur_value?'selected="selected"':''); ?>
				<option value="<?php echo $key?>" <?php echo $selected?>><?php echo $value?></option>
			<?php endforeach; ?>
			</select>
		</p>
			 
        <?php
        $allfields = WC()->checkout->checkout_fields;
        woocommerce_form_field( 'billing_country', $allfields['billing']['billing_country'], WC()->checkout->get_value( 'billing_country' ) ); 
        woocommerce_form_field( 'billing_state', $allfields['billing']['billing_state'], WC()->checkout->get_value( 'billing_state' ) ); 
        ?>            
        
		<?php      
        /** check if user is connected **/
        $add_hidden_user_mp_status = false;
        $add_hidden_user_business_type = false;
        $user_mp_status = '';
        if($wp_user_id = get_current_user_id()){
            
            /** get user data **/
            $user_mp_status = get_user_meta( $wp_user_id, 'user_mp_status', true );
            $user_business_type = get_user_meta( $wp_user_id, 'user_business_type', true );
            
            /** if we have status => read only else input **/
            if($user_mp_status){
                $add_hidden_user_mp_status = $user_mp_status;
            }
            
            /** if we have business type read only else input **/
            if($user_business_type && $user_mp_status!="individual"){
                $add_hidden_user_business_type = $user_business_type;
            }
            
        } /** user not connected **/
        ?>
        
        <?php
        /** PRINT HIDDEN user_mp_status
         * IF user is connected and mp status is set ($add_hidden_user_mp_status!=false)
         * OR default_buyer_status != either
         * => echo if necessary and put hidden
         **/
        if($add_hidden_user_mp_status!=false || 
            (isset( $this->options['default_buyer_status'] ) && 
			'either' != $this->options['default_buyer_status'])){
        ?>
            <?php 
            /** print the info if we are in edit account and not print in checkout **/
            if( is_wc_endpoint_url( 'edit-account' ) && $user_mp_status ) { ?>
                <p>
                    <?php echo __( 'User status', 'mangopay' ) . ': ' . ucfirst(__( $user_mp_status, 'mangopay' )); ?>
                </p>
            <?php } ?>
                
            <?php
            if($add_hidden_user_mp_status==false){
                $add_hidden_user_mp_status = $this->options['default_buyer_status'];
                $user_mp_status = $this->options['default_buyer_status'];
            }
            ?>                
            <input type="hidden" name="user_mp_status" value="<?php echo $user_mp_status; ?>" />
        <?php
        }else{ /** PRINT INPUT user_mp_status **/
        ?> 
            
            <?php /** if already try to validate and return with errors, fill again the field **/
            $user_mp_status_form = '';
            if( ! empty( $_POST['user_mp_status'] ) ) {$user_mp_status_form = esc_attr( $_POST['user_mp_status'] ); }
            ?>
            
            <p class="form-row form-row-wide">
            <label for="reg_user_mp_status"><?php _e( 'User status', 'mangopay' ); ?> <span class="required">*</span></label>
            <select class="mp_status_select" name="user_mp_status" id="reg_user_mp_status" data-changesomething="1">
                <option value=''><?php _e( 'Select option...', 'mangopay' ); ?></option>
                <option value='individual' <?php selected( 'individual', $user_mp_status_form ); ?>><?php _e( 'Individual', 'mangopay' ); ?></option>
                <option value='business' <?php selected( 'business', $user_mp_status_form ); ?>><?php _e( 'Business user', 'mangopay' ); ?></option>
            </select>
            </p>
                
        <?php } //$add_hidden_user_mp_status ?>
        
        <?php
        /** PRINT HIDDEN default_buyer_status **/        
        /** test if we need the field to be input
                 * if user is individual already no need for this field
                 * test if actual config of mango allow user to be business type
                 **/            
        if( $user_mp_status!="individual" ){

            if( $add_hidden_user_business_type!=false ||
                (isset( $this->options['default_business_type'] ) && 
                'either' != $this->options['default_business_type'])){

                //check
                if( is_wc_endpoint_url( 'edit-account' ) && !empty( $user_business_type ) ) { ?>
                        <p><?php 
                        echo __( 'Business type', 'mangopay' ) . ': ' . __( ucfirst($user_business_type), 'mangopay' ); ?></p>
                <?php } //end is_wc_endpoint_url ?>

                <?php
                if($add_hidden_user_business_type==false){
                    $list_admin_front = array('organisations'=>'organisation','soletraders'=>'soletrader','businesses'=>'business');
                    $user_business_type = $list_admin_front[$this->options['default_business_type']];
                }
                ?>
                <input type="hidden" name="user_business_type" value="<?php echo $user_business_type; ?>" />

            <?php }else{ //end $print_input_user_business_type  ?>

                    <?php 
                    /** let user choose only if the config let the choise **/
                    if(isset( $this->options['default_business_type'] ) && 
                       'businesses' == $this->options['default_buyer_status'] || 'either' == $this->options['default_buyer_status']){
                     ?>
                
                        <?php 
                        /** if already try to validate and return with errors, fill again the field **/
                        $user_business_type_form = '';
                        if( ! empty( $_POST['user_business_type'] ) ) { $user_business_type_form = esc_attr( $_POST['user_business_type'] ); }
                        ?>

                        <?php 
                        /** if the user status is already filled but not the type we need to cancel the Hidden **/
                        $display_none_business_type = 'display:none;';
                        if($add_hidden_user_mp_status != false){
                            $display_none_business_type = '';
                        }
                        ?>

                        <p class="form-row form-row-wide hide_business_type" style="<?php echo $display_none_business_type; ?>">
                            <label for="reg_user_business_type"><?php _e( 'Business type', 'mangopay' ); ?> <span class="required">*</span></label>
                            <select class="mp_btype_select" name="user_business_type" id="reg_user_business_type">
                                <option value=''><?php _e( 'Select option...', 'mangopay' ); ?></option>
                                <option value='organisation' <?php selected( 'organisation', $user_business_type_form ); ?>><?php _e( 'Organisation', 'mangopay' ); ?></option>
                                <option value='business' <?php selected( 'business', $user_business_type_form ); ?>><?php _e( 'Business', 'mangopay' ); ?></option>
                                <option value='soletrader' <?php selected( 'soletrader', $user_business_type_form ); ?>><?php _e( 'Soletrader', 'mangopay' ); ?></option>
                            </select>
                        </p>
                    
                    <?php }//'either' == $this->options['default_business_type'] ?>
                    
            <?php }//$add_hidden_user_business_type ?>
        <?php } ?>

		<script>
		(function($) {
			$(document).ready(function() {
				$('input.calendar').datepicker(datepickerL10n);
				if( 'business'==$('#reg_user_mp_status').val() )
					$('.hide_business_type').show();
			});
			$('#reg_user_mp_status').on('change',function(e){
				if( 'business'==$('#reg_user_mp_status').val() ) {
					$('.hide_business_type').show();
				} else {
					$('.hide_business_type').hide();
				}
			});
		})( jQuery );
		</script>
		<?php
	}
	
	public function wooc_account_details_required( $required ) {
		$required['user_birthday']		= __( 'Birthday', 'mangopay' );
		//$required['user_nationality']	= __( 'Nationality', 'mangopay' );
		$required['billing_country']	= __( 'Country of residence', 'mangopay' );
		return $required;
	}
			
  /**
	 * Validate the extra register fields.
	 * We need this to enforce mandatory/required fields that we need for createMangoUser
	 * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
	 * This is a WC 'woocommerce_register_post' action hook - must be a public method
	 *
	 * @param  string $username          Current username.
	 * @param  string $email             Current email.
	 * @param  object $validation_errors WP_Error object.
	 *
	 * @return void
	 */
	public function wooc_validate_extra_register_fields_user( $validation_errors,$dontknow, $user) {
    
    $data_post = $_POST;
    
    $list_post_keys = array(
      'user_birthday'=>'date',
      'user_nationality'=>'country',
      'billing_country'=>'country',
      'user_mp_status'=>'status',
      'user_business_type'=>'businesstype',
    );
    
    foreach ($list_post_keys as $key=>$value) {
      $function_name = 'validate_'.$value;
      $data_to_send = array();
      $data_to_send['data_post'] = $data_post;
      $data_to_send['key_field'] = $key;
      $data_to_send['wp_error'] = $validation_errors;
      $data_to_send['main_options'] = $this->options;
      $data_to_send['caller_func'] = 'wooc_validate_extra_register_fields_user';
      $this->mangopayWCValidation->$function_name($data_to_send);
    }
    
	}
  
  public function wooc_validate_extra_register_fields_userfront( $validation_errors,$user) {

    $data_post = $_POST;
    
    $list_post_keys = array(
      'user_birthday'=>'date',
      'user_nationality'=>'country',
      'billing_country'=>'country',
      'user_mp_status'=>'status',
      'user_business_type'=>'businesstype',
    );
    
    foreach ($list_post_keys as $key=>$value) {
      $function_name = 'validate_'.$value;
      $data_to_send = array();
      $data_to_send['data_post'] = $data_post;
      $data_to_send['key_field'] = $key;
      $data_to_send['main_options'] = $this->options;
      //$data_to_send['double_test'] = array('user_birthday'=>1);
      $data_to_send['caller_func'] = 'wooc_validate_extra_register_fields_userfront';
      $this->mangopayWCValidation->$function_name($data_to_send);
    }
    
	}
  
  
	/**
	 * Validate the extra register fields.
	 * We need this to enforce mandatory/required fields that we need for createMangoUser
	 * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
	 * This is a WC 'woocommerce_register_post' action hook - must be a public method
	 *
	 * @param  string $username          Current username.
	 * @param  string $email             Current email.
	 * @param  object $validation_errors WP_Error object.
	 *
	 * @return void
	 */
	public function wooc_validate_extra_register_fields( $username, $email, $validation_errors ) {
		
    $data_post = $_POST;
    
    $list_post_keys = array(
      'billing_first_name'=>'single',
      'billing_last_name'=>'single',
      'user_birthday'=>'date',
      'user_nationality'=>'country',
      'billing_country'=>'country',
      'user_mp_status'=>'status',
      'user_business_type'=>'businesstype',
    );
    
    foreach ($list_post_keys as $key=>$value) {
      $function_name = 'validate_'.$value;
      $data_to_send = array();
      $data_to_send['data_post'] = $data_post;
      $data_to_send['key_field'] = $key;
      $data_to_send['wp_error'] = $validation_errors;
      $data_to_send['main_options'] = $this->options;
      $data_to_send['double_test'] = array('user_birthday'=>1);
      $data_to_send['caller_func'] = 'wooc_validate_extra_register_fields';
      $this->mangopayWCValidation->$function_name($data_to_send);
    }
    
	}
  /**
	 * Validate the extra register fields.
	 * We need this to enforce mandatory/required fields that we need for createMangoUser
	 * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
	 * This is a WC 'woocommerce_checkout_process' action hook - must be a public method
	 *
	 * @return void
	 */
	public function wooc_validate_extra_register_fields_checkout() {
    $data_post = $_POST;
    $list_post_keys = array(
      //'billing_first_name'=>'single',
      //'billing_last_name'=>'single',
      'user_birthday'=>'date',
      'user_nationality'=>'country',
      'billing_country'=>'country',
      'user_mp_status'=>'status',
      'user_business_type'=>'businesstype',
    );
    
    foreach ($list_post_keys as $key=>$value) {
      $function_name = 'validate_'.$value;
      $data_to_send = array();
      $data_to_send['data_post'] = $data_post;
      $data_to_send['key_field'] = $key;
      $data_to_send['main_options'] = $this->options;
      $data_to_send['caller_func'] = 'wooc_validate_extra_register_fields_checkout';
      $this->mangopayWCValidation->$function_name($data_to_send);
    }
    
	}
			
	/**
	 * Save the extra register fields.
	 * We need this to enforce mandatory/required fields that we need for createMangoUser
	 * @see: https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
	 * This is a WC 'woocommerce_created_customer' action hook - must be a public method
	 * 
	 * @param  int  $customer_id Current customer ID.
	 *
	 * @return void
	 */
	public function wooc_save_extra_register_fields( $customer_id ) {
        
		if ( isset( $_POST['billing_first_name'] ) ) {
			// WordPress default first name field.
			update_user_meta( $customer_id, 'first_name', sanitize_text_field( $_POST['billing_first_name'] ) );
	
			// WooCommerce billing first name.
			update_user_meta( $customer_id, 'billing_first_name', sanitize_text_field( $_POST['billing_first_name'] ) );
		}
	
		if ( isset( $_POST['billing_last_name'] ) ) {
			// WordPress default last name field.
			update_user_meta( $customer_id, 'last_name', sanitize_text_field( $_POST['billing_last_name'] ) );
	
			// WooCommerce billing last name.
			update_user_meta( $customer_id, 'billing_last_name', sanitize_text_field( $_POST['billing_last_name'] ) );
		}
	
		if ( isset( $_POST['user_birthday'] ) ) {
			// New custom user meta field
			update_user_meta( 
				$customer_id, 
				'user_birthday', 
				$this->convertDate( $_POST['user_birthday'] )
			);
		}
		
		if ( isset( $_POST['user_nationality'] ) ) {
			// New custom user meta field
			update_user_meta( $customer_id, 'user_nationality', sanitize_text_field( $_POST['user_nationality'] ) );
		}
		
		if ( isset( $_POST['billing_country'] ) ) {
			// WooCommerce billing country.
			update_user_meta( $customer_id, 'billing_country', sanitize_text_field( $_POST['billing_country'] ) );
		}
        
        if ( isset( $_POST['billing_state'] ) ) {
			// WooCommerce billing state.
			update_user_meta( $customer_id, 'billing_state', sanitize_text_field( $_POST['billing_state'] ) );
		}
		
		if ( isset( $_POST['user_mp_status'] ) ) {
			// New custom user meta field
			update_user_meta( $customer_id, 'user_mp_status', sanitize_text_field( $_POST['user_mp_status'] ) );
		}
		
		if ( isset( $_POST['user_business_type'] ) ) {
			// New custom user meta field
			update_user_meta( $customer_id, 'user_business_type', sanitize_text_field( $_POST['user_business_type'] ) );
		}
		
		$mp_user_id = $this->mp->set_mp_user( $customer_id );
		$this->mp->set_mp_wallet( $mp_user_id );
		
		/** Update MP user account **/
		$this->on_shop_settings_saved( $customer_id );
	}
	
	
	/**
	 * Add the required fields to the checkout form
	 * @see: https://docs.woothemes.com/document/tutorial-customising-checkout-fields-using-actions-and-filters/
	 * 
	 * @param array $fields
	 * @return array $fields
	 */
	public function custom_override_checkout_fields( $fields ) {
    
		/** We store a different mp_user_id for production and sandbox environments **/
		$umeta_key = 'mp_user_id';
		if( !$this->mp->is_production() )
			$umeta_key .= '_sandbox';

        //----------------------------------------------------------------------------------------------
        ////possibility - 1, the conf : default_buyer_status is on "individuals" -> no need to had the field
        //do nothing

        //possibility - 2, the conf : default_buyer_status is on "either" -> add the field "user mp status" AND if "business" selected "business type"
        //if user does NOT have USER MP STATUS we ask
        if('either' == $this->options['default_buyer_status']):
          if(!get_user_meta( get_current_user_id(), 'user_mp_status', true )):
             $fields = $this->add_usermpstatus_field($fields);
          endif;
        endif;

        //possibility - 3, the conf : default_buyer_status is on "business" or either -> add the field "business type"
        //this field will be hidden by javascript, it's dependent of "user mp status" field

        if('business' == $this->options['default_buyer_status'] || 'businesses' == $this->options['default_buyer_status'] || 'either' == $this->options['default_buyer_status']):
          //and user does not have it AND the user is business type
          if(!get_user_meta( get_current_user_id(), 'user_mp_status', true ) == "business" 
              && !get_user_meta( get_current_user_id(), 'user_business_type', true )):
            $fields = $this->add_userbusinesstype_field($fields);
          endif;
        endif;

        //if user has no nationality ask
        if( !get_user_meta( get_current_user_id(), 'user_nationality', true ) ) {
          $fields = $this->add_usernationality_field($fields);
        }
            //if user has no birthday ask
        if( !get_user_meta( get_current_user_id(), 'user_birthday', true ) ) {
          $fields = $this->add_userbirthday_field($fields);
        }

        return $fields;
    }
  
    public function add_userbirthday_field($fields){
      wp_enqueue_script('jquery-ui-datepicker');			
      $this->localize_datepicker();

      //wp_register_style('jquery-ui', '//ajax.googleapis.com/ajax/libs/jqueryui/1.8/themes/base/jquery-ui.css');
      wp_register_style(
        'jquery-ui',
        plugins_url( '/css/jquery-ui.css', dirname( __FILE__ ) ),
        false, '1.8'
      );
      wp_enqueue_style( 'jquery-ui' );
      $fields['billing']['user_birthday'] = array(
        'label'		=> __( 'Birthday', 'mangopay' ),
        'required'	=> true,
        'class'		=> array( 'calendar' )
      );
      return $fields;
    }
  
    public function add_usernationality_field($fields){
      $countries_obj = new WC_Countries();
      $countries = $countries_obj->__get('countries');
      //array_unshift( $countries, __( 'Select a country...', 'mangopay' ) );
      $countries[NULL] = __( 'Select a country...', 'mangopay' );
      $fields['billing']['user_nationality'] = array(
        'type'		=> 'select',
        'label'		=> __( 'Nationality', 'mangopay' ),
        'options'	=> $countries,
        'required'	=> true
      );
      return $fields;
    }
  
    public function add_userbusinesstype_field ($fields){
      $fields['billing']['user_business_type'] = array(
        'type'		=> 'select',
        'label'		=> __( 'Business type', 'mangopay' ),
        'options'	=> array(
            ''				=> __( 'Select option...', 'mangopay' ),
            'organisation'	=> __( 'Organisation', 'mangopay' ),
            'business'		=> __( 'Business', 'mangopay' ),
            'soletrader'		=> __( 'Soletrader', 'mangopay' )
        ),
        'required'	=> true,
        'class'		=> array( 'hide_business_type' )
      );
      return $fields;
    }
  
    private function add_usermpstatus_field($fields){
     $fields['billing']['user_mp_status'] = array(
        'type'		=> 'select',
        'label'		=> __( 'User status', 'mangopay' ),
        'options'	=> array( 
          ''				=> __( 'Select option...', 'mangopay' ),
          'individual'	=> __( 'Individual', 'mangopay' ),
          'business'		=> __( 'Business user', 'mangopay' )
        ),
        'required'	=> true,
      ); 
     return $fields;
    }
	
	/**
	 * To enable the jQuery-ui calendar for the birthday field on the checkout form
	 */
	public function after_checkout_fields() {

		/** If the user is already logged-in no birthday field is present **/
		if(
			is_user_logged_in() &&
			get_user_meta( get_current_user_id(), 'user_birthday', true ) &&
			get_user_meta( get_current_user_id(), 'user_mp_status', true )
		)
			return;

		?>
		<script>
		(function($) {
			$(document).ready(function() {
				if( 'business'==$('#user_mp_status').val() ) {
					$('.hide_business_type').show();
				} else {
					<?php if( 'businesses' != $this->options['default_buyer_status'] || 'either' != $this->options['default_business_type'] ) : ?>
					$('.hide_business_type').hide();
					$('#user_business_type').val('organisation');
					<?php endif; ?>
				}
			});
			$('#user_mp_status').on('change',function(e){
				if( 'business'==$('#user_mp_status').val() ) {
					$('.hide_business_type').show();
					$('#user_business_type').val('');
				} else {
					$('.hide_business_type').hide();
					$('#user_business_type').val('organisation');
				}
			});
		})( jQuery );
		</script>
		<?php

		if( !wp_script_is( 'jquery-ui-datepicker', 'enqueued' ) )
			return;

		?>
		<script>
		(function($) {
			$(document).ready(function() {
				$('input.calendar, #user_birthday').datepicker(datepickerL10n);
			});
		})( jQuery );
		</script>
		<?php
	}
	
	/**
	 * Fires up when user role has been changed,
	 * ie. when pending_vendor becomes vendor
	 * This is a WP 'set_user_role' action hook - must be a public method
	 *
	 * @param int $user_id
	 * @param string $role
	 * @param array $old_roles
	 *
	 */
	public function on_set_user_role( $wp_user_id, $role, $old_roles ) {
		if( 'vendor' != $role || array( 'vendor') == $old_roles )
			return;
	
		/** This will create a BUSINESS MP account for that user if they did not have one **/
		$this->mp->set_mp_user( $wp_user_id, 'BUSINESS' );
	}
	
	/**
	 * Fires up when user profile has been registered,
	 * ie. when new user is created in the WP back-office
	 * This is a WP 'user_register' action hook - must be a public method
	 *
	 * @param int $wp_user_id
	 */
	public function on_user_register( $wp_user_id ) {

		/** Don't register the new user as MP User if he's pending vendor **/
		$wp_userdata = get_userdata( $wp_user_id );
		if(
				isset( $wp_userdata->wp_capabilities['pending_vendor'] ) ||
				( is_array($wp_userdata->wp_capabilities) && in_array( 'pending_vendor', $wp_userdata->wp_capabilities , true ))
		)
			return false;

		$mp_user_id = $this->mp->set_mp_user( $wp_user_id );
	}
	
	/**
	 * Fires up when WC shop settings have been saved
	 * This is a WV 'wcvendors_shop_settings_saved' action hook - must be a public method
	 *
	 * @param int $wp_user_id
	 * 
	 * Shared with mangopayWCAdmin. TODO: refactor
	 *
	 */
	public function on_shop_settings_saved( $wp_user_id ) {

		/* *
		 var_dump( $wp_userdata ); exit; //Debug 
		 /* gives:
		 object(WP_User)#431 (7) { ["data"]=> object(stdClass)#430 (10) { 
		 	["ID"]=> string(2) "49" 
		 	["user_login"]=> string(8) "y.dubois" 
		 	["user_pass"]=> string(34) "(hashed pw)" 
		 	["user_nicename"]=> string(8) "y-dubois" 
		 	["user_email"]=> string(19) "email@address.com" 
		 	["user_url"]=> string(0) "" 
		 	["user_registered"]=> string(19) "2016-02-26 10:23:07" 
		 	["user_activation_key"]=> string(0) "" 
		 	["user_status"]=> string(1) "0" 
		 	["display_name"]=> string(8) "y.dubois" 
		 } 
		 ["ID"]=> int(49) 
		 ["caps"]=> array(1) { ["customer"]=> bool(true) } 
		 ["cap_key"]=> string(15) "wp_capabilities" 
		 ["roles"]=> array(1) { [0]=> string(8) "customer" } 
		 ["allcaps"]=> array(2) { ["read"]=> bool(true) ["customer"]=> bool(true) } 
		 ["filter"]=> NULL } 
		*/
		$wp_userdata = get_userdata( $wp_user_id );
		$usermeta['user_email'] = $wp_userdata->user_email;
		
		/** For first and last name, we take the billing info if available **/
		$usermeta['first_name']			= get_user_meta( $wp_user_id, 'first_name', true );
		if( isset( $_POST['first_name'] ) && $_POST['first_name'] )
			$usermeta['first_name']		= $_POST['first_name'];
		if( $first_name = get_user_meta( $wp_user_id, 'billing_first_name', true ) )
			$usermeta['first_name']		= $first_name;
		
		$usermeta['last_name']			= get_user_meta( $wp_user_id, 'last_name', true );
		if( isset( $_POST['last_name'] ) && $_POST['last_name'] )
			$usermeta['last_name']		= $_POST['last_name'];
		if( $last_name = get_user_meta( $wp_user_id, 'billing_last_name', true ) )
			$usermeta['last_name']		= $last_name;
		
		$usermeta['address_1']			= get_user_meta( $wp_user_id, 'billing_address_1', true );
		$usermeta['city']				= get_user_meta( $wp_user_id, 'billing_city', true );
		$usermeta['postal_code']		= get_user_meta( $wp_user_id, 'billing_postcode', true );
		$usermeta['pv_shop_name']		= get_user_meta( $wp_user_id, 'pv_shop_name', true );
		$usermeta['billing_country']	= get_user_meta( $wp_user_id, 'billing_country', true );
	    if( isset( $_POST['billing_state'] ) )
	      $usermeta['billing_state']	= get_user_meta( $wp_user_id, 'billing_state', true );
	    
		$usermeta['user_birthday']		= get_user_meta( $wp_user_id, 'user_birthday', true );
		$usermeta['user_nationality']	= get_user_meta( $wp_user_id, 'user_nationality', true );
		
		$mp_user_id = $this->mp->set_mp_user( $wp_user_id );
		
		$result = $this->mp->update_user( $mp_user_id, $usermeta );
		
		/** Create a default MP wallet if the user has none **/
		$this->mp->set_mp_wallet( $mp_user_id );
	}
	
	/**
	 * Displayed on the user-edit profile admin page
	 * 
	 * Public because shared with mangopayWCAdmin. TODO: refactor
	 *
	 */
	public function mangopay_wallet_table() {

		if( !current_user_can( 'administrator' ) )
			return;
			
		global $current_user;
			
		$wp_user_id = $current_user->ID;
			
		/** If we're on user_edit profile screen, add some styles and inject some html **/
		if( is_admin() ) {
			global $profileuser;
			$wp_user_id = $profileuser->ID;
			?>
			<style>
				.table-vendor-mp_wallets{
					border:1px solid #333;
					background:#FFF;
				}
				.table-vendor-mp_wallets th,
				.table-vendor-mp_wallets td{
					padding: 5px 10px;
					text-align: left;
					
				}
			</style>
			<tr>
			<th><?php _e( 'MANGOPAY info', 'mangopay' ); ?></th>
			<td>
			<?php 
		}
		
		if ( $mp_user_id = $this->mp->set_mp_user( $wp_user_id ) ) {

			$dashboard_user_url		= $this->mp->getDBUserUrl( $mp_user_id );
			$dashboard_user_link	= '<a target="_mp_db" href="' . $dashboard_user_url . '">';
			
			$dashboard_trans_url	= $dashboard_user_url . '/Transactions';
			$dashboard_trans_link	= '<a target="_mp_db" href="' . $dashboard_trans_url . '">';
						
			$wallets = $this->mp->set_mp_wallet( $mp_user_id );
			
			if( !$wallets )
				echo '<p>' .
					__( 'No MANGOPAY wallets. Please check that all required fields have been completed in the user profile.', 'mangopay' ) .
					'</p>';
			
			if( false && mangopayWCConfig::DEBUG ) {
				echo "<pre>Wallets debug:\n";
				var_dump( $wallets );
				echo '</pre>';
			}
			
			echo '<p>' . $dashboard_user_link . sprintf(__( 'View the user (#%s) in the MANGOPAY Dashboard', 'mangopay' ), $mp_user_id ) . '</a></p>';
			echo '<p>' . $dashboard_trans_link . __( 'View user&apos;s MANGOPAY transactions', 'mangopay' ) . '</a></p>';
		
			?>
			<table class="table table-condensed table-vendor-mp_wallets form-table">
				<thead>
				<tr>
					<th class="mpw-id-header"><?php _e( 'Wallet #', 'mangopay' ); ?></th>
					<th class="mpw-creation-header"><?php _e( 'Creation Date', 'mangopay' ); ?></th>
					<th class="mpw-description-header"><?php _e( 'Description', 'mangopay' ); ?></th>
					<th class="mpw-balance-header"><?php _e( 'Balance', 'mangopay' ); ?></th>
					<th class="mpw-options-header"><?php _e( 'Wallet Options', 'mangopay' ); ?></th>
				</tr>
				</thead>
				<tbody>
			<?php
			if( $wallets && is_array($wallets) ) {
				foreach( $wallets as $wallet ) {
	
					$dashboard_wallet_url	= $dashboard_user_url . '/WalletTransactions/' . $wallet->Id;
					$dashboard_wallet_title = sprintf( __( 'See MANGOPAY transactions for wallet #%s', 'mangopay' ), $wallet->Id );
					$dashboard_wallet_link	= '<a target="_mp_db" href="' . $dashboard_wallet_url . '" title="' . $dashboard_wallet_title . '">';
					
					if( $this->is_vendor( $wp_user_id ) ) {
						$dashboard_payout_url = $this->mp->getDBPayoutUrl( $wallet->Id );
						$dashboard_payout_title = sprintf( __( 'Do a MANGOPAY payout for wallet #%s', 'mangopay' ), $wallet->Id );
						$dashboard_payout_link	= '<a target="_mp_db" href="' . $dashboard_payout_url . '" title="' . $dashboard_payout_title . '">';
					}
					
					echo '<tr>';
					
					echo '<td>' . $wallet->Id . '</a></td>';
					
					echo '<td>' . get_date_from_gmt( date( 'Y-m-d H:i:s', $wallet->CreationDate ), 'F j, Y H:i:s' )  . '</td>';
						//@see: http://wordpress.stackexchange.com/questions/94755/converting-timestamps-to-local-time-with-date-l18n
						
					echo '<td>' . $wallet->Description . '</td>';
					echo '<td>' . number_format_i18n( $wallet->Balance->Amount/100, 2 ) . ' ' . $wallet->Currency . '</td>';
										
					echo '<td>';
					
					echo $dashboard_wallet_link . __( 'View transactions', 'mangopay' ) . '</a><br>';
					
					if( $this->is_vendor( $wp_user_id ) )
						echo $dashboard_payout_link . __( 'Do a PayOut', 'mangopay' ) . '</a> ';
					
					echo '</td>';
					
					echo '</tr>';
				}
			} else {
				if( mangopayWCConfig::DEBUG )
					var_dump( $wallets );
			}
			?>
				</tbody>
			</table>
			<?php
		} else {
			echo '<p>' .
				__( 'No MANGOPAY wallets. Please check that all required fields have been completed in the user profile.', 'mangopay' ) .
				'</p>';

			return false;
		}
		
		if( is_admin() ) {
			echo '</td></tr>';
		}
	}
	
	/**
	 * Displays bank account form for vendors
	 * on shop settings page of the front-end vendor dashboard
	 * This is a WV action hook
	 * @see: https://www.wcvendors.com/help/topic/how-to-add-custom-field-on-vendor-shop-setting/
	 * @see: https://docs.mangopay.com/api-references/bank-accounts/
	 * 
	 * Shared with mangopayWCAdmin. TODO: refactor
	 * 
	 */
	public function bank_account_form( $wp_user_id ) {

		$screen = null;
		if( is_admin() && function_exists('get_current_screen') )
			$screen = get_current_screen();
		//var_dump( $screen ); //debug

		if( !$wp_user_id && ( 
			!is_admin() ||
			preg_match( '/wcv-vendor-shopsettings/', $screen->id ) 
		) ){
			$wp_user_id = get_current_user_id();
        }

        $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		$assets_path = str_replace( array( 'http:', 'https:' ), '', WC()->plugin_url() ) . '/assets/';
        
		$countries_obj = new WC_Countries();
		$countries = $countries_obj->__get('countries');
        
		?>
		<div class="mp_merchant_bank_account_container">
		
			<?php if( !is_admin() ) : ?>
			<p><b><?php _e( 'Bank account info', 'mangopay' ); ?></b></p>
			<?php endif; ?>
			
			<table>
			
			<thead>

			<?php if( is_admin() && preg_match( '/wcv-vendor-shopsettings/', $screen->id ) ) : ?>
				<tr><td><b><?php _e( 'Bank account info', 'mangopay' ); ?></b></td><td>&nbsp;</td></tr>
			<?php endif; ?>
			<tr>
			<td>
			<label for="vendor_account_type" class="required"><?php _e( 'Account type:', 'mangopay' ); ?></label>
	        </td><td>
	        <select name="vendor_account_type" id="vendor_account_type">
		        <option value=""></option>
		        <?php foreach( $this->account_types as $type => $fields ) : 
                    if(isset($_POST['vendor_account_type']) && $_POST['vendor_account_type'] == $type){
                        $selected = 'selected="selected"';
                    }else{
                        $selected=(get_user_meta( $wp_user_id, 'vendor_account_type', true )==$type)?'selected="selected"':''; 
                    }
                ?>    
		        	<option <?php echo $selected; ?>><?php echo $type; ?></option>
		        <?php endforeach; ?>
	        </select>
			</td>
			</tr>
			</thead>
			
			<?php foreach( $this->account_types as $type => $fields ) : $hidden=(get_user_meta( $wp_user_id, 'vendor_account_type', true )==$type)?'':'style="display:none;"'; ?>
			<tbody class="vendor_account_fields <?php echo $type; ?>_fields" <?php echo $hidden; ?>>
			
				<?php foreach( $fields as $field => $c ) : list( $ftype, $n ) = explode( ':', $c['format'] ); ?>
				<tr>
				<td>
		        <label for="<?php echo $field; ?>" class="<?php echo ($c['required']?'required':''); ?>">
		        	<?php _e( $c['label'], 'mangopay' ); ?>
		        	<?php if( $c['required'] ) : ?>
		        	 <span class="description required"><?php _e( '(required)', 'mangopay' ); ?></span>
		        	<?php endif; ?>
		        </label>
		        </td><td>
		        <?php if( 'text' == $ftype || 'number' == $ftype ) : ?>
                <?php 
                $field_value = '';
                if(isset($_POST[$field])){
                    $field_value = $_POST[$field];
                }else{
                    $field_value = get_user_meta( $wp_user_id, $field, true );
                }
                ?>
		        <input type="text" name="<?php echo $field; ?>" id="<?php echo $field; ?>" placeholder="<?php echo $c['placeholder']; ?>" value="<?php echo $field_value; ?>" class="regular-text" />
				<?php elseif( 'select' == $ftype ) : ?>
				<select name="<?php echo $field; ?>" id="<?php echo $field; ?>">
					<?php 
                    foreach( explode( ',', $n ) as $option ) : 
                        if(isset($_POST[$field]) && $_POST[$field] == $option){
                            $selected = 'selected="selected"';
                        }else{
                            $selected=($option==get_user_meta( $wp_user_id, $field, true )?'selected="selected"':''); 
                        }
                     ?>
					<option <?php echo $selected; ?>><?php echo $option; ?></option>
					<?php endforeach; ?>
				</select>
				<?php elseif( 'country' == $ftype ) : ?>
				<select name="<?php echo $field; ?>" id="<?php echo $field; ?>">
				 <option value=""><?php _e( 'Select a country...', 'mangopay' ); ?></option>
		        <?php foreach ($countries as $key => $value): 
                    if(isset($_POST[$field]) && $_POST[$field] == $key){
                        $selected = 'selected="selected"';
                    }else{
                        $selected=($key==get_user_meta( $wp_user_id, $field, true )?'selected="selected"':''); 
                    }
		        ?>
					<option value="<?php echo $key?>" <?php echo $selected; ?>><?php echo $value?></option>
				<?php endforeach; ?>
				</select>
				<?php endif; ?>
				</td>
				</tr>
				<?php endforeach; ?>

			</tbody>
			<?php endforeach; ?>
			
			<tbody class="bank_account_address">
				<tr>
				<td>
				<label for="vendor_account_name"><?php _e( 'Account holder&apos;s name', 'mangopay' ); ?> <span class="description required"><?php _e( '(required)', 'mangopay' ); ?></span></label>
				</td>
                <td>
                <?php 
                $field_value = '';
                if(isset($_POST['vendor_account_name'])){
                    $field_value = $_POST['vendor_account_name'];
                }else{
                    $field_value = get_user_meta( $wp_user_id, 'vendor_account_name', true );
                }
                ?>
				<input type="text" name="vendor_account_name" id="vendor_account_name" value="<?php echo $field_value; ?>" class="regular-text" />
				</td>
				</tr>
				
				<tr>
				<td>
				<label for="vendor_account_address1"><?php _e( 'Account holder&apos;s address', 'mangopay' ); ?> <span class="description required"><?php _e( '(required)', 'mangopay' ); ?></span></label>
				</td>
                <td>
                <?php 
                $field_value = '';
                if(isset($_POST['vendor_account_address1'])){
                    $field_value = $_POST['vendor_account_address1'];
                }else{
                    $field_value = get_user_meta( $wp_user_id, 'vendor_account_address1', true );
                }
                ?>
				<input type="text" name="vendor_account_address1" id="vendor_account_address1" value="<?php echo $field_value; ?>" class="regular-text" /><br/>
                <?php 
                $field_value = '';
                if(isset($_POST['vendor_account_address2'])){
                    $field_value = $_POST['vendor_account_address2'];
                }else{
                    $field_value = get_user_meta( $wp_user_id, 'vendor_account_address2', true );
                }
                ?>                
				<input type="text" name="vendor_account_address2" id="vendor_account_address2" value="<?php echo $field_value; ?>" class="regular-text" />
				</td>
				</tr>
				
				<tr>
				<td>
				<label for="vendor_account_city"><?php _e( 'Account holder&apos;s city', 'mangopay' ); ?> <span class="description required"><?php _e( '(required)', 'mangopay' ); ?></span></label>
				</td>
                <td>
                <?php 
                $field_value = '';
                if(isset($_POST['vendor_account_city'])){
                    $field_value = $_POST['vendor_account_city'];
                }else{
                    $field_value = get_user_meta( $wp_user_id, 'vendor_account_city', true );
                }
                ?>                       
				<input type="text" name="vendor_account_city" id="vendor_account_city" value="<?php echo $field_value; ?>" class="regular-text" />
				</td>
				</tr>
				
				<tr>
				<td>
				<label for="vendor_account_postcode"><?php _e( 'Account holder&apos;s postal code', 'mangopay' ); ?> <span class="description required"><?php _e( '(required)', 'mangopay' ); ?></span></label>
				</td>
                <td>
                <?php 
                $field_value = '';
                if(isset($_POST['vendor_account_postcode'])){
                    $field_value = $_POST['vendor_account_postcode'];
                }else{
                    $field_value = get_user_meta( $wp_user_id, 'vendor_account_postcode', true );
                }
                ?>                      
				<input type="text" name="vendor_account_postcode" id="vendor_account_postcode" value="<?php echo $field_value; ?>" class="regular-text" />
				</td>
				</tr>		
                <?php if(is_admin()){ ?>
                <tr>
                    <td>
                        <label for="vendor_account_country">
                            <?php _e( 'Account holder&apos;s country', 'mangopay' ); ?> 
                            <span class="description required">
                                <?php _e( '(required)', 'mangopay' ); ?>
                            </span>
                        </label>
                    <td>
                    <select class="vendor_account_select js_field-country" name="vendor_account_country" id="vendor_account_country">
                    <option value=""><?php _e( 'Select a country...', 'mangopay' ); ?></option>
                    <?php foreach ($countries as $key => $value): 
                        if(isset($_POST['vendor_account_country']) && $_POST['vendor_account_country'] == $key){
                            $selected = 'selected="selected"';
                        }else{
                            $selected=($key==get_user_meta( $wp_user_id, 'vendor_account_country', true )?'selected="selected"':''); 
                        }
                        ?>
                        <option value="<?php echo $key?>" <?php echo $selected; ?>><?php echo $value?></option>
                    <?php endforeach; ?>
                    </select>
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="vendor_account_region"><?php _e( 'Account holder&apos;s region', 'mangopay' ); ?><span class="description required"></span></label>
                    </td>
                    <td>
                        <?php 
                        $field_value = '';
                        if(isset($_POST['vendor_account_region'])){
                            $field_value = $_POST['vendor_account_region'];
                        }else{
                            $field_value = get_user_meta( $wp_user_id, 'vendor_account_region', true );
                        }
                        ?>  
                        <input type="hidden" class="vendor_account_select js_field-state" name="vendor_account_region" id="vendor_account_region" value="<?php echo $field_value; ?>" />
                    </td>
                </tr>
                <?php }else{ //if in front ?>
                <tr>
                    <td>
                        <label for="vendor_account_country">
                            <?php _e( 'Account holder&apos;s country', 'mangopay' ); ?> 
                            <span class="description required">
                                <?php _e( '(required)', 'mangopay' ); ?>
                            </span>
                        </label>
                    <td>              
                        <?php
                        $field_value = '';
                        if(isset($_POST['vendor_account_country'])){
                            $field_value = $_POST['vendor_account_country'];
                        }else{
                            $field_value = get_user_meta( $wp_user_id, 'vendor_account_country', true );
                        }
                        $vendor_account_country_options = array();
                        $vendor_account_country_options['type'] = 'country';
                        $vendor_account_country_options['class'] = array('form-row-wide','address-field','update_totals_on_change');
                        $vendor_account_country_options['required'] = 1;
                        $vendor_account_country_options['autocomplete'] = 'country';
                        $this->mangopay_form_field( 'vendor_account_country', $vendor_account_country_options, $field_value ); 
                        ?>    
                    </td>
                </tr>
                <tr>
                    <td>
                        <label for="vendor_account_region"><?php _e( 'Account holder&apos;s region', 'mangopay' ); ?><span class="description required"></span></label>
                    </td>
                    <td>
                        <?php
                        $field_value = '';
                        if(isset($_POST['vendor_account_region'])){
                            $field_value = $_POST['vendor_account_region'];
                        }else{
                            $field_value = get_user_meta( $wp_user_id, 'vendor_account_region', true );
                        }
                        $vendor_account_region_options = array();
                        $vendor_account_region_options['type'] = 'state';
                        $vendor_account_region_options['required'] = 1;
                        $vendor_account_region_options['class'] = array('form-row-first','address-field');
                        $vendor_account_region_options['validate'] = array('state');
                        $vendor_account_region_options['countrykey'] = 'vendor_account_country';
                        $vendor_account_region_options['autocomplete'] = 'address-level1';
                        $vendor_account_region_options['userid'] = $wp_user_id;
                        $this->mangopay_form_field( 'vendor_account_region', $vendor_account_region_options, $field_value ); 
                        ?>                        
                     </td>
                </tr>
                <?php }//end if admin ?>
			</tbody>
						
			</table>
			
			<script>
			(function($) {
				$(document).ready(function() {
					$('.vendor_account_fields').hide();
					if( $('#vendor_account_type').val() )
						$('.vendor_account_fields.' + $('#vendor_account_type').val() + '_fields').show();
					$('#vendor_account_type').on( 'change', function(e) {
						$('.vendor_account_fields').hide();
						$('.vendor_account_fields.' + $(this).val() + '_fields').show();
					});
				});
			})( jQuery );
			</script>
		</div>
		<?php if( is_admin() && preg_match( '/wcv-vendor-shopsettings/', $screen->id ) ) : ?>
			<p>&nbsp;</p>
		<?php endif; ?>
		<?php
	}
	
	/**
	 * Save redacted bank account info hints in vendor's usermeta
	 * Registers or updates actual bank info with MP API
	 * @see: https://docs.mangopay.com/api-references/bank-accounts/
	 * 
	 * Shared with mangopayWCAdmin. TODO: refactor
	 * 
	 */
	public function save_account_form( $wp_user_id ) {

			if( !isset( $_POST['vendor_account_type'] ) || !$_POST['vendor_account_type'] )
				return true;

			if( !isset( $this->account_types[$_POST['vendor_account_type']] ) )
				return false;
			
			$account_type = $this->account_types[$_POST['vendor_account_type']];
			$needs_update = false;
			$account_data = array();
			
			/** Record redacted bank account data in vendor's usermeta **/
			foreach( $account_type as $field => $c ) {
				if( 
					isset( $_POST[$field] ) &&
					$_POST[$field] &&
					!preg_match( '/\*\*/', $_POST[$field] )
				) {
					if( isset( $c['redact'] ) && $c['redact'] ) {
						$needs_update = true;
						list( $obf_start, $obf_end ) = explode( ',', $c['redact'] );
						$strlen = strlen( $_POST[$field] );
						
						/**
						 * if its <=5 characters, lets just redact the whole thing
						 * @see: https://github.com/Mangopay/wordpress-plugin/issues/12
						 */
						if( $strlen <= 5 ) {
							$to_be_stored = str_repeat( '*', $strlen );
							
						} else {
							$obf_center = $strlen - $obf_start - $obf_end;
							if( $obf_center < 2 )
								$obf_center = 2;
							$to_be_stored = substr( $_POST[$field], 0, $obf_start ) .
								str_repeat( '*', $obf_center ) .
								substr( $_POST[$field], -$obf_end, $obf_end );
						}
					} else {
						if( get_user_meta( $wp_user_id, $field, true ) != $_POST[$field] )
							$needs_update = true;
						$to_be_stored = $_POST[$field];
					}
					update_user_meta( $wp_user_id, $field, $to_be_stored );
					$account_data[$field] = $_POST[$field];
				}
			}
			
			/** Record clear text bank account data in vendor's usermeta **/
			$account_clear_data = array(
				'vendor_account_type',
				'vendor_account_name',
				'vendor_account_address1',
				'vendor_account_address2',
				'vendor_account_city',
				'vendor_account_postcode',
				'vendor_account_region',
				'vendor_account_country'
			);
			foreach( $account_clear_data as $field ) {
				/** update_user_meta() returns "false" if the value is unchanged **/
				if( update_user_meta( $wp_user_id, $field, $_POST[$field] ) )
					$needs_update = true;
			}
			
			if( $needs_update ) {
				$mp_user_id = $this->mp->set_mp_user( $wp_user_id );
				
				/** We store a different mp_account_id for production and sandbox environments **/
				$umeta_key = 'mp_account_id';
				if( !$this->mp->is_production() )
					$umeta_key .= '_sandbox';
				
				$existing_account_id = get_user_meta(  $wp_user_id, $umeta_key, true );
				
				$mp_account_id = $this->mp->save_bank_account( 
					$mp_user_id, 
					$wp_user_id,
					$existing_account_id,
					$_POST['vendor_account_type'],
					$_POST['vendor_account_name'],
					$_POST['vendor_account_address1'],
					$_POST['vendor_account_address2'],
					$_POST['vendor_account_city'],
					$_POST['vendor_account_postcode'],
					$_POST['vendor_account_region'],
					$_POST['vendor_account_country'],
					$account_data,
					$this->account_types
				);

				update_user_meta( $wp_user_id, $umeta_key, $mp_account_id );
			}
	}

    
    public function shop_settings_saved($wp_user_id){
        
		/** Update bank account data if set && valid **/
		$errors = new WP_Error;
		$this->validate_bank_account_data( $errors, NULL, $wp_user_id );
		$e = $errors->get_error_code();
		if( empty( $e ) ) {
			$this->save_account_form( $wp_user_id );
			return true;
		}
		
        foreach( $errors->errors as $error ) {
            wc_add_notice( $error[0], 'error' );
        }            
        
    }
    
	/**
	 * Specific procedure to validate and save bank account data when in the
	 * /wp-admin/admin.php?page=wcv-vendor-shopsettings back-office screen
	 * (WV specific)
	 * 
	 * @param int $wp_user_id
	 */
	public function shop_settings_admin_saved( $wp_user_id ) {
        
		/** Update bank account data if set && valid **/
		$errors = new WP_Error;
		$this->validate_bank_account_data( $errors, NULL, $wp_user_id );
		$e = $errors->get_error_code();
		if( empty( $e ) ) {
			$this->save_account_form( $wp_user_id );
			return true;
		}
		
        foreach( $errors->errors as $error ) {
            echo '<div class="error"><p>';
            echo $error[0];
            echo '</p></div>';
        }
        return $errors;
	}
	
	/**
	 * Child method of user_edit_checks()
	 * Specifically checks data related to bank accounts
	 * 
	 * @param object $errors
	 * @param unknown $update
	 * @param unknown $user
	 * 
	 * Shared with mangopayWCAdmin. TODO: refactor
	 * 
	 */
	public function validate_bank_account_data( &$errors, $update, $user ) {
                
		$required = array(
			'vendor_account_name'		=> __( 'Account holder&apos;s name', 'mangopay' ),
			'vendor_account_address1'	=> __( 'Account holder&apos;s address', 'mangopay' ),
			'vendor_account_city'		=> __( 'Account holder&apos;s city', 'mangopay' ),
			'vendor_account_country'	=> __( 'Account holder&apos;s country', 'mangopay' )
		);

		$mandatory_region_countries = array( 'MX', 'CA', 'US' );
		
		if( isset( $_POST['vendor_account_country'] ) && in_array( $_POST['vendor_account_country'], $mandatory_region_countries ) ){
			$required['vendor_account_region'] = __( 'Account holder&apos;s region', 'mangopay' );
        }
		
		$no_postcode_countries = array(
			"AO", "AG", "AW", "BS", "BZ", "BJ", "BW", "BF", "BI", 
			"CM", "CF", "KM", "CG", "CD", "CK", "CI", "DJ", "DM", 
			"GQ", "ER", "FJ", "TF", "GM", "GH", "GD", "GN", "GY", 
			"HK", "IE", "JM", "KE", "KI", "MO", "MW", "ML", "MR", 
			"MU", "MS", "NR", "AN", "NU", "KP", "PA", "QA", "RW", 
			"KN", "LC", "ST", "SA", "SC", "SL", "SB", "SO", "ZA", 
			"SR", "SY", "TZ", "TL", "TK", "TO", "TT", "TV", "UG", 
			"AE", "VU", "YE", "ZW"
		);
		
		if( isset( $_POST['vendor_account_country'] ) && !in_array( $_POST['vendor_account_country'], $no_postcode_countries ) )
			$required['vendor_account_postcode'] = __( 'Account holder&apos;s postal code', 'mangopay' );

		$account_type = array();
		if( isset( $_POST['vendor_account_type'] ) ) {
			if( !isset( $this->account_types[$_POST['vendor_account_type']] ) ) {
				$errors->add(
					'invalid_vendor_account_type',
					'<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
					__( 'not a valid bank account type', 'mangopay' ),
					array( 'form-field' => 'vendor_account_type' )
				);
			} else {
				$account_type = $this->account_types[$_POST['vendor_account_type']];
			}
		}
		
		/** Check that required clear-text fields are present **/
		foreach( $required as $field => $label ) {
			if ( isset( $_POST[$field] ) && empty( $_POST[$field] ) ) {
				$errors->add(
					$field . '_required_error',
					'<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
					$label . ' ' .
					__( 'is required!', 'mangopay' )
				);
			}
		}

		/** Validate postal code **/
		if( !empty( $_POST['vendor_account_postcode'] ) ) {
			if( !preg_match( '/^[a-z0-9 \-]+$/i', $_POST['vendor_account_postcode'] ) )
				$errors->add(
						'vendor_account_postcode_invalid_error',
						'<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
						__( 'Account holder&apos;s postal code', 'mangopay' ) . ' ' .
						__( 'is invalid!', 'mangopay' )
				);
		}
		
		/** Validate country **/
		if( isset( $_POST['vendor_account_country'] ) ) {
			$countries_obj = new WC_Countries();
			$countries = $countries_obj->__get('countries');
			if( !isset( $countries[$_POST['vendor_account_country']] ) )
				$errors->add(
						'vendor_account_country_invalid_error',
						'<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
						__( 'Account holder&apos;s country', 'mangopay' ) . 
						__( 'is invalid!', 'mangopay' )
				);
		}
		
		/** Check that required bank account fields are present and either redacted or valid **/
		$allobfuscated = true;
		foreach( $account_type as $field => $c ) {

			/** Check for required fields **/
			if( 
				isset( $c['required'] ) && 
				$c['required'] &&
				( !isset( $_POST[$field] ) || !$_POST[$field] )
			)
				$errors->add(
					'missing_' . $field,
					'<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
					__( $c['label'], 'mangopay' ) . ' ' .
					__( 'is required!', 'mangopay' ),
					array( 'form-field' => $field )
				);
			
			/** All of them or none of them can be redacted **/
			if( $c['redact'] && !preg_match( '/\*\*/', $_POST[$field] ) )
				$allobfuscated = false;
			
			/** Validation rules (regexp based) **/
			if( isset( $_POST[$field] ) && $_POST[$field] )
				if(
					( !$allobfuscated && preg_match( '/\*\*/', $_POST[$field] ) ) ||
					( 	
						!preg_match( '/\*\*/', $_POST[$field] ) && 
						!preg_match( '/' . $c['validate'] . '/', $_POST[$field] )
					)
				) {
					$errors->add(
						'invalid_' . $field,
						'<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ' .
						__( $c['label'], 'mangopay' ) . ' ' .
						__( 'is invalid!', 'mangopay' ),
						array( 'form-field' => $field )
					);
				}
		}
	}
  
  public function order_redirect(){
    
    global $wp;

    //is_page( wc_get_page_id( 'checkout' ) ) && isset( $wp->query_vars['order-received'] )
      if ( is_checkout() && ! empty( $wp->query_vars['order-received'] ) ):
        
        //get the order id
        $order_id = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
        //get the order
        $order    = wc_get_order( $order_id );

        //get the payement type
        $payment_type = get_post_meta( $order_id, 'mangopay_payment_type', 'card',true );
        //if it's not card get out
        if($payment_type != "card"):
          return;
        endif;
        
        //get the ref for the transaction id
        $payment_ref = get_post_meta( $order_id, 'mangopay_payment_ref',true);
        //if no ref get out
        if(!$payment_ref):
          return;
        endif;
        
        //get the data from transaction
        $mp_transaction = $this->mp->get_payin( $payment_ref['transaction_id'] );
        //if no data get out
        if(!$mp_transaction):
          return;
        endif;
        
        //if status is failed, send to cancel order url
        if($mp_transaction->Status == "FAILED"):
                  
        //user message
        //wc_add_notice(__( $mp_transaction->ResultMessage, 'mangopay') , "notice");//error, success or notice
        /** spécial code to be intercepted, do NOT change it **/
        wc_add_notice( '<span class="cancelmessagealone">'.__( $mp_transaction->ResultMessage, 'mangopay').'</span>', "notice");//error, success or notice
                
        //status to cancel + message admin
        $order->update_status( 'failed', __( $mp_transaction->ResultMessage, 'mangopay')); // message pour admin
        
        //to get te cancel url
        $redirect_url = $order->get_cancel_order_url_raw();
        //and let's go
        wp_redirect($redirect_url );
        exit;
        endif;//status is pending
        
    endif;//we are on chekout page and order recieved is empty
 }
  
	
	/** 
	 * Verify payment payin transaction and update order status appropriately 
	 * Checks that payment status is SUCCEEDED
	 * Checks that order_total == payment total
	 * Checks that order_currency == payment currency
	 * Store MP transaction ID in order meta
	 * 
	 * If Everything OK, order status is changed to processing
	 * 
	 * This all takes place on the order-received/thank-you WC page on the front-office
	 * 
	 */
	public function order_received( $order_id ) {

		if( !$order_id )
			return false;
		
		if( !$order = new WC_Order( $order_id ) )
			return false;
		
		if ( $order->status == 'failed' )
      return false;
    
		if( !isset( $_GET['transactionId'] ) || !$_GET['transactionId'] )
			return false;
		
		$transaction_id = $_GET['transactionId'];
		
		if( !$mp_transaction = $this->mp->get_payin( $transaction_id ) )
			return false;
		
		/* Debug *
		echo '<pre>';
		echo "transactionId: $transaction_id\n";
		var_dump( $mp_transaction );
		echo '</pre>';
		/* */
		
		if( !$mp_status = $mp_transaction->Status )
			return false;
		
		if( !$mp_amount = $mp_transaction->CreditedFunds->Amount )
			return false;
		
		if( !$mp_currency = $mp_transaction->CreditedFunds->Currency )
			return false;
		
		if( mangopayWCConfig::DEBUG ) {
			$tr_href = $this->mp->getDBUserUrl( '' ) . 'PayIn/' . $transaction_id;
			$tr_link = '<a target="_mp_db" href="' . $tr_href . '">';
			echo '<p>' . __( 'MANGOPAY transaction Id:', 'mangopay' ) . ' ' . $tr_link . $transaction_id . '</a></p>';
			echo '<p>' . __( 'MANGOPAY transaction status:', 'mangopay' ) . ' ' . $mp_status . '</p>';
			echo '<p>' . __( 'MANGOPAY transaction total amount:', 'mangopay' ) . ' ' . $mp_amount . '</p>';
			echo '<p>' . __( 'MANGOPAY transaction currency:', 'mangopay' ) . ' ' . $mp_currency . '</p>';
			echo '<p>' . __( 'Order total:', 'mangopay' ) . ' ' . $order->order_total . '</p>';	//Debug
			echo '<p>' . __( 'Order currency:', 'mangopay' ) . ' ' . $order->order_currency . '</p>';	//Debug
		}
		
		if( 'SUCCEEDED' != $mp_status ) {
			echo '<p>' . __( 'Error: MANGOPAY transaction did not succeed.', 'mangopay' ) . '</p>';
			return false;
		}
		
		if( $order->order_currency != $mp_currency ) {
			echo '<p>' . __( 'Error: wrong currency.', 'mangopay' ) . '</p>';
			return false;
		}
		
		if( ( $order->order_total * 100 ) != $mp_amount ) {
			echo '<p>' . __( 'Error: wrong payment amount.', 'mangopay' ) . '</p>';
			return false;
		}
		
		/** 
		 * Save the MP transaction ID in the WC order metas 
		 * this needs to be done before calling payment->complete()
		 * to handle auto-completed orders such as downloadables and virtual products & bookings
		 * 
		 */
		update_post_meta( $order_id, 'mp_transaction_id', $transaction_id );
		update_post_meta( $order_id, 'mp_success_transaction_id', $transaction_id );
				
		$order->payment_complete();
	}
	
	/**
	 * Display bankwire ref at top of thankyou page when new order received
	 * (only if payment was bankwire)
	 * 
	 * @param int $order_id
	 */
	public function display_bankwire_ref( $order_id ) {
    
//    echo "<pre>", print_r("HERE BANKWIRE thank you 1 ", 1), "</pre>";
//    die("stop here");
    
		$order = new WC_Order( $order_id );
		
		if(
			get_post_meta( $order_id, 'mangopay_payment_type', true ) != 'bank_wire' ||
			!$ref = get_post_meta( $order_id, 'mangopay_payment_ref', true )
		)
			return $order_id;	// Do nothing

		echo '<h2>' . __( 'Information for your bank transfer', 'mangopay' ) . '</h2>';
		echo '<p>' . __( 'To complete your order, please do a bank transfer with the following information, including the bank wire reference.', 'mangopay' ) . '<p>';
		echo '<p>' . __( 'We will process your order once the transfer is received.', 'mangopay' ) . '<p>';
		
		?>
		<ul class="order_details">
			<li class="mp_amount">
				<?php _e( 'Amount:', 'mangopay' ); ?>
				<strong><?php echo $ref->PaymentDetails->DeclaredDebitedFunds->Amount/100; ?></strong>
				<strong><?php echo $ref->PaymentDetails->DeclaredDebitedFunds->Currency; ?></strong>
			</li>
			<li class="mp_owner">
				<?php _e( 'Bank account owner:', 'mangopay' ); ?>
				<strong><?php echo $ref->PaymentDetails->BankAccount->OwnerName; ?></strong>
			</li>
			<!--<li class="mp_address">
				<?php _e( 'Owner address:', 'mangopay' ); ?>
				<div class="mp_address_block">
					<strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->AddressLine1; ?></strong><br/>
					<?php if( $ref->PaymentDetails->BankAccount->OwnerAddress->AddressLine2 ): ?>
						<strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->AddressLine2; ?></strong><br/>
					<?php endif; ?>
					<strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->PostalCode; ?></strong>
					<strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->City; ?></strong><br/>
					<?php if( $ref->PaymentDetails->BankAccount->OwnerAddress->Region ): ?>
						<strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->Region; ?></strong><br/>
					<?php endif; ?>
					<strong><?php echo $ref->PaymentDetails->BankAccount->OwnerAddress->Country; ?></strong>
				</div>
			</li>-->
			<li class="mp_iban">
				<?php _e( 'IBAN:', 'mangopay' ); ?>
				<strong><?php echo $ref->PaymentDetails->BankAccount->Details->IBAN; ?></strong>
			</li>
			<li class="mp_bic">
				<?php _e( 'BIC:', 'mangopay' ); ?>
				<strong><?php echo $ref->PaymentDetails->BankAccount->Details->BIC; ?></strong>
			</li>
			<li class="mp_wire_ref">
				<?php _e( 'Wire reference:', 'mangopay' ); ?>
				<strong><?php echo $ref->PaymentDetails->WireReference; ?></strong>
			</li>		
		</ul>
		<?php
	}
	
	/**
	 * Do wallet transactions when an order gets completed
	 * 
	 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/transfer.php
	 * 
	 * @param int $order_id
	 * 
	 */
	public function on_order_completed( $order_id ) {

		if( $mp_transfers = get_post_meta( $order_id, 'mp_transfers', true ) )
			return false;	// The wallet transfer has already been done
		
		if( !$mp_transaction_id = get_post_meta( $order_id, 'mp_transaction_id', true ) )
			return false;
		
		if( $mp_success_transaction_id = get_post_meta( $order_id, 'mp_success_transaction_id', true ) )
			$mp_transaction_id = $mp_success_transaction_id;
		
		$order 			= new WC_Order( $order_id );
		$wp_user_id 	= $order->customer_user;
		$mp_currency	= $order->order_currency;
		
		$mp_transfers = array();
		$mp_instapays = array();
		/** Get due commissions from WC-Vendors **/
		$dues  = WCV_Vendors::get_vendor_dues_from_order( $order, false );
		foreach ( $dues as $vendor_id => $lines ) {
			if( 1 == $vendor_id )
				continue;
				
			foreach( $lines as $item_id => $details ) {

				$mp_fees	= $dues[1][$item_id]['total'];
				$mp_amount 	= $details['total'] + $mp_fees;	// This will be DebitedFunds, so it includes the fees
				$transfer_result = $this->mp->wallet_trans( 
					$order_id, 
					$mp_transaction_id,
					$wp_user_id, 
					$vendor_id, 
					$mp_amount, 
					$mp_fees, 
					$mp_currency 
				);
				$mp_transfers[] = $transfer_result;
				
				/* $transfer_result example:
				a:1:{
					i:0;O:17:"MangoPay\Transfer":16:{
						s:15:"DebitedWalletId";s:8:"12557921";
						s:16:"CreditedWalletId";s:8:"11401718";
						s:8:"AuthorId";s:8:"11094805";
						s:14:"CreditedUserId";s:8:"10944650";
						s:12:"DebitedFunds";O:14:"MangoPay\Money":2:{
							s:8:"Currency";s:3:"EUR";
							s:6:"Amount";i:11006;
						}
						s:13:"CreditedFunds";O:14:"MangoPay\Money":2:{
							s:8:"Currency";s:3:"EUR";
							s:6:"Amount";i:10786;
						}
						s:4:"Fees";O:14:"MangoPay\Money":2:{
							s:8:"Currency";s:3:"EUR";
							s:6:"Amount";i:220;
						}
						s:6:"Status";s:9:"SUCCEEDED";
						s:10:"ResultCode";s:6:"000000";
						s:13:"ResultMessage";s:7:"Success";
						s:13:"ExecutionDate";i:1463169085;
						s:4:"Type";s:8:"TRANSFER";
						s:6:"Nature";s:7:"REGULAR";
						s:2:"Id";s:8:"12562008";
						s:3:"Tag";s:39:"WC Order #891 - ValidatedBy:yann.dubois";
						s:12:"CreationDate";i:1463169084;
					}
				}
				*/
								
				/** 
				 * WV "instapay" feature: Instantly pay vendors their commission when an order is made 
				 * @see WV: wp-plugins/wc-vendors/classes/gateways/PayPal_AdvPayments:L302&L126...
				 *
				 */
				$instapay_success = true;
				if( $this->instapay && 'SUCCEEDED' == $transfer_result->Status) {

					/** We store a different mp_account_id for production and sandbox environments **/
					$umeta_key = 'mp_account_id';
					if( !$this->mp->is_production() )
						$umeta_key .= '_sandbox';
					
					if( $mp_account_id = get_user_meta( $vendor_id, $umeta_key, true ) ) {
							
						$payout_result = $this->mp->payout(
							$vendor_id,
							$mp_account_id, //Bank account: PayOut->MeanOfPaymentDetails->BankAccountId
							$order_id, 
							$mp_currency, 
							$transfer_result->CreditedFunds->Amount/100,	//$amount
							0												//$fees
						);

						if(
							isset( $payout_result->Status ) &&
							( 'SUCCEEDED' == $payout_result->Status || 'CREATED' == $payout_result->Status )
						) {
							$this->set_commission_paid( $item_id );
								
						} else {
							$instapay_success = false;				
						}
						
						$mp_instapays[] = $payout_result;
						
					} else {
						$instapay_success = false;
						$mp_instapays[] = 'No mp_account_id';
					}
				}
			}
			if( $this->instapay && $instapay_success )
				WCV_Commission::set_order_commission_paid( $order_id );
		}
		
		update_post_meta( $order_id, 'mp_transfers', $mp_transfers );
		
		if( $this->instapay )
			update_post_meta( $order_id, 'mp_instapays', $mp_instapays );
	}

	/**
	 * Adds "refuse item" button on vendor dashboard order list
	 * 
	 * @param unknown $output
	 * @param unknown $item_meta_o
	 */
	public function refuse_item_button( $output, $item_meta_o ) {

		if( !isset( $this->options['per_item_wf'] ) || !$this->options['per_item_wf']=='yes' )
			return $output;

		$vendor_dashboard_page = WC_Vendors::$pv_options->get_option( 'vendor_dashboard_page' );
		if ( is_admin() || !is_page( $vendor_dashboard_page ) )
			return $output;
		
		//echo '<pre>'; var_dump( $this->_current_order );	echo '</pre>';	//Debug
		//echo '<pre>'; var_dump( $item_meta_o->meta );	echo '</pre>';	//Debug
		

		$order_id = $this->_current_order->id;
		$product_id = $item_meta_o->meta['_product_id'][0];
		$url = wp_nonce_url( '?mp_refuse&order_id=' . $order_id . '&product_id=' . $product_id );
		$output .= '<a href="' . $url . '" class="mp_refuse_button">';
		$output .= __( 'Refuse this item', 'mangopay' );
		$output .= '</a>';
		return $output;
	}
	public function record_current_order( $order_actions, $order ) {
		$this->_current_order = $order;
		return $order_actions;
	}
		
	/**
	 * Check if a wp_user_id is a vendor
	 * 
	 * @param int $wp_user_id
	 * @return boolean
	 * 
	 * Public because shared with mangopayWCAdmin. TODO: refactor
	 * 
	 */
	public function is_vendor( $wp_user_id ) {
		$is_vendor = false;
		$wp_userdata = get_userdata( $wp_user_id );
		if(
			isset( $wp_userdata->wp_capabilities['vendor'] ) ||
			(is_array($wp_userdata->wp_capabilities) && in_array( 'vendor', $wp_userdata->wp_capabilities , true )) ||
			user_can( $wp_user_id, 'vendor' )
		)
			$is_vendor = true;

		return $is_vendor;
	}
	
	/**
	 * Payline form template shortcode
	 * 
	 */
	public function payform_shortcode( $html ) {
		return '<div id="PaylineForm"></div>';
	}
	
	/**
	 * Check that date conforms to expected date format
	 * @see: http://stackoverflow.com/questions/19271381/correctly-determine-if-date-string-is-a-valid-date-in-that-format
	 *
	 * @param string $date
	 * @return boolean
	 * 
	 * Public because shared with mangopayWCAdmin. TODO: refactor
	 * 
	 */
	public function convertDate( $date, $format=null ) {

		if( !$format )
			$format = $this->supported_format( get_option( 'date_format' ) );
	
		if( preg_match( '/F/', $format ) && function_exists( 'strptime' ) ) {
	
			/** Convert date format to strftime format */
			$format = preg_replace( '/j/', '%d', $format );
			$format = preg_replace( '/F/', '%B', $format );
			$format = preg_replace( '/Y/', '%Y', $format );
			$format = preg_replace( '/,\s*/', ' ', $format );
			$date = preg_replace( '/,\s*/', ' ', $date );
			
			setlocale( LC_TIME, get_locale() );
			
			$d = strptime( $date, $format );
			if( false === $d )	// Fix problem with accentuated month names on some systems
				$d = strptime( utf8_decode( $date ), $format );
			
			/* Debug *
			echo '<div class="debug" style="background:#fff">';
			echo '<strong>Debug date:</strong><br/>';
			echo 'Original date: ' . $date . '<br/>';
			echo 'Date format: ' . get_option( 'date_format' ) . '<br/>';
			echo 'strftime format: ' . $format . '<br/>';
			echo 'get_locale(): ' . get_locale() . '<br/>';
			echo 'strftime( \'' . $format . '\' ): ' . strftime( $format ) . '<br/>';
			echo 'strptime():<br/><pre>';
			var_dump( $d );
			echo '</pre>';
			echo 'checkdate:<br/>';
			var_dump( checkdate( $d['tm_mon']+1, $d['tm_mday'], 1900+$d['tm_year'] ) );
			echo '</div>';
			/* */
				
			if( !$d )
				return false;
				
			return
			1900+$d['tm_year'] . '-' .
			sprintf( '%02d', $d['tm_mon']+1 ) . '-' .
			sprintf( '%02d', $d['tm_mday'] );
				
		} else {
	
			$d = DateTime::createFromFormat( $format, $date );
	
			if( !$d )
				return false;
	
			return $d->format( 'Y-m-d' );
		}
	}
	
	/**
	 * If the date format is not properly supported at system level
	 * (if textual dates cannot be translated back and forth using the locale settings),
	 * this will replace textual month names with month numbers in the date format
	 * 
	 * @param string $date_format
	 * @return string $date_format
	 * 
	 * Public because shared with mangopayWCAdmin. TODO: refactor
	 * 
	 */
	public function supported_format( $date_format ) {
		if( date( 'Y-m-d' ) == $this->convertDate( date_i18n( get_option( 'date_format' ), time() ), get_option( 'date_format' ) ) )
			return $date_format;
		
		return preg_replace( '/F/', 'm', $date_format );
	}
	
	/**
	 * Checks that date is a valid Gregorian calendar date
	 * Uses the yyyy-mm-dd format as input
	 * 
	 * @param string $date	// yyyy-mm-dd
	 * @return boolean
	 * 
	 * Public because shared with mangopayWCAdmin. TODO: refactor
	 * 
	 */
	public function validateDate( $date ) {
	
		// echo 'validateDate<br/>';	//Debug
		// var_dump( $date );			//Debug
	
		if( !preg_match( '/^(\d{4,4})\-(\d{2,2})\-(\d{2,2})$/', $date, $matches ) )
			return false;
	
		if( !wp_checkdate( $matches[2], $matches[3], $matches[1], $date ) )
			return false;
	
		return true;
	}
	
	/**
	 * Sets-up JS initilization parameters for jQ-ui-Datepicker localization
	 * 
	 * @see: http://www.renegadetechconsulting.com/tutorials/jquery-datepicker-and-wordpress-i18n
	 * 
	 * Public because shared with mangopayWCAdmin. TODO: refactor
	 * 
	 */
	public function localize_datepicker() {
		global $wp_locale;
		$aryArgs = array(
				'showButtonPanel'	=> true,
				'closeText'         => __( 'Done', 'mangopay' ),
				'currentText'       => __( 'Today', 'mangopay' ),
				'monthNames'        => array_values( $wp_locale->month ),
				'monthNamesShort'   => array_values( $wp_locale->month_abbrev ),
				'monthStatus'       => __( 'Show a different month', 'mangopay' ),
				'dayNames'          => array_values( $wp_locale->weekday ),
				'dayNamesShort'     => array_values( $wp_locale->weekday_abbrev ),
				'dayNamesMin'       => array_values( $wp_locale->weekday_initial ),
				// set the date format to match the WP general date settings
				'dateFormat'        => $this->date_format_php_to_js( $this->supported_format( get_option( 'date_format' ) ) ),
				// get the start of week from WP general setting
				'firstDay'          => get_option( 'start_of_week' ),
				// is Right to left language? default is false
				'isRTL'             => $wp_locale->is_rtl(),
				'changeYear'		=> true,
				'yearRange'			=> (date('Y')-120) . ':' . date('Y'),
				'defaultDate'		=> -( 365 * 29 )
		);
		wp_localize_script( 'jquery-ui-datepicker', 'datepickerL10n', $aryArgs );
	}
	
	/**
	 * This tries to convert allowed default WP date formats to what jquery-ui-datepicker expects
	 * @see:https://support.woothemes.com/hc/en-us/articles/203182373-How-to-add-custom-fields-in-user-registration-on-the-My-Account-page
	 *
	 * @param string $sFormat
	 * @return string
	 *
	 */
	private function date_format_php_to_js( $sFormat ) {
		switch( $sFormat ) {
			//Predefined WP date formats
			case 'F j, Y':
				return( 'MM dd, yy' );
				break;
			case 'Y/m/d':
				return( 'yy/mm/dd' );
				break;
			case 'm/d/Y':
				return( 'mm/dd/yy' );
				break;
			case 'd/m/Y':
				return( 'dd/mm/yy' );
				break;
			default:
				$jsFormat = preg_replace( '/F/', 'MM', $sFormat );
				$jsFormat = preg_replace( '/d/', 'dd', $jsFormat );
				$jsFormat = preg_replace( '/j/', 'dd', $jsFormat );
				$jsFormat = preg_replace( '/Y/', 'yy', $jsFormat );
				$jsFormat = preg_replace( '/m/', 'mm', $jsFormat );
				return $jsFormat;
		}
	}
	
	/**
	 * Passphrase security
	 * 
	 */
	public function encrypt_passphrase( $new_options, $old_options ) {

		if( isset( $new_options['sand_passphrase'] ) && preg_match( '/^\*+$/', $new_options['sand_passphrase'] ) )
			$new_options['sand_passphrase'] = $old_options['sand_passphrase'];
		
		if( isset( $new_options['prod_passphrase'] ) && preg_match( '/^\*+$/', $new_options['prod_passphrase'] ) )
			$new_options['prod_passphrase'] = $old_options['prod_passphrase'];

		if( !function_exists("mcrypt_encrypt") )
			return $new_options;

		if( isset( $new_options['sand_passphrase'] ) && $new_options['sand_passphrase'] )
			$new_options['sand_passphrase'] = $this->encrypt( $new_options['sand_passphrase'] );
		
		if( isset( $new_options['sand_passphrase'] ) && $new_options['sand_passphrase']==='' )
			$new_options['sand_passphrase'] = '';
		
		if( isset( $new_options['prod_passphrase'] ) && $new_options['prod_passphrase'] )
			$new_options['prod_passphrase'] = $this->encrypt( $new_options['prod_passphrase'] );
		
		if( isset( $new_options['prod_passphrase'] ) && $new_options['prod_passphrase']==='' )
			$new_options['prod_passphrase'] = '';

		return $new_options;
	}
	public function decrypt_passphrase( $options ) {

		if( !function_exists("mcrypt_encrypt") )
			return $options;

		if( isset( $options['sand_passphrase'] ) && $options['sand_passphrase'] )
			$options['sand_passphrase'] = $this->decrypt( $options['sand_passphrase'] );
		
		if( isset( $options['prod_passphrase'] ) && $options['prod_passphrase'] )
			$options['prod_passphrase'] = $this->decrypt( $options['prod_passphrase'] );

		return $options;
	}
	private function encrypt( $data ) {

		$keyfile = dirname( $this->mp->get_tmp_dir() ) . '/' . mangopayWCConfig::KEY_FILE_NAME;
		if( !file_exists( $keyfile) ) {
			$key = substr( str_shuffle(MD5(microtime())), 0, 16 );
			$file_content = '<?php header("HTTP/1.0 404 Not Found"); echo "File not found."; exit; //' . $key . ' ?>';
			file_put_contents( $keyfile, $file_content );
		} else {
			$file_content = file_get_contents( $keyfile );
			if( preg_match( '|//(\w+)|', $file_content, $matches ) ) {
				$key = $matches[1];
			} else {
				return $data;
			}
		}
		$iv_size = mcrypt_get_iv_size( MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC );
		$iv = mcrypt_create_iv( $iv_size, MCRYPT_RAND );
		$ciphertext = mcrypt_encrypt(MCRYPT_RIJNDAEL_128, $key,
				$data, MCRYPT_MODE_CBC, $iv);
		$ciphertext = $iv . $ciphertext;
		$ciphertext_base64 = base64_encode($ciphertext);
		return $ciphertext_base64;
	}
	private function decrypt( $data ) {
		
		$keyfile = dirname( $this->mp->get_tmp_dir() ) . '/' . mangopayWCConfig::KEY_FILE_NAME;
		if( !file_exists( $keyfile) )
			return $data;
		
		$file_content = file_get_contents( $keyfile );
		if( preg_match( '|//(\w+)|', $file_content, $matches ) ) {
			$key = $matches[1];
		} else {
			return $data;
		}
		
		$ciphertext_dec = base64_decode( $data );	
		$iv_size = mcrypt_get_iv_size( MCRYPT_RIJNDAEL_128, MCRYPT_MODE_CBC );
		$iv_dec = substr($ciphertext_dec, 0, $iv_size);
		$ciphertext_dec = substr($ciphertext_dec, $iv_size);
		$plaintext_dec = mcrypt_decrypt(MCRYPT_RIJNDAEL_128, $key,
				$ciphertext_dec, MCRYPT_MODE_CBC, $iv_dec);
		$plaintext_dec = str_replace("\0", "", $plaintext_dec);
		return $plaintext_dec;
	}
	
	/**
	 * Record single commission as 'paid' in WV's custom commission table
	 * @see /plugins/wc-vendors/classes/class-commission.php
	 *
	 */
	public function set_commission_paid( $pv_commission_id ) {
		global $wpdb;
	
		$table_name = $wpdb->prefix . mangopayWCConfig::WV_TABLE_NAME;
	
		$query  = "UPDATE `{$table_name}` SET `status` = 'paid' WHERE id=%d";
		$query = $wpdb->prepare( $query, $pv_commission_id );
		$result = $wpdb->query( $query );
	
		return $result;
	}
    
    function mangopay_form_field( $key, $args, $value = null ) {
		$defaults = array(
			'type'              => 'text',
			'label'             => '',
			'description'       => '',
			'placeholder'       => '',
			'maxlength'         => false,
			'required'          => false,
			'autocomplete'      => false,
			'id'                => $key,
			'class'             => array(),
			'label_class'       => array(),
			'input_class'       => array(),
			'return'            => false,
			'options'           => array(),
			'custom_attributes' => array(),
			'validate'          => array(),
			'default'           => '',
			'autofocus'         => '',
			'priority'          => '',
		);
		$args = wp_parse_args( $args, $defaults );
		$args = apply_filters( 'woocommerce_form_field_args', $args, $key, $value );
		if ( $args['required'] ) {
			$args['class'][] = 'validate-required';
			$required = ' <abbr class="required" title="' . esc_attr__( 'required', 'woocommerce' ) . '">*</abbr>';
		} else {
			$required = '';
		}
		if ( is_string( $args['label_class'] ) ) {
			$args['label_class'] = array( $args['label_class'] );
		}
		if ( is_null( $value ) ) {
			$value = $args['default'];
		}
		// Custom attribute handling
		$custom_attributes         = array();
		$args['custom_attributes'] = array_filter( (array) $args['custom_attributes'] );
		if ( $args['maxlength'] ) {
			$args['custom_attributes']['maxlength'] = absint( $args['maxlength'] );
		}
		if ( ! empty( $args['autocomplete'] ) ) {
			$args['custom_attributes']['autocomplete'] = $args['autocomplete'];
		}
		if ( true === $args['autofocus'] ) {
			$args['custom_attributes']['autofocus'] = 'autofocus';
		}
		if ( ! empty( $args['custom_attributes'] ) && is_array( $args['custom_attributes'] ) ) {
			foreach ( $args['custom_attributes'] as $attribute => $attribute_value ) {
				$custom_attributes[] = esc_attr( $attribute ) . '="' . esc_attr( $attribute_value ) . '"';
			}
		}
		if ( ! empty( $args['validate'] ) ) {
			foreach ( $args['validate'] as $validate ) {
				$args['class'][] = 'validate-' . $validate;
			}
		}
		$field           = '';
		$label_id        = $args['id'];
		$sort            = $args['priority'] ? $args['priority'] : '';
		$field_container = '<p class="form-row %1$s" id="%2$s" data-sort="' . esc_attr( $sort ) . '">%3$s</p>';
		switch ( $args['type'] ) {
            
			case 'country_nop' :
                //$key = 'billing_country';
                $id =  esc_attr( $args['id'] );//vendor_account_country vendor_account_region
                //$id = 'billing_country';
                $name = esc_attr( $key );
                //$name = 'billing_country';
				$countries = 'shipping_country' === $key ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();
				if ( 1 === sizeof( $countries ) ) {
					$field .= '<strong>' . current( array_values( $countries ) ) . '</strong>';
					$field .= '<input type="hidden" name="' . $name . '" id="' . $id . '" value="' . current( array_keys( $countries ) ) . '" ' . implode( ' ', $custom_attributes ) . ' class="country_to_state" />';
				} else {
					$field = '<select name="' .$name . '" id="' . $id . '" class="country_to_state country_select ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . '>' . '<option value="">' . __( 'Select a country&hellip;', 'woocommerce' ) . '</option>';
					foreach ( $countries as $ckey => $cvalue ) {
						$field .= '<option value="' . esc_attr( $ckey ) . '" ' . selected( $value, $ckey, false ) . '>' . $cvalue . '</option>';
					}
					$field .= '</select>';
					$field .= '<noscript><input type="submit" name="woocommerce_checkout_update_totals" value="' . esc_attr__( 'Update country', 'woocommerce' ) . '" /></noscript>';
				}
				break;
                
            case 'country' :
				$countries = 'shipping_country' === $key ? WC()->countries->get_shipping_countries() : WC()->countries->get_allowed_countries();
				if ( 1 === sizeof( $countries ) ) {
					$field .= '<strong>' . current( array_values( $countries ) ) . '</strong>';
					$field .= '<input type="hidden" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" value="' . current( array_keys( $countries ) ) . '" ' . implode( ' ', $custom_attributes ) . ' class="country_to_state" />';
				} else {
					$field = '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" class="country_to_state country_select ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . '>' . '<option value="">' . __( 'Select a country&hellip;', 'woocommerce' ) . '</option>';
					foreach ( $countries as $ckey => $cvalue ) {
						$field .= '<option value="' . esc_attr( $ckey ) . '" ' . selected( $value, $ckey, false ) . '>' . $cvalue . '</option>';
					}
					$field .= '</select>';
					$field .= '<noscript><input type="submit" name="woocommerce_checkout_update_totals" value="' . esc_attr__( 'Update country', 'woocommerce' ) . '" /></noscript>';
				}
            break;
                
			case 'state' :
				/* Get Country */
                
                $country_key = 'billing_state' === $key ? 'billing_country' : 'shipping_country';
                $current_cc  = WC()->checkout->get_value( $country_key );
                $id = esc_attr( $args['id'] );
                $name = esc_attr( $key );
                if(isset($args['countrykey'])){
                    if(isset($value)){
                        $current_cc = $value;
                    }else{
                        $current_cc = get_user_meta( $args['userid'], $args['countrykey'] , true );//'vendor_account_country'
                    }
                    
                }
                $states = WC()->countries->get_states( $current_cc );
                                
				if ( is_array( $states ) && empty( $states ) ) {
					$field_container = '<p class="form-row %1$s" id="%2$s" style="display: none">%3$s</p>';
					$field .= '<input type="hidden" class="hidden" name="' . $name . '" id="' . $id . '" value="" ' . implode( ' ', $custom_attributes ) . ' placeholder="' . esc_attr( $args['placeholder'] ) . '" />';
				} elseif ( is_array( $states ) ) {
					$field .= '<select name="' .$name . '" id="' . $id . '" class="state_select ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . ' data-placeholder="' . esc_attr( $args['placeholder'] ) . '">
						<option value="">' . __( 'Select a state&hellip;', 'woocommerce' ) . '</option>';
					foreach ( $states as $ckey => $cvalue ) {
						$field .= '<option value="' . esc_attr( $ckey ) . '" ' . selected( $value, $ckey, false ) . '>' . $cvalue . '</option>';
					}
					$field .= '</select>';
				} else {
					$field .= '<input type="text" class="input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" value="' . esc_attr( $value ) . '"  placeholder="' . esc_attr( $args['placeholder'] ) . '" name="' . esc_attr( $key ) . '" id="' . $id . '" ' . implode( ' ', $custom_attributes ) . ' />';
				}
				break;
			case 'textarea' :
				$field .= '<textarea name="' . esc_attr( $key ) . '" class="input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '" ' . ( empty( $args['custom_attributes']['rows'] ) ? ' rows="2"' : '' ) . ( empty( $args['custom_attributes']['cols'] ) ? ' cols="5"' : '' ) . implode( ' ', $custom_attributes ) . '>' . esc_textarea( $value ) . '</textarea>';
				break;
			case 'checkbox' :
				$field = '<label class="checkbox ' . implode( ' ', $args['label_class'] ) . '" ' . implode( ' ', $custom_attributes ) . '>
						<input type="' . esc_attr( $args['type'] ) . '" class="input-checkbox ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" value="1" ' . checked( $value, 1, false ) . ' /> '
						 . $args['label'] . $required . '</label>';
				break;
			case 'password' :
			case 'text' :
			case 'email' :
			case 'tel' :
			case 'number' :
				$field .= '<input type="' . esc_attr( $args['type'] ) . '" class="input-text ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" placeholder="' . esc_attr( $args['placeholder'] ) . '"  value="' . esc_attr( $value ) . '" ' . implode( ' ', $custom_attributes ) . ' />';
				break;
			case 'select' :
				$options = $field = '';
				if ( ! empty( $args['options'] ) ) {
					foreach ( $args['options'] as $option_key => $option_text ) {
						if ( '' === $option_key ) {
							// If we have a blank option, select2 needs a placeholder
							if ( empty( $args['placeholder'] ) ) {
								$args['placeholder'] = $option_text ? $option_text : __( 'Choose an option', 'woocommerce' );
							}
							$custom_attributes[] = 'data-allow_clear="true"';
						}
						$options .= '<option value="' . esc_attr( $option_key ) . '" ' . selected( $value, $option_key, false ) . '>' . esc_attr( $option_text ) . '</option>';
					}
					$field .= '<select name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '" class="select ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" ' . implode( ' ', $custom_attributes ) . ' data-placeholder="' . esc_attr( $args['placeholder'] ) . '">
							' . $options . '
						</select>';
				}
				break;
			case 'radio' :
				$label_id = current( array_keys( $args['options'] ) );
				if ( ! empty( $args['options'] ) ) {
					foreach ( $args['options'] as $option_key => $option_text ) {
						$field .= '<input type="radio" class="input-radio ' . esc_attr( implode( ' ', $args['input_class'] ) ) . '" value="' . esc_attr( $option_key ) . '" name="' . esc_attr( $key ) . '" id="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_key ) . '"' . checked( $value, $option_key, false ) . ' />';
						$field .= '<label for="' . esc_attr( $args['id'] ) . '_' . esc_attr( $option_key ) . '" class="radio ' . implode( ' ', $args['label_class'] ) . '">' . $option_text . '</label>';
					}
				}
				break;
		}
		if ( ! empty( $field ) ) {
			$field_html = '';
			if ( $args['label'] && 'checkbox' != $args['type'] ) {
				$field_html .= '<label for="' . esc_attr( $label_id ) . '" class="' . esc_attr( implode( ' ', $args['label_class'] ) ) . '">' . $args['label'] . $required . '</label>';
			}
			$field_html .= $field;
			if ( $args['description'] ) {
				$field_html .= '<span class="description">' . esc_html( $args['description'] ) . '</span>';
			}
			$container_class = esc_attr( implode( ' ', $args['class'] ) );
			$container_id = esc_attr( $args['id'] ) . '_field';
			$after = ! empty( $args['clear'] ) ? '<div class="clear"></div>' : '';
			$field = sprintf( $field_container, $container_class, $container_id, $field_html ) . $after;
		}
		$field = apply_filters( 'woocommerce_form_field_' . $args['type'], $field, $key, $args, $value );
		if ( $args['return'] ) {
			return $field;
		} else {
			echo $field;
		}
	}

}
?>