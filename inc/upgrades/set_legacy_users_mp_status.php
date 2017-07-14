<?php
/**
 * Legacy user upgrade procedure
 * for plugin version < 0.3.0
 * give all legacy users a user_mp_status,
 * either 'individual' for all buyers
 * or 'business' for all vendors
 * (those were the plugin defaults when creating MP user accounts before ver 0.3.0)
 * 
 */

//echo '<h1>DOING UPGRADE</h1>';					//Debug


//echo '<h2>Production</h2>';						//Debug

//echo '<h3>Vendors</h3><ul>';						//Debug
$vendors = get_users( array(
		'role'		=> 'vendor',
		'meta_key'	=> 'mp_user_id',
) );
foreach( $vendors as $vendor ) {
	add_user_meta( $vendor->ID, 'user_mp_status', 'business', true );
	add_user_meta( $vendor->ID, 'user_business_type', 'business', true );
	//echo '<li>' . $vendor->user_email . '</li>';	//Debug
}
//echo '</ul>';										//Debug

//echo '<h3>Buyers</h3><ul>';						//Debug
$buyers = get_users( array(
		'meta_key'	=> 'mp_user_id',
) );
foreach( $buyers as $buyer ) {
	add_user_meta( $buyer->ID, 'user_mp_status', 'individual', true );
	add_user_meta( $buyer->ID, 'user_business_type', '', true );
	//echo '<li>' . $buyer->user_email . '</li>';	//Debug
}
//echo '</ul>';										//Debug


//echo '<h2>Sandbox</h2>';							//Debug

//echo '<h3>Vendors</h3><ul>';						//Debug
$vendors = get_users( array(
		'role'		=> 'vendor',
		'meta_key'	=> 'mp_user_id_sandbox',
) );
foreach( $vendors as $vendor ) {
	add_user_meta( $vendor->ID, 'user_mp_status', 'business', true );
	add_user_meta( $vendor->ID, 'user_business_type', 'business', true );
	//echo '<li>' . $vendor->user_email . '</li>';	//Debug
}
//echo '</ul>';										//Debug

//echo '<h3>Buyers</h3><ul>';						//Debug 
$buyers = get_users( array(
	'meta_key'	=> 'mp_user_id_sandbox',	
) );
foreach( $buyers as $buyer ) {
	add_user_meta( $buyer->ID, 'user_mp_status', 'individual', true );
	add_user_meta( $buyer->ID, 'user_business_type', '', true );
	//echo '<li>' . $buyer->user_email . '</li>';	//Debug	
}
//echo '</ul>';										//Debug


//exit;												//Debug
?>