<?php



require_once(__ROOT__ . 'php/inc/database.php');
require_once(__ROOT__ . 'local_config/config.php');
require_once(__ROOT__ . 'php/utilities/general.php');
require_once(__ROOT__ . 'local_config/lang/'.get_session_language() . '.php');



/**
 * 
 * recalculates the individual order quanties when adjusting the total delivered quantity. 
 * @param unknown_type $order_id
 * @param unknown_type $product_id
 * @param unknown_type $new_total_quantity
 */
function edit_total_order_quantities($order_id, $product_id, $new_total_quantity){
	
	$rs = do_stored_query('get_order_item_detail', $order_id, 0,0, 0,$product_id );
	
	$uf_qu = array();
	
	$total_quantity = 0; 
	//for each uf 
	while ($row = $rs->fetch_assoc()) {
		$uf_qu[$row['uf_id']] =  $row['quantity'];
	 	$total_quantity = $total_quantity + $row['quantity'];	 
	}		
	DBWrap::get_instance()->free_next_results();
	
	//calc and save adjusted quantities for each uf
	$xml = '<total>'.$total_quantity.'</total>';
	$xml .= '<rows>';
	foreach ($uf_qu as $uf_id => $quantity){
	    $new_quantity = round(($quantity / $total_quantity) * $new_total_quantity, 3);
	    do_stored_query('modify_order_item_detail', $order_id, $product_id, $uf_id, $new_quantity);
	    $xml .= "<row><uf_id>${uf_id}</uf_id><quantity>${new_quantity}</quantity></row>";
	}
	DBWrap::get_instance()->free_next_results();
	
	printXML($xml . '</rows>');
}


/**
 * Prepare the order to review: Insert order items into aixada_order_to_shop 
 *      if is needed.    
 */        
function prepare_order_to_shop($order_id) {
    $ok = do_stored_query('modify_order_item_detail', $order_id, 0, 0, 0);
    if (!$ok) {
        throw new Exception(
                "An error occured during preparing aixada_order_to_shop!!");      			
    }
}

/**
 * Change individual order quantity. 
 * @param integer $order_id
 * @param integer $product_id
 * @param integer $uf_id
 * @param number $quantity
 * @return number The quantity if all is ok
 */
