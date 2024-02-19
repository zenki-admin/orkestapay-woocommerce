<?php

/**
 * OrkestaPay_Gateway class.
 *
 * @extends WC_Payment_Gateway
 */

if (!defined('ABSPATH')) {
    exit();
}

if (!class_exists('Svix')) {
    require_once dirname(__DIR__) . '/lib/svix/init.php';
}

class OrkestaPay_Gateway extends WC_Payment_Gateway
{
    const PAYMENT_ACTION_REQUIRED = 'PAYMENT_ACTION_REQUIRED';

    protected $test_mode = true;
    protected $merchant_id;
    protected $client_id;
    protected $client_secret;
    protected $device_key;
    protected $whsec;
    protected $plugin_version = '0.1.0';

    public function __construct()
    {
        $this->id = 'orkestapay'; // Payment gateway plugin ID
        $this->method_title = __('OrkestaPay', 'orkestapay');
        $this->method_description = __('Orchestrate multiple payment gateways for a frictionless, reliable, and secure checkout experience.', 'orkestapay');
        $this->has_fields = true;
        $this->supports = ['products', 'refunds', 'tokenization', 'add_payment_method'];

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->settings['title'];
        $this->description = $this->settings['description'];

        $this->enabled = $this->settings['enabled'];
        $this->test_mode = strcmp($this->settings['test_mode'], 'yes') == 0;
        $this->merchant_id = $this->settings['merchant_id'];
        $this->client_id = $this->settings['client_id'];
        $this->client_secret = $this->settings['client_secret'];
        $this->device_key = $this->settings['device_key'];
        $this->whsec = $this->settings['whsec'];

        OrkestaPay_API::set_client_id($this->client_id);
        OrkestaPay_API::set_client_secret($this->client_secret);

        if ($this->test_mode) {
            $this->description .= __('TEST MODE ENABLED. In test mode, you can use the card number 4242424242424242 with any CVC and a valid expiration date.', 'orkestapay');
        }

        add_action('wp_enqueue_scripts', [$this, 'payment_scripts']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('woocommerce_api_orkesta_get_access_token', [$this, 'orkesta_get_access_token']);
        add_action('woocommerce_api_' . strtolower(get_class($this)), [$this, 'webhookHandler']);

        // This action hook saves the settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);

        wp_enqueue_style('orkestapay_style', plugins_url('assets/css/styles.css', ORKESTAPAY_WC_PLUGIN_FILE), [], $this->plugin_version);
    }

    public function webhookHandler()
    {
        $payload = file_get_contents('php://input');
        OrkestaPay_Logger::log('#webhook', ['payload' => $payload]);

        $headers = apache_request_headers();
        $svix_headers = [];
        foreach ($headers as $key => $value) {
            $header = strtolower($key);
            $svix_headers[$header] = $value;
        }

        try {
            $secret = $this->whsec;
            $wh = new \Svix\SvixWebhook($secret);
            $json = $wh->verify($payload, $svix_headers);

            if ($json->eventType !== 'order.update') {
                header('HTTP/1.1 400 Bad Request');
                header('Content-type: application/json');
                echo json_encode(['error' => true, 'message' => 'Event type is not order.update.']);
                exit();
            }

            $payment = $json->data;
            if ($payment->status != 'COMPLETED') {
                header('HTTP/1.1 400 Bad Request');
                header('Content-type: application/json');
                echo json_encode(['error' => true, 'message' => 'Transaction status is not completed.']);
                exit();
            }

            $order = new WC_Order($payment->merchantOrderId);
            OrkestaPay_Logger::log('#webhook', ['order_status' => $order->get_status()]);

            if ($order->get_status() == 'processing' || $order->get_status() == 'completed') {
                $order->payment_complete();
                $order->add_order_note(sprintf("%s payment completed with Order Id of '%s'", $this->method_title, $payment->orderId));
                // wc_reduce_stock_levels($order_id);
            }
        } catch (Exception $e) {
            OrkestaPay_Logger::error('#webhook', ['error' => $e->getMessage()]);

            header('HTTP/1.1 500 Internal Server Error');
            header('Content-type: application/json');
            echo json_encode(['error' => true, 'message' => $e->getMessage()]);
            exit();
        }

        header('HTTP/1.1 200 OK');
        header('Content-type: application/json');
        echo json_encode(['success' => true]);
        exit();
    }

    /**
     * Plugin options
     */
    public function init_form_fields()
    {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable OrkestaPay', 'orkestapay'),
                'label' => __('Enable', 'orkestapay'),
                'type' => 'checkbox',
                'default' => 'no',
                'description' => __('Check the box to enable Orkesta as a payment method.', 'orkestapay'),
            ],
            'test_mode' => [
                'title' => __('Enable test mode', 'orkestapay'),
                'label' => __('Enable', 'orkestapay'),
                'type' => 'checkbox',
                'default' => 'yes',
                'description' => __('Check the box to make test payments.', 'orkestapay'),
            ],
            'title' => [
                'title' => __('Title', 'orkestapay'),
                'type' => 'text',
                'default' => __('Credit Card', 'orkestapay'),
                'description' => __('Payment method title that the customer will see on your checkout.', 'orkestapay'),
            ],
            'description' => [
                'title' => __('Description', 'woocommerce'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your website.', 'orkestapay'),
                'default' => __('Pay with your credit or debit card.', 'orkestapay'),
            ],
            'merchant_id' => [
                'title' => __('Merchant ID', 'orkestapay'),
                'type' => 'text',
                'default' => '',
                'custom_attributes' => [
                    'autocomplete' => 'off',
                    'aria-autocomplete' => 'none',
                ],
            ],
            'client_id' => [
                'title' => __('Access Key', 'orkestapay'),
                'type' => 'text',
                'default' => '',
                'custom_attributes' => [
                    'autocomplete' => 'off',
                    'aria-autocomplete' => 'none',
                    'role' => 'presentation',
                ],
            ],
            'client_secret' => [
                'title' => __('Secret Key', 'orkestapay'),
                'type' => 'password',
                'default' => '',
                'custom_attributes' => [
                    'autocomplete' => 'off',
                    'aria-autocomplete' => 'none',
                    'role' => 'presentation',
                ],
            ],
            'device_key' => [
                'title' => __('Device Key', 'orkestapay'),
                'type' => 'text',
                'default' => '',
                'custom_attributes' => [
                    'autocomplete' => 'off',
                    'aria-autocomplete' => 'none',
                ],
            ],
            'whsec' => [
                'title' => __('Webhook Signing Secret', 'orkestapay'),
                'type' => 'password',
                'default' => '',
                'description' => __('This secret is required to verify payment notifications.', 'orkestapay'),
                'custom_attributes' => [
                    'autocomplete' => 'off',
                    'aria-autocomplete' => 'none',
                    'role' => 'presentation',
                ],
            ],
        ];
    }

