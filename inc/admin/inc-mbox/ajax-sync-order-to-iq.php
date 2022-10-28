<?php

/**
 * Sync order to AJAX
 */
add_action('wp_ajax_nopriv_iq_sync_order', 'iq_sync_order');
add_action('wp_ajax_iq_sync_order', 'iq_sync_order');

function iq_sync_order() {

    check_ajax_referer('iq sync woo order to iq');

    // retrieve iq settings
    $settings = maybe_unserialize(get_option('iq_settings'));

    // setup basic auth
    $basic_auth_raw = $settings['user-no'] . ':' . $settings['user-pass'];
    $basic_auth     = base64_encode($basic_auth_raw);
    $auth_string    = 'Basic ' . $basic_auth;

    // setup request url
    $request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Request_GenericSQL';

    // retrieve order id
    $order_id = $_POST['order_id'];

    // retrieve order object
    $order = wc_get_order($order_id);

    // retrieve order user id
    $user_id    = $order->get_customer_id();
    $iq_user_id = 'WWW' . $user_id;

    // setup request payload
    $payload = [
        'IQ_API' => [
            'IQ_API_Request_GenericSQL' => [
                'IQ_Company_Number'     => $settings['company-no'],
                'IQ_Terminal_Number'    => $settings['terminal-no'],
                'IQ_User_Number'        => $settings['user-no'],
                'IQ_User_Password'      => $settings['user-pass-api-key'],
                'IQ_Partner_Passphrase' => !empty($settings['passphrase']) ? $settings['passphrase'] : '',
                "IQ_SQL_Text"           => "SELECT * FROM debtors WHERE account = '$iq_user_id';"
            ]
        ]
    ];

    // check if user is already on iq; if not, offer to sync and bail
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL            => $request_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => array(
            'Authorization: ' . $auth_string,
            'Content-Type: application/json'
        ),
    ));

    $response_json = curl_exec($curl);

    // request successful
    if (false !== $response_json) :

        $response = json_decode($response_json, true);

        // response 429
        if ($response['response_code'] == 429) :
            wp_send_json_error($response['response_message']);
            wp_die();
        endif;

        // if no iq error
        if ($response['iq_api_error'][0]['iq_error_code'] === 0) :

            // if no customer records returned
            if (empty($response['iq_api_result_data']['records'])) :

                // log
                iq_logger('single_order_no_customer', 'The customer for order ID ' . $order_id . ' (Customer ID ' . $iq_user_id . ') does not exist on IQ. Order cannot be synced as a result.', strtotime('now'));

                // send no records message
                wp_send_json_error('The customer for this order (Customer ID ' . $iq_user_id . ') does not exist on IQ. Orders for non-existent users cannot be synced. Please sync this customer to IQ first using the button provided.');

                // die
                wp_die();
            endif;

        elseif ($response['iq_api_error'][0]['iq_error_code'] !== 0) :
        // do nothing
        endif;

    // request failed
    else :

        $error = curl_error($curl);

        wp_send_json('Request to IQ failed with the following error: ' . $error . '. Please reload the page and try again.');

        wp_die();

    endif;

    // setup request url
    $request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Submit_Document_Sales_Order';

    // setup initial payload
    $payload = [
        'IQ_API' => [
            'IQ_API_Submit_Document_Sales_Order' => [
                'IQ_Company_Number'     => $settings['company-no'],
                'IQ_Terminal_Number'    => $settings['terminal-no'],
                'IQ_User_Number'        => $settings['user-no'],
                'IQ_User_Password'      => $settings['user-pass-api-key'],
                'IQ_Partner_Passphrase' => !empty($settings['passphrase']) ? $settings['passphrase'] : '',
                "IQ_Submit_Data"        => [
                    "IQ_Root_JSON" => [
                        "IQ_Identification_Info" => [
                            "Company_Code" => $settings['company-no']
                        ],
                        "Processing_Documents" => [],
                    ]
                ],
                "IQ_Overrides" => ["ideNegativeStock", "ideInvalidDateRange"]
            ]
        ]
    ];

    // retrieve products
    $prods = $order->get_items();

    // file_put_contents(IQ_RETAIL_PATH . 'inc/push/files/orders/order_prods_obj.txt', print_r($prods, true), FILE_APPEND);

    // array to hold order line items
    $order_items = [];

    // calculate pre discount cart total
    $cart_total_no_disc = '';

    foreach ($prods as $prod) :

        // calculate cart total
        $cart_total_no_disc += (float)$prod->get_subtotal();

        $prod_id = $prod->get_product_id();

        // push line items to $order_items array
        $order_items[] = [
            "Stock_Code"  => get_post_meta($prod_id, '_sku', true),
            "Comment"     => $prod->get_name(),
            "Quantity"    => (int)$prod->get_quantity(),
            "Volumetrics" => [
                "Units"           => 0,
                "Volume_Length"   => 0,
                "Volume_Width"    => 0,
                "Volume_Height"   => 0,
                "Volume_Quantity" => 1,
                "Volume_Value"    => 0,
                "Volume_Rounding" => 0
            ],
            "Item_Price_Inclusive" => (float)get_post_meta($prod_id, '_regular_price', true),
            "Item_Price_Exclusive" => (float)number_format(get_post_meta($prod_id, '_regular_price', true) / 1.15, 2, '.', ''),
            "Discount_Percentage"  => 0,
            "Line_Total_Inclusive" => (float)$prod->get_total(),
            "Line_Total_Exclusive" => (float)number_format($prod->get_total() / 1.15, 2, '.', ''),
            "Custom_Cost"          => 0,
            "List_Price"           => (float)get_post_meta($prod_id, '_regular_price', true),
            "Invoiced_Quantity"    => 0
        ];

    endforeach;

    // retrieve shipping
    $shipping = $order->get_items('shipping');
    $shipping_name = '';
    $shipping_cost = '';
    foreach ($shipping as $ship_id => $item) :
        $shipping_name = $item->get_name();
        $shipping_cost = (float)$item->get_total();
    endforeach;

    // push shipping cost to $order_items array
    $order_items[] = [
        "stock_code"        => "H020",
        "stock_description" => "",
        "comment"           => "Shipping cost",
        "quantity"          => 1,
        "volumetrics"       => [
            "units"           => 0,
            "volume_length"   => 0,
            "volume_width"    => 0,
            "volume_height"   => 0,
            "volume_quantity" => 0,
            "volume_value"    => 0,
            "volume_rounding" => 0
        ],
        "item_price_inclusive" => (float)number_format($shipping_cost, 2, '.', ''),
        "item_price_exclusive" => (float)number_format($shipping_cost / 1.15, 2, '.', ''),
        "discount_percentage"  => 0,
        "line_total_inclusive" => (float)number_format($shipping_cost, 2, '.', ''),
        "line_total_exclusive" => (float)number_format($shipping_cost / 1.15, 2, '.', ''),
        "custom_cost"          => 0,
        "list_price"           => (float)number_format($shipping_cost, 2, '.', ''),
        "delcol"               => "",
        "invoiced_quantity"    => 0
    ];

    // retrieve delivery address info
    $deladdy1 = $order->get_shipping_address_1();
    $deladdy2 = $order->get_shipping_address_2();
    $delcity  = $order->get_shipping_city();
    $delprov  = $order->get_shipping_state();
    $delpcode = $order->get_shipping_postcode();
    $delphone = $order->get_shipping_phone();
    $delemail = $order->get_billing_email();

    // retrieve order total
    $order_total = $order->get_total();

    // retrieve discount
    $disc_amount = $order->get_discount_total();

    // work out discount percentage if applicable
    $disc_perc = $disc_amount > 0 ? number_format(1 / ($order_total / $disc_amount) * 100, 2, '.', '') : 0;

    // figure out discount type (coupon vs whatever else)
    $coupons = $order->get_coupons();
    $discount_type = !empty($coupons) ? 'Coupon(s)' : 'Unknown';

    // retrieve order currency
    $currency = $order->get_currency();

    // calculate total VAT
    $vat_amt = $order->get_shipping_country() == 'ZA' ? $order_total - ($order_total / 1.15) : 0.00;

    // vat included or not
    $vat_inc = $order->get_shipping_country() == 'ZA' ? true : false;

    // setup bookpack description if application
    $long_descr = get_post_meta($order_id, 'bookpack_id', true) ? get_the_title(get_post_meta($order_id, 'bookpack_id', true)) : '';

    // setup base order data array
    $base_order_data[] = [
        "Export_Class" => "Sales_Order",
        "Document"     => [
            "Document_Number"              => "",
            "Delivery_Address_Information" => [
                $deladdy1,
                $deladdy2,
                $delcity,
                WC()->countries->get_states($order->get_shipping_country())[$delprov]
            ],
            "Email_Address"             => $delemail,
            "Order_Number"              => $order_id,
            "Delivery_Method"           => $shipping_name,
            "Delivery_Note_Number"      => "",
            "Total_Vat"                 => (float)number_format($vat_amt, 2, '.', ''),
            "Discount_Percentage"       => (float)$disc_perc,
            "Discount_Type"             => $discount_type,
            "Discount_Amount"           => (float)number_format($disc_amount, 2, '.', ''),
            "Long_Description"          => $long_descr,
            "Document_Total"            => (float)number_format($order_total, 2, '.', ''),
            "Total_Number_Of_Items"     => (int)$order->get_item_count('line-item'),
            "Document_Description"      => "",
            "Print_Layout"              => 1,
            "Warehouse"                 => "",
            "Cashier_Number"            => 1,
            "Till_Number"               => 1,
            "Document_Includes_VAT"     => $vat_inc,
            "Currency"                  => $currency,
            "Currency_Rate"             => $currency == 'ZAR' ? 1 : "",
            "Internal_Order_Number"     => "",
            "Store_Department"          => "",
            "Document_Terms"            => "Not Applicable",
            "Telephone_Number"          => $delphone,
            "Postal_Code"               => $delpcode,
            "Extra_Charges_Information" => [
                [
                    "Extra_Charge_Description" => "",
                    "Extra_Charge_Amount"      => 0
                ],
                [
                    "Extra_Charge_Description" => "",
                    "Extra_Charge_Amount"      => 0
                ],
                [
                    "Extra_Charge_Description" => "",
                    "Extra_Charge_Amount"      => 0
                ],
                [
                    "Extra_Charge_Description" => "",
                    "Extra_Charge_Amount"      => 0
                ],
            ],
            "Debtor_Account"              => $iq_user_id,
            "Debtor_Name"                 => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            "Sales_Representative_Number" => 1,
            "Order_Information"           => [
                "Order_Date"             => get_the_date('Y-m-d', $order_id),
                "Expected_Date"          => get_the_date('Y-m-d', $order_id),
                "Credit_Approver_Number" => 0
            ]

        ],
        // product + shipping line items
        "Items" => $order_items
    ];

    // push $base_order_data to Processing_Documents key in $payload
    $payload['IQ_API']['IQ_API_Submit_Document_Sales_Order']['IQ_Submit_Data']['IQ_Root_JSON']['Processing_Documents'] = $base_order_data;

    /**
     * SEND REQUEST
     */
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL            => $request_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'POST',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => array(
            "Authorization: $auth_string",
            'Content-Type: application/json'
        ),
    ));

    $response_json = curl_exec($curl);

    // if request fails, send error message back and log
    if ($response_json === false) :

        $error = curl_error($curl);
        wp_send_json('Connection to IQ failed with the following error: ' . $error);

        iq_logger('single_order_sync_connect_error', 'Connection to IQ failed with the following error (Order ID:' . $order_id . '): ' . $error, strtotime('now'));

    // if request successful, write response to file
    else :

        // decode response
        $response = json_decode($response_json, true);

        // if iq did not return an error
        if ($response['iq_api_error'][0]['iq_error_code'] == 0) :

            // response 429
            if ($response['response_code'] == 429) :
                wp_send_json_error($response['response_message']);
                wp_die();
            endif;

            // find document number 
            $doc_number = $response['iq_api_success']['iq_api_success_items'][0][0]['data'];

            // save document number to order meta
            update_post_meta($order_id, '_iq_doc_number', $doc_number);

            // add order note
            $order->add_order_note('<b>Order successfully synced to IQ. IQ document number:</b><br> ' . $doc_number, 0, false);
            $order->save();

            // log
            iq_logger('single_order_sync_success', 'Order ID ' . $order_id . ' successfully synced to IQ. IQ document number: ' . $doc_number, strtotime('now'));

            // return success message
            wp_send_json('Order ID ' . $order_id . ' successfully synced to IQ. IQ document number: ' . $doc_number . '.');

        // if IQ error returned
        elseif ($response['iq_api_error'][0]['iq_error_code'] != 0) :

            // retrieve, combine and display/log/return error messages
            $error_arr = $response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'][0]['iq_error_extended_data']['iq_root_json']['error_data'][0]['errors'];

            $err_msg = '';

            foreach ($error_arr as $err_data) :
                $err_msg .= $err_data['error_description'];
            endforeach;

            // add order note
            $order->add_order_note('<b>Order automatic sync to IQ failed with the following error(s):</b><br> ' . $err_msg, 0, false);
            $order->save();

            // add log
            iq_logger('single_order_sync_iq_error', 'Single order submission to IQ failed with the follow IQ error(s) for order ' . $order_id . ': ' . $err_msg, strtotime('now'));

            // send error
            wp_send_json_error('Could not sync order ' . $order_id . ' to IQ because of the following error(s): ' . $err_msg);

        endif;

    endif;
    curl_close($curl);

    wp_die();
}
