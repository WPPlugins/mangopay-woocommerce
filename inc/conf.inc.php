<?php
/**
 * MANGOPAY WooCommerce plugin configuration class
 * 
 * @author yann@abc.fr
 * @see: https://github.com/Mangopay/wordpress-plugin
 *
 **/
class mangopayWCConfig {
	
	/** Class constants **/
	const DEBUG 			= false;							// Turns debugging messages on or off (should be false for production)
	const WC_PLUGIN_PATH	= 'woocommerce/woocommerce.php';
	const WV_PLUGIN_PATH	= 'wc-vendors/class-wc-vendors.php';
	const WV_TABLE_NAME		= 'pv_commission';					// Name of the custom table created by the WV plugin
	const WV_OPTION_KEY		= 'wc_prd_vendor_options';			// Name of the option key where WV options are stored
	const KEY_FILE_NAME		= 'secret.key.php';					// Need to make it a PHP file in order to prevent it from being downloaded
	const OPTION_KEY		= 'mangopay_settings';				// Where our options are stored in the wp_options table
	
	/** Signup URLs (displayed on the setup admin screen) **/
	const SANDBOX_SIGNUP	= 'https://www.mangopay.com/signup/create-sandbox/';
	const PROD_SIGNUP		= 'https://www.mangopay.com/signup/submit-your-app/go-live/';
	
	/** Default plugin options (the ones that will be stored in mangopayWCConfig::OPTION_KEY) **/
	public static $defaults = array(
			'prod_or_sandbox'			=> 'sandbox',
			'sand_client_id'			=> '',
			'sand_passphrase'			=> '',
			'prod_client_id'			=> '',
			'prod_passphrase'			=> '',
			'default_buyer_status'		=> 'individuals',
			'default_vendor_status' 	=> 'either',
			'default_business_status'	=> 'either',
			'per_item_wf'				=> '',
			'webhook_key'				=> '',
			'plugin_version'			=> '0.2.2'
	);
	
	/** Supported currencies **/
	public static $allowed_currencies = array(
			'EUR', 'GBP', 'USD', 'CHF', 'NOK', 'PLN', 'SEK', 'DKK', 'CAD', 'ZAR'
	);
	
	/**
	 * Bank accounts required fields configuration
	 * @see: https://docs.mangopay.com/api-references/bank-accounts/
	 *
	 * i18n _e() is applied when displaying the labels
	 *
	 */
	public static $account_types = array(
			'IBAN'		=> array(
					'vendor_iban'				=> array(
							'label'			=> 'IBAN',	// _e() will be applied to all labels
							'required'		=> 1,
							'format'		=> 'text:27',
							'placeholder'	=> '____ ____ ____ ____ ____',
							'redact'		=> '4,4',
							'validate'		=> '^[a-zA-Z]{2}\d{2}\s*(\w{4}\s*){2,7}\w{1,4}\s*$',
							'mp_property'	=> 'IBAN'
					),
					'vendor_bic'				=> array(
							'label'			=> 'BIC',
							'required'		=> 1,
							'format'		=> 'text:11',
							'placeholder'	=> '___________',
							'redact'		=> '0,2',
							'validate'		=> '^[a-zA-Z]{6}\w{2}(\w{3})?$',
							'mp_property'	=> 'BIC'
					)
			),
			'GB'		=> array(
					'vendor_gb_accountnumber'	=> array(
							'label'			=> 'Account Number',
							'required'		=> 1,
							'format'		=> 'number:*',
							'placeholder'	=> '',
							'redact'		=> '0,2',
							'validate'		=> '^\d{8}$',
							'mp_property'	=> 'AccountNumber'
									),
									'vendor_gb_sortcode'		=> array(
									'label'			=> 'Sort Code',
									'required'		=> 1,
									'format'		=> 'number:6',
									'placeholder'	=> '______',
									'redact'		=> '0,2',
									'validate'		=> '^\d{6}$',
									'mp_property'	=> 'SortCode'
											)
			),
			'US'		=> array(
			'vendor_us_accountnumber'	=> array(
			'label'			=> 'Account Number',
			'required'		=> 1,
			'format'		=> 'number:*',
			'placeholder'	=> '',
			'redact'		=> '0,2',
			'validate'		=> '^\d+$',
			'mp_property'	=> 'AccountNumber'
					),
					'vendor_us_aba'				=> array(
					'label'			=> 'ABA',
					'required'		=> 1,
					'format'		=> 'number:9',
					'placeholder'	=> '_________',
					'redact'		=> '0,2',
					'validate'		=> '^\d{9}$',
					'mp_property'	=> 'ABA'
							),
							'vendor_us_datype'			=> array(
							'label'			=> 'Deposit Account Type',
							'required'		=> 1,
							'format'		=> 'select:CHECKING,SAVINGS',
							'placeholder'	=> '',
							'redact'		=> '',
							'validate'		=> 'CHECKING|SAVINGS',
							'mp_property'	=> 'DepositAccountType'
									)
			),
			'CA'		=> array(
			'vendor_ca_bankname'		=> array(
			'label'			=> 'Bank Name',
			'required'		=> 1,
			'format'		=> 'text:50',
			'placeholder'	=> '',
			'redact'		=> '',
			'validate'		=> '^[\w\s]{1,50}$',
			'mp_property'	=> 'BankName'
					),
					'vendor_ca_instnumber'		=> array(
					'label'			=> 'Institution Number',
					'required'		=> 1,
					'format'		=> 'number:4',
					'placeholder'	=> '____',
					'redact'		=> '0,2',
					'validate'		=> '\d{3,4}',
					'mp_property'	=> 'InstitutionNumber'
							),
							'vendor_ca_branchcode'		=> array(
							'label'			=> 'Branch Code',
							'required'		=> 1,
							'format'		=> 'number:5',
							'placeholder'	=> '_____',
							'redact'		=> '0,2',
							'validate'		=> '^\d{5}$',
							'mp_property'	=> 'BranchCode'
									),
									'vendor_ca_accountnumber'	=> array(
									'label'			=> 'Account Number',
									'required'		=> 1,
									'format'		=> 'number:20',
									'placeholder'	=> '____ ____ ____ ____ ____',
									'redact'		=> '0,2',
									'validate'		=> '^\d{1,20}$',
									'mp_property'	=> 'AccountNumber'
											)
			),
			'OTHER'		=> array(
			'vendor_ot_country'			=> array(
			'label'			=> 'Country',
			'required'		=> 1,
			'format'		=> 'country:*',
			'placeholder'	=> '',
			'redact'		=> '',
			'validate'		=> '^[A-Z]{2}$',
			'mp_property'	=> 'Country'
					),
					'vendor_ot_bic'				=> array(
					'label'			=> 'BIC',
					'required'		=> 1,
					'format'		=> 'text:11',
					'placeholder'	=> '___________',
					'redact'		=> '0,2',
					'validate'		=> '.+',
					'mp_property'	=> 'BIC'
							),
							'vendor_ot_accountnumber'	=> array(
							'label'			=> 'Account Number',
							'required'		=> 1,
							'format'		=> 'text:*',
							'placeholder'	=> '',
							'redact'		=> '0,2',
							'validate'		=> '.+',
							'mp_property'	=> 'AccountNumber'
									)
			)
	);
}
?>