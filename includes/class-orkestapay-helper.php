<?php
if (!defined('ABSPATH')) {
    exit();
}

/**
 * OrkestaPay_Helper class.
 *
 */
class OrkestaPay_Helper
{
    const PAYMENT_METHOD_CARD = 'CARD';
    const PAYMENT_METHOD_ALIAS = 'Default card';

    /**
     * Description
     *
     * @return array
     */
    public static function transform_data_4_customers($order)
    {
        $customerData = [
            'external_id' => "{$order->get_user_id()}",
            'name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'phone' => $order->get_billing_phone(),
            'country' => $order->get_billing_country(),
        ];

        return $customerData;
    }

    /**
     * Description
     *
     * @return array
     */
    public static function transform_data_4_orders($orkestaCustomerId, $order)
    {
        $products = [];
        foreach ($order->get_items() as $item) {
            $product = wc_get_product($item->get_product_id());
            $name = trim(preg_replace('/[[:^print:]]/', '', strip_tags($product->get_title())));
            $desc = trim(preg_replace('/[[:^print:]]/', '', strip_tags($product->get_description())));
            $thumbnailUrl = wp_get_attachment_image_url($product->get_image_id());

            $products[] = [
                'id' => "{$item->get_product_id()}",
                'name' => $name,
                'description' => substr($desc, 0, 250),
                'quantity' => $item->get_quantity(),
                'unit_price' => $item->get_subtotal(),
                'thumbnail_url' => $thumbnailUrl,
            ];
        }

        $orderData = [
            'merchant_order_id' => "{$order->get_id()}",
            'customer_id' => $orkestaCustomerId,
            'currency' => $order->get_currency(),
            'subtotal_amount' => $order->get_subtotal(),
            'order_country' => $order->get_billing_country(),
            'additional_charges' => [
                'shipment' => $order->get_shipping_total(),
                'taxes' => $order->get_total_tax(),
            ],
            'discounts' => [
                'promo_discount' => $order->get_discount_total(),
            ],
            'total_amount' => $order->get_total(),
            'products' => $products,
            'shipping_address' => [
                'first_name' => $order->get_shipping_first_name(),
                'last_name' => $order->get_shipping_last_name(),
                'email' => $order->get_billing_email(),
                'address' => [
                    'line_1' => $order->get_shipping_address_1(),
                    'line_2' => $order->get_shipping_address_2(),
                    'city' => $order->get_shipping_city(),
                    'state' => $order->get_shipping_state(),
                    'country' => $order->get_shipping_country(),
                    'zip_code' => $order->get_shipping_postcode(),
                ],
            ],
            'billing_address' => [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'address' => [
                    'line_1' => $order->get_billing_address_1(),
                    'line_2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'country' => $order->get_billing_country(),
                    'zip_code' => $order->get_billing_postcode(),
                ],
            ],
        ];

        return $orderData;
    }

    /**
     * Description
     *
     * @return array
     */
    public static function transform_data_4_payment_method($order, $creditCard)
    {
        $paymentMethodData = [
            'type' => self::PAYMENT_METHOD_CARD,
            'card' => [
                'holder_name' => $order->get_billing_first_name(),
                'holder_last_name' => $order->get_billing_last_name(),
                'number' => $creditCard['card_number'],
                'expiration_month' => $creditCard['exp_month'],
                'expiration_year' => $creditCard['exp_year'],
                'cvv' => intval($creditCard['cvv']),
                'one_time_use' => true,
            ],
            'billing_address' => [
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'address' => [
                    'line_1' => $order->get_billing_address_1(),
                    'line_2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'country' => $order->get_billing_country(),
                    'zip_code' => $order->get_billing_postcode(),
                ],
            ],
        ];

        return $paymentMethodData;
    }

    /**
     * Description
     *
     * @return array
     */
    public static function transform_data_4_payment($orkestaPaymentMethodId, $order, $deviceSessionId, $orkestaCardCvc)
    {
        $paymentData = [
            'payment_method' => $orkestaPaymentMethodId,
            'payment_amount' => [
                'amount' => $order->get_total(),
                'currency' => $order->get_currency(),
            ],
            'device_info' => [
                'device_session_id' => $deviceSessionId,
            ],
            'payment_options' => [
                'capture' => true,
                'cvv' => $orkestaCardCvc,
            ],
            'metadata' => [
                'merchant_order_id' => "{$order->get_id()}",
            ],
        ];

        return $paymentData;
    }

