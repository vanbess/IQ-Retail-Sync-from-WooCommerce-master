<?php

/*****************************
 * Manually sync orders to IQ
 *****************************/

function iq_manual_sync_orders_to_iq() {

    // retrieve iq settings
    $settings = maybe_unserialize(get_option('iq_settings'));

    // setup basic auth
    $basic_auth_raw = $settings['user-no'] . ':' . $settings['user-pass'];
    $basic_auth     = base64_encode($basic_auth_raw);
    $auth_string    = 'Basic ' . $basic_auth;

    // retrieve all orders for which meta key _iq_doc_number doesn't exist
    $order_q = new WP_Query([
        'post_type'      => 'shop_order',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'meta_key'       => '_iq_doc_number',
        'meta_compare'   => 'NOT EXISTS',
        'fields'         => 'ids'
    ]);

    $order_ids = $order_q->posts;

    /**
     * If no order ids returned, bail with log message
     */
    if (empty($order_ids)) :

        // add log
        iq_logger('order_sync_no_orders', 'No orders matching required criteria to sync at this time.', strtotime('now'));

        // bail
        return;

    endif;

    /**************
     * ORDERS LOOP
     **************/
    foreach ($order_ids as $order_id) :

        set_time_limit(30);

        /************************************************************************************************************
         * 1. CHECK WHETHER ORDER ALREADY EXISTS ON IQ; IF TRUE, UPDATE ORDER META WITH DOCUMENT NUMBER AND CONTINUE
         ************************************************************************************************************/

        // setup request url
        $request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Request_GenericSQL';

        // setup payload
        $payload = [
            'IQ_API' => [
                'IQ_API_Request_GenericSQL' => [
                    'IQ_Company_Number'     => $settings['company-no'],
                    'IQ_Terminal_Number'    => $settings['terminal-no'],
                    'IQ_User_Number'        => $settings['user-no'],
                    'IQ_User_Password'      => $settings['user-pass-api-key'],
                    'IQ_Partner_Passphrase' => !empty($settings['passphrase']) ? $settings['passphrase'] : '',
                    "IQ_SQL_Text"           => "SELECT document FROM sorders WHERE ordernum = '$order_id';"
                ]
            ]
        ];

        // init curl
        $curl = curl_init();

        // init curl options
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

        // execute curl
        $response = curl_exec($curl);

        // if request successful
        if (false !== $response) :

            // decode response
            $response = json_decode($response, true);

            // if iq did not return an error
            if ($response['iq_api_error']['iq_error_code'] == 0) :

                // retrieve records
                $records = $response['iq_api_result_data']['records'];

                // if records, log, update and continue
                if (!empty($records)) :

                    // log
                    iq_logger('orders_on_iq', 'Order ID ' . $order_id . ' is already present on IQ.', strtotime('now'));

                    // update order meta with document number
                    update_post_meta($order_id, '_iq_doc_number', $records['document']);

                    // continue to next iteration of loop
                    continue;

                endif;

            // if IQ error returned, retrieve error(s), log and continue on to next loop iteration
            elseif ($response['iq_api_error']['iq_error_code'] != 0) :

                // retrieve, combine and display/log/return error messages
                $error_arr = $response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'][0]['iq_error_extended_data']['iq_root_json']['error_data'][0]['errors'];

                $err_msg = '';

                foreach ($error_arr as $err_data) :
                    $err_msg .= $err_data['error_description'];
                endforeach;

                // add log
                iq_logger('order_check_error', 'Order ID ' . $order_id . ' could not be checked from IQ. IQ error(s): ' . $err_msg, strtotime('now'));

                continue;

            endif;

        // if curl request failed for some reason
        else :

            // retrieve error
            $error = curl_error($curl);

            // log
            iq_logger('order_check_error', "Order check request (order ID $order_id) to IQ failed with the following error: $error.", strtotime('now'));

            // continue
            continue;

        endif;

        // close curl
        curl_close($curl);

        /*********************************************************************************************************
         * 2. IF WE'RE STILL GOOD AT THIS POINT, CHECK WHETHER ORDER USER/CLIENT EXISTS ON IQ AND CONTINUE IF NOT
         *********************************************************************************************************/

        // setup request url
        $request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Request_GenericSQL';

        // retrieve order object
        $ord_obj = wc_get_order($order_id);

        // retrieve order user id
        $user_id    = $ord_obj->get_user_id();
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

        // if request successful
        if (false !== $response_json) :

            $response = json_decode($response_json, true);

            // if no iq error
            if ($response['iq_api_error'][0]['iq_error_code'] === 0) :

                // if no customer records returned, log and continue to next iteration of loop
                if (empty($response['iq_api_result_data']['records'])) :

                    // log
                    iq_logger('single_order_no_customer', 'The customer for order ID ' . $order_id . ' (Customer ID ' . $iq_user_id . ') does not exist on IQ. Order cannot be synced as a result.', strtotime('now'));

                    // continue
                    continue;

                endif;

            // if iq error
            elseif ($response['iq_api_error'][0]['iq_error_code'] !== 0) :

                // retrieve, combine and display/log/return error messages
                $error_arr = $response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'][0]['iq_error_extended_data']['iq_root_json']['error_data'][0]['errors'];

                $err_msg = '';

                foreach ($error_arr as $err_data) :
                    $err_msg .= $err_data['error_description'];
                endforeach;

                // add log
                iq_logger('order_customer_check_request_failure', 'Customer ID ' . $iq_user_id . ' could not be checked from IQ. IQ error(s): ' . $err_msg, strtotime('now'));

                // continue
                continue;

            endif;

        // if request failed, log error and continue
        else :

            $error = curl_error($curl);
            iq_logger('order_customer_check_request_failure', 'Customer check request to IQ failed with the following error: ' . $error, strtotime('now'));
            continue;

        endif;

        /*********************************************************************************************
         * 3. IF WE'RE STILL GOLDEN AT THIS POINT, BUILD ORDER DATA SET AND SEND TO IQ FOR PROCESSING
         *********************************************************************************************/

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
        $prods = $ord_obj->get_items();

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
        $shipping = $ord_obj->get_items('shipping');
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
        $deladdy1 = $ord_obj->get_shipping_address_1();
        $deladdy2 = $ord_obj->get_shipping_address_2();
        $delcity  = $ord_obj->get_shipping_city();
        $delprov  = $ord_obj->get_shipping_state();
        $delpcode = $ord_obj->get_shipping_postcode();
        $delphone = $ord_obj->get_shipping_phone();
        $delemail = $ord_obj->get_billing_email();

        // retrieve order total
        $order_total = $ord_obj->get_total();

        // retrieve discount
        $disc_amount = $ord_obj->get_discount_total();

        // work out discount percentage if applicable
        $disc_perc = $disc_amount > 0 ? number_format(1 / ($order_total / $disc_amount) * 100, 2, '.', '') : 0;

        // figure out discount type (coupon vs whatever else)
        $coupons = $ord_obj->get_coupons();
        $discount_type = !empty($coupons) ? 'Coupon(s)' : 'Unknown';

        // retrieve order currency
        $currency = $ord_obj->get_currency();

        // calculate total VAT
        $vat_amt = $ord_obj->get_shipping_country() == 'ZA' ? $order_total - ($order_total / 1.15) : 0.00;

        // vat included or not
        $vat_inc = $ord_obj->get_shipping_country() == 'ZA' ? true : false;

        // setup debtor account
        $debtor_acc = $ord_obj->get_user_id() != 0 ? 'WWW' . $ord_obj->get_user_id() : 'WWW1';

        // setup bookpack description if applicable
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
                    WC()->countries->get_states($ord_obj->get_shipping_country())[$delprov]
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
                "Total_Number_Of_Items"     => (int)$ord_obj->get_item_count('line-item'),
                "Document_Description"      => "",
                "Print_Layout"              => 1,
                "Warehouse"                 => "",
                "Cashier_Number"            => 1,
                "Till_Number"               => 1,
                "Document_Includes_VAT"     => $vat_inc,
                "Currency"                  => "",
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
                "Debtor_Account"              => $debtor_acc,
                "Debtor_Name"                 => $ord_obj->get_billing_first_name() . ' ' . $ord_obj->get_billing_last_name(),
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
            iq_logger('single_order_sync_iq_error', 'Connection to IQ failed with the following error (Order ID:' . $order_id . '): ' . $error, strtotime('now'));

        // if request successful, write response to file
        else :

            // decode response
            $response = json_decode($response_json, true);

            // if iq did not return an error
            if ($response['iq_api_error'][0]['iq_error_code'] == 0) :

                // find document number 
                $doc_number = $response['iq_api_success']['iq_api_success_items'][0][0]['data'];

                // save document number to order meta
                update_post_meta($order_id, '_iq_doc_number', $doc_number);

                // add order note
                $ord_obj->add_order_note('Order successfully synced to IQ. IQ document number: ' . $doc_number, 0, false);
                $ord_obj->save();

                // log
                iq_logger('single_order_sync_success', 'Order ID ' . $order_id . ' successfully synced to IQ. IQ document number: ' . $doc_number, strtotime('now'));

            // if IQ error returned
            elseif ($response['iq_api_error'][0]['iq_error_code'] != 0) :

                // retrieve, combine and display/log/return error messages
                $error_arr = $response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'][0]['iq_error_extended_data']['iq_root_json']['error_data'][0]['errors'];

                $err_msg = '';

                foreach ($error_arr as $err_data) :
                    $err_msg .= $err_data['error_description'];
                endforeach;

                // add log
                iq_logger('single_order_sync_iq_error', 'Single order submission to IQ failed with the follow IQ error(s) for order ' . $order_id . ': ' . $err_msg, strtotime('now'));

            endif;
        endif;

        curl_close($curl);

    endforeach;

    // reset time limit once done
    set_time_limit(120);
}
