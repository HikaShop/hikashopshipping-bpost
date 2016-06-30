<?php
/**
 * @copyright	Copyright (C) 2009-2016 HIKARI SOFTWARE SARL - All rights reserved.
 * @license		http://www.gnu.org/licenses/gpl-2.0.html GNU/GPL
 */
defined('_JEXEC') or die('Restricted access');
?><?php
class plgHikashopshippingBpost extends hikashopShippingPlugin {
	var $multiple = true;
	var $name = 'bpost';
	var $doc_form = 'bpost';
	var $use_cache = false;
	var $pluginConfig = array(
		'accountId' => array('Customer ID', 'input'),
		'passphrase' => array('Passphrase', 'input'),
		'costCenter' => array('Cost center', 'input'),
	);

	/**
	 *
	 */
	public function onShippingDisplay(&$order,&$dbrates,&$usable_rates,&$messages) {
		$ret = parent::onShippingDisplay($order, $dbrates, $usable_rates, $messages);
		if($ret === false)
			return false;

		if(empty($order->cart_params->bpost))
			return true;
		$app = JFactory::getApplication();
		$shipping_data = reset($app->getUserState(HIKASHOP_COMPONENT.'.shipping_data'));
		$currencyClass = hikashop_get('class.currency');
		foreach($usable_rates as $k => $rate) {
			if($rate->shipping_type != $this->name)
				continue;

			$usable_rates[$k]->shipping_name .= ' '.$order->cart_params->bpost->deliveryMethod;
			$usable_rates[$k]->shipping_price_with_tax = $order->cart_params->bpost->deliveryMethodPriceDefault;
			$round = $currencyClass->getRounding(@$usable_rates[$k]->shipping_currency_id, true);
			$usable_rates[$k]->shipping_price = $currencyClass->getUntaxedPrice($usable_rates[$k]->shipping_price_with_tax, hikashop_getZone(), $usable_rates[$k]->shipping_tax_id, $round);
			$usable_rates[$k]->taxes_added = true;

			if(!empty($shipping_data) && $shipping_data->shipping_id == $usable_rates[$k]->shipping_id) {
				$app->setUserState(HIKASHOP_COMPONENT.'.shipping_data', array($usable_rates[$k]));
			}
		}
		return true;
	}

	/**
	 *
	 */
	public function onHikashopBeforeDisplayView(&$view) {
		if(empty($view->ctrl) || $view->ctrl != 'checkout')
			return true;

		if(!empty($_REQUEST['cancel_bpost'])) {
			$this->_closePopup();
			return true;
		}

		if(!empty($_POST['orderReference']) && !empty($view->full_cart)) {
			$this->_handleReturn($view->full_cart);
		}

		// if the have the shipping_id parameter in the view, we've just displayed the shipping view of the checkout
		if(!empty($view->shipping_id) && !empty($view->rates)) {
			// we loop through the shipping methods displayed and when one match with our plugin we call the displayPopup function
			foreach($view->rates as $rate) {
				if($rate->shipping_type == $this->name) {
					// In the displayPopup function we'll check that the currently selected shipping method match with the shipping method
					$this->_displayPopup($view->full_cart, $rate, $view->shipping_id);
				}
			}
		}
	}

	/**
	 *
	 */
	public function onBeforeOrderCreate(&$order, &$do) {
		if(@$order->order_shipping_method != $this->name) {
			return true;
		}

		// Don't create the order yet if the bpost popup wasn't displayed and validated yet
		if(empty($order->cart->cart_params->bpost)) {
			$do = false;
			return true;
		}

		// Add the shipping parameters and cart_id
		$order->order_shipping_params->bpost = $order->cart->cart_params->bpost;
		$order->order_shipping_params->bpost->cart_id = $order->cart->cart_id;
	}

	/**
	 *
	 */
	public function onAfterOrderCreate(&$order) {
		return $this->onAfterOrderUpdate($order);
	}