    /**
     * Description
     *
     * @return array
     */
    public static function transform_data_4_checkout($customer, $cart, $orketaPayCartId, $successUrl, $cancelUrl)
    {
        $products = [];

        foreach ($cart->get_cart() as $item) {
            $product = wc_get_product($item['product_id']);
            $name = trim(preg_replace('/[[:^print:]]/', '', strip_tags($product->get_title())));
            $desc = trim(preg_replace('/[[:^print:]]/', '', strip_tags($product->get_short_description())));
            $thumbnailUrl = wp_get_attachment_image_url($product->get_image_id());

            $products[] = [
                'id' => "{$product->get_id()}",
                'name' => $name,
                'description' => substr($desc, 0, 250),
                'quantity' => $item['quantity'],
                'unit_price' => wc_get_price_excluding_tax($product),
                'thumbnail_url' => $thumbnailUrl,
            ];
        }

        // Definir la fecha futura (en este ejemplo, 1 hora en el futuro)
        $expiresAt = strtotime('+1 hour') * 1000; // Convertir a milisegundos

        $checkoutData = [
            'expires_at' => $expiresAt,
            'completed_redirect_url' => $successUrl,
            'canceled_redirect_url' => $cancelUrl,
            'order' => [
                'merchant_order_id' => $orketaPayCartId,
                'currency' => get_woocommerce_currency(),
                'subtotal_amount' => $cart->get_subtotal(),
                'order_country' => $customer['billing_country'],
                'additional_charges' => [
                    'shipment' => $cart->get_shipping_total(),
                    'taxes' => $cart->get_taxes_total(),
                ],
                'discounts' => [
                    'promo_discount' => $cart->get_discount_total(),
                ],
                'total_amount' => $cart->total,
                'products' => $products,
                'customer' => [
                    'external_id' => $customer['id'],
                    'name' => $customer['first_name'],
                    'last_name' => $customer['last_name'],
                    'email' => $customer['email'],
                    'phone' => $customer['phone'],
                ],
                'shipping_address' => [
                    'first_name' => $customer['first_name'],
                    'last_name' => $customer['last_name'],
                    'email' => $customer['email'],
                    'address' => [
                        'line_1' => $customer['billing_address_1'],
                        'line_2' => $customer['billing_address_2'],
                        'city' => $customer['billing_city'],
                        'state' => $customer['billing_state'],
                        'country' => $customer['billing_country'],
                        'zip_code' => $customer['billing_postcode'],
                    ],
                ],
                'billing_address' => [
                    'first_name' => $customer['first_name'],
                    'last_name' => $customer['last_name'],
                    'email' => $customer['email'],
                    'address' => [
                        'line_1' => $customer['billing_address_1'],
                        'line_2' => $customer['billing_address_2'],
                        'city' => $customer['billing_city'],
                        'state' => $customer['billing_state'],
                        'country' => $customer['billing_country'],
                        'zip_code' => $customer['billing_postcode'],
                    ],
                ],
                'config' => [
                    'use_3ds' => true,
                ],
            ],
        ];

        // Si no existe un ID, se remueve el índice
        if ($cart->get_customer()->get_id() === 0) {
            unset($checkoutData['order']['customer']['external_id']);
        }

        return $checkoutData;
    }

    public static function remove_string_spaces($text)
    {
        return preg_replace('/\s+/', '', wc_clean($text));
    }

    public static function get_expiration_month($exp_date)
    {
        $exp_date = self::remove_string_spaces($exp_date);
        $exp_date = explode('/', $exp_date);
        return $exp_date[0];
    }

    public static function get_expiration_year($exp_date)
    {
        $exp_date = self::remove_string_spaces($exp_date);
        $exp_date = explode('/', $exp_date);
        return $exp_date[1];
    }

    public static function is_null_or_empty_string($string)
    {
        return !isset($string) || trim($string) === '';
    }

    public static function get_signature_from_url($url)
    {
        $url_components = explode('&signature=', $url);
        return $url_components[1];
    }
}
