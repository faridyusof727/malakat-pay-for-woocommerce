<?php

/**
 * Plugin Name: Malakat Pay for WooCommerce
 * Plugin URI:
 * Description: Malakat Pay Payment Gateway | <a href="https://www.malakatpay.com/" target="_blank">Sign up Now</a>.
 * Author: Malakat Pay
 * Author URI:
 * Version: 0.1.0.0
 * Requires PHP: 7.4
 * Requires at least: 4.6
 * License: GPLv3
 * Text Domain: malakatpay
 * Domain Path: /languages/
 * WC requires at least: 3.0
 */

/* Load Required Libraries */
if (!class_exists('RaudhahPayConnect') && !class_exists('RaudhahPayApi')) {
    require('includes/RaudhahPayConnect.php');
    require('includes/RaudhahPayApi.php');
    require('includes/LogHelper.php');
    require('includes/NoticeHelper.php');
}

function raudhahpay_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'raudhahpay_fallback_notice');
        return;
    }

    class WC_Malakatpay_Gateway extends WC_Payment_Gateway
    {
        const DEFAULT_CURRENCY = 'MYR';

        private $web_service_url;
        private $access_token;
        private $signature_key;
        private $collection_id;
        private $instructions;
        private $custom_error;
        private $reference_1;
        private $logger;
        private $raudhah;

        public function __construct()
        {
            $this->id = 'raudhahpay';
            $this->icon = apply_filters('raudhahpay_form_fields', plugins_url('assets/payment-options.png', __FILE__));
            $this->has_fields = true;
            $this->method_title = __('Malakat Pay', 'raudhahpay');
            $this->method_description = '';

            $this->init_form_fields();
            $this->init_settings();

            $this->settings = apply_filters('raudhahpay_settings_value', $this->settings);

            $this->title = $this->settings['title'];
            $this->description = $this->settings['description'];

            $this->web_service_url = $this->settings['web_service_url'];
            $this->access_token = $this->getAccessToken();
            $this->signature_key = $this->getSignatureKey();
            $this->collection_id = $this->getCollectionId();
            $this->reference_1 = $this->settings['reference_1'];
            $this->instructions = $this->settings['instructions'];
            $this->custom_error = $this->settings['custom_error'];

            $this->raudhah = $this->initRaudhahPay();
            $this->logger = $this->initLogger();

            // Save setting configuration
            add_action('woocommerce_update_options_payment_gateways_raudhahpay', array($this,'process_admin_options'));

            // Payment listener/API hook
            add_action('woocommerce_api_wc_malakatpay_gateway', array($this,'processIpnResponse'));

            // Update thank you page
            add_action('woocommerce_thankyou_raudhahpay', array($this, 'postPaymentPageContent'));
        }

        public static function settings_value($settings)
        {
            return $settings;
        }

        public function init_form_fields()
        {
            $formFields = include 'includes/settings-raudhahpay.php';
            $this->form_fields = apply_filters('raudhahpay_form_fields', $formFields);
        }

        public function process_payment($orderId)
        {
            if (!isset($_POST['raudhahpay_bank']) && $this->has_fields === 'yes') {
                wc_add_notice(__('Please choose your bank to proceed', 'raudhahpay'), 'error');
                return;
            }

            $billId = get_post_meta($orderId, '_transaction_id', true);

            $this->logger->log('Creating bills order id #' . $orderId . ', check bill Id: '.$billId);

            $order = $this->getOrder($orderId);

            $params = [
                'due' => $this->getBillDue(),
                'currency' => self::DEFAULT_CURRENCY,
                'ref1' => $this->reference_1,
                'ref2' => $order->get_id(),
                'customer' => $this->getCustomerDataFromOrder($order),
                'product' => $this->buildProduct($order),
            ];

            list($responseCode, $body) = $this->raudhah->createBill($params);

            if ($responseCode !== RaudhahPayConnect::DEFAULT_SUCCESS_CODE) {
                wc_add_notice( __('Payment error:', 'woothemes') . NoticeHelper::humanify($body), 'error' );
                return;
            }

            $this->logger->log('Bill ID '.$body['id'].' created for order number #' . $order->get_id() . ' payment URL: '.$body['payment_url']);

            return array(
                'result' => 'success',
                'redirect' => $body['payment_url']
            );
        }

        public function payment_fields()
        {
            if ($description = $this->get_description()) {
                echo wpautop(wptexturize($description));
            }

            if (isset($this->has_fields) && $this->has_fields === 'yes') {
                ?>
                <p class="form-row validate-required">
                    <label><?php echo 'Full Name'; ?> <span class="required">*</span></label>
                    <input type="text" name="fullname" />
                    <label><?php echo 'Choose Bank'; ?> <span class="required">*</span></label>
                    <select name="test_bank">
                        <option value="" disabled selected>Choose your bank</option>
                        <?php
                        $bankName = [
                            'Maybank',
                            'CIMB',
                            'RHB',
                            'Bank Islam',
                            'Public Bank'
                        ];
                        foreach ($bankName as $bank) {
                            ?><option value="<?php echo $bank; ?>">
                            <?= $bank ?></option><?php
                        }
                        ?>
                        <option value="OTHERS">OTHERS</option>
                    </select>
                </p>
                <?php
            }
        }

        public function access_token_missing_message()
        {
            $message = '<div class="error">';
            $message .= '<p>' . sprintf(__('<strong>Gateway Notice!</strong> You should inform your API Access Token in Malakat Pay. %sClick here to configure!%s', 'raudhahpay'), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&tab=checkout&section=raudhahpay">', '</a>') . '</p>';
            $message .= '</div>';
            echo $message;
        }

        public function signature_key_missing_message()
        {
            $message = '<div class="error">';
            $message .= '<p>' . sprintf(__('<strong>Gateway Notice!</strong> You should inform your API Signature Key in Malakat Pay. %sClick here to configure!%s', 'raudhahpay'), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&tab=checkout&section=raudhahpay">', '</a>') . '</p>';
            $message .= '</div>';
            echo $message;
        }

        public function collection_id_missing_message()
        {
            $message = '<div class="error">';
            $message .= '<p>' . sprintf(__('<strong>Gateway Notice!</strong> You should inform your Collection ID in Malakat Pay. %sClick here to configure!%s', 'raudhahpay'), '<a href="' . get_admin_url() . 'admin.php?page=wc-settings&tab=checkout&section=raudhahpay">', '</a>') . '</p>';
            $message .= '</div>';
            echo $message;
        }

        public function processIpnResponse()
        {
            $this->logger->log('Callback initiated');

            @ob_clean();

            if (!$responseBody = $this->raudhah->getIpnResponseData()) {
                exit('Failed signature validation');
            }

            $this->logger->log('IPN Raudhah Pay response '. print_r($responseBody, true));

            try {
                $this->raudhah->validateSignature($responseBody, $this->signature_key);
                $order = $this->getIpnOrder($responseBody);

                if ($responseBody['paid'] == true) {
                    $this->processSuccessOrder($order, $responseBody );
                    $redirect = $order->get_checkout_order_received_url();
                } else {
                    $this->processFailedOrder($order, $responseBody['bill_id']);
                    $redirect = $order->get_cancel_order_url();
                }

                if ($this->shouldRedirect()) {
                    $this->logger->log('Redirect to '. $redirect);

                    wp_redirect($redirect);
                } else {
                    $this->logger->log('Completed without redirect');

                    echo 'RECEIVEOK';
                    exit;
                }
            } catch (Exception $e) {
                $this->logger->log( $e->getMessage() );
                exit( $e->getMessage() );
            }
        }

        public function postPaymentPageContent()
        {
            if ($this->instructions) {
                echo wpautop(wptexturize($this->instructions));
            }
        }

        private function getIpnOrder(array $ipnResponse)
        {
            if (empty($ipnResponse['ref2'])) {
                throw new Exception('Order Id is not specified on IPN response');
            }

            return $this->getOrder($ipnResponse['ref2']);
        }

        private function processSuccessOrder(WC_Order $order, array $ipnResponse)
        {
            $referer = '<br>Bill ID: ' . $ipnResponse['bill_id'];
            $referer .= '<br>Bill NO: ' . $ipnResponse['bill_no'];
            $referer .= '<br>Transaction Number: ' . $ipnResponse['ref_id'];
            $referer .= '<br>Payment Method: ' . $ipnResponse['payment_method'];
            $referer .= '<br>Ref 1: ' . $ipnResponse['ref1'];

            $order->add_order_note('Payment Status: SUCCESSFUL' . $referer);
            $order->payment_complete($ipnResponse['bill_id']);
        }

        private function processFailedOrder(WC_Order $order, $billId)
        {
            $order->add_order_note('Payment Status: CANCELLED BY USER' . '<br>Bill ID: ' . $billId);
            wc_add_notice(__('ERROR: ', 'woothemes') . $this->custom_error, 'error');
        }

        private function initRaudhahPay()
        {
            return new RaudhahPayApi($this->web_service_url, $this->access_token, $this->collection_id);
        }

        private function initLogger()
        {
            return new LogHelper($this->isDebug());
        }

        private function getAccessToken()
        {
            if (empty($this->settings['access_token'])) {
                add_action('admin_notices', array(&$this,'access_token_missing_message'));
            }

            return $this->settings['access_token'];
        }

        private function getSignatureKey()
        {
            if (empty($this->settings['signature_key'])) {
                add_action('admin_notices', array(&$this,'signature_key_missing_message'));
            }

            return $this->settings['signature_key'];
        }

        private function getCollectionId()
        {
            if (empty($this->settings['collection_id'])) {
                add_action('admin_notices', array(&$this,'collection_id_missing_message'));
            }

            return $this->settings['collection_id'];
        }

        private function isDebug()
        {
            return 'yes' === $this->get_option('debug', 'no');
        }

        private function getBillDue()
        {
            return date('Y-m-d');
        }

        private function getOrder($orderId)
        {
            return new WC_Order($orderId);
        }

        private function getCustomerDataFromOrder(WC_Order $order)
        {
            return [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'address' => $order->get_formatted_billing_address(),
                'email' => $order->get_billing_email(),
                'mobile' => $order->get_billing_phone()
            ];
        }

        private function buildProduct(WC_Order $order)
        {
            return [
                [
                    'title' => sprintf('Order (%s)', $order->get_id()),
                    'quantity' => 1, //Fix value to not calculate the price against the qty
                    'price' => $order->get_total() - $order->get_shipping_total(),
                ],
                [
                    'title' => 'Shipping',
                    'quantity' => 1,
                    'price' => $order->get_shipping_total() ?: 0
                ]
            ];
        }

        private function shouldRedirect()
        {
            return $_SERVER['REQUEST_METHOD'] == RaudhahPayConnect::METHOD_GET;
        }
    }
}

add_action('plugins_loaded', 'raudhahpay_init', 0);

function raudhahpay_plugin_uninstall()
{
    global $wpdb;

    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%raudhahpay%'");
}

register_uninstall_hook(__FILE__, 'raudhahpay_plugin_uninstall');

function raudhahpay_fallback_notice()
{
    $message = '<div class="error">';
    $message .= '<p>' . __('Raudhah Pay for WooCommerce depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!', 'raudhahpay') . '</p>';
    $message .= '</div>';

    echo $message;
}

function raudhahpay_add_gateway($methods) {
    $methods[] = 'WC_Malakatpay_Gateway';
    return $methods;
}

add_filter('http_request_args', 'bal_http_request_args', 100, 1);
function bal_http_request_args($r)
{
    $r['timeout'] = 15;
    return $r;
}

add_action('http_api_curl', 'bal_http_api_curl', 100, 1);
function bal_http_api_curl($handle)
{
    curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 15 );
    curl_setopt( $handle, CURLOPT_TIMEOUT, 15 );
}

add_filter('woocommerce_payment_gateways', 'raudhahpay_add_gateway');
add_filter('raudhahpay_settings_value', array('WC_Malakatpay_Gateway', 'settings_value'));