    /**
     * Handles admin notices
     *
     * @return void
     */
    public function admin_notices()
    {
        if ('no' == $this->enabled) {
            return;
        }

        /**
         * Check if WC is installed and activated
         */
        if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
            // WooCommerce is NOT enabled!
            echo wp_kses_post('<div class="error"><p>');
            echo esc_html_e('Orkesta needs WooCommerce plugin is installed and activated to work.', 'orkestapay');
            echo wp_kses_post('</p></div>');
            return;
        }
    }

    function admin_options()
    {
        wp_enqueue_style('font_montserrat', 'https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600&display=swap', ORKESTAPAY_WC_PLUGIN_FILE, [], $this->plugin_version);
        wp_enqueue_style('orkesta_admin_style', plugins_url('assets/css/admin-style.css', ORKESTAPAY_WC_PLUGIN_FILE), [], $this->plugin_version);

        $this->logo = plugins_url('assets/images/orkestapay.svg', ORKESTAPAY_WC_PLUGIN_FILE);

        include_once dirname(__DIR__) . '/templates/admin.php';
    }

    public function process_admin_options()
    {
        $settings = new WC_Admin_Settings();

        $post_data = $this->get_post_data();
        $client_id = $post_data['woocommerce_' . $this->id . '_client_id'];
        $client_secret = $post_data['woocommerce_' . $this->id . '_client_secret'];
        $merchant_id = $post_data['woocommerce_' . $this->id . '_merchant_id'];
        $device_key = $post_data['woocommerce_' . $this->id . '_device_key'];
        $whsec = $post_data['woocommerce_' . $this->id . '_whsec'];

        $this->settings['title'] = $post_data['woocommerce_' . $this->id . '_title'];
        $this->settings['description'] = $post_data['woocommerce_' . $this->id . '_description'];
        $this->settings['merchant_id'] = $merchant_id;
        $this->settings['device_key'] = $device_key;
        $this->settings['whsec'] = $whsec;
        $this->settings['test_mode'] = $post_data['woocommerce_' . $this->id . '_test_mode'] == '1' ? 'yes' : 'no';
        $this->settings['enabled'] = $post_data['woocommerce_' . $this->id . '_enabled'] == '1' ? 'yes' : 'no';
        $this->test_mode = $post_data['woocommerce_' . $this->id . '_test_mode'] == '1';

        if ($merchant_id == '' || $device_key == '' || $whsec == '') {
            $this->settings['enabled'] = 'no';
            $settings->add_error('You need to enter all your credentials if you want to use this plugin.');
            return;
        }

        if (!$this->validateOrkestaCredentials($client_id, $client_secret)) {
            $this->settings['enabled'] = 'no';
            $this->settings['client_id'] = '';
            $this->settings['client_secret'] = '';

            $settings->add_error(__('Provided credentials are invalid.', 'orkestapay'));
        } else {
            $this->settings['client_id'] = $client_id;
            $this->settings['client_secret'] = $client_secret;
            $settings->add_message(__('Configuration completed successfully.', 'orkestapay'));
        }

        return update_option($this->get_option_key(), apply_filters('woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings), 'yes');
    }

    /**
     * Loads (enqueue) static files (js & css) for the checkout page
     *
     * @return void
     */
    public function payment_scripts()
    {
        if (!is_checkout()) {
            return;
        }

        $orkesta_customer_id = $this->getOrkestaCustomerId();
        $get_access_token_url = esc_url(WC()->api_request_url('orkesta_get_access_token'));

        $payment_args = [
            'orkestapay_api_url' => $this->getApiHost(),
            'plugin_payment_gateway_id' => $this->id,
            'orkestapay_customer_id' => $orkesta_customer_id,
            'merchant_id' => $this->merchant_id,
            'device_key' => $this->device_key,
            'get_access_token_url' => $get_access_token_url,
        ];

        wp_enqueue_script('orkestapay_js_resource', ORKESTAPAY_JS_URL . '/script/orkestapay.js', [], $this->plugin_version, true);
        wp_enqueue_script('orkestapay_payment_js', plugins_url('assets/js/orkesta-payment.js', ORKESTAPAY_WC_PLUGIN_FILE), ['jquery'], $this->plugin_version, true);
        wp_enqueue_style('orkestapay_checkout_style', plugins_url('assets/css/checkout-style.css', ORKESTAPAY_WC_PLUGIN_FILE), [], $this->plugin_version, 'all');
        wp_localize_script('orkestapay_payment_js', 'orkestapay_payment_args', $payment_args);
    }

    public function payment_fields()
    {
        wp_enqueue_script('wc-credit-card-form');

        $this->images_dir = plugins_url('assets/images/', ORKESTAPAY_WC_PLUGIN_FILE);

        include_once dirname(__DIR__) . '/templates/payment.php';
    }

    public function validate_fields()
    {
        if (empty($_POST['orkesta_device_session_id']) || empty($_POST['orkesta_customer_id']) || empty($_POST['orkesta_payment_method_id'])) {
            wc_add_notice('Some Orkesta ID is missing.', 'error');
            return false;
        }

        return true;
    }

    /**
     * Process payment at checkout
     *
     * @return int $order_id
     */
    public function process_payment($order_id)
    {
        global $woocommerce;
        $apiHost = $this->getApiHost();
        $deviceSessionId = wc_clean($_POST['orkesta_device_session_id']);
        $paymentMethodId = wc_clean($_POST['orkesta_payment_method_id']);
        $customerId = wc_clean($_POST['orkesta_customer_id']);
        $orkestaCardCvc = wc_clean($_POST['orkesta_card_cvc']);

        $order = wc_get_order($order_id);

        try {
            $orderDTO = OrkestaPay_Helper::transform_data_4_orders($customerId, $order);
            $orkestaOrder = OrkestaPay_API::request($orderDTO, "$apiHost/v1/orders");

            $this->saveOrkestaCustomerId($customerId);

            $paymentDTO = OrkestaPay_Helper::transform_data_4_payment($paymentMethodId, $order, $deviceSessionId, $orkestaCardCvc);
            $orkestaPayment = OrkestaPay_API::request($paymentDTO, "$apiHost/v1/orders/{$orkestaOrder->order_id}/payments");

            if ($orkestaPayment->status !== 'COMPLETED') {
                throw new Exception(__('Payment Failed.', 'orkestapay'));
            }

            // COMPLETED - we received the payment
            $order->payment_complete();
            $order->add_order_note(sprintf("%s payment completed with Transaction Id of '%s'", $this->method_title, $orkestaPayment->id));

            update_post_meta($order->get_id(), '_orkesta_order_id', $orkestaOrder->order_id);
            update_post_meta($order->get_id(), '_orkesta_payment_id', $orkestaPayment->id);

            // Remove cart
            $woocommerce->cart->empty_cart();

            // Redirect to the thank you page
            return [
                'result' => 'success',
                'redirect' => $this->get_return_url($order),
            ];
        } catch (Exception $e) {
            OrkestaPay_Logger::error('#process_payment', ['error' => $e->getMessage()]);

            $order->add_order_note(sprintf("%s Credit card payment failed with message: '%s'", $this->method_title, $e->getMessage()));
            $order->update_status('failed');
            $order->save();

            wc_add_notice(__('A transaction error occurred. Your credit card has not been charged.', 'orkestapay'), 'error');

            return;
        }
    }

    /**
     * Checks if the Orkesta key is valid
     *
     * @return boolean
     */
    protected function validateOrkestaCredentials($client_id, $client_secret)
    {
        $token_result = OrkestaPay_API::get_access_token($client_id, $client_secret);

        if (!array_key_exists('access_token', $token_result)) {
            OrkestaPay_Logger::error('#validateOrkestaCredentials', ['error' => 'Error al obtener access_token']);

            return false;
        }

        // Se valida que la respuesta sea un JWT
        $regex = preg_match('/^([a-zA-Z0-9_=]+)\.([a-zA-Z0-9_=]+)\.([a-zA-Z0-9_\-\+\/=]*)/', $token_result['access_token']);
        if ($regex !== 1) {
            return false;
        }

        return true;
    }

    private function getOrkestaCustomerId()
    {
        if (!is_user_logged_in()) {
            return null;
        }

        $customerId = get_user_meta(get_current_user_id(), '_orkesta_customer_id', true);
        if (OrkestaPay_Helper::is_null_or_empty_string($customerId)) {
            return null;
        }

        return $customerId;
    }

    private function saveOrkestaCustomerId($orkestaCustomerId)
    {
        if (!is_user_logged_in()) {
            return;
        }

        update_user_meta(get_current_user_id(), '_orkesta_customer_id', $orkestaCustomerId);

        // $existsCustomerId = get_user_meta(get_current_user_id(), '_orkesta_customer_id', true);
        // if (OrkestaPay_Helper::is_null_or_empty_string($existsCustomerId)) {
        //     update_user_meta(get_current_user_id(), '_orkesta_customer_id', $orkestaCustomerId);
        // }

        return;
    }

    /**
     * AJAX - Get access token function
     *
     * @return string
     */
    public function orkesta_get_access_token()
    {
        $token_result = OrkestaPay_API::get_access_token($this->client_id, $this->client_secret);

        if (!array_key_exists('access_token', $token_result)) {
            OrkestaPay_Logger::error('#orkesta_get_access_token', ['error' => 'Error al obtener access_token']);

            wp_send_json_error(
                [
                    'result' => 'fail',
                    'message' => __('An error occurred getting access token.', 'orkestapay'),
                ],
                400
            );
        }

        wp_send_json_success([
            'result' => 'success',
            'access_token' => $token_result['access_token'],
        ]);

        die();
    }

    public function getApiHost()
    {
        return $this->test_mode ? ORKESTAPAY_API_SAND_URL : ORKESTAPAY_API_URL;
    }
}
?>
