<?php

/**
 * Function to manually push new users to IQ
 */
function iq_manual_sync_new_users() {

    // Retrieve WC customers
    $wc_customers = get_users([
        'role'    => 'customer',
        'orderby' => 'ID'
    ]);

    // loop through customers and send sync request for each
    if (is_object($wc_customers) || is_array($wc_customers) && !empty($wc_customers)) :

        foreach ($wc_customers as $customer) :

            // if user has IQ user id meta, continue to next iteration of loop
            if (get_user_meta($customer->ID, '_iq_user_id', true)) :
                continue;
            endif;

            // set time limit for each iteration of the loop, just in case
            set_time_limit(30);

            // retrieve last customer order
            $last_order = wc_get_customer_last_order($customer->ID);

            // setup user IQ id
            $iq_user_id = 'WWW' . $customer->ID;

            // retrieve iq settings
            $settings = maybe_unserialize(get_option('iq_settings'));

            // setup basic auth
            $basic_auth_raw = $settings['user-no'] . ':' . $settings['user-pass'];
            $basic_auth     = base64_encode($basic_auth_raw);
            $auth_string    = 'Basic ' . $basic_auth;

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
                        ]
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

                // retrieve billing address details
                $b_address1 = $last_order->get_billing_address_1();
                $b_address2 = $last_order->get_billing_address_2();
                $b_state    = $last_order->get_billing_state();
                $b_city     = $last_order->get_billing_city();
                $b_postcode = $last_order->get_billing_postcode();
                $b_tel      = $last_order->get_billing_phone();
                $b_country  = $last_order->get_billing_country();
                $b_email    = $last_order->get_billing_email();

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

                // retrieve billing address details
                $b_address1 = $customer->get_billing_address_1();
                $b_address2 = $customer->get_billing_address_2();
                $b_state    = $customer->get_billing_state();
                $b_city     = $customer->get_billing_city();
                $b_postcode = $customer->get_billing_postcode();
                $b_tel      = $customer->get_billing_phone();
                $b_country  = $customer->get_billing_country();
                $b_email    = $customer->get_billing_email();

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

            // setup IQ customer data array 
            $iq_cust_data = [
                "export_class"           => "Debtor",
                "postal_address_details" => [
                    $b_address1,
                    $b_address2,
                    WC()->countries->get_states($b_country)[$b_state],
                    $b_city,
                    $b_postcode
                ],
                "delivery_address_details" => [
                    $s_address1,
                    $s_address2,
                    WC()->countries->get_states($s_country)[$s_state],
                    $s_city,
                    $s_postcode
                ],
                "credit_limit"               => 0,
                "telephone_numbers"          => [
                    $b_tel
                ],
                "cellphone_number"           => $s_tel,
                "email_address"              => $b_email,
                "allow_use_of_email_address" => true,
                "debtor_account"             => $iq_user_id,
                "debtor_name"                => $name,
                "debtor_group"               => "D505",
                "invoice_layout"             => 1,
                "delivery_route"             => "R001",
                "normal_representative"      => 1,
                "terms"                      => "CAD",
                "vat_status"                 => $vat_status
            ];

            // push $iq_cust_data to $user_data_arr
            $user_data_arr[] = $iq_cust_data;

            // push $user_data_arr to $payload
            $payload['IQ_API']['IQ_API_Submit_Debtor']['IQ_Submit_Data']['iq_root_json']['debtors_master'] = $user_data_arr;

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

            $response_json = curl_exec($curl);

            // if request successful
            if (false !== $response_json) :

                $response = json_decode($response_json, true);

                // if user sync successful
                if ($response['iq_api_error'][0]['iq_error_code'] === 0) :

                    // add log
                    iq_logger('single_user_sync_success', 'Single user sync to IQ successful for user ' . $iq_user_id, strtotime('now'));

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
                    iq_logger('single_user_sync_iq_error', 'Single user submission to IQ failed with the follow IQ error(s) for user ' . $iq_user_id . ': ' . $err_msg, strtotime('now'));

                endif;

            // if request failed
            else :

                // retrieve error
                $error = curl_error($curl);

                // add log
                iq_logger('single_user_sync_request_fail', 'Request to IQ failed with the following error: ' . $error, strtotime('now'));

            endif;

            // close curl
            curl_close($curl);

        endforeach;
    endif;

    // reset time limit
    set_time_limit(120);
}
