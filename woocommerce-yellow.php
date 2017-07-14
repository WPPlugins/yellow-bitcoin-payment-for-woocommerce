<?php
/*
    Plugin Name: Yellow for WooCommerce
    Plugin URI:  https://yellowpay.co
    Description: Enable your WooCommerce store to accept Bitcoin with Yellow.
    Author:      Yellow
    Author URI:  https://yellowpay.co

    Version:           1.0.1
    License:           Copyright 2015 Yellow Inc., MIT License
    GitHub Plugin URI: https://github.com/YellowPay/yellow-wordpress
 */

// Exit if accessed directly
if (false === defined('ABSPATH')) {
    exit;
}

$autoloader_param = __DIR__.'/vendor/autoload.php';

// Load up the Yellow library
if (true === file_exists($autoloader_param) &&
    true === is_readable($autoloader_param))
{
    require_once $autoloader_param;
} else {
    throw new \Exception('The Yellow payment plugin was not installed correctly or the files are corrupt. Please reinstall the plugin. If this message persists after a reinstall, contact support@yellow.com with this message.');
}
use Yellow\Bitcoin\Invoice;

// Ensures WooCommerce is loaded before initializing the Yellow plugin
add_action('plugins_loaded', 'woocommerce_yellow_init', 0);
register_activation_hook(__FILE__, 'woocommerce_yellow_activate');

