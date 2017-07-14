<?php
/**
 * MANGOPAY WooCommerce plugin admin methods class
 * This class handles user profile field validations
 * 
 * The validation functions are called from:
 * admin.inc.php in the user_edit_checks() function
 * main.inc.php in the wooc_validate_extra_register_fields_user() function
 * main.inc.php in the wooc_validate_extra_register_fields_userfront() function
 * main.inc.php in the wooc_validate_extra_register_fields() function
 * main.inc.php in the wooc_validate_extra_register_fields_checkout() function
 *
 * @author yann@abc.fr, Silver
 * @see: https://github.com/Mangopay/wordpress-plugin
 *
 **/
class mangopayWCValidation{
	
	private $mangopayWCMain;		// The mangopayWCMain object that instanciated us
	
	/** Field attributes **/
	private $_list_post_keys = array(
		'billing_first_name'	=> array( 'type' => 'single',		'text' => 'First name'),
		'billing_last_name'		=> array( 'type' => 'single',		'text' => 'Last name'),
		'user_birthday'			=> array( 'type' => 'date',			'text' => 'Birthday'),
		'user_nationality'		=> array( 'type' => 'country',		'text' => 'Nationality'),
		'billing_country'		=> array( 'type' => 'country',		'text' => 'Country'),
		'user_mp_status'		=> array( 'type' => 'status',		'text' => 'User status'),
		'user_business_type'	=> array( 'type' => 'businesstype',	'text' => 'Business type')
	);
  
	/**
	 * Class constructor
	 *
	 */
	public function __construct( $mangopayWCMain=NULL ) {
		$this->mangopayWCMain		= $mangopayWCMain;
	}
	
	/**
	 * Validate single style information
	 * @param array $data - field data
	 */
	public function validate_single( &$data ){
		$value	= $data['data_post'][$data['key_field']];
        if(isset($this->_list_post_keys[$data['key_field']])):
            $info	= $this->_list_post_keys[$data['key_field']];

            if ( isset( $value ) && empty( $value ) ) :
                $data['message'][] = __( $info['text'], 'mangopay' );
                $data['message'][] = __( 'is required!', 'mangopay' );
                $this->send_message_format($data);
            endif;
        endif;
	}	// function validate_single()
  
	/**
	 * Validate date-style information
	 * (birth date)
	 * 
	 * @param array $data - field data
	 */
	public function validate_date( &$data ){
    
		$isset = false;
		$value = false;
		if(isset($data['data_post'][$data['key_field']])) {
			$value = $data['data_post'][$data['key_field']];
			$isset = true;
		}
		$info = $this->_list_post_keys[$data['key_field']];
    
		/** If value exists but is empty **/
		if ( $isset && empty( $value ) ) {
      
			/** To avoid double test error we use that one in a specific case **/
			if(
				isset($data['double_test']['user_birthday']) &&
				1 == $data['double_test']['user_birthday']
			) {
				$data['message'][] = __( $info['text'], 'mangopay' );
				$data['message'][] = __( 'is required!', 'mangopay' );
				$this->send_message_format($data);
			}

		/** If date value exists but format is wrong **/  
		} elseif(
			$isset && 
			!$this->validate_date_format( $this->convert_date( $value ) )
		) {
		            
			$data['message'][] = __( 'Invalid '.$info['text'].' date.', 'mangopay' );
			$data['message'][] = __( 'Please use this format: ', 'mangopay' );
			$data['message'][] = $this->supported_format( get_option( 'date_format' ) );
			$this->send_message_format($data);
		      
		/** If birth date value exists verify that it is in the past **/  
		} elseif ( $isset ) {
		    
			$input_date = strtotime( $this->convert_date( $value ));
			$today = strtotime( date( 'Y-m-d' ) );
			
			/** Test if date is in the future **/
			if( $input_date >= $today ) {
		        
				$data['message'][] = __( 'Invalid Birthday date.', 'mangopay' );
				$this->send_message_format($data);
		        
			}
		}
	} // function validate_date()
  
  	/**
  	 * Country field validation method
  	 * Verifies if country field is set
  	 * Verifies that the country is a valid/known country for WC
  	 * For specific countries, verify that mandatory state field is also present
  	 * 
  	 * @param array $data - field data
  	 * 
  	 */
	public function validate_country( &$data ){
		
		$isset = false;
		$value = false;
		if( isset( $data['data_post'][$data['key_field']] ) ) {
			$value = $data['data_post'][$data['key_field']];
			$isset = true;
		}
		$info = $this->_list_post_keys[$data['key_field']];
        
		/** If value exists but is empty **/
		if( $isset && empty( $value ) ) {

			$data['message'][] = __( $info['text'], 'mangopay' );
			$data['message'][] = __( 'is required!', 'mangopay' );
			$this->send_message_format($data);
      
		/** If value exists check if country is valid **/  
		} elseif ( $isset ) {
			
			$countries_obj = new WC_Countries();
			$countries = $countries_obj->__get('countries');
      
			/** Check if country is known/valid for WC **/
			if( !isset( $countries[$value] ) ) {
				$data['message'][] = __( 'Unknown country for Nationality', 'mangopay' );
				$this->send_message_format($data);
			}

			/** If one of those countries is selected verify that state is not empty **/
			if(
				isset( $value ) && (
					'MX' == $value || 
					'CA' == $value || 
					'US' == $value
				)
			) {
				if( empty( $data['data_post']['billing_state'] ) ) {
					$data['message'][] = __( "State", 'mangopay' );
					$data['message'][] = __( 'is required!', 'mangopay' );
					//$data['message'][] = $data['caller_func'];	// Debug
					$this->send_message_format( $data );
				}
			}
		}
	}	// function validate_country($data)

