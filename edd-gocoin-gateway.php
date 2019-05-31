<?php
/**
* Plugin Name: Easy Digital Downloads - GoCoin Payment Gateway
* Plugin URI: https://qctechjunkie.com
* Description: Provides Payment abilities with GoCoin for Easy Digital Downloads
* Version: 1.0.1
* Author: TechJunkie LLC
* Author URI: https://qctechjunkie.com
*/

/**
* Exit if accessed directly
*
* @since 1.0.0
*/
if ( ! defined( 'ABSPATH' ) ) exit;

/**
* Load additional handlers
*
* @since 1.0.0
*/
require_once('gocoin-php/src/GoCoin.php');
require_once('gocoin-util.php');


/**
* Registers GoCoin Gateway
*
* @since 1.0.0
*/
function edd_gocoin_register_gateway( $gateways ) {
  $gateways[ 'gocoin' ] = array(
  'admin_label'   => 'GoCoin',
  'checkout_label'=> __( 'GoCoin', 'edd_gocoin' ),
  );
  return $gateways;

}
add_filter( 'edd_payment_gateways', 'edd_gocoin_register_gateway' );


/**
* Load the EDD license handler only if not already loaded. Must be placed in the main plugin file
*
* @since 1.0.0
*/
if( class_exists( 'EDD_License' ) ) {
  // Instantiate the licensing / updater. Must be placed in the main plugin file
  $license = new EDD_License( __FILE__, 'EDD - GoCoin Payment Gateway', '1.0.1', 'TechJunkie LLC', null, 'https://qctechjunkie.com' );

}

function edd_gocoin_cc_form() {
  // register the action to remove default CC form
  ?>
  <fieldset id="edd-gocoin-description">
    <legend>
      <span>GoCoin Notice</span>
    </legend>
    <p id="edd-gocoin-description"><?php echo trim( edd_get_option( 'gocoin_description' ) ) ?></p>
  </fieldset>
  <?php
  return;

}
add_action('edd_gocoin_cc_form', 'edd_gocoin_cc_form');

// processes the payment
function edd_process_payment($purchase_data) {
  global $edd_options;

  $merchant_id = trim( edd_get_option( 'gocoin_merchant_id' ) );
  $access_token = trim( edd_get_option( 'gocoin_api_key' ) );

  if(edd_is_test_mode()) {
    // set test credentials here

  } else {
    //switch to production mode

  }

  // check for any stored errors
  $errors = edd_get_errors();

  if(!$errors) {
    $purchase_summary = edd_get_purchase_summary($purchase_data);

    /**********************************
    * setup the payment details
    **********************************/
    $payment_data = array(
    'price'        => $purchase_data['price'],
    'date'         => $purchase_data['date'],
    'user_email'   => $purchase_data['user_email'],
    'purchase_key' => $purchase_data['purchase_key'],
    'currency'     => $edd_options['currency'],
    'downloads'    => $purchase_data['downloads'],
    'cart_details' => $purchase_data['cart_details'],
    'user_info'    => $purchase_data['user_info'],
    'status'       => 'pending'
    );

    // record the pending payment
    $payment_id = edd_insert_payment($payment_data);

    // Check payment
    if ( ! $payment_id ) {
      // Record the error
      edd_record_gateway_error( __( 'Payment Error', 'easy-digital-downloads' ), sprintf( __( 'Payment creation failed before sending buyer to GoCoin. Payment data: %s', 'easy-digital-downloads' ), json_encode( $payment_data ) ), $payment_id );

      // Problems? send back
      edd_send_back_to_checkout( '?payment-mode=' . $purchase_data['post_data']['edd-gateway'] );

    } else {
      $payment = new EDD_Payment( $payment_id );

      $listener_url = add_query_arg( 'edd-listener', 'GoCoin', home_url( 'index.php' ) );
      $currency = edd_get_currency();

      // Get the success url
      $return_url = add_query_arg( array(
      'payment-confirmation' => 'gocoin',
      'payment-id' => $payment_id
      ), get_permalink( edd_get_option( 'success_page', false ) ) );

      $options = array(
      "type"                  => 'bill',
      "base_price"            => number_format( ( float ) $purchase_data[ 'price' ], 2 ),
      "base_price_currency"   => $currency,
      "callback_url"          => $listener_url,
      "redirect_url"          => $return_url,
      "order_id"              => $payment_id,
      "customer_name"         => $purchase_data['user_info']['first_name'] . ' ' . $purchase_data['user_info']['last_name'],
      "customer_address_1"    => $purchase_data['user_info']['address']['line1'],
      "customer_address_2"    => $purchase_data['user_info']['address']['line2'],
      "customer_city"         => $purchase_data['user_info']['address']['city'],
      "customer_region"       => $purchase_data['user_info']['address']['state'],
      "customer_postal_code"  => $purchase_data['user_info']['address']['zip'],
      "customer_country"      => $purchase_data['user_info']['address']['country'],
      "customer_email"        => $purchase_data[ 'user_info' ][ 'email' ],
      );

      // Sign invoice with access token, if this fails we should still allow user to check out.
      if ($signature = Util::sign($options, $access_token)) {
        $options['user_defined_8'] = $signature;

      }

      try {
        $invoice = GoCoin::createInvoice($access_token, $merchant_id, $options);
        $payment -> add_note( "Invoice status: " . $invoice->status );
        $payment -> save( );
        $payment -> add_note( "Gateway Link: " . $invoice->gateway_url );
        $payment -> save( );

        edd_empty_cart( );

        // Redirect to GoCoin Gateway to proceed with payment
        wp_redirect( $invoice->gateway_url );

      } catch (Exception $e) {
        $msg = $e->getMessage();
        $payment -> add_note( "An error occurred during invoice creation. Error: " . $msg );
        $payment -> save( );

        //Get any errors from the above checks
        edd_set_error('invoice_error', __( $msg , 'edd'));
        $errors = edd_get_errors( );

        edd_send_back_to_checkout( $errors );

      }

    }

  }

}
add_action('edd_gateway_gocoin', 'edd_process_payment');

