<?php
/**
 * MANGOPAY WooCommerce plugin MANGOPAY access class
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
class mpAccess {

	/** Class constants **/
	const DEBUG 			= false;	// Turns debugging messages on or off (should be false for production)
	const TMP_DIR_NAME		= 'mp_tmp';
	const SANDBOX_API_URL	= 'https://api.sandbox.mangopay.com';
	const PROD_API_URL		= 'https://api.mangopay.com';
	const SANDBOX_DB_URL	= 'https://dashboard.sandbox.mangopay.com';
	const PROD_DB_URL		= 'https://dashboard.mangopay.com';
	const LOGFILENAME		= 'mp-transactions.log.php';
	const WC_PLUGIN_PATH	= 'woocommerce/woocommerce.php';
	const WV_PLUGIN_PATH	= 'wc-vendors/class-wc-vendors.php';
	const PAYIN_SUCCESS_HK	= 'PAYIN_NORMAL_SUCCEEDED';
	const PAYIN_FAILED_HK	= 'PAYIN_NORMAL_FAILED';
	
	/** Class variables **/
	private $mp_loaded		= false;
	private	$mp_production	= false;	// Sandbox environment is default
	private $mp_client_id	= '';
	private $mp_passphrase	= '';
	private $mp_db_url		= '';
	private $logFilePath	= '';
	private $errorStatus	= false;
	private $errorMsg;
	private $mangoPayApi;
	
	/**
	 * @var Singleton The reference to *Singleton* instance of this class
	 * 
	 */
	private static $instance;
	
	/**
	 * Returns the *Singleton* instance of this class.
	 *
	 * @return Singleton The *Singleton* instance.
	 * 
	 */
	public static function getInstance() {
		if( null === static::$instance )
			static::$instance = new mpAccess();
	
		return static::$instance;
	}
	
	/**
	 * Protected constructor to prevent creating a new instance of the
	 * Singleton via the `new` operator from outside of this class.
	 * 
	 */
	protected function __construct() {
		//$this->init();
	}
	
	/**
	 * Sets the MANGOPAY environment to either 'Production' or 'Sandbox'
	 * @param unknown $environment
	 * 
	 */
	public function setEnv( 
		$prod_or_sandbox, 
		$client_id, 
		$passphrase, 
		$default_buyer_status, 
		$default_vendor_status,
		$default_business_type,
		$debug 
	) {
		if( 'prod' != $prod_or_sandbox && 'sandbox' != $prod_or_sandbox )
			return false;

		$this->mp_client_id			= $client_id;
		$this->mp_passphrase		= $passphrase;
		$this->default_buyer_status	= $default_buyer_status;
		$this->default_vendor_status= $default_vendor_status;
		$this->default_business_type= $default_business_type;
		$this->mp_db_url			= self::SANDBOX_DB_URL;
		
		/** @var $this->mp_production is false by default **/
		if( 'prod' == $prod_or_sandbox ) {
			$this->mp_production	= true;
			$this->mp_db_url		= self::PROD_DB_URL;
		}
		$this->init();
	}
	
	/**
	 * Returns class error status
	 * 
	 * @return array $status
	 * 
	 */
	public function getStatus( $mangopayWCMain ) {
		
		/** Checks that at least one card/payment method is enabled **/
		$card_enabled = false;
		if(	$wc_settings = get_option( 'woocommerce_mangopay_settings' ) ) {
			if( is_array( $wc_settings ) && isset( $wc_settings['enabled'] ) ) {
				$enabled = $wc_settings['enabled'];
				foreach( $wc_settings as $key=>$value )
					if( preg_match( '/^enabled_/', $key ) && 'yes' == $value )
						$card_enabled	= true;
			} else {
				$enabled = false;
			}
		} else {
			if( false === $wc_settings ) {
				/** When the option is not set at all the default is true **/
				$enabled		= 'yes';
				$card_enabled	= true;
			} else {
				$enabled		= false;
			}
		}
		
		/** If Bankwire Direct payment is enabled, check that the incoming webhooks are registered **/
		$webhook_status = false;
		if( $wc_settings && isset( $wc_settings['enabled_BANK_WIRE'] ) && 'yes' == $wc_settings['enabled_BANK_WIRE'] ) {
			$bankwire_enabled = true;
			
			$webhook_status = (
				$this->check_webhook( $mangopayWCMain->options['webhook_key'], self::PAYIN_SUCCESS_HK ) &&
				$this->check_webhook( $mangopayWCMain->options['webhook_key'], self::PAYIN_FAILED_HK )
			);
			
		} else {
			$bankwire_enabled = false;
		}
		
		$status = array(
			'status'			=> $this->errorStatus,
			'message'			=> $this->errorMsg,
			'environment'		=> $this->mp_production,
			'client_id'			=> $this->mp_client_id,
			'loaded'			=> $this->mp_loaded,
			'enabled'			=> $enabled,
			'card_enabled'		=> $card_enabled,
			'bankwire_enabled'	=> $bankwire_enabled,
			'webhook_status'	=> $webhook_status
		);
		return $status;
	}
	
	/**
	 * MANGOPAY init
	 * loads and instantiates the MANGOPAY API
	 * 
	 */
	private function init() {
		
		/** Setup tmp directory **/
		$tmp_path = $this->set_tmp_dir();
		
		$this->logFilePath	= $tmp_path . '/' . self::LOGFILENAME;
		
		/** Initialize log file if not present **/
		if( !file_exists( $this->logFilePath ) )
			file_put_contents( $this->logFilePath, '<?php header("HTTP/1.0 404 Not Found"); echo "File not found."; exit; /*' );

		/** Add a .htaccess to mp_tmp dir for added security **/
		$htaccess_path = $tmp_path . '/' . '.htaccess';
		if( !file_exists( $htaccess_path ) )
			file_put_contents( $htaccess_path, "order deny,allow\ndeny from all\nallow from 127.0.0.1" );
		$htaccess_path = dirname( $tmp_path ) . '/' . '.htaccess';
		if( !file_exists( $htaccess_path ) )
			file_put_contents( $htaccess_path, "order deny,allow\ndeny from all\nallow from 127.0.0.1" );
		
		/** Instantiate MP API **/
		$sdk_dir = dirname( dirname( __FILE__ ) ) . '/sdk';
		require_once( $sdk_dir . '/MangoPay/Autoloader.php' );
		require_once( 'mock-storage.inc.php' );
		
		$this->mangoPayApi = new MangoPay\MangoPayApi();
		
		/** MANGOPAY API configuration **/
		$this->mangoPayApi->Config->ClientId		= $this->mp_client_id;
		$this->mangoPayApi->Config->ClientPassword	= $this->mp_passphrase;
		$this->mangoPayApi->Config->TemporaryFolder	= $tmp_path . '/';
		$this->mangoPayApi->OAuthTokenManager->RegisterCustomStorageStrategy(new \MangoPay\WPPlugin\MockStorageStrategy());
		if( $this->mp_production ) {
			$this->mangoPayApi->Config->BaseUrl 	= self::PROD_API_URL;
		} else {
			$this->mangoPayApi->Config->BaseUrl 	= self::SANDBOX_API_URL;
		}
		
		return true;
	}
	
	/**
	 * Setup temporary directory
	 * 
	 * @return string
	 */
	private function set_tmp_dir() {
		$uploads			= wp_upload_dir();
		$uploads_path		= $uploads['basedir'];
		$prod_or_sandbox 	= 'sandbox';
		if( $this->mp_production )
			$prod_or_sandbox = 'prod';
		$tmp_path			= $uploads_path . '/' . self::TMP_DIR_NAME . '/' . $prod_or_sandbox;
		wp_mkdir_p( $tmp_path );
		return $tmp_path;
	}
		
	/**
	 * Simple API connection test
	 * @see: https://gist.github.com/hobailey/105c53717b8547ba66d7
	 *
	 */
	public function connection_test() {

		if( !self::getInstance()->mp_loaded )
			$this->init();
		
		try{
			$pagination	= new MangoPay\Pagination( 1, 1 );
			$sorting	= new \MangoPay\Sorting();
			$sorting->AddField( 'CreationDate', \MangoPay\SortDirection::DESC );
			$result 	= $this->mangoPayApi->Users->GetAll( $pagination, $sorting );
			$this->mp_loaded = true;
			return $result;
				
		} catch (MangoPay\Libraries\ResponseException $e) {
		
			echo '<div class="error"><p>' . __( 'MANGOPAY API returned:', 'mangopay' ) . ' ';
			MangoPay\Libraries\Logs::Debug('MangoPay\ResponseException Code', $e->GetCode());
			MangoPay\Libraries\Logs::Debug('Message', $e->GetMessage());
			MangoPay\Libraries\Logs::Debug('Details', $e->GetErrorDetails());
			echo '</p></div>';
		
		} catch (MangoPay\Libraries\Exception $e) {
		
			echo '<div class="error"><p>' . __( 'MANGOPAY API returned:', 'mangopay' ) . ' ';
			MangoPay\Libraries\Logs::Debug('MangoPay\Exception Message', $e->GetMessage());
			echo '</p></div>';
		
		}  catch (Exception $e) {
			$error_message = __( 'Error:', 'mangopay' ) .
					' ' . $e->getMessage();
			error_log(
				current_time( 'Y-m-d H:i:s', 0 ) . ': ' . $error_message . "\n\n",
				3,
				$this->logFilePath
			);
			
			echo '<div class="error"><p>' . __( 'MANGOPAY API returned:', 'mangopay' ) . ' ';
			echo '&laquo;' . $error_message . '&raquo;</p></div>';
		}
		return false;
	}
		
	/**
	 * Checks if wp_user already has associated mp account
	 * if not, creates an mp user account
	 * 
	 * @param string $wp_user_id
	 * 
	 */
	public function set_mp_user( $wp_user_id, $p_type='NATURAL' ) {
		
		/** We store a different mp_user_id for production and sandbox environments **/
		$umeta_key = 'mp_user_id';
		if( !$this->mp_production )
			$umeta_key .= '_sandbox';
		
		$legal_p_type = null;
				
		if( !$mp_user_id = get_user_meta( $wp_user_id, $umeta_key, true ) ) {
			
			//echo 'p_type: ' . $p_type . '<br/>';	//Debug
			
			if( !$wp_userdata = get_userdata( $wp_user_id ) )
				return false;	// WP User has been deleted
			
			/** Vendor or buyer ? **/			
			if( 
				isset( $wp_userdata->wp_capabilities['vendor'] ) || 
				( is_array($wp_userdata->wp_capabilities) && in_array( 'vendor', $wp_userdata->wp_capabilities , true )) ||
				'BUSINESS' == $p_type
			) {
				
				/** Vendor **/
				if( !empty( $this->default_vendor_status ) ) {
				
					if( 'either' == $this->default_vendor_status ) {
							
						$user_mp_status = get_user_meta( $wp_user_id, 'user_mp_status', true );	//Custom usermeta
						if( !$user_mp_status )
							return false;	// Can't create a MP user in this case
							
						if( 'business' == $user_mp_status )
							$p_type = 'BUSINESS';
							
						if( 'individual' == $user_mp_status )
							$p_type = 'NATURAL';
							
						if( !$p_type )
							return false;
							
					} else {
							
						if( 'businesses' == $this->default_vendor_status )
							$p_type = 'BUSINESS';
							
						if( 'individuals' == $this->default_vendor_status )
							$p_type = 'NATURAL';
				
						if( !$p_type )
							return false;
					}
				
				} else {
					/** The way it worked before (kept for retro-compatibility, but this should in fact never occur) **/
					$p_type = 'BUSINESS';
				}
				
			} else {
				
				/** Buyer **/
				if( !empty( $this->default_buyer_status ) ) {
				
					if( 'either' == $this->default_buyer_status ) {
							
						$user_mp_status = get_user_meta( $wp_user_id, 'user_mp_status', true );	//Custom usermeta
						if( !$user_mp_status )
							return false;	// Can't create a MP user in this case
							
						if( 'business' == $user_mp_status )
							$p_type = 'BUSINESS';
							
						if( 'individual' == $user_mp_status )
							$p_type = 'NATURAL';
							
						if( !$p_type )
							return false;
							
					} else {
							
						if( 'businesses' == $this->default_buyer_status )
							$p_type = 'BUSINESS';
							
						if( 'individuals' == $this->default_buyer_status )
							$p_type = 'NATURAL';
				
						if( !$p_type )
							return false;
					}
				
				} else {
					/** The way it worked before (kept for retro-compatibility, but this should in fact never occur) **/
					$p_type = 'NATURAL';
				}
			}
			
			if( 'BUSINESS' == $p_type ) {
				
				if( 'either' == $this->default_business_type ) {
				
					$user_business_type = get_user_meta( $wp_user_id, 'user_business_type', true );	//Custom usermeta
					if( !$user_business_type )
						return false;	// Can't create a MP user in this case
					
					if( 'business' == $user_business_type )
						$legal_p_type = 'BUSINESS';
						
					if( 'organisation' == $user_business_type )
						$legal_p_type = 'ORGANIZATION';
					
					if( 'soletrader' == $user_business_type )
						$legal_p_type = 'SOLETRADER';
						
					if( !$legal_p_type )
						return false;
					
				} else {
					
					if( 'businesses' == $this->default_business_type )
						$legal_p_type = 'BUSINESS';
						
					if( 'organisations' == $this->default_business_type )
						$legal_p_type = 'ORGANIZATION';
					
					if( 'soletraders' == $this->default_business_type )
						$legal_p_type = 'SOLETRADER';
					
					if( !$legal_p_type )
						return false;
				}
			}
			
			/* Debug
			var_dump( $wp_userdata->wp_capabilities );
			var_dump( in_array( 'vendor', $wp_userdata->wp_capabilities, true ) );
			var_dump( $p_type ); exit;	//Debug
			*/
			
			/** Required fields **/
			$b_date = strtotime( get_user_meta( $wp_user_id, 'user_birthday', true ) );	//Custom usermeta
			if( $offset = get_option('gmt_offset') )
				$b_date += ( $offset * 60 * 60 );
			
			$natio	= get_user_meta( $wp_user_id, 'user_nationality', true );			//Custom usermeta
			$ctry	= get_user_meta( $wp_user_id, 'billing_country', true );			//WP usermeta
			
			if( !$vendor_name = get_user_meta( $wp_user_id, 'pv_shop_name', true ) )	//WC-Vendor plugin usermeta
				$vendor_name = $wp_userdata->nickname;
				
			if( $mangoUser = $this->createMangoUser( 
				$p_type, 
				$legal_p_type,
				$wp_userdata->first_name, 
				$wp_userdata->last_name, 
				$b_date, 
				$natio, 
				$ctry,
				$wp_userdata->user_email,
				$vendor_name,
				$wp_user_id
			) ) {
				$mp_user_id = $mangoUser->Id;
				
				/** We store a different mp_user_id for production and sandbox environments **/
				$umeta_key = 'mp_user_id';
				if( !$this->mp_production )
					$umeta_key .= '_sandbox';
				update_user_meta( $wp_user_id, $umeta_key, $mp_user_id );
				
				/** Store effective user_mp_status **/
				$user_mp_status		= 'individual';
				$user_business_type	= '';
				if( 'BUSINESS' == $p_type ) {
					$user_mp_status = 'business';
					$user_business_type = 'business';
					if( 'ORGANIZATION' == $legal_p_type )
						$user_business_type = 'organisation';
					if( 'SOLETRADER' == $legal_p_type )
						$user_business_type = 'soletrader';
				}
				update_user_meta( $wp_user_id, 'user_mp_status', $user_mp_status );
				update_user_meta( $wp_user_id, 'user_business_type', $user_business_type );
				
			} else {
				return false;
			}
		} elseif( ( $user_ptype = $this->getDBUserPType( $mp_user_id ) ) != $p_type ) {
			
			if( false === $user_ptype )
				return false;
			
			if( 'BUSINESS' == $p_type && 'LEGAL' == $user_ptype )
				return $mp_user_id;	// This is Ok.
			
			/* Disabled: we do not want to change MP user status after creation */
			//$this->switchDBUserPType( $wp_user_id, $p_type );
		} else {
			//echo 'p_type: ' . $p_type . '<br/>';	//Debug
		}
		return $mp_user_id;
	}
	
	/**
	 * Checks if mp_user already has associated wallet(s)
	 * if not,creates a default wallet
	 * 
	 * @param string $mp_user_id - Required
	 * 
	 */
	public function set_mp_wallet( $mp_user_id ) {

		if( !$mp_user_id )
				return false;
		
		/** Check existing MP user & user type **/
		if( !$mangoUser = $this->mangoPayApi->Users->Get( $mp_user_id ) )
			return false;
		
		//var_dump( $mangoUser ); //Debug
		
		if( 'BUSINESS' == $mangoUser->PersonType || 'LEGAL' == $mangoUser->PersonType ) {
			$account_type = 'Business';
		} elseif( 'NATURAL' == $mangoUser->PersonType ) {
			$account_type = 'Individual';
		} else {
			/** Unknown person type **/
			return false;
		}

		$currency = get_woocommerce_currency();
		
		if( !$wallets = $this->mangoPayApi->Users->GetWallets( $mp_user_id ) ) {
			$result = $this->create_the_wallet( $mp_user_id, $account_type, $currency );
			$wallets = $this->mangoPayApi->Users->GetWallets( $mp_user_id );
		}
		
		//var_dump( $result );	//Debug
		
		/** Check that one wallet has the right currency, otherwise create a new one **/
		$found = false;
		foreach( $wallets as $wallet )
			if( $wallet->Currency == get_woocommerce_currency() )
				$found = true;
		if( !$found ) {
			$result = $this->create_the_wallet( $mp_user_id, $account_type, $currency );
			$wallets = $this->mangoPayApi->Users->GetWallets( $mp_user_id );
		}
		
		//var_dump( $result );	//Debug
		
		return $wallets;
	}
	
	/**
	 * Create a new MP wallet
	 * 
	 * @param int $mp_user_id
	 * @param string $account_type
	 * @param string $currency
	 */
	private function create_the_wallet( $mp_user_id, $account_type, $currency ) {
		$Wallet					= new \MangoPay\Wallet();
		$Wallet->Owners			= array( $mp_user_id );
		$Wallet->Description	= "WooCommerce $account_type $currency Wallet";
		$Wallet->Currency		= $currency;
		return $this->mangoPayApi->Wallets->Create($Wallet);
	}
	
	/**
	 * Register a user's bank account in MP profile
	 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/bankaccount.php
	 * 
	 * @param inst $mp_user_id
	 * @param string $type
	 * @param string $name
	 * @param string $address1
	 * @param string $address2
	 * @param string $city
	 * @param string $region
	 * @param string $country
	 * @param array $account_data
	 * 
	 */
	public function save_bank_account( 
		$mp_user_id, 
		$wp_user_id,
		$existing_account_id,
		$type, 
		$name, 
		$address1, 
		$address2, 
		$city, 
		$postcode, 
		$region,
		$country, 
		$account_data=array(),
		$account_types
	) {

		/** If there is an existing bank account, fetch it first to get the redacted info we did not store **/
		$ExistingBankAccount = null;
		if( $existing_account_id ) {
			try{
				$ExistingBankAccount = $this->mangoPayApi->Users->GetBankAccount( $mp_user_id, $existing_account_id );
			} catch( Exception $e ) {
				$ExistingBankAccount = null;
			}
		}
		
		$BankAccount 			= new \MangoPay\BankAccount();
		$BankAccount->Type 		= $type;
		$BankAccount->UserId	= $mp_user_id;
		
		$detail_class_name 		= 'MangoPay\BankAccountDetails' . $type;
		$BankAccount->Details 	= new $detail_class_name();
		foreach( $account_types[$type] as $field_name => $field_data ) {
			if(
				!empty( $ExistingBankAccount ) &&
				$type == $ExistingBankAccount->Type && (
					empty( $account_data[$field_name] ) ||
					preg_match( '/\*\*/', $account_data[$field_name] )
				)
			) {
				/** Replace redacted data with data from existing bank account **/
				$BankAccount->Details->{$field_data['mp_property']} = $ExistingBankAccount->Details->{$field_data['mp_property']};
			} else {
				if( isset( $account_data[$field_name] ) )
					$BankAccount->Details->{$field_data['mp_property']} = $account_data[$field_name];
			}
		}
		
		$BankAccount->OwnerName 							= $name;
		$BankAccount->OwnerAddress 							= new \MangoPay\Address();
		$BankAccount->OwnerAddress->AddressLine1 			= $address1;
		$BankAccount->OwnerAddress->AddressLine2 			= $address2;
		$BankAccount->OwnerAddress->City 					= $city;
		$BankAccount->OwnerAddress->Country 				= $country;
		$BankAccount->OwnerAddress->PostalCode 				= $postcode;	// Optional? not really...
		//unset( $BankAccount->OwnerAddress->PostalCode );
		
		//$BankAccount->OwnerAddress->Region 		= 'Region';				// Optional
		unset( $BankAccount->OwnerAddress->Region );
		if( isset( $region ) && $region )
			$BankAccount->OwnerAddress->Region				= $region;		// Mandatory for some countries
				
		$BankAccount->Tag									= 'wp_user_id:' . $wp_user_id;
		
		try{
			$BankAccount = $this->mangoPayApi->Users->CreateBankAccount( $mp_user_id, $BankAccount );
		} catch( Exception $e ) {
			$backlink = '<a href="javascript:history.back();">' . __( 'back', 'mangopay' ) . '</a>';
			wp_die( __( 'Error: Invalid bank account data.', 'mangopay' ) . ' ' . $backlink );
		}
			
		return $BankAccount->Id;
	}
	
	/**
	 * Create MANGOPAY User + first wallet
	 *
	 * @param string $p_type		| must be "BUSINESS" or "NATURAL" - Required
	 * @param string $f_name		| first name - Required
	 * @param string $l_name		| last name - Required
	 * @param int $b_date			| birthday (unix timestamp - ex 121271) - Required
	 * @param string $natio			| nationality (2-letter UC country code - ex "FR") - Required
	 * @param string $ctry			| country (2-letter UC country code - ex "FR") - Required
	 * @param string $email			| e-mail address - Required
	 * @param string $vendor_name	| name of business - Required only if $p_type=='BUSINESS'
	 * @param int $wp_user_id		| WP User ID
	 *
	 * @return MangopPayUser $mangoUser
	 * 
	 */
	private function createMangoUser( 
		$p_type, 
		$legal_p_type=null,
		$f_name, 
		$l_name, 
		$b_date, 
		$natio, 
		$ctry, 
		$email, 
		$vendor_name=null, 
		$wp_user_id 
	) {

		/** All fields are required **/
		if( !$p_type || !$f_name || !$l_name || !$b_date || !$natio || !$ctry || !$email ) {
			if( self::DEBUG ) {
				echo __( 'Error: some required fields are missing in createMangoUser', 'mangopay' ) . '<br/>';
				echo "$p_type || !$f_name || !$l_name || !$b_date || !$natio || !$ctry || !$email<br/>";
			}
			return false;
		}
		
		/** Initialize user data **/
		if( 'BUSINESS'==$p_type ) {
			if( !$vendor_name )
				return false;
			
			$mangoUser = new \MangoPay\UserLegal();
			$mangoUser->Name 									= $vendor_name;	//Required
			$mangoUser->LegalPersonType							= $legal_p_type;//Required
			$mangoUser->LegalRepresentativeFirstName			= $f_name;		//Required
			$mangoUser->LegalRepresentativeLastName				= $l_name;		//Required
			$mangoUser->LegalRepresentativeBirthday				= $b_date;		//Required
			$mangoUser->LegalRepresentativeNationality			= $natio;		//Required
			$mangoUser->LegalRepresentativeCountryOfResidence	= $ctry;		//Required
		} else {
			$mangoUser = new \MangoPay\UserNatural();
			$mangoUser->PersonType			= $p_type;
			$mangoUser->FirstName			= $f_name;
			$mangoUser->LastName			= $l_name;
			$mangoUser->Birthday			= $b_date;
			$mangoUser->Nationality			= $natio;
			$mangoUser->CountryOfResidence	= $ctry;
		}
		$mangoUser->Email		= $email;										//Required
		$mangoUser->Tag			= 'wp_user_id:' . $wp_user_id;
		
		if( self::DEBUG ) {
			echo '<pre>';			//Debug
			var_dump( $mangoUser );	//Debug
		}
		
		/** Send the request **/
		try{
			$mangoUser = $this->mangoPayApi->Users->Create($mangoUser);
			$mp_user_id = $mangoUser->Id;
			
		} catch (Exception $e) {
			$error_message = $e->getMessage();
				
			error_log(
				current_time( 'Y-m-d H:i:s', 0 ) . ': ' . $error_message . "\n\n",
				3,
				$this->logFilePath
			);
			
			//$backlink = '<a href="javascript:history.back();">' . __( 'back', 'mangopay' ) . '</a>';
				
			$msg = '<div class="error"><p>' . __( 'Error:', 'mangopay' ) . ' ' .
					__( 'MANGOPAY API returned:', 'mangopay' ) . ' ';
			$msg .= '&laquo;' . $error_message . '&raquo;</p></div>';
			
			echo $msg;
			return false;
		}
				
		if( self::DEBUG ) {
			var_dump( $mangoUser );	//Debug
			echo '</pre>';			//Debug
		}
		
		/** If new user has no wallet yet, create one **/
		$this->set_mp_wallet( $mp_user_id );
		
		return $mangoUser;
	}
	
	/**
	 * Update MP User account info
	 * 
	 * $p_type
	 * @param int $mp_user_id
	 * @param array $usermeta
	 * 
	 */
	public function update_user( $mp_user_id, $usermeta=array() ) {
        
		if( !$mp_user_id )
			return;
		
		/** Get existing MP user **/
		if( !$mangoUser = $this->mangoPayApi->Users->Get( $mp_user_id ) )
			return;
		
		/** mangoUser basic object cleanup **/
		foreach( $mangoUser as $key=>$value )
			if( null==$value )
			unset( $mangoUser->$key );
		
		$needs_updating = false;
		
		//var_dump( $usermeta ); exit;	//Debug
		//var_dump( $mangoUser ); exit;	//Debug
		
		if( 'NATURAL' == $mangoUser->PersonType ) {
			if(
				isset( $usermeta['first_name'] ) &&
				$usermeta['first_name'] &&
				$mangoUser->FirstName != $usermeta['first_name']
			) {
				$mangoUser->FirstName = $usermeta['first_name'];
				$needs_updating = true;
			}
			if(
				isset( $usermeta['last_name'] ) &&
				$usermeta['last_name'] &&
				$mangoUser->LastName != $usermeta['last_name']
			) {
				$mangoUser->LastName = $usermeta['last_name'];
				$needs_updating = true;
			}
			if(
				isset( $usermeta['address_1'] ) &&
				$usermeta['address_1'] && (
					$mangoUser->Address->AddressLine1 != $usermeta['address_1'] ||
					$mangoUser->Address->City != $usermeta['city'] ||
					$mangoUser->Address->PostalCode != $usermeta['postal_code'] ||
					$mangoUser->Address->Country != $usermeta['billing_country']
				)
			) {
				$mangoUser->Address->AddressLine1 = $usermeta['address_1'];
				$mangoUser->Address->City = $usermeta['city'];
				$mangoUser->Address->PostalCode = $usermeta['postal_code'];
				$mangoUser->Address->Country = $usermeta['billing_country'];
				
				if(
					'US' == $usermeta['billing_country'] ||
					'MX' == $usermeta['billing_country'] ||
					'CA' == $usermeta['billing_country']
				) 
					$mangoUser->Address->Region = $usermeta['billing_state'];
        
				$needs_updating = true;
			}
			if( 
				isset( $usermeta['billing_country'] ) && 
				$usermeta['billing_country'] &&
				$mangoUser->CountryOfResidence != $usermeta['billing_country']
			) {
				$mangoUser->CountryOfResidence = $usermeta['billing_country'];
				$needs_updating = true;
			}
			
			if( isset( $usermeta['user_birthday'] ) ) {
				$timestamp = strtotime( $usermeta['user_birthday'] );
				if( $offset = get_option('gmt_offset') )
					$timestamp += ( $offset * 60 * 60 );
				
				/* *
				 echo '<strong>Birthday debug:</strong><br/>';										//Debug
				echo 'stored birth date: ' . $usermeta['user_birthday'] . '<br/>';					//Debug
				echo 'GMT offset: ' . get_option('gmt_offset') . '<br/>';							//Debug
				echo 'Original timestamp: ' . strtotime( $usermeta['user_birthday'] ) . '<br/>';	//Debug
				echo 'Correct UTC timestamp for MP: ' . $timestamp . '<br/>';						//Debug
				exit;																				//Debug
				/* */
			}
						
			if(
				isset( $usermeta['user_birthday'] ) &&
				$usermeta['user_birthday'] &&
				$mangoUser->Birthday != $timestamp
			) {
				$mangoUser->Birthday = $timestamp;
				$needs_updating = true;
			}
			if(
				isset( $usermeta['user_nationality'] ) &&
				$usermeta['user_nationality'] &&
				$mangoUser->Nationality != $usermeta['user_nationality']
			) {
				$mangoUser->Nationality = $usermeta['user_nationality'];
				$needs_updating = true;
			}
			if(
				isset( $usermeta['user_email'] ) &&
				$usermeta['user_email'] &&
				$mangoUser->Email != $usermeta['user_email']
			) {
				$mangoUser->Email = $usermeta['user_email'];
				$needs_updating = true;
			}
		} else {
			/** Business / legal user **/
			if(
				isset( $usermeta['pv_shop_name'] ) &&
				$usermeta['pv_shop_name'] &&
				$mangoUser->Name != $usermeta['pv_shop_name']
			) {
				$mangoUser->Name = $usermeta['pv_shop_name'];
				$needs_updating = true;
			}
			if(
				isset( $usermeta['first_name'] ) &&
				$usermeta['first_name'] &&
				$mangoUser->LegalRepresentativeFirstName != $usermeta['first_name']
			) {
				$mangoUser->LegalRepresentativeFirstName = $usermeta['first_name'];
				$needs_updating = true;
			}
			if(
				isset( $usermeta['last_name'] ) &&
				$usermeta['last_name'] &&
				$mangoUser->LegalRepresentativeLastName != $usermeta['last_name']
			) {
				$mangoUser->LegalRepresentativeLastName = $usermeta['last_name'];
				$needs_updating = true;
			}
			if(
				isset( $usermeta['address_1'] ) &&
				$usermeta['address_1'] && (
					$mangoUser->LegalRepresentativeAddress->AddressLine1 != $usermeta['address_1'] ||
					$mangoUser->LegalRepresentativeAddress->City != $usermeta['city'] ||
					$mangoUser->LegalRepresentativeAddress->PostalCode != $usermeta['postal_code'] ||
					$mangoUser->LegalRepresentativeAddress->Country != $usermeta['billing_country']
				)
			) {
				$mangoUser->LegalRepresentativeAddress->AddressLine1 = $usermeta['address_1'];
				$mangoUser->LegalRepresentativeAddress->City = $usermeta['city'];
				$mangoUser->LegalRepresentativeAddress->PostalCode = $usermeta['postal_code'];
				$mangoUser->LegalRepresentativeAddress->Country = $usermeta['billing_country'];
        
        		if(
        			'US' == $usermeta['billing_country'] || 
        			'MX' == $usermeta['billing_country'] || 
        			'CA' == $usermeta['billing_country']
        		)
					$mangoUser->LegalRepresentativeAddress->Region = $usermeta['billing_state'];
        
				$needs_updating = true;
			}
			if(
				isset( $usermeta['billing_country'] ) &&
				$usermeta['billing_country'] &&
				$mangoUser->LegalRepresentativeCountryOfResidence != $usermeta['billing_country']
			) {
				$mangoUser->LegalRepresentativeCountryOfResidence = $usermeta['billing_country'];
				$needs_updating = true;
			}
			
			if( isset( $usermeta['user_birthday'] ) ) {
				$timestamp = strtotime( $usermeta['user_birthday'] );
				if( $offset = get_option('gmt_offset') )
					$timestamp += ( $offset * 60 * 60 );
			
				/* *
				 echo '<strong>Birthday debug:</strong><br/>';										//Debug
				echo 'stored birth date: ' . $usermeta['user_birthday'] . '<br/>';					//Debug
				echo 'GMT offset: ' . get_option('gmt_offset') . '<br/>';							//Debug
				echo 'Original timestamp: ' . strtotime( $usermeta['user_birthday'] ) . '<br/>';	//Debug
				echo 'Correct UTC timestamp for MP: ' . $timestamp . '<br/>';						//Debug
				exit;																				//Debug
				/* */
			}
			
			if(
				isset( $usermeta['user_birthday'] ) &&
				$usermeta['user_birthday'] &&
				$mangoUser->LegalRepresentativeBirthday != $timestamp
			) {
				$mangoUser->LegalRepresentativeBirthday = $timestamp;
				$needs_updating = true;
			}
			if(
				isset( $usermeta['user_nationality'] ) &&
				$usermeta['user_nationality'] &&
				$mangoUser->LegalRepresentativeNationality != $usermeta['user_nationality']
			) {
				$mangoUser->LegalRepresentativeNationality = $usermeta['user_nationality'];
				$needs_updating = true;
			}
			if(
				isset( $usermeta['user_email'] ) &&
				$usermeta['user_email'] &&
				$mangoUser->Email != $usermeta['user_email']
			) {
				$mangoUser->Email = $usermeta['user_email'];
				$needs_updating = true;
			}
		}
		
		if( $needs_updating ) {
			
			/** mangoUser address objects cleanup **/
			if( 
				isset( $mangoUser->Address ) && (
					!$mangoUser->Address->AddressLine1	|| 
					!$mangoUser->Address->City 			||
					!$mangoUser->Address->PostalCode	||
					!$mangoUser->Address->Country
				)
			)
				unset( $mangoUser->Address );
			
			if(
				isset( $mangoUser->HeadquartersAddress ) && (
					!$mangoUser->HeadquartersAddress->AddressLine1	||
					!$mangoUser->HeadquartersAddress->City 			||
					!$mangoUser->HeadquartersAddress->PostalCode	||
					!$mangoUser->HeadquartersAddress->Country
				)
			)
				unset( $mangoUser->HeadquartersAddress );
			
			if(
				isset( $mangoUser->LegalRepresentativeAddress ) && (
					!$mangoUser->LegalRepresentativeAddress->AddressLine1	||
					!$mangoUser->LegalRepresentativeAddress->City 			||
					!$mangoUser->LegalRepresentativeAddress->PostalCode	||
					!$mangoUser->LegalRepresentativeAddress->Country
				)
			)
				unset( $mangoUser->LegalRepresentativeAddress );
		
			//var_dump( $mangoUser );	//Debug
			
			$result = $this->mangoPayApi->Users->Update($mangoUser);
		}
	}
	
	/**
	 * Generate URL for card payin button
	 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/payin-card-web.php
	 * 
	 */
	public function card_payin_url( 
			$order_id, 
			$wp_user_id, 
			$amount, 
			$currency='EUR', 
			$fees, 
			$return_url, 
			$locale,
			$mp_card_type='CB_VISA_MASTERCARD',
			$mp_template_url=''
	) {
		
		/** Get mp_user_id and mp_wallet_id from wp_user_id **/
		$mp_user_id		= $this->set_mp_user( $wp_user_id );
		$wallets 		= $this->set_mp_wallet( $mp_user_id );
		
		if( !$wallets && !( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			if( self::DEBUG ) {
				echo "<pre>mp_user_id:\n";
				var_dump( $mp_user_id );
				echo "wallets:\n";
				var_dump( $wallets );
				echo '</pre>';
			}
			$my_account = '<a href="' . wc_customer_edit_account_url() . '" title="' . __( 'My Account', 'mangopay' ) . '">' . __( 'My Account', 'mangopay' ) . '</a>';
			wp_die( sprintf( __( 'Your profile info needs to be completed. Please go to %s and fill-in all required fields.', 'mangopay' ), $my_account ) );
		}
		
		/** Take first wallet with right currency **/
		foreach( $wallets as $wallet )
			if( $wallet->Currency == $currency )
				$mp_wallet_id = $wallet->Id;
		
		/** If no wallet abort **/
		if( !isset( $mp_wallet_id ) || !$mp_wallet_id )
			return false;
		
		$PayIn 								= new \MangoPay\PayIn();
		$PayIn->CreditedWalletId			= $mp_wallet_id;
		$PayIn->Tag							= 'WC Order #' . $order_id;
		$PayIn->AuthorId 					= $mp_user_id;
		$PayIn->PaymentType 				= 'CARD';
		$PayIn->PaymentDetails 				= new \MangoPay\PayInPaymentDetailsCard();
		$PayIn->PaymentDetails->CardType 	= $mp_card_type;
		$PayIn->DebitedFunds 				= new \MangoPay\Money();
		$PayIn->DebitedFunds->Currency		= $currency;
		$PayIn->DebitedFunds->Amount		= $amount;
		$PayIn->Fees 						= new \MangoPay\Money();
		$PayIn->Fees->Currency 				= $currency;
		$PayIn->Fees->Amount 				= $fees;
		$PayIn->ExecutionType 				= 'WEB';
		$PayIn->ExecutionDetails 			= new \MangoPay\PayInExecutionDetailsWeb();
		$PayIn->ExecutionDetails->ReturnURL	= $return_url;
		$PayIn->ExecutionDetails->Culture	= $locale;
		
		if( $mp_template_url )
			$PayIn->ExecutionDetails->TemplateURLOptions = array( 'PAYLINE' => $mp_template_url );
		
		$result = $this->mangoPayApi->PayIns->Create($PayIn);
	
		/** Return the RedirectUrl and the transaction_id **/
		return array(
			'redirect_url'		=> $result->ExecutionDetails->RedirectURL,
			'transaction_id'	=> $result->Id
		);
	}
	
	/**
	 * Get WireReference and BankAccount data for a bank_wire payment
	 * 
	 */
	public function bankwire_payin_ref(
		$order_id,						// Used to fill-in the "Tag" optional info
		$wp_user_id, 					// WP User ID
		$amount,						// Amount
		$currency='EUR',				// Currency
		$fees							// Fees
	) {
		/** Get mp_user_id and mp_wallet_id from wp_user_id **/
		$mp_user_id		= $this->set_mp_user( $wp_user_id );
		$wallets 		= $this->set_mp_wallet( $mp_user_id );
		
		if( !$wallets && !( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			if( self::DEBUG ) {
				echo "<pre>mp_user_id:\n";
				var_dump( $mp_user_id );
				echo "wallets:\n";
				var_dump( $wallets );
				echo '</pre>';
			}
			$my_account = '<a href="' . wc_customer_edit_account_url() . '" title="' . __( 'My Account', 'mangopay' ) . '">' . __( 'My Account', 'mangopay' ) . '</a>';
			wp_die( sprintf( __( 'Your profile info needs to be completed. Please go to %s and fill-in all required fields.', 'mangopay' ), $my_account ) );
		}
		
		/** Take first wallet with right currency **/
		foreach( $wallets as $wallet )
			if( $wallet->Currency == $currency )
			$mp_wallet_id = $wallet->Id;
		
		/** If no wallet abort **/
		if( !isset( $mp_wallet_id ) || !$mp_wallet_id )
			return false;
		
		$PayIn 								= new \MangoPay\PayIn();
		$PayIn->CreditedWalletId			= $mp_wallet_id;
		$PayIn->Tag							= 'WC Order #' . $order_id;
		$PayIn->AuthorId 					= $mp_user_id;
		$PayIn->PaymentType 				= 'BANK_WIRE';
		$PayIn->PaymentDetails 				= new \MangoPay\PayInPaymentDetailsBankWire();
		$PayIn->PaymentDetails->DeclaredDebitedFunds 				= new \MangoPay\Money();
		$PayIn->PaymentDetails->DeclaredDebitedFunds->Currency		= $currency;
		$PayIn->PaymentDetails->DeclaredDebitedFunds->Amount		= $amount;
		$PayIn->PaymentDetails->DeclaredFees 			= new \MangoPay\Money();
		$PayIn->PaymentDetails->DeclaredFees->Currency	= $currency;
		$PayIn->PaymentDetails->DeclaredFees->Amount	= $fees;
		$PayIn->ExecutionDetails 			= new \MangoPay\PayInExecutionDetailsDirect();
		
		return $this->mangoPayApi->PayIns->Create($PayIn);
	}
	
	/**
	 * Processes card payin refund
	 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/refund-payin.php
	 *
	 */
	public function card_refund( $order_id, $mp_transaction_id, $wp_user_id, $amount, $currency, $reason ) {
		
		$mp_user_id	= $this->set_mp_user( $wp_user_id );
		
		//$PayIn = $this->get_payin( $mp_transaction_id );
		//var_dump( $PayIn ); exit; //Debug;
		
		$PayInId						= $mp_transaction_id;
		$Refund							= new \MangoPay\Refund();
		$Refund->AuthorId				= $mp_user_id;
		$Refund->DebitedFunds			= new \MangoPay\Money();
		$Refund->DebitedFunds->Currency	= $currency;
		$Refund->DebitedFunds->Amount	= $amount;
		$Refund->Fees					= new \MangoPay\Money();
		$Refund->Fees->Currency			= $currency;
		$Refund->Fees->Amount			= 0;
		$Refund->Tag					= 'WC Order #' . $order_id . ' - ' . $reason . ' - ValidatedBy:' . wp_get_current_user()->user_login;
		$result = $this->mangoPayApi->PayIns->CreateRefund( $PayInId, $Refund );
	
		return $result;
	}
	
	/**
	 * Perform MP wallet-to-wallet transfer with retained fees
	 * 
	 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/transfer.php
	 * 
	 * @param int $order_id
	 * @param int $mp_transaction_id
	 * @param int $wp_user_id
	 * @param int $vendor_id
	 * @param string $mp_amount		| money amount
	 * @param string $mp_fees		| money amount
	 * @param string $mp_currency
	 * @return object Transfer result
	 * 
	 */
	public function wallet_trans( $order_id, $mp_transaction_id, $wp_user_id, $vendor_id, $mp_amount, $mp_fees, $mp_currency ) {

		$mp_user_id		= $this->set_mp_user( $wp_user_id );
		$mp_vendor_id	= $this->set_mp_user( $vendor_id );

		/** Get the user wallet that was used for the transaction **/
		$transaction = $this->mangoPayApi->PayIns->Get( $mp_transaction_id );
		$mp_user_wallet_id = $transaction->CreditedWalletId;
		
		/** Get the vendor wallet **/
		$wallets 		= $this->set_mp_wallet( $mp_vendor_id );
		
		/** Take first wallet with right currency **/
		foreach( $wallets as $wallet )
			if( $wallet->Currency == $mp_currency )
			$mp_vendor_wallet_id = $wallet->Id;
		
		$Transfer							= new \MangoPay\Transfer();
		$Transfer->AuthorId					= $mp_user_id;
		$Transfer->DebitedFunds				= new \MangoPay\Money();
		$Transfer->DebitedFunds->Currency	= $mp_currency;
		$Transfer->DebitedFunds->Amount		= ($mp_amount * 100);
		$Transfer->Fees						= new \MangoPay\Money();
		$Transfer->Fees->Currency			= $mp_currency;
		$Transfer->Fees->Amount				= ($mp_fees * 100);
		$Transfer->DebitedWalletID			= $mp_user_wallet_id;
		$Transfer->CreditedWalletId			= $mp_vendor_wallet_id;
		$Transfer->Tag						= 'WC Order #' . $order_id . ' - ValidatedBy:' . wp_get_current_user()->user_login;
		
		$result = $this->mangoPayApi->Transfers->Create($Transfer);
		return $result;
	}
	
	/**
	 * Get a list of failed payout transactions
	 * For display in the dedicated admin dashboard widget
	 * 
	 * @see: https://gist.github.com/hobailey/ae06c3ef51c1245132a7
	 * 
	 */
	public function get_failed_payouts() {

		$pagination = new \MangoPay\Pagination(1, 100);
		
		$filter = new \MangoPay\FilterEvents();
		$filter->EventType = \MangoPay\EventType::PayoutNormalFailed;
		
		$sorting = new \MangoPay\Sorting();
		$sorting->AddField("Date", \MangoPay\SortDirection::DESC);
		
		try{
			$failed_payouts = $this->mangoPayApi->Events->GetAll( $pagination, $filter, $sorting );
		} catch (Exception $e) {
			$failed_payouts = array();
		}
			
		/** get refused kyc docs **/
		$pagination = new \MangoPay\Pagination(1, 100);
		
		$filter = new \MangoPay\FilterEvents();
		$filter->EventType = \MangoPay\EventType::KycFailed;
		
		$sorting = new \MangoPay\Sorting();
		$sorting->AddField("Date", \MangoPay\SortDirection::DESC);
		
		try{
			$refused_kycs = $this->mangoPayApi->Events->GetAll( $pagination, $filter, $sorting );
		} catch (Exception $e) {
			$refused_kycs = array();
		}
		
		return array(
			'failed_payouts'	=> $failed_payouts,
			'refused_kycs'		=> $refused_kycs
		);
	}
	
	/**
	 * To check if the MP API is running in production or sandbox environment
	 * 
	 * @return boolean
	 */
	public function is_production() {
		return $this->mp_production;
	} 
	
	/**
	 * Get temporary folder path
	 * 
	 */
	public function get_tmp_dir() {
		if( !$this->mp_loaded )
			return $this->set_tmp_dir();
			
		return $this->mangoPayApi->Config->TemporaryFolder;
	}
	
	/**
	 * Get payin info (to confirm payment executed)
	 * 
	 * @param int $transaction_id
	 * 
	 */
	public function get_payin( $transaction_id ) {
		return $this->mangoPayApi->PayIns->Get( $transaction_id );
	}
	
	/**
	 * Get the URL to access a User's MP dashboard page
	 * 
	 * @param int $mp_user_id
	 * @return string URL
	 * 
	 */
	public function getDBUserUrl( $mp_user_id ) {
		return $this->mp_db_url . '/Users/' . $mp_user_id;
	}
	
	/**
	 * Get the URL to access a Wallet's MP Payout Operation page
	 *
	 * @param int $mp_wallet_id
	 * @return string URL
	 *
	 */
	public function getDBPayoutUrl( $mp_wallet_id ) {
		return $this->mp_db_url . '/Operations/PayOut?walletId=' . $mp_wallet_id;
	}
	
	/**
	 * Get the URL to upload a KYC Document for that user
	 * 
	 * @param string $mp_user_id
	 * @return string URL
	 */
	public function getDBUploadKYCUrl( $mp_user_id ) {
		return $this->mp_db_url . '/Operations/UploadKycDocument?userId=' . $mp_user_id;
	}
	
	/**
	 * Get the URL of the webhooks dashboard
	 *
	 * @return string URL
	 */
	public function getDBWebhooksUrl() {
		return $this->mp_db_url . '/Notifications';
	}
	
	/**
	 * Gets the profile type of an existing MP user account
	 * 
	 * @param int $mp_user_id
	 * @return string|boolean
	 */
	private function getDBUserPType( $mp_user_id ) {
		try{
			$mangoUser = $this->mangoPayApi->Users->Get( $mp_user_id );
			
		}  catch (Exception $e) {
			$error_message = $e->getMessage();
			
			error_log(
				current_time( 'Y-m-d H:i:s', 0 ) . ': ' . $error_message . "\n\n",
				3,
				$this->logFilePath
			);
			
			echo '<div class="error"><p>' . __( 'Error:', 'mangopay' ) . ' ' .
				__( 'MANGOPAY API returned:', 'mangopay' ) . ' ';
			echo '&laquo;' . $error_message . '&raquo;</p></div>';
		}
		if( isset( $mangoUser ) && $mangoUser ) {
			//var_dump( $mangoUser->PersonType );	//Debug
			return $mangoUser->PersonType;
		} else {
			return false;
		}
	}
	
	/**
	 * Will create BUSINESS type account for Customers that become Vendors
	 * 
	 * NOT USED
	 * 
	 * @param int $wp_user_id	| WP user ID
	 * @param string $p_type	| MP profile type
	 */
	private function switchDBUserPType( $wp_user_id, $p_type ) {
		
		/** 
		 * We only switch accounts when a Customer becomes a Vendor,
		 * ie vendors that become customers keep their existing vendor account
		 * 
		 */
		if( 'BUSINESS' != $p_type ) 
			return;
			
		/** We will creata a new MP BUSINESS account for that user **/
		
		/** We store a different mp_user_id for production and sandbox environments **/
		$umeta_key = 'mp_user_id';
		if( !$this->mp_production )
			$umeta_key .= '_sandbox';
		delete_user_meta( $wp_user_id, $umeta_key );
		$this->set_mp_user( $wp_user_id, 'BUSINESS' );
	}
	
	/**
	 * MP payout transaction (for vendors)
	 * @see: https://github.com/Mangopay/mangopay2-php-sdk/blob/master/demos/workflow/scripts/payout.php
	 * 
	 * @param int $wp_user_id
	 * @param int $mp_account_id
	 * @param int $order_id
	 * @param string $currency
	 * @param float $amount
	 * @param float $fees
	 * @return boolean
	 * 
	 */
	public function payout(  $wp_user_id, $mp_account_id, $order_id, $currency, $amount, $fees ){
		
		/** The vendor **/
		$mp_vendor_id	= $this->set_mp_user( $wp_user_id );
		
		/** Get the vendor wallet **/
		$wallets 		= $this->set_mp_wallet( $mp_vendor_id );
		
		/** Take first wallet with right currency **/
		foreach( $wallets as $wallet )
			if( $wallet->Currency == $currency )
			$mp_vendor_wallet_id = $wallet->Id;
		
		if( !$mp_vendor_wallet_id )
			return false;
		
		$PayOut = new \MangoPay\PayOut();
		$PayOut->AuthorId								= $mp_vendor_id;
		$PayOut->DebitedWalletID						= $mp_vendor_wallet_id;
		$PayOut->DebitedFunds							= new \MangoPay\Money();
		$PayOut->DebitedFunds->Currency					= $currency;
		$PayOut->DebitedFunds->Amount					= $amount * 100;
		$PayOut->Fees									= new \MangoPay\Money();
		$PayOut->Fees->Currency							= $currency;
		$PayOut->Fees->Amount							= $fees * 100;
		$PayOut->PaymentType							= "BANK_WIRE";
		$PayOut->MeanOfPaymentDetails					= new \MangoPay\PayOutPaymentDetailsBankWire();
		$PayOut->MeanOfPaymentDetails->BankAccountId	= $mp_account_id;
		$PayOut->Tag									= 'Commission for WC Order #' . $order_id . ' - ValidatedBy:' . wp_get_current_user()->user_login;
		
		//var_dump( $PayOut );	//Debug
		
		$result = $this->mangoPayApi->PayOuts->Create($PayOut);
		
		return $result;
	}
	
	/**
	 * Retrieve info about an existing (past) payout
	 * 
	 * @param int $payOutId
	 * @return object \MangoPay\PayOut
	 */
	public function get_payout( $payOutId ) {
		return $this->mangoPayApi->PayOuts->Get( $payOutId );
	}
	
	/**
	 * Retrieve info about an existing KYV document
	 *
	 * @param int $kycDocumentId
	 * @return object \MangoPay\KycDocument
	 */
	public function get_kyc( $kycDocumentId ) {
		return $this->mangoPayApi->KycDocuments->Get( $kycDocumentId );
	}
	
	/**
	 * Returns plugin's log file path
	 * 
	 */
	public function get_logfilepath() {
		return apply_filters( 'mangopay_logfilepath', $this->logFilePath );
	}
	
	/**
	 * Returns the webhook for successful payins
	 * 
	 * NOT USED
	public function get_successful_payin_hook() {
		return $this->get_webhook_by_type( self::PAYIN_SUCCESS_HK );
	}
	/** **/
	
	/**
	 * Get a webhook registered in the MP API by its type.
	 * Return false if not present.
	 * 
	 */
	public function get_webhook_by_type( $webhook_type ) {
		$pagination = new \MangoPay\Pagination(1, 100);//get the first page with 100 elements per page
		try{
			$list = $this->mangoPayApi->Hooks->GetAll( $pagination );
		} catch (Exception $e) {
			return false;
		}
		foreach($list as $hook)
			if( $hook->EventType == $webhook_type )
				return $hook;	// We don't care about the rest of the list
		
		return false;
	}
	
	/**
	 * Check that a MANGOPAY incoming webhook is enabled & valid
	 * 
	 * @param object $hook - MANGOPAY Hook object
	 * @return boolean
	 */
	public function hook_is_valid( $hook ) {
		if( $hook->Status != 'ENABLED' )
			return false;
		
		if( $hook->Validity != 'VALID' )
			return false;
		
		return true;
	}
	
	/**
	 * Register all necessary webhooks
	 * 
	 * NOT USED
	public function create_all_webhooks( $webhook_prefix, $webhook_key ) {
		$r1 = $this->create_webhook( $webhook_prefix, $webhook_key, self::PAYIN_SUCCESS_HK );
		$r2 = $this->create_webhook( $webhook_prefix, $webhook_key, self::PAYIN_FAILED_HK );
		return $r1 && $r2;
	}
	*/
	
	/**
	 * Registers a new webhook with the MANGOPAY API
	 * creates the webhook and returns its Id if successful, false otherwise
	 * 
	 */
	public function create_webhook( $webhook_prefix, $webhook_key, $event_type ) {
		
		$inboundPayinWPUrl = site_url( $webhook_prefix . '/' . $webhook_key . '/' . $event_type );
		$hook = new \MangoPay\Hook();
		$hook->Url			= $inboundPayinWPUrl;
		$hook->Status		= 'ENABLED';
		$hook->Validity		= 'VALID';
		$hook->EventType	= $event_type;
		try{
			$result = $this->mangoPayApi->Hooks->Create( $hook );
		} catch (Exception $e) {
			return false;
		}	
		
		if( $result->Id )
			return $result->Id;
		
		return false;
	}
	
	/**
	 * Updates an existing webhook of the MANGOPAY API
	 * returns its Id if successful, false otherwise
	 *
	 */
	public function update_webhook( $existing_hook, $webhook_prefix, $webhook_key, $event_type ) {
		$inboundPayinWPUrl = site_url( $webhook_prefix . '/' . $webhook_key . '/' . $event_type );
		$hook = new \MangoPay\Hook();
		$hook->Url			= $inboundPayinWPUrl;
		$hook->Status		= 'ENABLED';
		$hook->Validity		= 'VALID';
		$hook->EventType	= $event_type;
		$hook->Id			= $existing_hook->Id;
		try{
			$result = $this->mangoPayApi->Hooks->Update( $hook );
		} catch (Exception $e) {
			return false;
		}
		
		if( $result->Id )
			return $result->Id;
		
		return false;
	}
	
	/**
	 * Check that a webhook of the specified type is registered
	 * 
	 * @param string $event_type
	 * @return boolean
	 */
	private function check_webhook( $webhook_key, $event_type ) {
		if( $hook = $this->get_webhook_by_type( $event_type ) ) {
			if(
				!empty($webhook_key) &&
				$this->hook_is_valid( $hook )
			) {
				$inboundPayinWPUrl = site_url(
					mangopayWCWebHooks::WEBHOOK_PREFIX . '/' .
					$webhook_key . '/' .
					$event_type
				);
				if( $inboundPayinWPUrl == $hook->Url )
					return true;
			}
		}
		return false;
	}
}
?>