function woocommerce_yellow_init()
{
    if (true === class_exists('WC_Gateway_Yellow')) {
        return;
    }

    if (false === class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Yellow extends WC_Payment_Gateway {
        private $is_initialized = false;

        public function __construct() {
            // General
            $this->id                 = 'yellow';
            $this->has_fields         = false;
            $this->title              = 'Pay with Bitcoin (<a href="http://yellowpay.co/what-is-bitcoin/" target="_blank">what is bitcoin?</a>)';
            $this->backend_title      = 'Bitcoin Payment';
            $this->description        = 'Bitcoin is digital cash. Make online payments even if you don\'t have a credit card!';
            $this->method_title       = 'Yellow';
            $this->method_description = 'To accept bitcoin payment, register through <a target="_blank" href="http://merchant.yellowpay.co/">Yellow merchants website</a>, then paste your API key and secret below';

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define Yellow settings
            $this->api_key            = $this->get_option('api_key');
            $this->api_secret         = $this->get_option('api_secret');
            $this->debug              = 'yes' === $this->get_option('debug', 'no');

            // Actions
            add_action('woocommerce_update_options_payment_gateways_'.$this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_'.$this->id, array($this, 'order_invoice'));

            // Valid for use and IPN Callback
            if (false === $this->is_valid_for_use() || !$this->get_option('enabled') ) {
                $this->enabled = 'no';
            } else {
                $this->enabled = 'yes';
                add_action('woocommerce_api_wc_gateway_yellow', array($this, 'ipn_callback'));
            }

            $this->is_initialized = true;
        }

        public function is_valid_for_use()
        {
            // Check that API credentials are set
            if (true === is_null($this->api_key) ||
                true === is_null($this->api_secret))
            {
                return false;
            }

            return true;
        }

        /**
         * Initialise Gateway Settings Form Fields
         */
        public function init_form_fields()
        {
            $log_file = 'yellow-' . sanitize_file_name( wp_hash( 'yellow' ) ) . '-log';
            $logs_href = get_bloginfo('wpurl') . '/wp-admin/admin.php?page=wc-status&tab=logs&log_file=' . $log_file;

            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'Yellow'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable Bitcoin Payments via Yellow', 'Yellow'),
                    'default' => 'yes',
                ),
                'api_key' => array(
                    'type'  => 'api_key',
                    'title'   => __('API Key', 'Yellow'),
                ),
                'api_secret' => array(
                    'title'  => __('API Secret', 'Yellow'),
                    'type'   => 'api_secret',
                    'type'   => 'password',
                ),
                'debug' => array(
                    'title'       => __('Debug Log', 'Yellow'),
                    'type'        => 'checkbox',
                    'label'       => sprintf(__('Enable logging <a href="%s" class="button">View Logs</a>', 'Yellow'), $logs_href),
                    'default'     => 'no',
                    'description' => sprintf(__('Log Yellow events, such as IPN requests, inside <code>%s</code>', 'Yellow'), wc_get_log_file_path('Yellow')),
                    'desc_tip'    => true,
               ),
           );
        }

        /**
         * Process the payment and return the result
         *
         * @param   int     $order_id
         * @return  array
         */
        public function process_payment($order_id)
        {
            if (true === empty($order_id)) {
                throw new \Exception('The Yellow payment plugin was called to process a payment but the order_id was missing. Cannot continue!');
            }

            $order = wc_get_order($order_id);

            if (false === $order) {
                throw new \Exception('The Yellow payment plugin was called to process a payment but could not retrieve the order details for order_id '.$order_id.'. Cannot continue!');
            }

            // session_start();
            if( !session_id() )
                session_start();
            $order_invoice_url_variable = 'yellow_order_'.$order_id.'_invoice_url';

            if( !isset($_SESSION[$order_invoice_url_variable]) || $order->get_status() == "failed" ){
                try {
                    $yellow   = new Invoice($this->api_key, $this->api_secret);
                    $amount   = (float)$order->order_total;
                    $currency = get_woocommerce_currency();
                    $callback = WC()->api_request_url('WC_Gateway_Yellow');
                    $type = 'cart';
                    $order_number = (string)$order->get_order_number();
                    $payload = array(
                        'base_price'=> $amount,
                        'base_ccy'  => $currency,
                        'callback'  => $callback,
                        'type'      => $type,
                        'order'     => $order_number,
                    );
                    $invoice = $yellow->createInvoice( $payload );
                    $this->log('Invoice created with payload: '.json_encode($payload).', response: '.json_encode($invoice));

                    if (false === isset($invoice) || true === empty($invoice) || true === is_object($invoice)) {
                        throw new \Exception('The Yellow payment plugin was called to process a payment but could not instantiate an invoice object. Cannot continue!');
                    }
                    
                    if( $order->get_status() != "failed" ){ //new order
                        $order->add_order_note(__('Order created with Yellow invoice of ID: '.$invoice["id"], 'yellow'));
                        // Reduce stock levels
                        $order->reduce_order_stock();
                        // Remove cart
                        WC()->cart->empty_cart();
                    }else{  //failed order with new invoice
                        $order->add_order_note(__('New Yellow invoice created of ID: '.$invoice["id"], 'yellow'));
                        $order->update_status('pending');
                    }

                    $_SESSION[$order_invoice_url_variable] = $invoice["url"];
                } catch (\Exception $e) {
                    error_log($e->getMessage());

                    return array(
                        'result'    => 'success',
                        'messages'  => "We're sorry, an error has occurred while completing your request. Please resubmit the shopping cart and try again. If the error persists, please send us an email at <a href='mailto:support@yellowpay.co' target='_blank'>support@yellowpay.co</a>"
                    );
                }
            }

            // Redirect the customer to the Yellow invoice
            return array(
                'result'   => 'success',
                'redirect'  => $order->get_checkout_payment_url( true ),
            );
        }

        /**
         * Output for the order invoice.
         */
        public function order_invoice($order_id) {
            if( !session_id() )
                session_start();

            $order = wc_get_order($order_id);
            $order_invoice_url_variable = 'yellow_order_'.$order_id.'_invoice_url';
            $order_invoice_url = $_SESSION[$order_invoice_url_variable];
            $order_return_url = $this->get_return_url($order);

            echo "<script> \n";
            echo "    function invoiceListener(event) { \n";
            echo "        switch (event.data) { \n";
            echo "            case \"authorizing\": \n";
            echo "                // Handle the invoice status update \n";
            echo "                window.location = \"".$order_return_url."\" \n";
            echo "                break; \n";
            echo "        } \n";
            echo "    } \n";
            echo "    // Attach the message listener \n";
            echo "    if (window.addEventListener) { \n";
            echo "        addEventListener(\"message\", invoiceListener, false) \n";
            echo "    } else { \n";
            echo "        attachEvent(\"onmessage\", invoiceListener) \n";
            echo "   } \n";
            echo "</script>";

            echo 'Invoice payment details:';
            echo '<iframe src="'.$order_invoice_url.'" style="width:393px; height:220px; overflow:hidden; border:none; margin:auto; display:block;"  scrolling="no" allowtransparency="true" frameborder="0"></iframe>';
        }

        public function ipn_callback()
        {
            $yellow     = new Invoice($this->api_key, $this->api_secret);
            $body       = file_get_contents("php://input") ;
            $url        = $yellow->getCurrentUrl();
            $sign       = $_SERVER["HTTP_API_SIGN"];
            $api_key    = $_SERVER["HTTP_API_KEY"];
            $nonce      = $_SERVER["HTTP_API_NONCE"];

            $isValidIPN = $yellow->verifyIPN($url,$sign,$api_key,$nonce,$body); //bool
            if( $isValidIPN ){
                $this->log('Valid IPN call: '.json_encode($body));
            }else{
                $this->log('Invalid IPN call: '.json_encode($body));
                wp_die('Invalid IPN call');
            }

            // Fetch the invoice from Yellow's server to update the order
            $invoice = json_decode($body);

            $order_id = $invoice->order;

            if (false === isset($order_id) && true === empty($order_id)) {
                throw new \Exception('The Yellow payment plugin was called to process an IPN message but could not obtain the order ID from the invoice. Cannot continue!');
            }

            // Creating a new WooCommerce Order object with $order_id
            $order = wc_get_order($order_id);

            if (false === isset($order) && true === empty($order)) {
                throw new \Exception('The Yellow payment plugin was called to process an IPN message but could not retrieve the order details for order_id '.$order_id.'. Cannot continue!');
            }

            $current_status = $order->get_status();

            if (false === isset($current_status) && true === empty($current_status)) {
                throw new \Exception('The Yellow payment plugin was called to process an IPN message but could not obtain the current status from the order. Cannot continue!');
            }

            $checkStatus = $invoice->status;

            if (false === isset($checkStatus) && true === empty($checkStatus)) {
                throw new \Exception('The Yellow payment plugin was called to process an IPN message but could not obtain the current status from the invoice. Cannot continue!');
            }

            // Based on the payment status parameter for this
            // IPN, we will update the current order status.
            switch ($checkStatus) {

                // The "authorizing" IPN message is received when the invoice is paid but still not authorized.
                case 'authorizing':
                    if( $current_status == 'pending' || $current_status == 'on-hold' ) {
                        $order->update_status('processing');
                        $order->add_order_note(__('Yellow invoice paid. Awaiting network confirmation and payment completed status.', 'yellow'));
                    }
                    break;

                // The "paid" IPN message is received when the invoice got authorized.
                case 'paid':
                    if( $current_status == 'processing' ) {
                        $order->payment_complete();
                        $order->add_order_note(__('Yellow invoice payment confirmed. The order is awaiting fulfillment.', 'yellow'));
                    }
                    break;

                // The "refund_owed" IPN message is received when the invoice under paid or over paid.
                case 'refund_owed':
                    if( $current_status != 'processing' || $current_status != 'completed' ) {
                        $order->update_status('failed');
                        $order->add_order_note(__('Yellow invoice needs refund.', 'yellow'));
                    }
                    break;

                // The "refund_paid" IPN message is received when the invoice got refunded.
                case 'refund_paid':
                    if( $current_status != 'processing' || $current_status != 'completed' ) {
                        $order->update_status('refunded');
                        $order->add_order_note(__('Yellow invoice refunded.', 'yellow'));
                    }
                    break;

                // The "expired" IPN message is received after the 10 minutes passes on the invoice without payment.
                case 'expired':
                    if( $current_status == 'pending' || $current_status == 'on-hold' ) {
                        $order->update_status('failed');
                        $order->add_order_note(__('Yellow invoice expired.', 'yellow'));
                    }
                    break;
            }
        }

        public function log($message)
        {
            if (true === isset($this->debug) && 'yes' == $this->debug) {
                if (false === isset($this->logger) || true === empty($this->logger)) {
                    $this->logger = new WC_Logger();
                }

                $this->logger->add('yellow', $message);
            }
        }

        /*
        * over riding get_title function to return title without html in the backend
        */
        public function get_title()
        {
            $trace=debug_backtrace();
            if( isset($trace[1]) && 
                isset($trace[1]['args']) && 
                isset($trace[1]['args'][0]) ){

                $caller_url = $trace[1]['args'][0];
                $find1 = 'payment-method.php';
                $find2 = 'form-pay.php';
                if( is_string($caller_url) && 
                    (substr_compare($caller_url, $find1, strlen($caller_url)-strlen($find1), strlen($find1)) === 0 || 
                     substr_compare($caller_url, $find2, strlen($caller_url)-strlen($find2), strlen($find2)) === 0) ){
                    return $this->title;
                }
            }

            return $this->backend_title;
        }
    }

    /**
    * Add Yellow Payment Gateway to WooCommerce
    **/
    add_filter('woocommerce_payment_gateways', 'wc_add_yellow');
    function wc_add_yellow($methods)
    {
        $methods[] = 'WC_Gateway_Yellow';

        return $methods;
    }

    /**
     * Add Settings link to the plugin entry in the plugins menu
     **/
    add_filter('plugin_action_links', 'yellow_plugin_action_links', 10, 2);
    function yellow_plugin_action_links($links, $file)
    {
        static $this_plugin;

        if (false === isset($this_plugin) || true === empty($this_plugin)) {
            $this_plugin = plugin_basename(__FILE__);
        }

        if ($file == $this_plugin) {
            $log_file = 'yellow-'.sanitize_file_name( wp_hash( 'yellow' ) ).'-log';
            $settings_link = '<a href="'.get_bloginfo('wpurl').'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wc_gateway_yellow">Settings</a>';
            $logs_link = '<a href="'.get_bloginfo('wpurl').'/wp-admin/admin.php?page=wc-status&tab=logs&log_file='.$log_file.'">Logs</a>';
            array_unshift($links, $settings_link, $logs_link);
        }

        return $links;
    }

    /**
     * Add message to apppear instead of the default message after the plugin activation
     **/
    add_filter('gettext', 
        function( $translated_text, $untranslated_text, $domain )
        {
            $old = array(
                "Plugin <strong>activated</strong>.",
                "Selected plugins <strong>activated</strong>." 
            );

            $settings_link = '<a href="'.get_bloginfo('wpurl').'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=wc_gateway_yellow">settings page</a>';
            $new = 'To start accepting Bitcoin payments, visit the '.$settings_link.' to connect your site to your Yellow account';
            if ( in_array( $untranslated_text, $old, true ) )
                $translated_text = substr($untranslated_text, 0, -1).', '.$new;

            return $translated_text;
         }
    , 99, 3);
}