	/**
	 *
	 */
	public function onAfterOrderUpdate(&$order) {
		$config =& hikashop_config();
		$confirmed = $config->get('order_confirmed_status');
		//if order status is not updated skip
		if(!isset($order->order_status))
			return true;
		if(!empty($order->order_type) && $order->order_type != 'sale')
			return true;

		if($order->order_status != $confirmed) {
			//if status is not confirmed just skip
			return true;
		}

		if(isset($order->old->order_status) && $order->old->order_status == $confirmed) {
			//if status was already confirmed just skip
			return true;
		}

		$orderClass = hikashop_get('class.order');
		$dbOrder = $orderClass->loadFullOrder($order->order_id);

		if($dbOrder->order_shipping_method != $this->name) {
			return true;
		}

		if(empty($dbOrder->order_shipping_params->bpost->cart_id)) {
			return true;
		}

		$shippingClass = hikashop_get('class.shipping');
		$shipping = $shippingClass->get($dbOrder->order_shipping_id);
		if(emtpy($shipping)) {
			return true;
		}

		$cart = hikashop_get('class.cart');
		$cart->loadAddress($dbOrder->shipping_address_full, $dbOrder->order_shipping_address, 'object', 'shipping');

		// confirm the order to bpost
		$vars = array(
			'accountId' => $shipping->shipping_params->accountId,
			'orderReference' => $dbOrder->order_shipping_params->bpost->cart_id,
			'action' => 'CONFIRM',
			'customerCountry' => $dbOrder->shipping_address_full->shipping_address->address_country->zone_code_2
		);

		ksort($vars);

		$checksum = '';
		foreach($vars as $k => $v) {
			$checksum .= $k.'='.$v.'&';
		}
		$vars['checksum'] = hash('sha256', $checksum.$shipping->shipping_params->passphrase);

		$vars['extra'] = 'Order number: '.$dbOrder->order_number;

		$this->_curl('https://shippingmanager.bpost.be/ShmFrontEnd/start', $vars);
	}

