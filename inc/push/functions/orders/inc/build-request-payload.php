<?php

/************************
 * BUILD REQUEST PAYLOAD
 ************************/

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
            "IQ_Instruction_Set"    => [[]],
            "IQ_AutoProcess"        => [
                "IQ_AutoProcess_Type" => "iqINV",
                "IQ_Instruction_Set"  => [
                    [
                        "Instruction_Type" => "apiitSubmitConfirmation"
                    ]
                ],
                "IQ_Instruction_Set" =>  [
                    [
                        "Instruction_Type" => "apiitSubmitError"
                    ]
                ],
            ],
            "IQ_Fallback" => [
                "IQ_Fallback_Type" => "iqSOR"
            ],
            "IQ_Submit_Data"        => [
                "IQ_Root_JSON" => [
                    "IQ_Identification_Info" => [
                        "Company_Code" => $settings['company-no']
                    ],
                    "Processing_Documents" => [],
                ]
            ],
            "IQ_Overrides" => ["ideNegativeStock", "ideInvalidDateRange", "imeDuplicateAccount"]
        ]
    ]
];

// setup order data
foreach ($orders_not_synced as $order_id) :

    // retrieve order object
    $ord_obj = wc_get_order($order_id);

    // retrieve products
    $prods = $ord_obj->get_items();

    file_put_contents(IQ_RETAIL_PATH . 'inc/push/files/orders/order_prods_obj.txt', print_r($prods, true), FILE_APPEND);

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
            "Quantity"    => $prod->get_quantity(),
            "Volumetrics" => [
                "Units"           => 0,
                "Volume_Length"   => 0,
                "Volume_Width"    => 0,
                "Volume_Height"   => 0,
                "Volume_Quantity" => 1,
                "Volume_Value"    => 0,
                "Volume_Rounding" => 0
            ],
            "Item_Price_Inclusive" => get_post_meta($prod_id, '_regular_price', true),
            "Item_Price_Exclusive" => number_format(get_post_meta($prod_id, '_regular_price', true) / 1.15, 2, '.', ''),
            "Discount_Percentage"  => 0,
            "Line_Total_Inclusive" => $prod->get_total(),
            "Line_Total_Exclusive" => number_format($prod->get_total() / 1.15, 2, '.', ''),
            "Custom_Cost"          => 0,
            "List_Price"           => get_post_meta($prod_id, '_regular_price', true),
            "Invoiced_Quantity"    => 0
        ];

    endforeach;

    // retrieve shipping
    $shipping = $ord_obj->get_items('shipping');
    $shipping_name = '';
    $shipping_cost = '';
    foreach ($shipping as $ship_id => $item) :
        $shipping_name = $item->get_name();
        $shipping_cost = $item->get_total();
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
        "item_price_inclusive" => number_format($shipping_cost, 2, '.', ''),
        "item_price_exclusive" => number_format($shipping_cost / 1.15, 2, '.', ''),
        "discount_percentage"  => 0,
        "line_total_inclusive" => number_format($shipping_cost, 2, '.', ''),
        "line_total_exclusive" => number_format($shipping_cost / 1.15, 2, '.', ''),
        "custom_cost"          => 0,
        "list_price"           => 0,
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
    $debtor_acc = 'WWW' . $ord_obj->get_user_id();

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
                $delprov
            ],
            "Email_Address"             => $delemail,
            "Order_Number"              => $order_id,
            "Delivery_Method"           => $shipping_name,
            "Delivery_Note_Number"      => "",
            "Total_Vat"                 => number_format($vat_amt, 2, '.', ''),
            "Discount_Percentage"       => $disc_perc,
            "Discount_Type"             => $discount_type,
            "Discount_Amount"           => number_format($disc_amount, 2, '.', ''),
            "Long_Description"          => $long_descr,
            "Document_Total"            => number_format($order_total, 2, '.', ''),
            "Total_Number_Of_Items"     => $ord_obj->get_item_count('line-item'),
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


endforeach;

file_put_contents(IQ_RETAIL_PATH . 'inc/push/files/orders/order-sync-request.json', json_encode($payload));
