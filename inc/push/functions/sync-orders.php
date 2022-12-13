<?php

/***********************************
 * Automatically sync orders to IQ
 ***********************************/

function iq_sync_orders() {

    // retrieve iq settings
    $settings = maybe_unserialize(get_option('iq_settings'));

    // setup basic auth
    $basic_auth_raw = $settings['user-no'] . ':' . $settings['user-pass'];
    $basic_auth     = base64_encode($basic_auth_raw);
    $auth_string    = 'Basic ' . $basic_auth;

    iq_logger('order_sync', 'Retrieving unsynced orders with status PROCESSING and _iq_doc_number empty.', strtotime('now'));

    // retrieve all orders for which meta key _iq_doc_number doesn't exist
    $order_q = new WP_Query([
        'post_type'      => 'shop_order',
        'post_status'    => 'wc-processing',
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
        iq_logger('order_sync', 'No WC orders matching required criteria returned. Aborting sync at this time.', strtotime('now'));

        // bail
        return;

    endif;

    // **************************
    // Retrieve current IQ orders
    // **************************

    iq_logger('order_sync', 'Setting up existing order request to IQ.', strtotime('now'));

    // current iq orders arr
    $curr_iq_orders = [];

    // request url
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
                "IQ_SQL_Text"           => "SELECT ordernum FROM sorders WHERE accnum LIKE '%WWW%';"
            ]
        ]
    ];

    iq_logger('order_sync', 'Executing existing orders request.', strtotime('now'));

    // init request to retrieve existing IQ users
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
        CURLOPT_HTTPHEADER => array(
            'Authorization: ' . $auth_string,
            'Content-Type: application/json'
        ),
    ));

    $response_js = curl_exec($curl);

    // iq request fails, log and bail
    if ($response_js == false) :

        iq_logger('order_sync', 'Existing orders list request to IQ failed with the following error: ' . curl_error($curl) . '. Stopping function execution.', strtotime('now'));
        return;

    // if request succeeds
    else :

        iq_logger('order_sync', 'IQ existing orders list cURL request successful.', strtotime('now'));

        // decode
        $response = json_decode($response_js, true);

        // response 429 etc
        if (isset($response['response_code']) && ($response['response_code']) !== 200) :

            iq_logger('order_sync', 'IQ error response code returned: ' . $response['response_code'] . '. Message returned: ' . $response['response_message'] . 'Stopping execution.', strtotime('now'));

            return;
        endif;

        // if IQ error code, log and bail
        if ($response['iq_api_error']['iq_error_code'] > 0) :

            // log
            iq_logger('order_sync', 'Existing orders list request to IQ returned the following error code: ' . $response['iq_api_error']['iq_error_code'], strtotime('now'));

            // retrieve errors
            // $errors = array_intersect_key(['errors'], $response);

            return;

        // if no IQ error code
        else :

            // loop to extract ext users and push to $curr_iq_orders
            $iq_orders = $response['iq_api_result_data']['records'];

            if (!empty($iq_orders)) :

                iq_logger('order_sync', 'Starting existing IQ orders loop.', strtotime('now'));

                foreach ($iq_orders as $order_data) :
                    // push
                    $curr_iq_orders[] = $order_data['ordernum'];
                endforeach;

                iq_logger('order_sync', 'Finishing existing IQ orders loop.', strtotime('now'));
            else :
                iq_logger('order_sync', 'Empty IQ existing orders list/record set returned. Bailing.', strtotime('now'));
            endif;

        endif;

    endif;

    curl_close($curl);

    /**
     * If no IQ orders returned, bail with log message
     */
    if (empty($curr_iq_orders)) :

        // add log
        iq_logger('order_sync', 'Existing IQ order list is empty, which means order sync cannot proceed, because either existing orders request to IQ failed, or empty record set was returned. Stopping function execution.', strtotime('now'));

        // bail
        return;

    endif;

    /*******************
     * ORDERS SYNC LOOP
     *******************/
    foreach ($order_ids as $order_id) :

        iq_logger('order_sync', 'PROCESS START: ORDER ID ' . $order_id, strtotime('now'));
        iq_logger('order_sync', 'Starting single order loop.', strtotime('now'));

        // tempt set timeout limit to unlimited
        set_time_limit(0);

        // if order id in $curr_iq_orders, fetch order document number, insert into order meta and continue to next iteration of loop
        if (in_array($order_id, $curr_iq_orders)) :

            iq_logger('order_sync', 'Order ID ' . $order_id . ' already present on IQ. Skipping.', strtotime('now'));

            iq_logger('order_sync', 'Preparing to fetch document number from IQ.', strtotime('now'));

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
                        "IQ_SQL_Text"           => "SELECT ordernum, document FROM sorders WHERE ordernum = '$order_id';"
                    ]
                ]
            ];
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

            iq_logger('order_sync', 'Sending order document number request to IQ.', strtotime('now'));

            // execute curl
            $response = curl_exec($curl);

            // if request successful
            if (false !== $response) :

                iq_logger('order_sync', 'Order document number request successful. Decoding response.', strtotime('now'));

                // decode response
                $response = json_decode($response, true);

                // if iq did not return an error
                if ($response['iq_api_error']['iq_error_code'] == 0) :

                    iq_logger('order_sync', 'No IQ errors returned for request. Checking returned record.', strtotime('now'));

                    // retrieve records
                    $records = $response['iq_api_result_data']['records'];

                    // if no records
                    if (empty($records)) :

                        iq_logger('order_sync', 'Empty record set returned. Moving on to next order.', strtotime('now'));
                        iq_logger('order_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

                        continue;

                    // if records
                    elseif (!empty($records)) :

                        iq_logger('order_sync', 'Record found. Extracting IQ document number.', strtotime('now'));

                        $doc_number_inserted = update_post_meta($order_id, '_iq_doc_number', $records['document']);

                        if ($doc_number_inserted) :
                            iq_logger('order_sync', 'IQ document number for ' . $order_id . ' saved to order meta. Moving on to next order.', strtotime('now'));
                            iq_logger('order_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));
                        endif;

                        continue;

                    endif;

                // if IQ error returned
                elseif ($response['iq_api_error']['iq_error_code'] != 0) :
                    iq_logger('order_sync', 'IQ error code for document number request. Error code returned: ' . $response['iq_api_error']['iq_error_code'] . '. Moving on to next order.', strtotime('now'));
                endif;

            // if curl request failed for some reason
            else :
                $error = curl_error($curl);

                iq_logger('order_sync', 'Order IQ document number cURL request failed with error: ' . $error, strtotime('now'));
            endif;

            curl_close($curl);

        endif;

        // setup request url
        $request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Request_GenericSQL';

        // reset order
        $order = '';

        // retrieve order object
        $order = wc_get_order($order_id);

        if ($order !== false) :
            iq_logger('order_sync', 'Order object successfully retrieved!', strtotime('now'));
        elseif ($order === false) :
            iq_logger('order_sync', 'Order object retrieval failed! Moving on to next order.', strtotime('now'));
            iq_logger('order_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));
            continue;
        endif;

        // retrieve order user email; if user does not exist with email, create user
        $user_id = get_post_meta($order_id, '_customer_user', true);

        // if user ID is 0, it means the user hasn't registered, and we need to register the user
        if ($user_id == 0) :

            iq_logger('order_sync', 'User/debtor ID equal to 0. Inserting new user/customer.', strtotime('now'));

            // retrieve billing email
            $email = $order->get_billing_email();

            // check if user doesn't already exist
            if (!email_exists($email) && !username_exists($email)) :

                iq_logger('order_sync', 'Billing email check complete. Continuing with user insertion.', strtotime('now'));

                // create new customer/user 
                $user_id = wc_create_new_customer($email, '', '', array(
                    'first_name' => $order->get_billing_first_name(),
                    'last_name'  => $order->get_billing_last_name(),
                ));

                // sync past orders
                wc_update_new_customer_past_orders($user_id);

                iq_logger('order_sync', 'Inserting user/debtor billing and shipping address details.', strtotime('now'));

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
                update_user_meta($user_id, 'billing_suburb', get_post_meta($order_id, '_billing_suburb', true) ? get_post_meta($order_id, '_billing_suburb', true) : 'No suburb provided');

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
                update_user_meta($user_id, 'shipping_suburb', get_post_meta($order_id, '_shipping_suburb', true) ? get_post_meta($order_id, '_shipping_suburb', true) : 'No suburb provided');

                iq_logger('order_sync', 'User/debtor billing and shipping address details insertion complete!', strtotime('now'));

                // setup custom iq reference
                $iq_user_id = 'WWW' . $user_id;

            // if email or username exists
            elseif (email_exists($email) || username_exists($email)) :

                iq_logger('order_sync', 'User/debtor email exists. Retrieving user ID.', strtotime('now'));

                $user_id    = email_exists($email) ? email_exists($email) : username_exists($email);
                $iq_user_id = 'WWW' . $user_id;
            endif;


        // if $user_id exists, format for use with IQ
        else :
            $iq_user_id = 'WWW' . $user_id;
        endif;

        iq_logger('order_sync', 'IQ user/debtor formatted id/account number is: ' . $iq_user_id, strtotime('now'));
        iq_logger('order_sync', 'Setting up user/debtor check payload.', strtotime('now'));

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
        iq_logger('order_sync', 'Init cURL for user/debtor request to IQ.', strtotime('now'));

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

        iq_logger('order_sync', 'Sending existing user/debtor request to IQ.', strtotime('now'));

        $response_json = curl_exec($curl);

        // request successful
        if (false !== $response_json) :

            iq_logger('order_sync', 'cURL user/debtor request to IQ successful. Parsing response.', strtotime('now'));

            $response = json_decode($response_json, true);

            // response 429 etc
            if (isset($response['response_code']) && ($response['response_code']) !== 200) :

                iq_logger('order_sync', 'cURL user/debtor request to returned response code ' . $response['response_code'] . '. Continuing to next loop iteration.', strtotime('now'));

                // add order note
                $order->add_order_note('<b>Order customer check request error (code: ' . $response['response_code'] . ') :</b></br> ' . $response['response_message']);
                $order->save();

                iq_logger('order_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

                continue;
            endif;

            // if no iq error
            if ($response['iq_api_error'][0]['iq_error_code'] === 0) :

                iq_logger('order_sync', 'cURL user/debtor request to IQ returned no IQ error codes.', strtotime('now'));

                // if no customer records returned
                if (empty($response['iq_api_result_data']['records'])) :

                    // add order note
                    $order->add_order_note('<b>Customer ID ' . $iq_user_id . ' does not exist on IQ. <br> Manual customer sync for this order required.</b>');
                    $order->save();

                    // log
                    iq_logger('order_sync', 'The user/debtor (Customer ID ' . $iq_user_id . ') does not exist on IQ. Order cannot be synced. Continuing to next order.', strtotime('now'));

                    iq_logger('order_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

                    // continue
                    continue;

                endif;

            elseif ($response['iq_api_error'][0]['iq_error_code'] !== 0) :

                iq_logger('order_sync', 'cURL user/debtor request to IQ returned IQ error code: ' . $response['iq_api_error'][0]['iq_error_code'] . '. Continuing to next product.', strtotime('now'));

                // retrieve errors
                $error_arr = $response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'][0]['iq_error_extended_data']['iq_root_json']['error_data'];

                // error message
                $err_msg = '';
                foreach ($error_arr as $err_data) :
                    $err_msg .= $err_data['error_description'];
                endforeach;

                // add errors to order notes
                $order->add_order_note($err_msg);
                $order->save();

            endif;

        // request failed
        elseif (false === $response_json) :

            $error = curl_error($curl);

            $order->add_order_note('<b>(Order ID: ' . $order_id . ') Order user existence cURL request to IQ failed with the following error:<br> ' . $error . '</b>');
            $order->save();

            iq_logger('order_sync', '(Order ID: ' . $order_id . ') cURL user/debtor request to IQ failed with the following cURL error: ' . $error, strtotime('now'));

            iq_logger('order_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

            continue;

        endif;

        curl_close($curl);

        iq_logger('order_sync', 'Setting up cURL order sync request URL.', strtotime('now'));

        // setup request url
        $request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Submit_Document_Sales_Order';

        iq_logger('order_sync', 'Setting cURL up request payload.', strtotime('now'));

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

        iq_logger('order_sync', 'Retrieve order products.', strtotime('now'));

        // retrieve products
        $prods = $order->get_items();

        // array to hold order line items
        $order_items = [];

        iq_logger('order_sync', 'Building order product data array.', strtotime('now'));

        foreach ($prods as $prod) :

            $prod_id = (int)$prod->get_product_id();

            // push line items to $order_items array
            $order_items[] = [
                "Stock_Code"           => get_post_meta($prod_id, '_sku', true),
                "Comment"              => $prod->get_name(),
                "Quantity"             => (int)$prod->get_quantity(),
                "Item_Price_Inclusive" => (float)get_post_meta($prod_id, '_regular_price', true),
                "Item_Price_Exclusive" => (float)get_post_meta($prod_id, '_regular_price', true),
                "Discount_Percentage"  => 0,
                "Line_Total_Inclusive" => (float)$prod->get_total(),
                "Line_Total_Exclusive" => (float)$prod->get_total() / 1.15,
                "Custom_Cost"          => 0,
                "List_Price"           => (float)get_post_meta($prod_id, '_regular_price', true),
                "Invoiced_Quantity"    => 0
            ];

        endforeach;

        // file_put_contents(IQ_RETAIL_PATH . 'logs-files/order_items_' . $order_id . '.txt', print_r($order_items, true), FILE_APPEND);

        // continue;

        iq_logger('order_sync', 'Retrieving shipping data and adding to product data array.', strtotime('now'));

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
            "item_price_inclusive" => (float)$shipping_cost,
            "item_price_exclusive" => (float)$shipping_cost / 1.15,
            "discount_percentage"  => 0,
            "line_total_inclusive" => (float)$shipping_cost, 2,
            "line_total_exclusive" => (float)$shipping_cost / 1.15,
            "custom_cost"          => 0,
            "list_price"           => (float)$shipping_cost,
            "delcol"               => "",
            "invoiced_quantity"    => 0
        ];

        iq_logger('order_sync', 'Setting up customer data.', strtotime('now'));

        // retrieve delivery address info
        $deladdy1   = $order->get_shipping_address_1();
        $deladdy2   = $order->get_shipping_address_2();
        $delsuburb  = get_post_meta($order_id, '_shipping_suburb', true) ? get_post_meta($order_id, '_shipping_suburb', true) : 'No suburb provided';
        $delcity    = $order->get_shipping_city();
        $delprov    = $order->get_shipping_state();
        $delpcode   = $order->get_shipping_postcode();
        $delphone   = $order->get_shipping_phone();
        $delemail   = $order->get_billing_email();
        $delcountry = $order->get_shipping_country();

        // retrieve order total
        $order_total = $order->get_total();

        // retrieve discount
        $disc_amount = $order->get_discount_total();

        // work out discount percentage if applicable
        $disc_perc = $disc_amount > 0 ? 1 / ($order_total / $disc_amount) * 100 : 0;

        // figure out discount type (coupon vs whatever else)
        $coupons = $order->get_coupons();
        $discount_type = !empty($coupons) ? 'Coupon(s)' : 'Unknown';

        // retrieve order currency
        $currency = $order->get_currency();

        // calculate total VAT
        $vat_amt = $order->get_shipping_country() == 'ZA' ? $order_total - ($order_total / 1.15) : 0.00;

        // vat included or not
        $vat_inc = $order->get_shipping_country() == 'ZA' ? true : false;

        // setup bookpack order long description if bookpack_id is present
        $long_descr = '';

        if (get_post_meta($order_id, 'bookpack_id', true)) :

            iq_logger('order_sync', 'Bookpack and associated student meta found in order. Generating bookpack meta long description...', strtotime('now'));
            
            // get bookpack id
            $bookpack_id = get_post_meta($order_id, 'bookpack_id', true);
            
            // build bookpack long description
            $long_descr .= 'Bookpack: ' . get_the_title($bookpack_id) . PHP_EOL;
            $long_descr .= 'Pupil Name: ' . get_post_meta($order_id, 'pupil_name', true) . PHP_EOL;
            $long_descr .= 'Pupil School: ' . get_post_meta($order_id, 'pupil_school', true) . PHP_EOL;
            $long_descr .= 'Pupil Grade: ' . get_post_meta($order_id, 'pupil_grade', true) . PHP_EOL;
            
            iq_logger('order_sync', 'Bookpack meta long description generated.', strtotime('now'));
            
        endif;

        iq_logger('order_sync', 'Setting up base order data array and pushing line items to said array.', strtotime('now'));

        // setup base order data array
        $base_order_data = [
            "Export_Class" => "Sales_Order",
            "Document"     => [
                "Document_Number"              => $order_id,
                "Delivery_Address_Information" => [
                    $deladdy1,
                    $deladdy2,
                    $delsuburb,
                    $delcity,
                    WC()->countries->get_states($order->get_shipping_country())[$delprov],
                    $delcountry
                ],
                "Email_Address"             => $delemail,
                "Order_Number"              => $order_id,
                "Delivery_Method"           => $shipping_name,
                "Delivery_Note_Number"      => "",
                "Total_Vat"                 => (float)$vat_amt,
                "Discount_Percentage"       => (float)$disc_perc,
                "Discount_Type"             => $discount_type,
                "Discount_Amount"           => (float)$disc_amount,
                "Long_Description"          => $long_descr,
                "Document_Total"            => (float)$order_total,
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

        iq_logger('order_sync', 'Pushing data to request payload.', strtotime('now'));

        // push $base_order_data to Processing_Documents key in $payload
        $payload['IQ_API']['IQ_API_Submit_Document_Sales_Order']['IQ_Submit_Data']['IQ_Root_JSON']['Processing_Documents'][] = $base_order_data;

        // debug
        // file_put_contents(IQ_RETAIL_PATH . 'logs-files/payload_' . $order_id . '.txt', print_r($payload, true), FILE_APPEND); 
        // continue;

        /**
         * SEND REQUEST
         */

        iq_logger('order_sync', 'Init order sync cURL request to IQ.', strtotime('now'));

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

        iq_logger('order_sync', 'Executing order sync request.', strtotime('now'));

        $response_json = curl_exec($curl);

        // if request fails, send error message back and log
        if ($response_json !== false) :

            iq_logger('order_sync', 'Order sync request successful. Parsing data.', strtotime('now'));

            // decode response
            $response = json_decode($response_json, true);

            // if iq did not return an error
            if ($response['iq_api_error'][0]['iq_error_code'] == 0) :

                // response 429
                if (isset($response['response_code'])  && $response['response_code'] !== 200) :

                    iq_logger('order_sync', 'Response code other than 200 returned: ' . $response['response_code'] . '. Moving on to next order.', strtotime('now'));

                    // add order note
                    $order->add_order_note('<b>Order auto sync request error (code: ' . $response['response_code'] . ') :</b></br> ' . $response['response_message']);
                    $order->save();

                    iq_logger('order_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

                    continue;
                endif;

                // find document number 
                $doc_number = $response['iq_api_success']['iq_api_success_items'][0][0]['data'];

                if ($doc_number !== '') :

                    // save document number to order meta
                    update_post_meta($order_id, '_iq_doc_number', $doc_number);

                    // add order note
                    $order->add_order_note('<b>Order successfully synced to IQ.<br> IQ document number:</b><br> ' . $doc_number);
                    $order->save();

                    // log
                    iq_logger('order_sync', 'Order ID ' . $order_id . ' successfully synced to IQ. IQ document number: ' . $doc_number, strtotime('now'));

                else :

                    // add order note
                    $order->add_order_note('<b>Order synced to IQ, but no Document Number returned. Please try to sync again, or check IQ to see if order synced successfully');
                    $order->save();

                    iq_logger('order_sync', 'Order ID ' . $order_id . ' successfully synced to IQ, but no or empty Document Number returned. Continuing to next order.', strtotime('now'));

                    iq_logger('order_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

                    continue;

                endif;

            // if IQ error returned
            elseif ($response['iq_api_error'][0]['iq_error_code'] != 0) :

                if (!isset($response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'][0]['iq_error_extended_data'])) :

                    // base err msg
                    $err_msg = '<b><u>AUTO IQ SYNC FAILURE</u></b><br>';
                    $err_msg = '<b>This order could not be synced to IQ due to the following error(s) returned by IQ during the sync process:</b><br>';

                    // retrieve error data
                    $error_arr = $response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'];

                    // errors arr
                    $errors = [];

                    // loop through err are to retrieve product data and associated errors and push to $errors
                    foreach ($error_arr as $err_data) :

                        $errors[] = [
                            'err_code' => $err_data['iq_error_code'],
                            'err_desc' => $err_data['iq_error_description']
                        ];

                    endforeach;

                    // loop through $errors and compile error msg
                    foreach ($errors as $err_data) :
                        $err_msg .= '<u><b>IQ error code:</b></u> ' . $err_data['err_code'] . '<br>';
                        $err_msg .= '<u><b>IQ error message:</b></u> ' . $err_data['err_desc'] . '<br>';
                    endforeach;

                    $err_msg .= '<b>The cause of this error is unknown. Please contact IQ for support.</b>';

                    // add order note with error msg
                    $order->add_order_note($err_msg);
                    $order->save();

                    // add log
                    iq_logger('order_sync', 'Order submission to IQ failed with the following IQ error(s):', strtotime('now'));

                    // loop through $errors and log each
                    foreach ($errors as $err_data) :
                        iq_logger('order_sync', 'IQ error code: ' . $err_data['err_code'], strtotime('now'));
                        iq_logger('order_sync', 'IQ error message: ' . $err_data['err_desc'], strtotime('now'));
                    endforeach;

                    iq_logger('order_sync', 'Moving on to next order.', strtotime('now'));
                    iq_logger('order_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

                elseif (isset($response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'][0]['iq_error_extended_data']['iq_root_json']['error_data'][0]['items'])) :

                    // retrieve, combine and display/log/return error messages
                    $error_arr = $response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'][0]['iq_error_extended_data']['iq_root_json']['error_data'][0]['items'];

                    // file_put_contents(IQ_RETAIL_PATH . 'logs-files/order_sync_err_' . $order_id . '.txt', print_r($response, true));
                    // continue;

                    // base err msg
                    $err_msg = '<b><u>AUTO IQ SYNC FAILURE</u></b><br>';
                    $err_msg = '<b>This order could not be synced to IQ due to the following error(s) returned by IQ during the sync process:</b><br>';

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
                    iq_logger('order_sync', 'Order submission to IQ failed with the following IQ error(s):', strtotime('now'));

                    // loop through $errors and log each
                    foreach ($errors as $err_data) :
                        iq_logger('order_sync', 'SKU: ' . $err_data['stock_code'], strtotime('now'));
                        iq_logger('order_sync', 'Product title: ' . $err_data['prod_title'], strtotime('now'));
                        iq_logger('order_sync', 'IQ error code: ' . $err_data['err_code'], strtotime('now'));
                        iq_logger('order_sync', 'IQ error message: ' . $err_data['err_desc'], strtotime('now'));
                    endforeach;

                    iq_logger('order_sync', 'Moving on to next order.', strtotime('now'));
                    iq_logger('order_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

                    continue;

                else :

                    iq_logger('order_sync', 'Unable to retrieve error data. Full response written to file for investigation.', strtotime('now'));
                    iq_filer('order_err_response_' . $order_id, $response_json);

                endif;
            endif;

        // if request successful, write response to file and order notes
        elseif ($response_json === false) :

            $error = curl_error($curl);

            $order->add_order_note('<b>(Order ID: ' . $order_id . ') Order sync cURL request to IQ failed with the following error:<br> ' . $error . '</b>');
            $order->save();

            iq_logger('order_sync', '(Order ID ' . $order_id . ') Order sync cURL request to IQ failed with the following cURL error: ' . $error, strtotime('now'));

        endif;

        curl_close($curl);

        iq_logger('order_sync', 'Ending single order loop for order ID ' . $order_id, strtotime('now'));
        iq_logger('order_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

    endforeach;
}
