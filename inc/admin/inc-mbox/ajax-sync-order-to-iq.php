<?php

/**
 * Sync order to AJAX
 */
add_action('wp_ajax_nopriv_iq_sync_order', 'iq_sync_order');
add_action('wp_ajax_iq_sync_order', 'iq_sync_order');

function iq_sync_order() {

    // reset $payload
    $payload     = '';
    $request_url = '';
    $curl        = '';
    $response    = '';
    $response_js = '';

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

    // retrieve order user email; if user does not exist with email, create user
    $user_id = get_post_meta($order_id, '_customer_user', true);

    // if user ID is 0, it means the user hasn't registered, and we need to register the user
    if ($user_id == 0) :

        // retrieve billing email
        $email = $order->get_billing_email();

        // create new customer/user 
        $user_id = wc_create_new_customer($email, '', '', array(
            'first_name' => $order->get_billing_first_name(),
            'last_name'  => $order->get_billing_last_name(),
        ));

        // sync past orders
        wc_update_new_customer_past_orders($user_id);

        // update user's billing data
        update_user_meta($user_id, 'billing_address_1', $order->billing_address_1);
        update_user_meta($user_id, 'billing_address_2', $order->billing_address_2);
        update_user_meta($user_id, 'billing_city', $order->billing_city);
        update_user_meta($user_id, 'billing_company', $order->billing_company);
        update_user_meta($user_id, 'billing_country', $order->billing_country);
        update_user_meta($user_id, 'billing_email', $order->billing_email);
        update_user_meta($user_id, 'billing_first_name', $order->billing_first_name);
        update_user_meta($user_id, 'billing_last_name', $order->billing_last_name);
        update_user_meta($user_id, 'billing_phone', $order->billing_phone);
        update_user_meta($user_id, 'billing_postcode', $order->billing_postcode);
        update_user_meta($user_id, 'billing_state', $order->billing_state);

        // update user's shipping data
        update_user_meta($user_id, 'shipping_address_1', $order->shipping_address_1);
        update_user_meta($user_id, 'shipping_address_2', $order->shipping_address_2);
        update_user_meta($user_id, 'shipping_city', $order->shipping_city);
        update_user_meta($user_id, 'shipping_company', $order->shipping_company);
        update_user_meta($user_id, 'shipping_country', $order->shipping_country);
        update_user_meta($user_id, 'shipping_first_name', $order->shipping_first_name);
        update_user_meta($user_id, 'shipping_last_name', $order->shipping_last_name);
        update_user_meta($user_id, 'shipping_method', $order->shipping_method);
        update_user_meta($user_id, 'shipping_postcode', $order->shipping_postcode);
        update_user_meta($user_id, 'shipping_state', $order->shipping_state);

        // setup custom iq reference
        $iq_user_id = 'WWW' . $user_id;

    // if $user_id exists, format for use with IQ
    else :
        $iq_user_id = 'WWW' . $user_id;
    endif;

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
                iq_logger('order_sync', '[ORDER EDIT SCREEN] The customer for order ID ' . $order_id . ' (Customer ID ' . $iq_user_id . ') does not exist on IQ. Order cannot be synced as a result.', strtotime('now'));

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

    curl_close($curl);

    // reset $payload
    $payload     = '';
    $request_url = '';
    $curl        = '';
    $response    = '';
    $response_js = '';

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
            "Document_Number"              => $order_id,
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

        iq_logger('order_sync', '[ORDER EDIT SCREEN] Connection to IQ failed with the following error (Order ID:' . $order_id . '): ' . $error, strtotime('now'));

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
            // $doc_number = $response['iq_api_success']['iq_api_success_items'][0][0]['data'];

            // save document number to order meta
            update_post_meta($order_id, '_iq_doc_number', $order_id);

            // add order note
            $order->add_order_note('<b>Order successfully synced to IQ. IQ document number:</b><br> ' . $order_id);
            $order->save();

            // log
            iq_logger('order_sync', '[ORDER EDIT SCREEN] Order ID ' . $order_id . ' successfully synced to IQ. IQ document number: ' . $order_id, strtotime('now'));

            // return success message
            wp_send_json('Order ID ' . $order_id . ' successfully synced to IQ. IQ document number: ' . $order_id); 

        // if IQ error returned
        elseif ($response['iq_api_error'][0]['iq_error_code'] != 0) :

            // retrieve, combine and display/log/return error messages
            $error_arr = $response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'][0]['iq_error_extended_data']['iq_root_json']['error_data'][0]['items'];

            print_r($response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'][0]['iq_error_extended_data']['iq_root_json']['error_data']);

            wp_die();

            // base err msg
            $err_msg = '<b>This order could not be synced to IQ due to the following error(s) returned by IQ during the sync process:</b><br><br>';

            // errors arr
            $errors = [];

            // loop through err are to retrieve product data and associated errors and push to $errors
            foreach ($error_arr as $item) :
                if (!empty($item['errors'])) :

                    $errors[] = [
                        'stock_code' => isset($item['stock_code']) ? $item['stock_code'] : 'SKU not defined on WooCommerce',
                        'prod_title' => $item['comment'],
                        'err_code' => $item['errors'][0]['error_code'],
                        'err_desc' => $item['errors'][0]['error_description']
                    ];

                endif;
            endforeach;

            // loop through $errors and compile error msg
            foreach ($errors as $err_data) :
                $err_msg .= '<u><b>SKU:</b></u> ' . $err_data['stock_code'] . '<br>';
                $err_msg .= '<u><b>Product title:</b></u> ' . $err_data['prod_title'] . '<br>';
                $err_msg .= '<u><b>IQ error code:</b></u> ' . $err_data['err_code'] . '<br>';
                $err_msg .= '<u><b>IQ error message:</b></u> ' . $err_data['err_desc'] . '<br>';
            endforeach;

            $err_msg .= '<b>Please rectify these errors on IQ before attempting to sync again.</b>';

            // print $err_msg;

            // add order note with error msg
            $order->add_order_note($err_msg);
            $order->save();

            // add log
            iq_logger('order_sync', '[ORDER EDIT SCREEN] Single order submission to IQ failed with the following IQ error(s) for order ID ' . $order_id . ':', strtotime('now'));

            // loop through $errors and log each
            foreach ($errors as $err_data) :
                iq_logger('order_sync', 'SKU: ' . $err_data['stock_code'], strtotime('now'));
                iq_logger('order_sync', 'Product title: ' . $err_data['prod_title'], strtotime('now'));
                iq_logger('order_sync', 'IQ error code: ' . $err_data['err_code'], strtotime('now'));
                iq_logger('order_sync', 'IQ error message: ' . $err_data['err_desc'], strtotime('now'));
            endforeach;

            // send error
            wp_send_json_error('Could not sync order ' . $order_id . ' to IQ because of IQ returned errors during the sync process. Details of these errors will appear under Order Notes in the right sidebar.');

        endif;

    endif;
    curl_close($curl);

    wp_die();
}
