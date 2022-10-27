<?php

/****************************************
 * 3. PUSH FILTERED LIST OF USERS TO IQ
 ****************************************/

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

// loop through $user_ids_to_push, retrieve user data and push to $user_data_arr
$user_data_arr = [];

// if there is user data to push, process data
if (!empty($user_ids_to_push)) :

    // if there are new user ids to push, loop through each and build data array which will be sent to IQ
    foreach ($user_ids_to_push as $user_id) :

        // reset time limit with each iteration of loop so that we don't run into timeout issues
        set_time_limit(300);

        // get raw user id
        $raw_id = str_replace('WWW', '', $user_id);

        // get customer data object
        $cust = new WC_Customer($raw_id);

        // get last order for customer so that we can get shipping/billing dets, in case is guest order without user address details in database
        $last_order = $cust->get_last_order();

        if (false !== $last_order) :

            // retrieve billing address details
            $b_address1 = $last_order->get_billing_address_1();
            $b_address2 = $last_order->get_billing_address_2();
            $b_state    = $last_order->get_billing_city();
            $b_city     = $last_order->get_billing_state();
            $b_postcode = $last_order->get_billing_postcode();
            $b_tel      = $last_order->get_billing_phone();

            // retrieve shipping address details
            $s_address1 = $last_order->get_shipping_address_1();
            $s_address2 = $last_order->get_shipping_address_2();
            $s_state    = $last_order->get_shipping_state();
            $s_city     = $last_order->get_shipping_state();
            $s_postcode = $last_order->get_shipping_postcode();
            $s_tel      = $last_order->get_shipping_phone();

        else :

            // retrieve billing address details
            $b_address1 = $cust->get_billing_address_1();
            $b_address2 = $cust->get_billing_address_2();
            $b_state    = $cust->get_billing_city();
            $b_city     = $cust->get_billing_state();
            $b_postcode = $cust->get_billing_postcode();
            $b_tel      = $cust->get_billing_phone();

            // retrieve shipping address details
            $s_address1 = $cust->get_shipping_address_1();
            $s_address2 = $cust->get_shipping_address_2();
            $s_state    = $cust->get_shipping_state();
            $s_city     = $cust->get_shipping_state();
            $s_postcode = $cust->get_shipping_postcode();
            $s_tel      = $cust->get_shipping_phone();

        endif;


        // name
        $name = $cust->get_first_name() . ' ' . $cust->get_last_name();

        // setup correct vat status
        $vat_status = $cust->get_shipping_country() == 'ZA' || $cust->get_shipping_country() == '' ? 'Normal' : 'Export';

        // setup IQ customer data array 
        $iq_cust_data = [
            "export_class"           => "Debtor",
            "postal_address_details" => [
                $b_address1,
                $b_address2,
                $b_city,
                $b_state,
                $b_postcode
            ],
            "delivery_address_details" => [
                $s_address1,
                $s_address2,
                $s_city,
                $s_state,
                $s_postcode
            ],
            "credit_limit"               => 0,
            "telephone_numbers"          => [
                $b_tel
            ],
            "cellphone_number"           => $s_tel,
            "email_address"              => $cust->get_email(),
            "allow_use_of_email_address" => true,
            "debtor_account"             => $user_id,
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

    endforeach;

    // reset time limit
    set_time_limit(120);

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

    $response = curl_exec($curl);

    if ($response === false) :
        error_log('Could not execute user push IQ. Request error returned: ' . curl_error($curl) . '.' . PHP_EOL, 3, IQ_RETAIL_PATH . 'inc/push/logs/users/push_errors.log');
    else :

        // delete previous result file if exists
        if (file_exists(IQ_RETAIL_PATH . 'inc/push/files/users/users-pushed.json')) :
            unlink(IQ_RETAIL_PATH . 'inc/push/files/users/users-pushed.json');
        endif;

        // write result to file
        file_put_contents(IQ_RETAIL_PATH . 'inc/push/files/users/users-pushed.json', $response);

        // logging
        $time_now = strtotime('now');

        // log
        file_put_contents(IQ_RETAIL_PATH . 'inc/push/logs/users/user-push.log', date('j F, Y @ h:i:s', $time_now) . ' - IQ user push successful.' . PHP_EOL, FILE_APPEND);

    endif;

    // close curl
    curl_close($curl);

endif;