function woocommerce_yellow_failed_requirements()
{
    global $wp_version;
    global $woocommerce;

    $errors = array();

    // PHP 5.4+ required
    if (true === version_compare(PHP_VERSION, '5.4.0', '<')) {
        $errors[] = 'Your PHP version is too old. The Yellow payment plugin requires PHP 5.4 or higher to function. Please contact your web server administrator for assistance.';
    }

    // Wordpress 3.9+ required
    if (true === version_compare($wp_version, '3.9', '<')) {
        $errors[] = 'Your WordPress version is too old. The Yellow payment plugin requires Wordpress 3.9 or higher to function. Please contact your web server administrator for assistance.';
    }

    // WooCommerce required
    if (true === empty($woocommerce)) {
        $errors[] = 'The WooCommerce plugin for WordPress needs to be installed and activated. Please contact your web server administrator for assistance.';
    }elseif (true === version_compare($woocommerce->version, '2.2', '<')) {
        $errors[] = 'Your WooCommerce version is too old. The Yellow payment plugin requires WooCommerce 2.2 or higher to function. Your version is '.$woocommerce->version.'. Please contact your web server administrator for assistance.';
    }

    // GMP or BCMath required
    if (false === extension_loaded('gmp') && false === extension_loaded('bcmath')) {
        $errors[] = 'The Yellow payment plugin requires the GMP or BC Math extension for PHP in order to function. Please contact your web server administrator for assistance.';
    }

    // Curl required
    if (false === extension_loaded('curl')) {
        $errors[] = 'The Yellow payment plugin requires the Curl extension for PHP in order to function. Please contact your web server administrator for assistance.';
    }

    if (false === empty($errors)) {
        return implode("<br>\n", $errors);
    } else {
        return false;
    }

}

