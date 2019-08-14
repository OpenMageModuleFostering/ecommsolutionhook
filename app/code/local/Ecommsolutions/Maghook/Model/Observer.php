<?php

/**
 * Observer to handle event
 * Sends JSON data to URL on cart save and purchase
 *
 * @author ECommSolution Dev-id: 26
 * @copyright  Copyright (c) 2015 ECommSolution
 * 
 */
class Ecommsolutions_Maghook_Model_Observer {

	private $_is_enabled ;
	private $_cart_change_hook ;
	private $_sales_hook;
	private $_shipment_hook ;
	private $_customer_add_hook ;
	
	
	const IS_ENABLED 			= 'ecommsolutions/ecommsolutions_group/aa_is_enabled';
	const CART_CHANGE_URL 		= 'ecommsolutions/ecommsolutions_group/aa_cart_change';
	const ORDER_URL 			= 'ecommsolutions/ecommsolutions_group/aa_sales_change';
	const SHIPMENT_URL 			= 'ecommsolutions/ecommsolutions_group/aa_shipping_hook';
	const CUSTOMER_URL 			= 'ecommsolutions/ecommsolutions_group/aa_customer_signup';
   
   
	private function initConfig($store_id){
		//$order->getStoreId();
		if (!isset($store_id) or $store_id == "")
			$st_id = Mage::app()->getStore()->getStoreId();
		else
			$st_id = $store_id;
		
		$this->_is_enabled = $this->getValueFromConfigByName(self::IS_ENABLED,$st_id);
		$this->_cart_change_hook = $this->getValueFromConfigByName(self::CART_CHANGE_URL,$st_id);
		$this->_sales_hook = $this->getValueFromConfigByName(self::ORDER_URL,$st_id);
		$this->_shipment_hook = $this->getValueFromConfigByName(self::SHIPMENT_URL,$st_id);
		$this->_customer_add_hook = $this->getValueFromConfigByName(self::CUSTOMER_URL,$st_id);
	}
	
	private function getValueFromConfigByName($name,$store_id){
		return Mage::getStoreConfig($name,$store_id);
	}
	
    public function postOrder($observer) {
		
		$order = $observer->getEvent()->getOrder();
		$this->initConfig($order->getStoreId());
		if($this->_is_enabled == 0) return;
		$orderStatus = $order->getStatus();
		Mage::log('in post order : ' . $orderStatus);
		
		try{
			if (!is_null($orderStatus)){
			$txn_ord["order_data"] = $this->transformOrder($order);
			$txn_ord['order_status'] = $orderStatus;
			$response = $this->proxy($txn_ord,$this->_sales_hook);
			}
		}
		catch (Exception $e) {
			echo 'Exception in order hook : ' .  $e->getMessage().  "\n";
		}
        return $this;
    }

	
	public function cartSave($observer) {
		$this->initConfig('');
		try{
			if($this->_is_enabled == 0) return;
			$customer = Mage::getSingleton('customer/session')->getCustomer();
			$email =  $customer->getEmail();
			
			$result = array('email' =>  $email , 
							'full_name' => $customer->getName(), 
							'first_name' =>  $customer->getFirstname(),
							'middle_name' =>  $customer->getMiddlename(),
							'last_name' =>  $customer->getLastname(),
							'dob' =>  $customer->getDob(),
							'gender' =>  $customer->getGender(),
							);
			
			if(Mage::getSingleton('customer/session')->isLoggedIn())
				$result['is_logged_in'] = true;
			else
				$result['is_logged_in'] = false;	
			
			$allitems = $observer["cart"]->getQuote()->getAllItems();
			$productMediaConfig = Mage::getModel('catalog/product_media_config');
			foreach ($allitems as $item) {
				$prodcut = $item->getProduct();
				$prodcut = Mage::getModel('catalog/product')->load($prodcut->getId());
				$productPrice = $prodcut->getPrice();
				$itemqty = $item->getQty() ;
				$sub_total = $productPrice * $itemqty ;
				$prd_counter = array( 
										'name' => $prodcut->getName() ,
										'quantity' => $itemqty ,
										'price' => Mage::helper('core')->currency($productPrice, true, false),
										'sub_total' => Mage::helper('core')->currency($sub_total, true, false),
										'thumbnail_image' => $productMediaConfig->getMediaUrl($prodcut->getThumbnail()) ,
										'product_url' =>  $prodcut->getProductUrl(true),
										'product_sku' => $item->getSku(),
										'prodcut_description' => $prodcut->getDescription(),
										'prodcut_short_description'	=> $prodcut->getShortDescription()
									) ;
				$result['cartDetail']['cart'][] = $prd_counter ;
			}
			if(count($allitems) == 0)
				$result['cartDetail']['cart'] = '';
			
			$result['cartDetail']['total_price'] =  $observer["cart"]->getQuote()->getGrandTotal();
			$this->proxy($result,$this->_cart_change_hook);
		}
		catch (Exception $e) {
			echo 'Exception in cart hook : ' .  $e->getMessage().  "\n";
		}
		return $this;
    }
	

