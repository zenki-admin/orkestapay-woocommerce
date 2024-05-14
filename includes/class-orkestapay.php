<?php

/**
 * OrkestaPay_Gateway class.
 *
 * @extends WC_Payment_Gateway
 */

if (!defined('ABSPATH')) {
    exit();
}

class OrkestaPay_Gateway extends WC_Payment_Gateway
{
    protected $test_mode = true;
    protected $client_id;
    protected $client_secret;
    protected $plugin_version = '0.3.3';

    const STATUS_COMPLETED = 'COMPLETED';

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
        $this->client_id = $this->settings['client_id'];
        $this->client_secret = $this->settings['client_secret'];

        OrkestaPay_API::set_client_id($this->client_id);
        OrkestaPay_API::set_client_secret($this->client_secret);

        if ($this->test_mode) {
            $this->description .= __('TEST MODE ENABLED. In test mode, you can use the card number 4242424242424242 with any CVC and a valid expiration date.', 'orkestapay');
        }

        add_action('wp_enqueue_scripts', [$this, 'payment_scripts']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('woocommerce_api_orkesta_create_checkout', [$this, 'orkesta_create_checkout']);
        add_action('woocommerce_api_orkesta_return_url', [$this, 'orkesta_return_url']);
        // This action hook saves the settings
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
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
                'title' => __('Description', 'orkestapay'),
                'type' => 'textarea',
                'description' => __('Payment method description that the customer will see on your website.', 'orkestapay'),
                'default' => __('Pay with your credit or debit card.', 'orkestapay'),
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
        wp_enqueue_style('orkesta_admin_style', plugins_url('assets/css/orkestapay-admin-style.css', ORKESTAPAY_WC_PLUGIN_FILE), [], $this->plugin_version);

        $this->logo = plugins_url('assets/images/orkestapay.svg', ORKESTAPAY_WC_PLUGIN_FILE);

        include_once dirname(__DIR__) . '/templates/admin.php';
    }

    public function process_admin_options()
    {
        $settings = new WC_Admin_Settings();

        $post_data = $this->get_post_data();
        $client_id = $post_data['woocommerce_' . $this->id . '_client_id'];
        $client_secret = $post_data['woocommerce_' . $this->id . '_client_secret'];

        $this->settings['title'] = $post_data['woocommerce_' . $this->id . '_title'];
        $this->settings['description'] = $post_data['woocommerce_' . $this->id . '_description'];
        $this->settings['test_mode'] = $post_data['woocommerce_' . $this->id . '_test_mode'] == '1' ? 'yes' : 'no';
        $this->settings['enabled'] = $post_data['woocommerce_' . $this->id . '_enabled'] == '1' ? 'yes' : 'no';
        $this->test_mode = $post_data['woocommerce_' . $this->id . '_test_mode'] == '1';

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

        $orkesta_checkout_url = esc_url(WC()->api_request_url('orkesta_create_checkout'));
        $apiHost = $this->getApiHost();

        $payment_args = [
            'orkestapay_api_url' => $apiHost,
            'plugin_payment_gateway_id' => $this->id,
            'orkesta_checkout_url' => $orkesta_checkout_url,
        ];

        wp_enqueue_script('orkestapay_payment_js', plugins_url('assets/js/orkestapay-payment.js', ORKESTAPAY_WC_PLUGIN_FILE), ['jquery'], $this->plugin_version, true);
        wp_enqueue_style('orkestapay_checkout_style', plugins_url('assets/css/orkestapay-checkout-style.css', ORKESTAPAY_WC_PLUGIN_FILE), [], $this->plugin_version, 'all');
        wp_localize_script('orkestapay_payment_js', 'orkestapay_payment_args', $payment_args);
    }

    public function payment_fields()
    {
        $apiHost = $this->getApiHost();
        $this->brands = OrkestaPay_API::retrieve("$apiHost/v1/merchants/providers/brands");

        include_once dirname(__DIR__) . '/templates/payment.php';
    }

    /**
     * Process payment at checkout
     *
     * @return int $order_id
     */
    public function process_payment($order_id)
    {
        global $woocommerce;

        $order = wc_get_order($order_id);

        // Mark as on-hold (we're awaiting confirmation)
        $order->set_status('on-hold');
        $order->save();

        // Remove cart
        $woocommerce->cart->empty_cart();

        // Return thankyou redirect
        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        ];
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

    public function getApiHost()
    {
        return $this->test_mode ? ORKESTAPAY_API_SAND_URL : ORKESTAPAY_API_URL;
    }