// Activating the plugin
function woocommerce_yellow_activate()
{
    // Check for Requirements
    $failed = woocommerce_yellow_failed_requirements();

    $plugins_url = admin_url('plugins.php');

    // Requirements met, activate the plugin
    if ($failed === false) {

        // Deactivate any older versions that might still be present
        $plugins = get_plugins();

        foreach ($plugins as $file => $plugin) {
            if ('Yellow Woocommerce' === $plugin['Name'] && true === is_plugin_active($file)) {
                deactivate_plugins(plugin_basename(__FILE__));
                wp_die('Yellow for WooCommerce requires that the old plugin, <b>Yellow Woocommerce</b>, is deactivated and deleted.<br><a href="'.$plugins_url.'">Return to plugins screen</a>');

            }
        }

        // Fix transaction_speed from older versions
        $settings = get_option('woocommerce_Yellow');
        if (true === isset($settings) && true === is_string($settings)) {
            $settings_array = @unserialize($settings);
            if (false !== $settings_array && true === isset($settings_array['transactionSpeed'])) {
                $settings_array['transaction_speed'] = $settings_array['transactionSpeed'];
                unset($settings_array['transactionSpeed']);
                update_option('woocommerce_Yellow', serialize($settings));
            }
        }

        update_option('woocommerce_Yellow_version', '1.0.0');
    } else {
        // Requirements not met, return an error message
        wp_die($failed.'<br><a href="'.$plugins_url.'">Return to plugins screen</a>');
    }
}