/**
* Listens for a GoCoin IPN requests and then sends to the processing function
*
* @since 1.0
* @return void
*/
function edd_listen_for_gocoin_ipn() {
  // Regular GoCoin IPN
  if ( isset( $_GET['edd-listener'] ) && $_GET['edd-listener'] == 'GoCoin' ) {
    do_action( 'edd_verify_gocoin_ipn_test' );

  }

}
add_action( 'init', 'edd_listen_for_gocoin_ipn' );

/**
* Process GoCoin IPN
*
* @since 1.0
* @return void
*/
function edd_process_gocoin_ipn() {
  $key = trim( edd_get_option( 'gocoin_api_key' ) );
  if(empty($key)){
    edd_debug_log( 'GoCoin API Key is blank' );
    return;

  }

  $data = Util::postData();
  if (isset($data->error)){
    edd_debug_log( 'GoCoin Callback Error: ' . $data->error );
    return;

  } else {
  $event_id     = $data -> id;
  $event        = $data -> event;
  $invoice      = $data -> payload;
  $payload_arr  = get_object_vars($invoice) ;

  ksort($payload_arr);

  $signature    = $invoice -> user_defined_8;
  $sig_comp     = Util::sign($payload_arr, $key);
  $status       = $invoice -> status;
  $order_id     = (int) $invoice -> order_id;

  $payment = new EDD_Payment( $order_id );

  if (!$payment) {
  $msg = "Payment with id: " . $order_id . " was not found. Event ID: " . $event_id;
  edd_debug_log( 'GoCoin Callback Error: '. $msg );
  return;

  }

  // Check that if a signature exists, it is valid
  if (isset($signature) && ($signature != $sig_comp)) {
  $msg = "Signature : " . $signature . "does not match for Order: " . $order_id ."$sig_comp        |    $signature ";

  } elseif (empty($signature) || empty($sig_comp) ) {
  $msg = "Signature is blank for Order: " . $order_id;

  } elseif($signature == $sig_comp) {
    switch($event) {
      case 'invoice_created':
      break;

      case 'invoice_payment_received':
        switch ($status) {
          case 'ready_to_ship':
            $msg = 'Order ' . $order_id .' is paid and awaiting payment confirmation on blockchain.';
            $payment -> status = 'processing';
            $payment -> add_note( "Invoice Status: ". $msg );
            $payment -> save( );
          break;

          case 'paid':
            $msg = 'Order ' . $order_id .' is paid and awaiting payment confirmation on blockchain.';
            $payment -> status = 'processing';
            $payment -> add_note( "Invoice Status: ". $msg );
            $payment -> save( );
          break;

          case 'underpaid':
            $msg = 'Order ' . $order_id .' is underpaid.';
            $payment -> status = 'pending';
            $payment -> add_note( "Invoice Status: ". $msg );
            $payment -> save( );
          break;

        }
      break;

      case 'invoice_merchant_review':
        $msg = 'Order ' . $order_id .' is under review. Action must be taken from the GoCoin Dashboard.';
        $payment -> status = 'pending';
        $payment -> add_note( "Invoice Status: ". $msg );
        $payment -> save( );
      break;

      case 'invoice_ready_to_ship':
        $msg = 'Order ' . $order_id .' has been paid in full and confirmed on the blockchain.';
        $payment -> status = 'complete';
        $payment -> add_note( "Invoice Status: ". $msg );
        $payment -> save( );
      break;

      case 'invoice_invalid':
        $msg = 'Order ' . $order_id . ' is invalid and will not be confirmed on the blockchain.';
        $payment -> status = 'failed';
        $payment -> add_note( "Invoice Status: ". $msg );
        $payment -> save( );
      break;

      default:
      $msg = "Unrecognized event type: ". $event;

    }

    if (isset($msg)){
      $msg .= ' Event ID: '. $event_id;

    }

    }
    edd_debug_log( 'GoCoin Callback: '. $msg );
    return;

  }
}
add_action( 'edd_verify_gocoin_ipn_test', 'edd_process_gocoin_ipn' );

