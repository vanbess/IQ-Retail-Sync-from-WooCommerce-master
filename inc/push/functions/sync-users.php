<?php

/**
 * Function to manually push new users to IQ
 */
function iq_sync_users() {

    // mod @ 14 Dec 2022
    // only get customer IDs which have orders
    // (condensed user sync list)
    // $orders = wc_get_orders([
    //     'status' => ['wc-processing'],
    //     'limit' => -1,
    //     'orderby' => 'ID',
    //     'order' => 'DESC'
    // ]);

    // $customer_ids = [];
    // $customer_ids_null = [];

    // if (is_object($orders) && !empty($orders)) :
    //     foreach ($orders as $order) :

    //         // check for customers with null id value (guest checkout) and register them and push to $customer_ids, else if customer id is not null, push to $customer_ids
    //         if($order->get_customer_id() === 0):
    //          $customer_id = wp_insert_user([

    //          ]);
    //         else:

    //         endif;

    //         $order->get_customer_id() !== 0 ? $customer_ids[] = $order->get_customer_id() : $customer_ids_null[$order->get_id()] = $order->get_customer_id();
    //     endforeach;
    // endif;

    iq_logger('user_sync', '==================================================================================================================', strtotime('now'));
    iq_logger('user_sync', 'Auto user sync start', strtotime('now'));
    iq_logger('user_sync', 'Retrieving IQ settings', strtotime('now'));

    // retrieve iq settings
    $settings = maybe_unserialize(get_option('iq_settings'));

    iq_logger('user_sync', 'Setting up basic auth', strtotime('now'));

    // setup basic auth
    $basic_auth_raw = $settings['user-no'] . ':' . $settings['user-pass'];
    $basic_auth     = base64_encode($basic_auth_raw);
    $auth_string    = 'Basic ' . $basic_auth;

    // **************************
    // Retrieve current IQ users
    // **************************

    iq_logger('user_sync', 'Setting up request URL', strtotime('now'));

    // request url
    $request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Request_GenericSQL';

    iq_logger('user_sync', 'Setting up IQ debtors user retrieval payload', strtotime('now'));

    // setup payload
    $payload = [
        'IQ_API' => [
            'IQ_API_Request_GenericSQL' => [
                'IQ_Company_Number'     => $settings['company-no'],
                'IQ_Terminal_Number'    => $settings['terminal-no'],
                'IQ_User_Number'        => $settings['user-no'],
                'IQ_User_Password'      => $settings['user-pass-api-key'],
                'IQ_Partner_Passphrase' => !empty($settings['passphrase']) ? $settings['passphrase'] : '',
                "IQ_SQL_Text"           => "SELECT account FROM debtors WHERE account LIKE '%WWW%';"
            ]
        ]
    ];

    $ext_users = [];

    iq_logger('user_sync', 'Executing request', strtotime('now'));

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

        iq_logger('user_sync', 'ERROR: User list request to IQ failed with the following error: ' . curl_error($curl), strtotime('now'));
        iq_logger('user_sync', 'Request failed, bailing', strtotime('now'));

        return;

    // if request succeeds
    else :

        iq_logger('user_sync', 'Request successful, processing results', strtotime('now'));

        // decode
        $response = json_decode($response_js, true);

        // if IQ error code, log and bail
        if ($response['iq_api_error'][0]['iq_error_code'] !== 0) :

            // log
            iq_logger('user_sync', 'ERROR: User list request to IQ returned the following error code: ' . $response['iq_api_error']['iq_error_code'], strtotime('now'));
            iq_logger('user_sync', 'Error code returned, bailing', strtotime('now'));

            return;

        // if no IQ error code
        else :

            iq_logger('user_sync', 'Parsing returned IQ debtor records', strtotime('now'));

            // loop to extract ext users and push to $ext_users
            $iq_users = $response['iq_api_result_data']['records'];

            iq_logger('user_sync', 'Looping through records and stripping out WWW prefix, then pushing stripped id to $ext_users array', strtotime('now'));

            foreach ($iq_users as $user_data) :

                // format user acc number to match WP user ID format
                $user_id = str_replace('WWW', '', $user_data['account']);

                // push
                $ext_users[] = $user_id;

            endforeach;

            iq_logger('user_sync', 'Done', strtotime('now'));

        endif;
    endif;

    iq_logger('user_sync', 'Closing cURL connection', strtotime('now'));

    // close curl
    curl_close($curl);

    iq_logger('user_sync', 'Retrieving WC customers', strtotime('now'));

    // Retrieve WC customers
    $wc_customers = get_users([
        'role'    => 'customer',
        'orderby' => 'ID',
        'order'   => 'DESC'
    ]);

    // if $ext_users empty for some reason, bail because then we know theres been an error
    if (empty($ext_users)) :
        iq_logger('user_sync', 'ERROR: User sync could not be completed because failed to retrieve existing users from IQ.', strtotime('now'));
        iq_logger('user_sync', 'Operation ended', strtotime('now'));
        return;
    endif;

    iq_logger('user_sync', 'Encoding $ext_users and writing to logs-files/users_ext.json', strtotime('now'));

    // write $ext_users to file for later ref
    iq_filer('users_ext', json_encode($ext_users));

    // for debugging
    // file_put_contents(IQ_RETAIL_PATH . 'logs-files/users_ext.txt', print_r($ext_users, true));

    iq_logger('user_sync', 'Begin loop through $wc_customers to extract user data and send to IQ', strtotime('now'));

    // loop through customers and send sync request for each
    if (is_object($wc_customers) || is_array($wc_customers) && !empty($wc_customers)) :

        foreach ($wc_customers as $customer) :

            iq_logger('user_sync', 'Checking for presence of customer ' . $customer->ID . ' in $ext_users', strtotime('now'));

            // if customer id in $ext_users, continue
            if (array_search($customer->ID, $ext_users) !== false) :
                iq_logger('user_sync', 'Customer ID ' . $customer->ID . ' already present in $ext_users, moving on to next customer', strtotime('now'));

                continue;
            endif;

            iq_logger('user_sync', 'Setting up customer request data, request URL and initial payload', strtotime('now'));

            // retrieve last customer order
            $last_order = wc_get_customer_last_order($customer->ID);

            // setup user IQ id
            $iq_user_id = 'WWW' . $customer->ID;

            // setup request url
            $request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Submit_Debtor';

            // setup initial payload
            $payload = [
                'IQ_API' => [
                    'IQ_API_Submit_Debtor' => [
                        'IQ_Company_Number'     => $settings['company-no'],
                        'IQ_Terminal_Number'    => $settings['terminal-no'],
                        'IQ_User_Number'        => $settings['user-no'],
                        'IQ_User_Password'      => $settings['user-pass-api-key'],
                        'IQ_Partner_Passphrase' => !empty($settings['passphrase']) ? $settings['passphrase'] : '',
                        'IQ_Submit_Data' => [
                            'iq_root_json' => [
                                'iq_identification_info' => [
                                    'company_code' => $settings['company-no']
                                ],
                                'debtors_master' => []
                            ]
                        ],
                        "IQ_Overrides" => ["imeDuplicateAccount"]
                    ]
                ]
            ];

            // retrieve user data and push to $user_data_arr
            $user_data_arr = [];

            // setup billing and shipping address correctly.
            // some customer will have incomplete user profiles with missing data,
            // so we check for last orders for customers and use that address data preferentially,
            // else we revert to normal customer data, whether present or not
            if (is_object($last_order) && !empty($last_order)) :

                iq_logger('user_sync', 'Customer order found, extracting address data', strtotime('now'));

                // retrieve billing address details
                $b_address1 = $last_order->get_billing_address_1();
                $b_address2 = $last_order->get_billing_address_2();
                $b_state    = $last_order->get_billing_state();
                $b_city     = $last_order->get_billing_city();
                $b_postcode = $last_order->get_billing_postcode();
                $b_tel      = $last_order->get_billing_phone();
                $b_country  = $last_order->get_billing_country();
                $b_email    = $last_order->get_billing_email();
                $b_company  = $last_order->get_billing_company();

                // get billing & shipping suburb
                $order_id = $last_order->get_order_number();
                $b_suburb = get_post_meta($order_id, '_billing_suburb', true) ? get_post_meta($order_id, '_billing_suburb', true) : 'No suburb provided';
                $s_suburb = get_post_meta($order_id, '_shipping_suburb', true) ? get_post_meta($order_id, '_shipping_suburb', true) : 'No suburb provided';

                // retrieve shipping address details
                $s_address1 = $last_order->get_shipping_address_1();
                $s_address2 = $last_order->get_shipping_address_2();
                $s_state    = $last_order->get_shipping_state();
                $s_city     = $last_order->get_shipping_city();
                $s_postcode = $last_order->get_shipping_postcode();
                $s_tel      = $last_order->get_shipping_phone();
                $s_country  = $last_order->get_shipping_country();

                // name
                $name = $last_order->get_billing_first_name() . ' ' . $last_order->get_billing_last_name();

                // setup correct vat status
                $vat_status = $last_order->get_shipping_country() == 'ZA' || $last_order->get_shipping_country() == '' ? 'Normal' : 'Export';

            // else use WC $customer data
            else :

                iq_logger('user_sync', 'Customer order not found, extracting address data from user meta', strtotime('now'));

                // retrieve billing address details
                $b_address1 = $customer->get_billing_address_1();
                $b_address2 = $customer->get_billing_address_2();
                $b_state    = $customer->get_billing_state();
                $b_city     = $customer->get_billing_city();
                $b_postcode = $customer->get_billing_postcode();
                $b_tel      = $customer->get_billing_phone();
                $b_country  = $customer->get_billing_country();
                $b_email    = $customer->get_billing_email();
                $b_company  = $customer->get_billing_company();

                // / get billing & shipping suburb
                $customer_id = $customer->ID;
                $b_suburb    = get_user_meta($customer_id, 'billing_suburb', true) ? get_user_meta($customer_id, 'billing_suburb', true) : 'No suburb provided';
                $s_suburb    = get_user_meta($customer_id, 'shipping_suburb', true) ? get_user_meta($customer_id, 'shipping_suburb', true) : 'No suburb provided';

                // retrieve shipping address details
                $s_address1 = $customer->get_shipping_address_1();
                $s_address2 = $customer->get_shipping_address_2();
                $s_state    = $customer->get_shipping_state();
                $s_city     = $customer->get_shipping_city();
                $s_postcode = $customer->get_shipping_postcode();
                $s_tel      = $customer->get_shipping_phone();
                $s_country  = $customer->get_shipping_country();

                // name
                $name = $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name();

                // setup correct vat status
                $vat_status = $customer->get_shipping_country() == 'ZA' || $customer->get_shipping_country() == '' ? 'Normal' : 'Export';

            endif;

            iq_logger('user_sync', 'Pushing address data to request body', strtotime('now'));

            // setup IQ customer data array 
            $iq_cust_data = [
                "export_class"           => "Debtor",
                "postal_address_details" => [
                    $b_address1,
                    $b_address2,
                    WC()->countries->get_states($b_country)[$b_state],
                    $b_suburb . ', ' . $b_city . ', ' . $b_country,
                    $b_postcode
                ],
                "delivery_address_details" => [
                    $s_address1,
                    $s_address2,
                    WC()->countries->get_states($s_country)[$s_state],
                    $s_suburb . ', ' . $s_city . ', ' . $s_country,
                    $s_postcode
                ],
                "credit_limit"               => 1,
                "telephone_numbers"          => [
                    $b_tel
                ],
                "cellphone_number"           => $s_tel,
                "tradingas"                  => strlen($b_company) !== 0 ? $b_company : 'Not applicable',
                "email_address"              => $b_email,
                "allow_use_of_email_address" => true,
                "debtor_account"             => $iq_user_id,
                "debtor_name"                => $name,
                "debtor_group"               => "D050",
                "invoice_layout"             => 1,
                "delivery_route"             => "R001",
                "normal_representative"      => 1,
                "terms"                      => "CAD",
                "vat_status"                 => $vat_status,
                "currency"                   => "ZAR"
            ];

            // push $iq_cust_data to $user_data_arr
            $user_data_arr[] = $iq_cust_data;

            // push $user_data_arr to $payload
            $payload['IQ_API']['IQ_API_Submit_Debtor']['IQ_Submit_Data']['iq_root_json']['debtors_master'] = $user_data_arr;

            iq_logger('user_sync', 'Initializing cURL request to sync customer data to IQ', strtotime('now'));

            /*********************************************
             * 3.1 SEND REQUEST TO IQ TO INSERT NEW USERS
             *********************************************/
            // curl request
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

            iq_logger('user_sync', 'Sending request', strtotime('now'));

            $response_json = curl_exec($curl);

            // file_put_contents(IQ_RETAIL_PATH . 'auto_user_sync_iq_response.log', print_r(json_decode($response_json, true), true), FILE_APPEND);

            // if request successful
            if (false !== $response_json) :

                iq_logger('user_sync', 'Request successful, decoding response', strtotime('now'));

                $response = json_decode($response_json, true);

                // if user sync successful
                if ($response['iq_api_error'][0]['iq_error_code'] === 0) :

                    // add log
                    iq_logger('user_sync', 'Single user sync to IQ successful for user ' . $iq_user_id, strtotime('now'));

                    iq_logger('user_sync', 'Moving on to next customer', strtotime('now'));
                    iq_logger('user_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

                    // update user meta with IQ account number so that user is skipped going forward
                    update_user_meta($customer->ID, '_iq_user_id', $iq_user_id);

                // if unable to sync user
                elseif ($response['iq_api_error'][0]['iq_error_code'] !== 0) :

                    // retrieve, combine and display/log/return error messages
                    $error_arr = $response['iq_api_error'][0]['iq_error_data']['iq_error_data_items'][0]['iq_error_extended_data']['iq_root_json']['error_data'][0]['errors'];

                    $err_msg = '';

                    foreach ($error_arr as $err_data) :
                        $err_msg .= $err_data['error_description'];
                    endforeach;

                    // if $err_msg = 'Duplicate Account Number', add user meta so that user isn't checked again
                    if ($err_msg == 'Duplicate Account Number') :
                        update_user_meta($customer->ID, '_iq_user_id', $iq_user_id);
                    endif;

                    // add log
                    iq_logger('user_sync', 'ERROR: Single user submission to IQ failed with the following IQ error(s) for user ' . $iq_user_id . ': ' . $err_msg, strtotime('now'));
                    iq_logger('user_sync', 'Moving on to next customer', strtotime('now'));
                    iq_logger('user_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

                endif;

            // if request failed
            else :

                // retrieve error
                $error = curl_error($curl);

                // add log
                iq_logger('single_user_sync_request_fail', 'ERROR: Request to IQ failed with the following error: ' . $error, strtotime('now'));
                iq_logger('user_sync', 'Moving on to next customer', strtotime('now'));
                iq_logger('user_sync', '~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~', strtotime('now'));

            endif;

            // close curl
            curl_close($curl);

        endforeach;
    endif;

    iq_logger('user_sync', 'Customer bulk sync to IQ ended', strtotime('now'));
    iq_logger('user_sync', '==================================================================================================================', strtotime('now'));

    // reset time limit
    // set_time_limit(120);
}