	/**
	 *
	 */
	private function _curl($url, $fields) {
		$ch = curl_init();

		$fields_string = '';
		foreach($fields as $key => $value) {
			$fields_string .= $key . '=' . $value . '&';
		}
		rtrim($fields_string, '&');

		curl_setopt($ch, CURLOPT_AUTOREFERER, true);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_POST, count($fields));
		curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);

		$data = curl_exec($ch);
		curl_close($ch);

		return $data;
	}

	/**
	 *
	 */
	public function onShippingSave(&$cart, &$methods, &$shipping_id, $warehouse_id = null) {
		$return = parent::onShippingSave($cart, $methods, $shipping_id, $warehouse_id);

		// If $return is not false, we are sure that the bpost method is selected since otherwise the onShippingSave function of the bpost plugin wouldn't be called
		if($return) {
			$this->_displayPopup($cart, $return, false);
		}

		return $return;
	}

	/**
	 *
	 */
	private function _handleReturn(&$cart) {
		// Set the shipping price
		if(empty($_POST['deliveryMethod']) || empty($_POST['deliveryMethodPriceDefault']))
			return;

		$cartClass = hikashop_get('class.cart');
		$cartObj = new stdClass();
		$cartObj->cart_id = $cart->cart_id;
		$cartObj->cart_params = $cart->cart_params;
		if(!is_object($cartObj->cart_params))
			$cartObj->cart_params = new stdClass();
		$cartObj->cart_params->bpost = new stdClass();
		$cartObj->cart_params->bpost->deliveryMethod = $_POST['deliveryMethod'];
		$cartObj->cart_params->bpost->deliveryMethodPriceDefault = $_POST['deliveryMethodPriceDefault'] / 100;
		$cartObj->cart_params->bpost->cache = $this->_getCacheKey($cart);
		$cartClass->save($cartObj);

		// Close the popup
		$current_page = hikashop_currentURL();
		$js = '
window.hikashop.ready(function(){
	window.top.location.href = "'.$current_page.'";
	if(window.parent.SHM) {
		try { window.parent.SHM.closePopup(); }catch(e){}
		try { window.parent.SHM.close(); }catch(e){}
		try { window.parent.hkbpostRefresh(); }catch(e){}
	}
	if(window.top.SHM) {
		try { window.top.SHM.closePopup(); }catch(e){}
		try { window.top.SHM.close(); }catch(e){}
		try { window.top.hkbpostRefresh(); }catch(e){}
	}
	try { SHM.closePopup(); }catch(e){}
	try { SHM.close(); }catch(e){}
	try { window.hkbpostRefresh(); }catch(e){}
});';
		$doc = JFactory::getDocument();
		$doc->addScript('https://shippingmanager.bpost.be/ShmFrontEnd/shm.js');
		$doc->addScriptDeclaration("\r\n<!--\r\n".$js."\r\n//-->\r\n");
	}

	private function _closePopup() {
		$js = 'window.hikashop.ready(function(){ window.parent.SHM.closePopup(); });';
		$doc = JFactory::getDocument();
		$doc->addScriptDeclaration("\r\n<!--\r\n".$js."\r\n//-->\r\n");
	}

	/**
	 *
	 */
	private function _getCacheKey(&$order) {
		$order_clone = new stdClass();
		$variables = array('products','cart_id','coupon','shipping_address','volume','weight','volume_unit','weight_unit');
		foreach($variables as $var) {
			if(isset($order->$var))
				$order_clone->$var = $order->$var;
		}
		foreach($order->products as $k => $product) {
			unset($order->products[$k]->cart_params);
		}
		return sha1(serialize($order_clone));
	}

	/**
	 *
	 */
	private function _displayPopup(&$order, &$rate, $check = true) {
		// No need to display the popup if the bpost shipping method is not selected
		if($check) {
			if(is_array($check)) {
				$shipping_ids = $check;
			} else {
				$app = JFactory::getApplication();
				$shipping_ids = $app->getUserState(HIKASHOP_COMPONENT.'.shipping_id');
			}

			$bpost = false;
			foreach($shipping_ids as $shipping_id) {
				$shipping_id = explode('@',$shipping_id);
				$shipping_id = array_shift($shipping_id);
				if($rate->shipping_id == $shipping_id) {
					$bpost = true;
				}
			}
			if(!$bpost)
				return true;
		}

		//display popup only once
		static $done = false;
		if($done)
			return true;
		$done = true;

		//don't ask againt he customer if nothing changed
		$cache_key = $this->_getCacheKey($order);
		if(isset($order->cart_params->bpost->cache) && $order->cart_params->bpost->cache == $cache_key)
			return true;

		if(empty($order->shipping_address_full)) {
			$cart = hikashop_get('class.cart');
			$app = JFactory::getApplication();
			$address = $app->getUserState(HIKASHOP_COMPONENT.'.shipping_address');
			$cart->loadAddress($order->shipping_address_full,$address,'object','shipping');
		}

		$weightHelper = hikashop_get('helper.weight');
		$weight = $weightHelper->convert($order->weight,$order->weight_unit,'g');

		$vars = array(
			'accountId' => $rate->shipping_params->accountId,
			'orderReference' => $order->cart_id,
			'action' => 'START',
			'customerCountry' => $order->shipping_address_full->shipping_address->address_country->zone_code_2,
			'orderWeight' => $weight,
		);

		if(!empty($rate->shipping_params->costCenter)) {
			$vars['costCenter'] = $rate->shipping_params->costCenter;
		}

		ksort($vars);

		// Checksum processing
		$checksum = '';
		foreach($vars as $k => $v) {
			$checksum .= $k.'='.$v.'&';
		}
		$vars['checksum'] = hash('sha256', $checksum.$rate->shipping_params->passphrase);

		if(!empty($order->shipping_address_full->shipping_address->address_firstname))
			$vars['customerFirstName'] = $order->shipping_address_full->shipping_address->address_firstname;

		if(!empty($order->shipping_address_full->shipping_address->address_lastname))
			$vars['customerLastName'] = $order->shipping_address_full->shipping_address->address_lastname;

		if(!empty($order->shipping_address_full->shipping_address->address_company))
			$vars['customerCompany'] = $order->shipping_address_full->shipping_address->address_company;

		if(!empty($order->shipping_address_full->shipping_address->address_street)) {
			$parts = explode(' ',$order->shipping_address_full->shipping_address->address_street);
			$firstpart = trim(reset($parts), ',');
			$lasttpart = end($parts);
			if(is_numeric($firstpart)) {
				$vars['customerStreetNumber'] = trim(array_shift($parts), ',');
				$vars['customerStreet'] = implode(' ', $parts);
			} elseif(is_numeric($lasttpart)) {
				$vars['customerStreetNumber'] = array_pop($parts);
				$vars['customerStreet'] = implode(' ', $parts);
			} else {
				$vars['customerStreet'] = $order->shipping_address_full->shipping_address->address_street;
			}
		}

		if(!empty($order->shipping_address_full->shipping_address->address_city))
			$vars['customerCity'] = $order->shipping_address_full->shipping_address->address_city;

		if(!empty($order->shipping_address_full->shipping_address->address_post_code))
			$vars['customerPostalCode'] = $order->shipping_address_full->shipping_address->address_post_code;

		if(!empty($order->shipping_address_full->shipping_address->address_telephone))
			$vars['customerPhoneNumber'] = $order->shipping_address_full->shipping_address->address_telephone;

		$user = hikashop_loadUser(true);
		$vars['customerEmail'] = $user->user_email;

		$vars['orderTotalPrice'] = (int)($order->full_total->prices[0]->price_value_with_tax * 100);
		if(isset($order->full_total->prices[0]->price_value_without_shipping_with_tax))
			$vars['orderTotalPrice'] = (int)($order->full_total->prices[0]->price_value_without_shipping_with_tax * 100);

		// Order lines processing
		$vars['orderLine'] = array();
		foreach($order->products as $product) {
			if(empty($product->cart_product_quantity))
				continue;
			$vars['orderLine'][] = strip_tags($product->product_name).'|'.(int)$product->cart_product_quantity;
		}

		$current_page = hikashop_currentURL();
		$parsedURL = parse_url($current_page);
		if(!empty($parsedURL['query']))
			$parsedURL['query'] .= '&';
		$parsedURL['query'] .= 'cancel_bpost=1';
		$cancel_URL = $this->unparse_url($parsedURL);

		$vars['confirmUrl'] = $current_page;
		$vars['cancelUrl'] = $cancel_URL;
		$vars['errorUrl'] = $cancel_URL;

		$lang = JFactory::getLanguage();
		$locale = strtolower(substr($lang->get('tag'), 0, 2));
		if(in_array($locale, array('nl','fr','en','de')))
			$vars['lang'] = $locale;

		$parameters = json_encode( $vars );

		$doc = JFactory::getDocument();
		$js = '
window.hikashop.ready(function(){
	SHM.open({
		integrationType: "POPUP",
		parameters: '.$parameters.',
		closeCallback: function(data) {
			if(data === "confirm") {
				window.top.location.href = "'.$current_page.'";
			}
		}
	});
});
window.hkbpostRefresh = function() {
	window.location.href = "'.$current_page.'";
};
';
		$doc->addScript('https://shippingmanager.bpost.be/ShmFrontEnd/shm.js');
		$doc->addScriptDeclaration("\r\n<!--\r\n".$js."\r\n//-->\r\n");

	}

	/**
	 *
	 */
	private function unparse_url($parsed_url) {
		$scheme   = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
		$host     = isset($parsed_url['host']) ? $parsed_url['host'] : '';
		$port     = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
		$user     = isset($parsed_url['user']) ? $parsed_url['user'] : '';
		$pass     = isset($parsed_url['pass']) ? ':' . $parsed_url['pass']  : '';
		$pass     = ($user || $pass) ? $pass.'@' : '';
		$path     = isset($parsed_url['path']) ? $parsed_url['path'] : '';
		$query    = isset($parsed_url['query']) ? '?' . $parsed_url['query'] : '';
		$fragment = isset($parsed_url['fragment']) ? '#' . $parsed_url['fragment'] : '';
		return $scheme.$user.$pass.$host.$port.$path.$query.$fragment;
	}

	/**
	 *
	 */
	public function onShippingConfigurationSave(&$element) {
		$app = JFactory::getApplication();

		if(empty($element->shipping_params->accountId)) {
			$app->enqueueMessage(JText::sprintf('ENTER_INFO', 'BPost', 'Customer Id'));
		}
		if(empty($element->shipping_params->passphrase)) {
			$app->enqueueMessage(JText::sprintf('ENTER_INFO', 'BPost', 'Passphrase'));
		}

		parent::onShippingConfigurationSave($element);
	}

	/**
	 *
	 */
	public function getShippingDefaultValues(&$element) {
		$element->shipping_name = 'BPost';
		$element->shipping_description = '';
		$element->shipping_images = 'bpost';
		$element->shipping_params->postCenter = '';
		$element->shipping_params->accountId = '';
		$elements = array($element);
	}

	/**
	 *
	 */
	public function onShippingConfiguration(&$element) {
		$this->bpost = JRequest::getCmd('name','bpost');
		$this->categoryType = hikashop_get('type.categorysub');
		$this->categoryType->type = 'tax';
		$this->categoryType->field = 'category_id';
		parent::onShippingConfiguration($element);
	}
}
