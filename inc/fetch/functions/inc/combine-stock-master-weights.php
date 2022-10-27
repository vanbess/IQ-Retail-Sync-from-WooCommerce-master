<?php

/**********************************************
 * 3. COMBINE STOCK MASTER AND WEIGHT DATASETS
 **********************************************/
// retrieve stock master and stock weight files
$fetched_stock = file_get_contents(IQ_RETAIL_PATH . 'inc/fetch/files/stock-master.json');
$fetched_weight = file_get_contents(IQ_RETAIL_PATH . 'inc/fetch/files/stock-weight.json');

// decode stock master and stock weight
$fetched_stock  = json_decode($fetched_stock, true);
$fetched_weight = json_decode($fetched_weight, true);

// reference correct record set
$fetched_stock  = $fetched_stock['iq_api_result_data']['records'];
$fetched_weight = $fetched_weight['iq_api_result_data']['records'];

// set $combined array initially to empty array for error checking
$combined = [];

// loop
foreach ($fetched_stock as $prod_data) :

    // add weight key to $prod_data
    $prod_data['weight'] = '';

    // loop through weight data
    foreach ($fetched_weight as $weight_data) :

        // if $prod_data sku matches $weight_data sku, push $weight_data weight to $prod_data['weight']
        if ($prod_data['code'] == $weight_data['code']) :
            $prod_data['weight'] = $weight_data['weight'];
        endif;

    endforeach;

    // finally push updated $prod_data to $combined
    array_push($combined, $prod_data);

endforeach;

// delete old file to avoid any potential errors
if (file_exists(IQ_RETAIL_PATH . 'inc/fetch/files/stock-combined.json')) :
    unlink(IQ_RETAIL_PATH . 'inc/fetch/files/stock-combined.json');
endif;

// write $combined to stock-combined.json
file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/files/stock-combined.json', json_encode($combined));

// write $combined to stock-master.json if is manual sync - used for future ref if needed
if ($is_manual == true) :
    file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/files/stock-master.json', json_encode($combined));
endif;