	/**
	 * User status validation method
	 * 
	 * @param array $data - field data
	 */
	public function validate_status( &$data ){
		
		/** 
		 * Possibility - 1, the conf:
		 * default_buyer_status is either set as 
		 * 	"individuals" OR 
		 * 	"business" 
		 * -> no need to test
    	 * -> get out!
    	 */
        
		/**
		 * Possibility - 2, the conf: 
		 * default_buyer_status is set as 
		 * "either" -> add the field "user mp status"
		 */
		if(
			isset( $data['main_options']['default_buyer_status'] ) &&	
			'either' == $data['main_options']['default_buyer_status']
		):
      
            //if we need to test
            //1 if is NOT set and user has already a value, OK
            //2 if is NOT set and user has NOT value -> error
            //3 if set but empty -> error
            //4 if set and full ->
            //4.1 check if it's in a set of values 
      
            //get the data
            $isset = false;
            $value = false;
            if(isset($data['data_post']['user_mp_status'])):
              $value = $data['data_post']['user_mp_status'];
              $isset = true;
            endif;

            //2 if is NOT set and user has NOT value -> error
            if(!$isset && !get_user_meta( get_current_user_id(), 'user_mp_status', true )):
              //error
              $data['message'][] = __( 'User status', 'mangopay' );
              $data['message'][] = __( 'is required!', 'mangopay' );
              $this->send_message_format($data);
              return;
            endif;

            //3 if set but empty -> error
            if($isset && empty($value)):
              //error
              $data['message'][] = __( 'User status', 'mangopay' );
              $data['message'][] = __( 'is required!', 'mangopay' );
              $this->send_message_format($data);
              return;
            endif;


            //4 check if it's in a set of values 
            if($isset && !empty($value)):
              if($value != 'business' && $value != 'individual' && $value != 'either'):
                $data['message'][] = __( 'Unknown user status type', 'mangopay' );
                $this->send_message_format($data);
                return;
              endif;
            endif;

        endif;
    
  }
  
