<?php

/**
 * Sync user to IQ via AJAX
 */
add_action('wp_ajax_nopriv_iq_sync_single_user', 'iq_sync_single_user');
add_action('wp_ajax_iq_sync_single_user', 'iq_sync_single_user');

function iq_sync_single_user() {

    check_ajax_referer('iq sync woo user to iq');

    // retrieve order and user data
    $order_id   = $_GET['order_id'];
    $order      = wc_get_order($order_id);
    $user_id    = $order->get_user_id();
    $iq_user_id = 'WWW' . $user_id;

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

    // retrieve billing address details
    $b_address1 = $order->get_billing_address_1();
    $b_address2 = $order->get_billing_address_2();
    $b_state    = $order->get_billing_city();
    $b_city     = $order->get_billing_state();
    $b_postcode = $order->get_billing_postcode();
    $b_tel      = $order->get_billing_phone();

    // retrieve shipping address details
    $s_address1 = $order->get_shipping_address_1();
    $s_address2 = $order->get_shipping_address_2();
    $s_state    = $order->get_shipping_state();
    $s_city     = $order->get_shipping_state();
    $s_postcode = $order->get_shipping_postcode();
    $s_tel      = $order->get_shipping_phone();

    // name
    $name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

    // setup correct vat status
    $vat_status = $order->get_shipping_country() == 'ZA' || $order->get_shipping_country() == '' ? 'Normal' : 'Export';

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
        "email_address"              => $order->get_billing_email(),
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

    wp_send_json($payload);

    wp_die();

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

    wp_die();
}
