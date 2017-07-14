<?php
/**
 * @package MANGOPAY-woocommerce
 * @author Yann Dubois, MANGOPAY
 * @version 2.2.0
 * @see: https://github.com/Mangopay/wordpress-plugin
 */

/*
 Plugin Name: MANGOPAY WooCommerce plugin
 Plugin URI: http://www.mangopay.com/
 Description: Official WooCommerce checkout gateway for the <a href="https://www.mangopay.com/">MANGOPAY</a> payment solution dedicated to marketplaces.
 Version: 2.2.0
 Author: Yann Dubois, MANGOPAY
 Author URI: http://www.yann.com/
 Text Domain: mangopay
 Domain Path: /languages
 License: GPL2
 */

/**
 * @copyright 2016  Yann Dubois & Silver for MANGOPAY ( email : yann _at_ abc.fr )
 *
 *  Original development of this plugin was kindly funded by MANGOPAY ( https://www.mangopay.com/ )
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 */

/**
 * Revision 2.2.0:
 * - Public stable v2.2 release of 2017/03/07
 * Revision 2.1.0:
 * - Public stable v2.1 release of 2016/12/08
 * Revision 2.0.1:
 * - Bugfix release of 2016/10/04
 * Revision 2.0.0:
 * - Public stable v2 release of 2016/09/14
 * Revision 1.0.3:
 * - Bugfix/compatibility release of 2016/08/24
 * Revision 1.0.2:
 * - Bugfix release of 2016/08/02
 * Revision 1.0.1:
 * - Bugfix release of 2016/07/21
 * Revision 1.0.0:
 * - Public stable v1 release of 2016/06/28
 * Revision 0.4.0:
 * - Public beta v4 release of 2016/05/18
 * Revision 0.3.0:
 * - Public beta v3 release of 2016/04/19
 * Revision 0.2.2:
 * - Bugfix beta release of 2015/04/08
 * Revision 0.2.1:
 * - Public beta release of 2015/03/31
 * Revision 0.1.1:
 * - Alpha release 2 of 2015/03/15
 * Revision 0.1.0:
 * - Original alpha release 00 of 2015/02/26
 */

$version = '2.2.0';

/** Custom classes includes **/
include_once( dirname( __FILE__ ) . '/inc/conf.inc.php' );			// Configuration class
include_once( dirname( __FILE__ ) . '/inc/hooks.inc.php' );			// Action and filter hooks class (will include the payment gateway class when appropriate)
include_once( dirname( __FILE__ ) . '/inc/plugin.inc.php' );		// Plugin maintenance class 
include_once( dirname( __FILE__ ) . '/inc/main.inc.php' );			// Main plugin class 
include_once( dirname( __FILE__ ) . '/inc/validation.inc.php' );	// User profile field validation methods 
include_once( dirname( __FILE__ ) . '/inc/mangopay.inc.php' );		// MANGOPAY access methods
include_once( dirname( __FILE__ ) . '/inc/webhooks.inc.php' );		// Incoming webhooks handler
if( is_admin() && defined( 'DOING_AJAX' ) && DOING_AJAX )
	include_once( dirname( __FILE__ ) . '/inc/ajax.inc.php' );		// Ajax methods
	
if( is_admin() )
	include_once( dirname( __FILE__ ) . '/inc/admin.inc.php' );		// Admin specific methods

/** Main plugin class instantiation **/
global $mngpp_o;
$mngpp_o = new mangopayWCMain( $version );
?>