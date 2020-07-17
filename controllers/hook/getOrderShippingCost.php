<?php

class TreggoShippingModuleGetOrderShippingCostController
{
	public function __construct($module, $file, $path)
	{
		$this->file = $file;
		$this->module = $module;
		$this->context = Context::getContext();
		$this->_path = $path;
	}

	public function getDeliveryService($cart)
	{
	
		$url = 'https://api.treggo.co/1/integrations/prestashop/rates';

		$data = array(
			'email' => Configuration::get('PS_SHOP_EMAIL'),
			'dominio' => $this->context->shop->domain ,
			'cp' => $this->postcode,
			'locality' => $this->city
		);
		
		try {

			// Initiating CURL library instance
			$curl = curl_init();

			// Setting CURL options... 
			curl_setopt($curl, CURLOPT_POST, 1);
			curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
			curl_setopt($curl, CURLOPT_URL, $url);
			curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($curl, CURLOPT_HTTPHEADER, array(
				'Content-Type: application/x-www-form-urlencoded',
				'cache-control: no-cache'
			));

			// Executing CURL request and parsing it from JSON to a PHP array
			$result = curl_exec($curl);
			$result = json_decode($result);

			// Closing CURL connection
			curl_close($curl);

			// If we got a price then we have shipping availability, if not, then we should hide the shipping method
			if(isset($result->total_price) && $result->total_price !== null) {
				$total_price = (int)$result->total_price;

			} elseif(isset($result->message) && $result->message === 'El usuario no tiene coberturas seteadas') {
				$total_price = false;
			}

		} catch (\Exception $e) {
			throw new \Exception('Error de comunicación con el servidor: ' . $e->getMessage()); 
		}

		return $total_price;

	}

	// Get location data necesary to execute Treggo rates request 
	public function loadLocation($cart)
	{
		$address = new Address($cart->id_address_delivery);
		$this->city = $address->city;
		$this->postcode = $address->postcode;
	}

	public function run($cart, $shipping_fees)
	{
		$this->loadLocation($cart);
		$shipping_cost = $this->getDeliveryService($cart);
		if ($shipping_cost === false)
			return false;
		return $shipping_cost + $shipping_fees;
	}
}
