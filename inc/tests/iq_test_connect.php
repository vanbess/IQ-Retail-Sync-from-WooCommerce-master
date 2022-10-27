<?php

/**
 * AJAX function to test connectivity to IQ
 */
add_action('wp_ajax_nopriv_iq_test_connect', 'iq_test_connect');
add_action('wp_ajax_iq_test_connect', 'iq_test_connect');

function iq_test_connect() {

    check_ajax_referer('test iq connectivity');

    // retrieve IQ settings
    $settings = maybe_unserialize(get_option('iq_settings'));

    // setup payload
    $payload = [
        'IQ_API' => [
            'IQ_API_Query_Exports' => [
                'IQ_Company_Number'     => $settings['company-no'],
                'IQ_Terminal_Number'    => $settings['terminal-no'],
                'IQ_User_Number'        => $settings['user-no'],
                'IQ_User_Password'      => $settings['user-pass-api-key'],
                'IQ_Partner_Passphrase' => !empty($settings['passphrase']) ? $settings['passphrase'] : ''
            ]
        ]
    ];

    // setup request URL
    $request_url = $settings['host-url'] . ':' . $settings['port-no'] . '/IQRetailRestAPI/' . $settings['api-version'] . '/IQ_API_Query_Exports';

    // setup basic auth
    $basic_auth_raw = $settings['user-no'] . ':' . $settings['user-pass'];
    $basic_auth     = base64_encode($basic_auth_raw);
    $auth_string    = 'Basic ' . $basic_auth;

    // setup test request
    $curl = curl_init();

    curl_setopt_array($curl, array(
        CURLOPT_URL            => $request_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING       => '',
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_TIMEOUT        => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST  => 'GET',
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => array(
            "Authorization: $auth_string",
            'Content-Type: application/json'
        ),
    ));

    curl_exec($curl);

    if (curl_exec($curl) === false) :
        print 'IQ <=> Woo connectivity test failed with the following error: ' . curl_error($curl) . '. Please double check your API settings on this page, make sure they are saved, and test for connectivity again. Also make sure that connections from this website is not being blocked by the IQ Rest API on your IQ server.';
    else :
        print 'IQ <=> Woo connectivity test successful.';
    endif;

    curl_close($curl);

    wp_die();
}