	public function postUserCreation($observer) {
		$this->initConfig('');
		if($this->_is_enabled == 0) return;
		$customer = $observer["customer"];
		$customer = $customer->getData();
		
		try{
			$result = array('email' =>  $customer["email"] , 
							'first_name' => $customer["firstname"] , 
							'last_name' =>  $customer["lastname"] ,
							'store_name' => $customer["created_in"],
							'store_id' => $customer["store_id"],
							'signup_date' => $customer["created_at"]
							);
			$this->proxy($result,$this->_customer_add_hook);
		}
		catch (Exception $e) {
			echo 'Exception in subscriber hook : ' .  $e->getMessage().  "\n";
		}
        return $this;
    }
	
	
	public function saveShipping($observer){
		try {
			$shipment = $observer->getEvent()->getShipment();
			$order = $shipment->getOrder();
			$this->initConfig($order->getStoreId());
			if($this->_is_enabled == 0) return;
			$order_data = $order->getData();
			$result["order_data"] = $this->transformOrder($order);
			$tracks = $shipment->getAllTracks();
			
			foreach ($tracks as $track) {	
				$trk_data = $track->getData();		
				$track_id = $trk_data["entity_id"];				
				$track_cntr = array( 
											'tracking_number' => $trk_data["track_number"] ,
											'carrier_code' =>  $trk_data["carrier_code"] ,
											'tracking_title' => $trk_data["title"],
											'tracking_created_at' => $trk_data["created_at"],
											'tracking_entity_id' =>  $trk_data["entity_id"] ,
											'tracking_url' => Mage::helper('shipping')->getTrackingPopUpUrlByTrackId($track_id)
										) ;
				$result['order_data']['shipping_details'][] = $track_cntr ;
				
			}
			$this->proxy($result,$this->_shipment_hook);
		}
		catch (Exception $e) {
			echo 'Exception in shipment hook : ' .  $e->getMessage().  "\n";
		}
	}
    /**
     * Curl data and return body
     *
     * @param $data
     * @param $url
     * @return stdClass $output
     */
    private function proxy($data, $url) {

        $output = new stdClass();
        $ch = curl_init();
        $body = json_encode($data);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            //'Accept: application/json',
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
            // http://stackoverflow.com/questions/11359276/php-curl-exec-returns-both-http-1-1-100-continue-and-http-1-1-200-ok-separated-b
            'Expect:' // Remove "HTTP/1.1 100 Continue" from response
        ));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 60 * 2); // 2 minutes to connect
        curl_setopt($ch, CURLOPT_TIMEOUT, 60 * 4); // 8 minutes to fetch the response
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // ignore cert issues
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        // execute
        $response = curl_exec($ch);
        $output->status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // handle response
        $arr = explode("\r\n\r\n", $response, 2);
        if (count($arr) == 2) {
            $output->header = $arr[0];
            $output->body = $arr[1];
        } else {
            $output->body = "Unexpected response";
        }
        return $output;
    }

    /**
     * Transform order into one data object for posting
     */
    /**
     * @param $orderIn Mage_Sales_Model_Order
     * @return mixed
     */
    private function transformOrder($orderIn) {
		Mage::Log(' in transformOrder');
		
        $orderOut = $orderIn->getData();
        $orderOut['line_items'] = array();
        foreach ($orderIn->getAllItems() as $item) {
            $orderOut['line_items'][] = $item->getData();
        }

        /** @var $customer Mage_Customer_Model_Customer */
        $customer = Mage::getModel('customer/customer')->load($orderIn->getCustomerId());
        $orderOut['customer'] = $customer->getData();
        $orderOut['customer']['customer_id'] = $orderIn->getCustomerId();

        /** @var $shipping_address Mage_Sales_Model_Order_Address*/
        $shipping_address = $orderIn->getShippingAddress();
        $orderOut['shipping_address'] = $shipping_address->getData();

        /** @var $shipping_address Mage_Sales_Model_Order_Address*/
        $billing_address = $orderIn->getBillingAddress();
        $orderOut['billing_address'] = $billing_address->getData();

        /** @var $shipping_address Mage_Sales_Model_Order_Payment*/
        $payment = $orderIn->getPayment()->getData();

        // remove cc fields
        foreach ($payment as $key => $value) {
            if (strpos($key, 'cc_') !== 0) {
                $orderOut['payment'][$key] = $value;
            }
        }

        /** @var $orderOut Mage_Core_Model_Session */
        $session = Mage::getModel('core/session');
        $orderOut['visitor'] = $session->getValidatorData();
        return $orderOut;
    }
}