/**
* Register GoCoin gateway subsection
*
* @since  1.0.0
* @param  array $gateway_sections  Current Gateway Tab subsections
* @return array                    Gateway subsections with GoCoin
*/
function edd_register_gocoin_gateway_section( $gateway_sections ) {
  $gateway_sections[ 'gocoin' ] = __( 'GoCoin', 'easy-digital-downloads' );
  return $gateway_sections;

}
add_filter( 'edd_settings_sections_gateways', 'edd_register_gocoin_gateway_section', 1, 1 );

/**
* Registers GoCoin settings for the GoCoin subsection
*
* @since  1.0.0
* @param  array $gateway_settings  Gateway tab settings
* @return array                    Gateway tab settings with the GoCoin settings
*/
function edd_register_gocoin_gateway_settings( $gateway_settings ) {
  $gocoin_settings = array(
    array(
      'id'    => 'gocoin_settings',
      'name'  => '<strong>' . __( 'GoCoin Settings', 'easy-digital-downloads' ) . '</strong>',
      'type'  => 'header',
    ),

    array(
      'id'    => 'gocoin_description',
      'name'  => __('Customer Message', 'easy-digital-downloads'),
      'type'  => 'textarea',
      'desc'  => __('Message which will show in checkout page.', 'easy-digital-downloads'),
      'std'   => __( 'You will be redirected to GoCoin.com to complete your purchase.', 'easy-digital-downloads' ),
    ),

    array(
      'id'    => 'gocoin_merchant_id',
      'name'  => __( 'Merchant ID', 'easy-digital-downloads' ),
      'desc'  => __( 'Enter your GoCoin Merchant ID', 'edd_gocoin' ),
      'type'  => 'text',
      'size'  => 'regular',
    ),

    array(
      'id'    => 'gocoin_api_key',
      'name'  => __( 'API Key', 'easy-digital-downloads' ),
      'desc'  => __( 'Enter your GoCoin API Key', 'edd_gocoin' ),
      'type'  => 'text',
      'size'  => 'regular',
    ),

  );

  $gocoin_settings = apply_filters( 'edd_gocoin_settings', $gocoin_settings );
  $gateway_settings[ 'gocoin' ] = $gocoin_settings;

  return $gateway_settings;

}
add_filter( 'edd_settings_gateways', 'edd_register_gocoin_gateway_settings',  1, 1 );

/**
* Registers GoCoin settings for the GoCoin subsection
*
* @since  1.0.0
* @param  array $icons
* @return array
*/
function edd_gocoin_icon( $icons ) {
  $iconUrl = plugin_dir_url( __FILE__ ) . 'gocoin-icon.png';
  $icons[ $iconUrl ] = 'GoCoin';
  return $icons;

}
add_filter('edd_accepted_payment_icons', 'edd_gocoin_icon');