function edit_order_quantity($order_id, $product_id, $uf_id, $quantity){
    // Check parameters
    if (!is_numeric($order_id)) {
        throw new Exception("`order_id` must be integer!");      			
    }
    if (!is_numeric($product_id)) {
        throw new Exception("`product_id` must be integer!");      			
    }
    if (!is_numeric($uf_id)) {
        throw new Exception("`uf_id` must be integer!");     			
    }
    if (!is_numeric($quantity)) {
        throw new Exception("`quantity` must be numeric!");      			
    }
    // Check if exist
    prepare_order_to_shop($order_id);
    $item = get_row_query("
        select uf_id from aixada_order_to_shop os
        where os.product_id = {$product_id}
            and os.order_id = {$order_id}
            and os.uf_id = {$uf_id};");
	if ($item) {
        // Update quantity
        $ok = do_stored_query('modify_order_item_detail', 
                                $order_id, $product_id, $uf_id, $quantity);
    } else {
        // New aixada_order_item and aixada_order_to_shop
        // 0. Insert oder items to aixada_order_to_shop table if is needed.            
        // 1. Copy any aixada_order_item on order and protduct with 0 quantity
        $db = DBWrap::get_instance();
    //TODO: Use $db->beginTransaction() $db->commit() and $db->rollback()
        $ok = $db->Execute("
            INSERT INTO aixada_order_item (
                uf_id, order_id, unit_price_stamp, date_for_order, 
                product_id, quantity )
            SELECT {$uf_id}, order_id, unit_price_stamp, date_for_order,
                product_id, 0
            FROM aixada_order_item 
            WHERE order_id={$order_id} and product_id = {$product_id}
            LIMIT 1;");
        if (!$ok) {
            throw new Exception("No order for this product!!");
        }
        $new_order_item_id = $db->last_insert_id();
        if (!$new_order_item_id) {
            throw new Exception("No order id for this product!!");
        }
        // 2. Insert new aixada_order_to_shop with the quantity
        $ok = $db->Execute("
            INSERT INTO aixada_order_to_shop (
                order_item_id, uf_id, order_id, unit_price_stamp, product_id,
                quantity, arrived, revised
            )
            SELECT
                id, uf_id, order_id, unit_price_stamp, product_id,
                {$quantity}, 1, 1
            FROM aixada_order_item
            WHERE id = {$new_order_item_id};"
        );
    }
    if (!$ok) {
        throw new Exception(
                   "An error occured during saving the new product quantity!!");
    }
    return $quantity;
}

/**
 * Change the gross price of a product into a order, all 
 *      `aixada_order_to_shop.unit_price_stamp` of the product of the order are
 *      updated as final UF price (gross price + VAT + Rev.Tax)
 * @param integer $order_id
 * @param integer $product_id
 * @param number $gross_price
 * @return string If all is ok returns a sting as
 *      "OK;_the_gross_price_;_the_net_price_;_the_uf_price_".
 */
function edit_order_gross_price($order_id, $product_id, $gross_price) {
    // Check parameters
    if (!is_numeric($order_id)) {
        throw new Exception("`order_id` must be integer!");      			
    }
    if (!is_numeric($product_id)) {
        throw new Exception("`product_id` must be integer!");      			
    }
    if (!is_numeric($gross_price)) {
        throw new Exception("`gross_price` must be numeric!");      			
    }
    // Insert oder items to aixada_order_to_shop table if is needed.            
    prepare_order_to_shop($order_id);
    
    // Get net price            
    $row = get_row_query("
        select 
            round({$gross_price} * 
                (1 + iva.percent/100), 2) net_price,
            round({$gross_price} * 
                (1 + iva.percent/100) * 
                (1 + rev.rev_tax_percent/100), 2) uf_price
        from  aixada_product p
        join (aixada_rev_tax_type rev, aixada_iva_type iva)
        on    rev.id = p.rev_tax_type_id and iva.id = p.iva_percent_id
        where p.id = {$product_id}
    ");
    $_uf_price = $row['uf_price'];
    // Ok, now update net price!!
    $ok = DBWrap::get_instance()->do_stored_query("
        update
            aixada_order_to_shop os
        set
            os.unit_price_stamp = {$_uf_price}
        where
            os.product_id = {$product_id}
            and os.order_id = {$order_id}
    ");
    if (!$ok){
        throw new Exception(
            "An error occured during saving the new product gross price!!");      			
    }
    return 'OK;'.$gross_price.';'.$row['net_price'].';'.$_uf_price;
}

/**
 * 
 * Finalizes an order which means, that an order is send to the provider (email, printed out, fetched by provider directly).
 * No more modifications are possible and the ordered items receive an order_id
 * @param int $provider_id
 * @param date $date_for_order
 */
function finalize_order($provider_id, $date_for_order)
{
	global $Text;  	
	$config_vars = configuration_vars::get_instance(); 
	$msg = ''; 
	
	
	//check here if an order_id already exists for this date and provider. 
	
	
	
	if ($config_vars->internet_connection && $config_vars->email_orders){
		
        $provider_name = get_list_query(array(
            'SELECT name FROM aixada_provider WHERE id = :1q',
            $provider_id));
		$rm = new report_manager();
		
        $message = '<h2>'.$config_vars->coop_name."</h2>\n";
        $email_order_format = $config_vars->email_order_format;
        if ($email_order_format == 1 || $email_order_format == 3){ 
            $message .= "<h2>".$Text['summarized_orders'].' '.$Text['for'].' "'.
                    $provider_name.'" '.$Text['for'].' '.
                    $date_for_order."</h2>\n";
            $message .= $rm->write_summarized_orders_html($provider_id, $date_for_order);
            $message .= "<p><br/></p>\n";
        }
        if ($email_order_format == 2 || $email_order_format == 3){
            $message .= "<h2>".$Text['detailed_orders'].' '.$Text['for'].' "'.
                    $provider_name.'" '.$Text['for'].' '.
                    $date_for_order."</h2>\n";
            $message .= $rm->extended_orders_for_provider_and_dateHTML($provider_id, $date_for_order);
            $message .= "<p><br/></p>\n";
        }
		
		$db = DBWrap::get_instance();
		$db->free_next_results();
		$strSQL = 'SELECT name, email FROM aixada_provider WHERE email is not null and id = :1q';
    	$rs = $db->Execute($strSQL, $provider_id);
		if($rs->num_rows == 0){
    		throw new Exception("The provider does not have an email.");
		}
    	
		while ($row = $rs->fetch_assoc()) {
      		$toEmail = $row['email'];
            $providerName = $row['name'];
    	}
    	
    	$db->free_next_results();
    	
		$rs = do_stored_query('get_responsible_uf', $provider_id);
    	
    	$responsible_ufs = get_list_rs($rs, 'email');
    	
		$subject = $Text['order'].' '.$Text['for'].' "'.$providerName.'" '.
            $Text['for'].' '.$date_for_order;		
        if (send_mail($toEmail, $subject, $message, array(
                        'reply_to'=>$responsible_ufs,
                        //also send order to responsible uf as cc
                        'cc'=>$responsible_ufs
                    ))) {
			$msg = $Text['msg_order_emailed'];			
		} else {
			$msg = '<span style="color:red">'.$Text['msg_err_emailed'].'</span>';
		}
		
		
	}
	
	
	
	
	if ($rs = do_stored_query('finalize_order', $provider_id, $date_for_order)){
		while ($row = $rs->fetch_assoc()) {
      		$order_id = $row['id'];
    	}
        $msg .= $msg!=='' ? '<br>' : '';
		$msg .= $Text['ostat_desc_fin_send'] . $order_id;
	} else {
		throw new Exception ($Text['msg_err_finalize']);
	}
	
	return $msg; 
	
}

/**
 * 
 * custom interface for order listing which converts most common query params into 
 * calls to stored procedures. 
 * @param str $time_period today | all | prevMonth | etc. 
 */
function get_orders_in_range($time_period='ordersForToday', $uf_id=0, $from_date=0, $to_date=0, $steps=1, $range="month", $limit='')
{
	
	//TODO server - client difference in time/date?!
	$today = date('Y-m-d', strtotime("Today"));
	$tomorrow = date('Y-m-d', strtotime("Tomorrow"));
	$next_week =  date('Y-m-d', strtotime('Tomorrow + 6 days'));
	$prev_month = date('Y-m-d', strtotime('Today - 1 month'));
	$prev_2month = date('Y-m-d', strtotime('Today - 2 month'));
	$prev_year = date('Y-m-d', strtotime('Today - 1 year'));
	$very_distant_future = '9999-12-30';
	$very_distant_past	= '1980-01-01';
	$pre_order	= '1234-01-23';
	
	
	//get results based on time periods and then go backward/foward a certain amount of time 
	$steps_from_date 	 = date('Y-m-d', strtotime("Today -". ($steps--) . " " .$range));
	$steps_to_date	 = date('Y-m-d', strtotime("Today -". ($steps) . " " .$range));	
	

	//stored procedure 'get_orders_listing(fromDate, toDate, uf_id, filter_revision_status_id, limit_str)'
	
	switch ($time_period) {
		// all orders where date_for_order = today
		case 'ordersForToday':
			printXML(stored_query_XML_fields('get_orders_listing', $today, $today, $uf_id,0,$limit));
			break;
			
		// all orders where date_for_order = today
		case 'ordersForTodayLimbo':
			//printXML(stored_query_XML_fields('get_orders_listing', $today, $today, $uf_id,30,$limit));
			break;
		
		//all orders 
		case 'all':
			printXML(stored_query_XML_fields('get_orders_listing', $very_distant_past, $very_distant_future, $uf_id,0,$limit));
			break;

		case 'pastMonths2Future':
			printXML(stored_query_XML_fields('get_orders_listing', $prev_2month, $very_distant_future, $uf_id,0,$limit));
			break;
			
		//last month
		case 'pastMonth':
			printXML(stored_query_XML_fields('get_orders_listing', $prev_month, $tomorrow, $uf_id,0,$limit));
			break;
			
		//last year
		case 'pastYear':
			printXML(stored_query_XML_fields('get_orders_listing', $prev_year, $tomorrow, $uf_id,0,$limit));
			break;
		
		//all orders that have been send off, but have no shop date assigned
		case 'limboOrders':
			printXML(stored_query_XML_fields('get_orders_listing', $prev_year, $very_distant_future, $uf_id, 3,$limit));
			break;
		
		case 'preOrders':
			printXML(stored_query_XML_fields('get_orders_listing', $pre_order, $pre_order, $uf_id, 0,$limit));
			break;
			
		case 'nextWeek':
			printXML(stored_query_XML_fields('get_orders_listing', $tomorrow, $next_week, $uf_id, 0,$limit));
			break;
					
		case 'futureOrders':
			printXML(stored_query_XML_fields('get_orders_listing', $today, $very_distant_future, $uf_id,0,$limit));
			break;
			
		case 'steps':
			printXML(stored_query_XML_fields('get_orders_listing', $steps_from_date, $steps_to_date, $uf_id,0,$limit));
			break;
			
		case 'exact':
			printXML(stored_query_XML_fields('get_orders_listing', $from_date, $to_date, $uf_id,0,$limit));
			break;
		
		
		default:
			throw new Exception("get_orders_in_range: param={$time_period} not supported");  
			break;
	}

	
}

/**
 * Retrieves products with prices that have been ordered.
 * @param integer|null $order_id Order id (if is null uses $date)
 * @param integer $provider_id
 * @param string|null $date Date for order (used only when $order_id is null)
 * @return mysqli_result
 */
function get_ordered_products_with_prices($order_id, $provider_id, $date) {
    $sql = "
        select distinct
            p.id, 
            p.name, 
            um.unit,
            round( 
                max(ifnull(ots.unit_price_stamp, oi.unit_price_stamp)) / 
                (1 + rev.rev_tax_percent/100) /
                (1 + iva.percent/100), 2) gross_price,
            iva.percent iva_percent,
            round( 
                max(ifnull(ots.unit_price_stamp, oi.unit_price_stamp)) /
                (1 + rev.rev_tax_percent/100), 2) net_price,
            rev.rev_tax_percent,
            max(ifnull(ots.unit_price_stamp,oi.unit_price_stamp)
                ) uf_price
        from
            aixada_order_item oi
        left join 
            aixada_order_to_shop ots
        on  oi.id = ots.order_item_id
        join (
            aixada_product p,
            aixada_rev_tax_type rev, 
            aixada_iva_type iva,
            aixada_unit_measure um
        ) on 
            oi.product_id = p.id
            and rev.id = p.rev_tax_type_id
            and iva.id = p.iva_percent_id
            and um.id =  p.unit_measure_order_id";
    if ($order_id) {
        $sql .= " where oi.order_id = {$order_id}";
    } else if($date && $provider_id) {
        $sql .= " where
                    oi.date_for_order = '{$date}'
                    and p.provider_id = {$provider_id}";
    } else {
        $sql .= " where oi.order_id = -1"; // no rows
    }
    $sql .= "
        group by p.id, p.name, 
                um.unit, rev.rev_tax_percent, iva.percent";
    $sql .= " order by p.name";
    return DBWrap::get_instance()->Execute($sql);
}
?>