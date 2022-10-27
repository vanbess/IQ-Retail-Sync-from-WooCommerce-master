<?php

/****************************
 * 4. UPDATE/INSERT PRODUCTS
 ****************************/
// fetch product data set
$products = file_get_contents(IQ_RETAIL_PATH . 'inc/fetch/files/stock-combined.json');

// decode $products json
$products = json_decode($products, true);

// delete log file if exists so that we keep file sizes to a minimum
if (file_exists(IQ_RETAIL_PATH . 'inc/fetch/logs/products-updated.log')) :
    unlink(IQ_RETAIL_PATH . 'inc/fetch/logs/products-updated.log');
endif;

// loop
if (is_array($products) || is_object($products)) :

    foreach ($products as $product) :

        // reset execution timer on each iteration so that we don't run into timeout issues
        set_time_limit(60);

        // retrieve product ID by sku
        $wc_prod_id = wc_get_product_id_by_sku($product['code']);

        // update product if it $wc_prod_id not zero, else... 
        if ($wc_prod_id != 0) :

            // retrieve product
            $wc_prod = wc_get_product($wc_prod_id);

            // update
            $wc_prod->set_name($product['descript']);
            $wc_prod->set_regular_price($product['sellpinc1']);
            $wc_prod->set_short_description(trim($product['memo']));
            $wc_prod->set_stock_quantity($product['onhand']);
            $wc_prod->set_weight($product['weight']);
            $wc_prod->set_manage_stock(true);
            $wc_prod->set_backorders('yes');

            // save
            $wc_prod->save();

            // log time
            $time_now = strtotime('now');

            // log insert
            if ($is_manual == true) :
                file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/manual-sync/products-updated.log', date('j F, Y @ h:i:s', $time_now) . ': Product SKU ' . $product['code'] . ' successfully updated.' . PHP_EOL, FILE_APPEND);
            else :
                file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/auto-sync/products-updated.log', date('j F, Y @ h:i:s', $time_now) . ': Product SKU ' . $product['code'] . ' successfully updated.' . PHP_EOL, FILE_APPEND);
            endif;

        // ...insert new product if $wc_prod_id is zero
        else :

            $wc_prod = new WC_Product();

            // set details
            $wc_prod->set_sku($product['code']);
            $wc_prod->set_name($product['descript']);
            $wc_prod->set_regular_price($product['sellpinc1']);
            $wc_prod->set_short_description(trim($product['memo']));
            $wc_prod->set_stock_quantity($product['onhand']);
            $wc_prod->set_weight($product['weight']);
            $wc_prod->set_manage_stock(true);
            $wc_prod->set_backorders('yes');

            // save
            $wc_prod->save();

            // log time
            $time_now = strtotime('now');

            // log insert
            if ($is_manual == true) :
                file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/manual-sync/products-inserted.log', date('j F, Y @ h:i:s', $time_now) . ': New product with SKU ' . $product['code'] . ' successfully inserted.' . PHP_EOL, FILE_APPEND);
            else :
                file_put_contents(IQ_RETAIL_PATH . 'inc/fetch/logs/auto-sync/products-inserted.log', date('j F, Y @ h:i:s', $time_now) . ': New product with SKU ' . $product['code'] . ' successfully inserted.' . PHP_EOL, FILE_APPEND);
            endif;

        endif;

    endforeach;

endif;

// reset max execution time
ini_set('max_execution_time', 120);