  public function validate_businesstype( &$data ){
    //init
    $business = false;
        
    //test if user is already business or he is registering as business
    if( (isset($data['data_post']['user_mp_status']) && $data['data_post']['user_mp_status'] == "business") ):
      $business = "business";
    endif;
    
    //Get values
    $isset_business = false;
    $value_business_type = false;
    if(isset($data['data_post']['user_business_type'])):
      $value_business_type = $data['data_post']['user_business_type'];
      $isset_business = true;
    endif;
    
    //start test values
    if($business == "business"):
            
      //IF it's set AND value is EMPTY --------------------
      if ( $isset_business && empty( $value_business_type ) ) :
        $data['message'][] = __( 'Business type', 'mangopay' );
        $data['message'][] = __( 'is required!', 'mangopay' );
        $this->send_message_format($data);
        return;
      endif;///if it's set and value is empty
      
      //If isset test the differents types ----------------
      if ( $isset_business ):
        //IF -TYPE- is wrong business type
        if(	
          'organisation' != $value_business_type &&
          'business' != $value_business_type &&
          'soletrader' != $value_business_type &&
          '' != $value_business_type
        ):

          $data['message'][] = __( 'Unknown business type', 'mangopay' );
          $this->send_message_format($data);
          return;
        endif;// test organisations types
       endif;//test if isset
      
    endif;//end if is business
  }
  
//      
//    /*
//     *     if('either' == $this->options['default_buyer_status']):
//      if(!get_user_meta( get_current_user_id(), 'user_mp_status', true )):
//         $fields = $this->add_usermpstatus_field($fields);
//      endif;
//    endif;
//      
//    //possibility - 3, the conf : default_buyer_status is on "business" or either -> add the field "business type"
//    //this field will be hidden by javascript, it's dependent of "user mp status" field
//    if('businesses' == $this->options['default_buyer_status'] || 'either' == $this->options['default_buyer_status']):
//      //and user does not have it
//      if(!get_user_meta( get_current_user_id(), 'user_business_type', true )):
//        $fields = $this->add_userbusinesstype_field($fields);
//      endif;
//    endif;
//     */
//    
//    
//    //test status kind on configuration
//    if(
//			isset( $data['main_options']['default_buyer_status'] ) &&
//			'businesses' == $data['main_options']['default_buyer_status'] &&
//        
//			isset( $data['main_options']['default_vendor_status'] ) &&
//			'businesses' == $data['main_options']['default_vendor_status'] &&
//        
//			isset( $data['main_options']['default_business_type'] ) && 
//			'either' == $data['main_options']['default_business_type']
//		) :
//      //STATUS IS BUSINESS
//      $data['data_post']['user_mp_status'] = "business";
//    endif;
//    
//        
//    //get the data ----------------------------
//    $isset = false;
//    $value = false;
//    if(isset($data['data_post']['user_mp_status'])):
//      $value = $data['data_post']['user_mp_status'];
//      $isset = true;
//    endif;
//    
//    $isset_business = false;
//    $value_business_type = false;
//    if(isset($data['data_post']['user_business_type'])):
//      $value_business_type = $data['data_post']['user_business_type'];
//      $isset_business = true;
//    endif;
//    
//    $info = $this->_list_post_keys['user_mp_status'];
//    //end get the data ----------------------------
//            
//    
//    //possibility - 1, the conf : default_buyer_status is on "individuals" -> no need to test
//    //get out!
//    
//    
//    //IF STATUS IS ASKED BUT IS EMPTY ///////////////////////////////////////////////
//		if ( $isset && empty( $value ) ) :
//      
//      $data['message'][] = __( $info['text'], 'mangopay' );
//      $data['message'][] = __( 'is required!', 'mangopay' );
//      $this->send_message_format($data);
//      
//   //IF STATUS IS ASKED AND NOT EMPTY ///////////////////////////////////////////////
//	 elseif ( $isset ):
//      
//     //IF THERE NO CHOISE MADE (only 2 possible)
//			if( 'individual' != $value && 'business' != $value ):
//        
//        $data['message'][] = __( 'Unknown user status', 'mangopay' );
//        $this->send_message_format($data);
//
//      endif;//IF THERE NO CHOISE MADE (only 2 possible)
//      
//		endif;//END IF test value exist and is selected
//		
//		//IF ITS A BUSINESS AND -TYPE- IS EMPTY
//    //---------------- NB 'user_business_type' is fix with user_mp_status -----------------
//		if( $isset  && 	'business' == $value &&	empty($value_business_type)):
//      
//      echo "<pre>", print_r("here 1 ---", 1), "</pre>";
//      echo "<pre>", print_r($isset, 1), "</pre>";
//      echo "<pre>", print_r($value_business_type, 1), "</pre>";
//      
//      $data['key_field'] = 'user_business_type'; //change KEY!
//      $data['message'][] = __( 'Business type', 'mangopay' );user_business_type
//      $data['message'][] = __( 'is required!', 'mangopay' );
//      $this->send_message_format($data);
//
//    //IF -TYPE- IS SELECTED do other tests
//		elseif ( $isset_business ):
//      
//      //IF -TYPE- is wrong business type
//			if(	(
//					'organisation' != $value_business_type &&
//					'business' != $value_business_type &&
//					'soletrader' != $value_business_type &&
//					'' != $value_business_type
//				) ||
//				(
//					'' == $value_business_type &&
//					'business' == $value
//				)
//			):
//        
//      $data['key_field'] = 'user_business_type'; //change KEY!
//      $data['message'][] = __( 'Unknown business type', 'mangopay' );
//      $this->send_message_format($data);
//
//      endif;//IF -TYPE- is wrong business type
//      
//		endif;//IF -TYPE- IS SELECTED do other tests
//    
//  }
  
  /**
   * format and use the good format and messenger to send the message
   * @param array $data
   */
  public function send_message_format($data){
        
    $text = '';
    $strong = 0;
    if(isset($data['place']) && $data['place'] == "admin"): //if not set it's front
      $text.= '<strong>' . __( 'Error:', 'mangopay' ) . '</strong> ';
      $strong = 1;
    endif;
    
    //assemble message
    foreach($data['message'] as $part):
      if($strong == 0):
        $text.= '<strong>' .$part . '</strong> ';
        $strong = 1;
      else:
        $text.= ' '.$part;
      endif;
      
    endforeach;
    
    //if there is the object error send the error
    if(isset($data['wp_error']) && $data['wp_error'] != null && $data['wp_error']):
      $data['wp_error']->add( $data['key_field'].'_error', $text);
    else:
      //else send the notice
      wc_add_notice( $text, 'error' );  
    endif;
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
		if( date( 'Y-m-d' ) == $this->convert_date( date_i18n( get_option( 'date_format' ), time() ), get_option( 'date_format' ) ) )
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
	public function validate_date_format( $date ) {
		
		if( !preg_match( '/^(\d{4,4})\-(\d{2,2})\-(\d{2,2})$/', $date, $matches ) )
			return false;
	
		if( !wp_checkdate( $matches[2], $matches[3], $matches[1], $date ) )
			return false;
	
		return true;
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
  public function convert_date( $date, $format=null ) {

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
}
?>