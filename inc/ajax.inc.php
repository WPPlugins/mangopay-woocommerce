<?php
/**
 * Ajax methods for MANGOPAY WooCommerce Plugin admin
 * 
 */
class mangopayWCAjax {
	
	/** This will store our mpAccess class instance **/
	private $mp;

	/** Ignored items **/
	private $ignored_failed_po		= array();
	private $ignored_refused_kyc	= array();
	
	/**
	 * Class constructor
	 *
	 */
	public function __construct() {
		
		/** Get the stored hidden/ignored items **/
		$this->ignored_failed_po	= get_option( 'mp_ignored_failed_po', array() );
		$this->ignored_refused_kyc	= get_option( 'mp_ignored_refused_kyc', array() );
		
		/** Admin ajax for failed payouts and KYCs dashboard widget **/
		add_action( 'wp_ajax_ignore_mp_failed_po', array( $this, 'ajax_ignore_mp_failed_po' ) );
		//add_action( 'wp_ajax_retry_mp_failed_po', array( $this, 'ajax_retry_mp_failed_po' ) );
		add_action( 'wp_ajax_ignore_mp_refused_kyc', array( $this, 'ajax_ignore_mp_refused_kyc' ) );
	}

	/**
	 * Stores a failed payout resource ID as ignored
	 * 
	 */
	public function ajax_ignore_mp_failed_po() {
		if ( !current_user_can( 'manage_options' ) )
			return;
		
		$this->ajax_head();
		$response = null;
		$ressource_id = null;
		
		if( !empty( $_POST['id'] ) )
			$ressource_id = $_POST['id'];
		
		if( $ressource_id && !in_array( $ressource_id, $this->ignored_failed_po ) ) {
			$this->ignored_failed_po[] = $ressource_id;
			$response = update_option( 'mp_ignored_failed_po', $this->ignored_failed_po );
		}
		
		echo json_encode( $response );
		exit;
	}
	
	/* NOT USED *
	public function ajax_retry_mp_failed_po() {
		if ( !current_user_can( 'manage_options' ) )
			return;
		
		$this->ajax_head();
		$response = null;
		$ressource_id = null;
		$order_id = null;
		
		if( !empty( $_POST['id'] ) )
			$ressource_id = $_POST['id'];
		
		if( $ressource_id ) {
			$this->mp = mpAccess::getInstance();
			
			if( !$payout = get_transient( 'mp_failed_po_' . $ressource_id ) ) {
				$payout = $this->mp->get_payout( $ressource_id );
				if( $payout && is_object( $payout) )
					set_transient( 'mp_failed_po_' . $ressource_id, $payout, 60*60*24 );
			}
			
			if( preg_match( '/WC Order #(\d+)/', $payout->Tag, $matches ) ) {
				$order_id = $matches[1];
				$order = new WC_Order( $order_id );
				$wp_user_id 	= $order->customer_user;
			}
			
			echo 'order_id: ' . $order_id . '<br/>';		//Debug
			echo 'wp_user_id: ' . $wp_user_id . '<br/>';	//Debug
			var_dump( $payout );							//Debug
			
			$PayOut = new \MangoPay\PayOut();
			$PayOut->AuthorId								= $payout->AuthorId;
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
			
			
			var_dump( $response );	//Debug
		}
		
		echo json_encode( $response );
		exit;
	}
	*/
	
	/**
	 * Stores a refused KYC doc resource ID as ignored
	 *
	 */
	public function ajax_ignore_mp_refused_kyc() {
		if ( !current_user_can( 'manage_options' ) )
			return;
		
		$this->ajax_head();
		$response = null;
		$ressource_id = null;
		
		if( !empty( $_POST['id'] ) )
			$ressource_id = $_POST['id'];
		
		if( $ressource_id && !in_array( $ressource_id, $this->ignored_refused_kyc ) ) {
			$this->ignored_refused_kyc[] = $ressource_id;
			$response = update_option( 'mp_ignored_refused_kyc', $this->ignored_refused_kyc );
		}
		
		echo json_encode( $response );
		exit;
	}
	
	private function ajax_head() {
		session_write_close();
		header( "Content-Type: application/json" );
	}
}
new mangopayWCAjax();
?>