    /**
     * Create checkout. Security is handled by WC.
     *
     */
    public function orkesta_create_checkout()
    {
        header('HTTP/1.1 200 OK');

        // $current_shipping_method = WC()->session->get('chosen_shipping_methods');

        try {
            $cart = WC()->cart;
            $apiHost = $this->getApiHost();
            $orkestaPayCartId = $this->getOrkestaPayCartId();
            $successUrl = esc_url(WC()->api_request_url('orkesta_return_url'));
            $cancelUrl = wc_get_checkout_url();

            $customer = [
                'id' => $cart->get_customer()->get_id(),
                'first_name' => wc_clean(wp_unslash($_POST['billing_first_name'])),
                'last_name' => wc_clean(wp_unslash($_POST['billing_last_name'])),
                'email' => wc_clean(wp_unslash($_POST['billing_email'])),
                'phone' => isset($_POST['billing_phone']) ? wc_clean(wp_unslash($_POST['billing_phone'])) : '',
                'billing_address_1' => wc_clean(wp_unslash($_POST['billing_address_1'])),
                'billing_address_2' => wc_clean(wp_unslash($_POST['billing_address_2'])),
                'billing_city' => wc_clean(wp_unslash($_POST['billing_city'])),
                'billing_state' => wc_clean(wp_unslash($_POST['billing_state'])),
                'billing_postcode' => wc_clean(wp_unslash($_POST['billing_postcode'])),
                'billing_country' => wc_clean(wp_unslash($_POST['billing_country'])),
            ];

            $checkoutDTO = OrkestaPay_Helper::transform_data_4_checkout($customer, $cart, $orkestaPayCartId, $successUrl, $cancelUrl);
            $orkestaCheckout = OrkestaPay_API::request($checkoutDTO, "$apiHost/v1/checkouts");

            // Redirect to the thank you page
            wp_send_json_success([
                'checkout_redirect_url' => $orkestaCheckout->checkout_redirect_url,
            ]);

            die();
        } catch (Exception $e) {
            OrkestaPay_Logger::error('#orkesta_create_checkout', ['error' => $e->getMessage()]);

            wp_send_json_error(
                [
                    'result' => 'fail',
                    'message' => $e->getMessage(),
                ],
                400
            );

            die();
        }
    }

    public function orkesta_return_url()
    {
        $cart = WC()->cart;
        if ($cart->is_empty()) {
            wp_safe_redirect(wc_get_cart_url());
            exit();
        }

        $orkestapayOrderId = isset($_GET['order_id']) ? $_GET['order_id'] : $_GET['orderId'];
        OrkestaPay_Logger::log('#orkesta_return_url', ['orkestapay_order_id' => $orkestapayOrderId]);

        $apiHost = $this->getApiHost();
        $orkestaOrder = OrkestaPay_API::retrieve("$apiHost/v1/orders/$orkestapayOrderId");

        $shipping_cost = $cart->get_shipping_total();
        $current_shipping_method = WC()->session->get('chosen_shipping_methods');
        $shipping_label = $this->getShippingLabel();

        // create shipping object
        $shipping = new WC_Order_Item_Shipping();
        $shipping->set_method_title($shipping_label);
        $shipping->set_method_id($current_shipping_method[0]); // set an existing Shipping method ID
        $shipping->set_total($shipping_cost); // set the cost of shipping

        $customer = $cart->get_customer();
        $order = wc_create_order();
        $order->set_customer_id(get_current_user_id());

        // Se agregan los productos al pedido
        foreach ($cart->get_cart() as $item) {
            $product = wc_get_product($item['product_id']);
            $order->add_product($product, $item['quantity']);
        }

        // Se agrega el costo de envío
        $order->add_item($shipping);
        $order->calculate_totals();

        $order->set_payment_method($this->id);
        $order->set_payment_method_title($this->title);

        // Direcciones de envío y facturación
        $order->set_address($customer->get_billing(), 'billing');
        $order->set_address($customer->get_shipping(), 'shipping');

        if ($orkestaOrder->status === self::STATUS_COMPLETED) {
            $order->payment_complete();
        } else {
            // awaiting  confirmation
            $order->set_status('on-hold');
            $order->save();
        }

        update_post_meta($order->get_id(), '_orkestapay_order_id', $orkestaOrder->order_id);

        // Remove cart
        $cart->empty_cart();

        wp_safe_redirect($this->get_return_url($order));
        exit();
    }

    public function getOrkestaPayCartId()
    {
        $bytes = random_bytes(16);
        return bin2hex($bytes);
    }

    private function getShippingLabel()
    {
        $shipping_methods = WC()->shipping->get_shipping_methods();
        $current_shipping_method = WC()->session->get('chosen_shipping_methods');
        $shipping_method = explode(':', $current_shipping_method[0]);
        $selected_shipping_method = $shipping_methods[$shipping_method[0]];

        return $selected_shipping_method->method_title;
    }

    private function getShippingCost($product)
    {
        $shipping_class_id = $product->get_shipping_class_id();
        $shipping_class = $product->get_shipping_class();
        $fee = 0;

        if ($shipping_class_id) {
            $flat_rates = get_option('woocommerce_flat_rates');
            $fee = $flat_rates[$shipping_class]['cost'];
        }

        $flat_rate_settings = get_option('woocommerce_flat_rate_settings');

        return $flat_rate_settings['cost_per_order'] + $fee;
    }
}
?>
