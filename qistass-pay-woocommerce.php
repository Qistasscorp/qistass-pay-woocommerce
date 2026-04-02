<?php
/**
 * Plugin Name: Qistass Pay for WooCommerce
 * Plugin URI: https://pay.qistass.com
 * Description: بوابة دفع Qistass Pay لمتاجر WooCommerce.
 * Version: 1.3.3
 * Author: Qistass LLC
 * Author URI: https://qistass.com
 * Requires Plugins: woocommerce
 * Text Domain: qistass-pay
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'qistass_pay_gateway_init', 11);

function qistass_pay_gateway_init() {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Qistass_Pay extends WC_Payment_Gateway {

        protected $logger;
        protected $debug;
        protected $public_key;
        protected $secret_key;
        protected $merchant_number;
        protected $api_base_url;
        protected $webhook_secret;

        public function __construct() {
            $this->id                 = 'qistass_pay';
            $this->icon               = plugin_dir_url(__FILE__) . 'assets/qistass-pay.png';
            $this->has_fields         = false;
            $this->method_title       = __('Qistass Pay', 'qistass-pay');
            $this->method_description = __('بوابة دفع Qistass Pay الآمنة لمتاجر WooCommerce.', 'qistass-pay');
            $this->supports           = array('products');

            $this->init_form_fields();
            $this->init_settings();

            $this->title             = $this->get_option('title', __('الدفع عبر Qistass Pay', 'qistass-pay'));
            $this->description       = $this->get_option('description', __('سيتم تحويلك بشكل آمن إلى منصة Qistass Pay لإتمام عملية الدفع.', 'qistass-pay'));
            $this->public_key        = trim((string) $this->get_option('public_key', ''));
            $this->secret_key        = trim((string) $this->get_option('secret_key', ''));
            $this->merchant_number   = trim((string) $this->get_option('merchant_number', ''));
            $this->api_base_url      = untrailingslashit(trim((string) $this->get_option('api_base_url', 'https://pay.qistass.com')));
            $this->webhook_secret    = trim((string) $this->get_option('webhook_secret', ''));
            $this->debug             = 'yes' === $this->get_option('debug', 'no');

            $this->logger = wc_get_logger();

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Callback المتصفح
            add_action('woocommerce_api_qistass_pay_callback', array($this, 'handle_callback'));

            // Webhook الخلفي
            add_action('woocommerce_api_qistass_pay_webhook', array($this, 'handle_webhook'));
        }

        public function get_icon() {
            $icon_url = esc_url($this->icon);
            return "<img src='{$icon_url}' alt='Qistass Pay' style='max-width:120px; max-height:40px; width:auto; height:auto; object-fit:contain;' />";
        }

        public function init_form_fields() {
            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('تفعيل/تعطيل', 'qistass-pay'),
                    'type'    => 'checkbox',
                    'label'   => __('تفعيل بوابة Qistass Pay', 'qistass-pay'),
                    'default' => 'yes',
                ),
                'title' => array(
                    'title'       => __('عنوان البوابة', 'qistass-pay'),
                    'type'        => 'text',
                    'default'     => __('الدفع عبر Qistass Pay', 'qistass-pay'),
                    'desc_tip'    => true,
                    'description' => __('العنوان الذي يراه العميل أثناء الدفع.', 'qistass-pay'),
                ),
                'description' => array(
                    'title'       => __('الوصف', 'qistass-pay'),
                    'type'        => 'textarea',
                    'default'     => __('سيتم تحويلك بشكل آمن إلى منصة Qistass Pay لإتمام عملية الدفع.', 'qistass-pay'),
                ),
                'api_base_url' => array(
                    'title'       => __('رابط API الأساسي', 'qistass-pay'),
                    'type'        => 'text',
                    'default'     => 'https://pay.qistass.com',
                ),
                'merchant_number' => array(
                    'title'       => __('رقم التاجر (Merchant Number)', 'qistass-pay'),
                    'type'        => 'text',
                ),
                'public_key' => array(
                    'title'       => __('المفتاح العام (Public Key)', 'qistass-pay'),
                    'type'        => 'text',
                ),
                'secret_key' => array(
                    'title'       => __('المفتاح السري (Secret Key)', 'qistass-pay'),
                    'type'        => 'password',
                ),
                'webhook_secret' => array(
                    'title'       => __('Webhook Secret', 'qistass-pay'),
                    'type'        => 'password',
                    'description' => __('Secret used to verify incoming webhooks from Qistass Pay.', 'qistass-pay'),
                ),
                'debug' => array(
                    'title'       => __('وضع التصحيح (Debug)', 'qistass-pay'),
                    'type'        => 'checkbox',
                    'label'       => __('تفعيل تسجيل الأخطاء داخل WooCommerce logs', 'qistass-pay'),
                    'default'     => 'yes',
                ),
            );
        }

        protected function log($message, $data = array(), $level = 'info') {
            if (!$this->debug) {
                return;
            }

            if (!empty($data)) {
                $message .= ' | ' . wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $this->logger->log($level, $message, array('source' => $this->id));
        }

        protected function is_config_valid() {
            return !empty($this->public_key) &&
                   !empty($this->secret_key) &&
                   !empty($this->merchant_number) &&
                   !empty($this->api_base_url);
        }

        protected function get_callback_url($order) {
            return add_query_arg(
                array(
                    'order_id'  => $order->get_id(),
                    'order_key' => $order->get_order_key(),
                ),
                home_url('/wc-api/qistass_pay_callback/')
            );
        }

        protected function get_webhook_url() {
            return home_url('/wc-api/qistass_pay_webhook/');
        }

        protected function is_valid_webhook_signature($raw_body) {
            $received_signature = isset($_SERVER['HTTP_X_QISTASS_SIGNATURE'])
                ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_QISTASS_SIGNATURE']))
                : '';

            if (empty($received_signature) || empty($this->webhook_secret)) {
                return false;
            }

            $calculated_signature = hash_hmac('sha256', $raw_body, $this->webhook_secret);

            return hash_equals($calculated_signature, $received_signature);
        }

        public function process_payment($order_id) {
            $order = wc_get_order($order_id);

            if (!$order) {
                wc_add_notice(__('تعذر العثور على الطلب.', 'qistass-pay'), 'error');
                return;
            }

            if (!$this->is_config_valid()) {
                wc_add_notice(__('إعدادات بوابة الدفع غير مكتملة. الرجاء مراجعة الإدارة.', 'qistass-pay'), 'error');
                $this->log('Invalid config', array('order_id' => $order_id), 'error');
                return;
            }

            $endpoint = $this->api_base_url . '/api/v1/create-payment-order';
            $amount   = (float) $order->get_total();

            $body = array(
                'public_key'         => $this->public_key,
                'secret_key'         => $this->secret_key,
                'merchant_number'    => $this->merchant_number,
                'amount'             => $amount,
                'order_id'           => $order_id,
                'merchant_order_id'  => (string) $order->get_id(),
                'merchant_order_key' => $order->get_order_key(),
                'webhook_url'        => $this->get_webhook_url(),
                'callback_url'       => $this->get_callback_url($order), // <== أضفنا هذا السطر لكي نرسل الرابط كاملاً
            );

            $response = wp_remote_post($endpoint, array(
                'method'  => 'POST',
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body'    => wp_json_encode($body),
            ));

            if (is_wp_error($response)) {
                $this->log('Create payment request failed', array(
                    'order_id' => $order_id,
                    'error'    => $response->get_error_message(),
                ), 'error');

                wc_add_notice(__('فشل الاتصال ببوابة الدفع. الرجاء المحاولة لاحقاً.', 'qistass-pay'), 'error');
                return;
            }

            $response_body = json_decode(wp_remote_retrieve_body($response), true);

            $this->log('Create payment response', array(
                'order_id' => $order_id,
                'body'     => $response_body,
            ));

            if (isset($response_body['status']) && $response_body['status'] === 'merchant_not_found') {
                wc_add_notice(__('بيانات التاجر غير صحيحة.', 'qistass-pay'), 'error');
                return;
            }

            if (isset($response_body['status'], $response_body['redirect_url']) && $response_body['status'] === 'payment_created') {
                $callback_url = $this->get_callback_url($order);
                $redirect_url = $response_body['redirect_url'] . '&callback=' . rawurlencode($callback_url);

                $order->update_status('pending', __('تم إنشاء طلب دفع عبر Qistass Pay بانتظار إتمام العميل للعملية.', 'qistass-pay'));
                $order->update_meta_data('_qistass_callback_url', $callback_url);
                $order->save();

                return array(
                    'result'   => 'success',
                    'redirect' => $redirect_url,
                );
            }

            wc_add_notice(__('حدث خطأ غير متوقع أثناء إنشاء طلب الدفع.', 'qistass-pay'), 'error');
            return;
        }

        protected function verify_payment_with_api($transaction_id, $order) {
            $verify_endpoint = $this->api_base_url . '/api/v1/payment-verification';

            $verify_body = array(
                'public_key'      => $this->public_key,
                'secret_key'      => $this->secret_key,
                'merchant_number' => $this->merchant_number,
                'transaction_id'  => $transaction_id,
            );

            $verify_response = wp_remote_post($verify_endpoint, array(
                'method'  => 'POST',
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
                'body'    => wp_json_encode($verify_body),
            ));

            if (is_wp_error($verify_response)) {
                $this->log('Verification failed (Network)', array(
                    'error' => $verify_response->get_error_message(),
                ), 'error');

                return array(
                    'success' => false,
                    'reason'  => 'network_error',
                );
            }

            $verify_data = json_decode(wp_remote_retrieve_body($verify_response), true);

            $this->log('Verification API response', array(
                'response' => $verify_data,
            ));

            $is_paid         = isset($verify_data['payment_record']['is_paid']) ? (int) $verify_data['payment_record']['is_paid'] : 0;
            $verified_amount = isset($verify_data['payment_record']['amount']) ? (float) $verify_data['payment_record']['amount'] : null;
            $order_amount    = (float) $order->get_total();

            if ($is_paid === 1 && $verified_amount !== null && abs($verified_amount - $order_amount) < 0.01) {
                return array(
                    'success' => true,
                    'reason'  => 'verified',
                    'data'    => $verify_data,
                );
            }

            return array(
                'success' => false,
                'reason'  => 'not_verified',
                'data'    => $verify_data,
            );
        }

        public function handle_callback() {
            $order_id       = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
            $order_key      = isset($_GET['order_key']) ? sanitize_text_field(wp_unslash($_GET['order_key'])) : '';
            $transaction_id = isset($_GET['transaction_id']) ? sanitize_text_field(wp_unslash($_GET['transaction_id'])) : '';

            $order = wc_get_order($order_id);

            if (!$order || $order->get_order_key() !== $order_key) {
                $this->log('Invalid callback order identity', array(
                    'order_id' => $order_id,
                ), 'error');

                wp_die(esc_html__('طلب غير صالح أو تعذر التحقق من هويته.', 'qistass-pay'));
            }

            if ($order->is_paid()) {
                wp_redirect($this->get_return_url($order));
                exit;
            }

            if (empty($transaction_id)) {
                $order->update_status('on-hold', __('عاد العميل من البوابة بدون إتمام الدفع (لا يوجد Transaction ID).', 'qistass-pay'));
                wp_redirect($this->get_return_url($order));
                exit;
            }

            $result = $this->verify_payment_with_api($transaction_id, $order);

            if ($result['success']) {
                $order->payment_complete($transaction_id);
                $order->add_order_note(sprintf(__('تم الدفع بنجاح عبر Qistass Pay. رقم العملية: %s', 'qistass-pay'), $transaction_id));

                if (WC()->cart) {
                    WC()->cart->empty_cart();
                }

                wp_redirect($this->get_return_url($order));
                exit;
            }

            $order->update_status('on-hold', __('تعذر تأكيد الدفع فورًا. سيتم التحقق من العملية تلقائيًا.', 'qistass-pay'));
            wp_redirect($this->get_return_url($order));
            exit;
        }

        public function handle_webhook() {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                status_header(405);
                exit('Method Not Allowed');
            }

            $raw_body = file_get_contents('php://input');

            if (empty($raw_body)) {
                status_header(400);
                exit('Empty body');
            }

            if (!$this->is_valid_webhook_signature($raw_body)) {
                $this->log('Invalid webhook signature', array(), 'error');
                status_header(403);
                exit('Invalid signature');
            }

            $data = json_decode($raw_body, true);

            if (!is_array($data)) {
                status_header(400);
                exit('Invalid JSON');
            }

            $this->log('Webhook received', array(
                'payload' => $data,
            ));

            $order_id       = isset($data['order_id']) ? absint($data['order_id']) : 0;
            $transaction_id = isset($data['transaction_id']) ? sanitize_text_field(wp_unslash($data['transaction_id'])) : '';

            if (!$order_id || !$transaction_id) {
                status_header(400);
                exit('Bad Request: Missing Order ID or Transaction ID');
            }

            $order = wc_get_order($order_id);

            if (!$order) {
                status_header(404);
                exit('Order Not Found');
            }

            if ($order->is_paid()) {
                status_header(200);
                exit('Order Already Paid');
            }

            $result = $this->verify_payment_with_api($transaction_id, $order);

            if ($result['success']) {
                $order->payment_complete($transaction_id);
                $order->add_order_note(sprintf(__('تم الدفع بنجاح عبر إشعار الـ Webhook من Qistass Pay. رقم العملية: %s', 'qistass-pay'), $transaction_id));
                status_header(200);
                exit('Payment Confirmed via Webhook');
            }

            $order->update_status('on-hold', __('وصل إشعار دفع لكن تعذر التحقق النهائي من العملية.', 'qistass-pay'));
            status_header(400);
            exit('Verification Failed');
        }
    }
}

add_filter('woocommerce_payment_gateways', 'qistass_add_gateway_class');

function qistass_add_gateway_class($gateways) {
    $gateways[] = 'WC_Gateway_Qistass_Pay';
    return $gateways;
}
