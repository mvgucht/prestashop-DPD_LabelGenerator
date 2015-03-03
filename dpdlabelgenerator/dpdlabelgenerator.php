<?php

if (!defined('_PS_VERSION_'))
 exit;
 
if(!class_exists('DpdLogin'))
	include_once dirname(__FILE__).'/classes/DPD/dpdlogin.php';
	
include_once dirname(__FILE__).'/classes/DPD/dpdshipment.php';
include_once dirname(__FILE__).'/classes/dpdlabelgeneratorconfig.php';

class DpdLabelGenerator extends Module
{
	public $download_location;
	private $hooks = array(
		'actionOrderStatusUpdate'
		,'actionOrderStatusPostUpdate'
		,'actionOrderHistoryAddAfter'
		,'actionObjectOrderUpdateAfter'
		,'actionOrderSlipAdd'
		,'displayAdminOrder'
		,'displayAdminOrderTabOrder'
		,'displayAdminOrderContentOrder'
	);
	
	/************************************
	 * Construct, Install and UnInstall *
	 ************************************/
	 
	public function __construct()
	{
		$this->download_location = _PS_DOWNLOAD_DIR_ . 'dpd';
		$this->config = new DpdLabelGeneratorConfig();
	
		$this->name = 'dpdlabelgenerator';
		$this->version = '0.1.0';
		$this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
		$this->author = 'Michiel Van Gucht';
		
		$this->tab = 'shipping_logistics';
		$this->need_instance = 1;
		$this->bootstrap = true;
		
		$this->limited_countries = array('BE', 'LU', 'NL');
		
		parent::__construct();
		
		$this->displayName = $this->l('DPD Label Generator');
		$this->description = $this->l('This module will automatically generate labels when an order is changed to a configurable status.');
		
		$this->confirmUninstall = $this->l('Are you sure you want to uninstall the DPD Label Generator Module?');
	}
	
	public function install()
	{
		if (Shop::isFeatureActive())
			Shop::setContext(Shop::CONTEXT_ALL);
			
		if (!parent::install())
			return false;
			
		foreach($this->hooks as $hook_name)
			if(!$this->registerHook($hook_name))
				return false;
				
		if(!copy(dirname(__FILE__) . '/views/img/dpd_logo.jpg', _PS_IMG_DIR_ . '/dpd_logo.jpg'))
			return false;
				
		return $this->initDownloadDir() 
			&& $this->installTab();

	}
	
	public function uninstall()
	{
		if (!parent::uninstall())
			return false;
			
		foreach($this->hooks as $hook_name)
			if(!$this->unregisterHook($hook_name))
				return false;
		
		foreach ($this->config->getAllElementsFlat() as $config_element)
		{
			$variable_name = $this->generateVariableName($config_element['name']);
			if (!Configuration::deleteByName($variable_name))
				return false;
		}
		
		return $this->uninstallTab();

	}
	
	/************************
	 * Configuration Screen *
	 ************************/

	public function getContent()
	{	
		$output = null;
		
		if (Tools::isSubmit('submit'.$this->name))
		{
			foreach ($this->config->getAllElementsFlat() as $config_element)
			{
				$variable_name = $this->generateVariableName($config_element['name']);
				$user_readable_name = $config_element['name'];
				
				$value = strval(Tools::getValue($variable_name));
				if (!$value || empty($value))
						$output .= $this->displayError($this->l('Invalid Configuration value ('.$user_readable_name.')'));
					else
						Configuration::updateValue($variable_name, $value);
			}
			
			if ($output == null)
				$output .= $this->displayConfirmation($this->l('Settings updated'));
		}
		return $output.$this->displayForm();
	}
	
	public function displayForm()
	{
		// Get default language
		$default_lang = (int)Configuration::get('PS_LANG_DEFAULT');
		
		$fields_config = $this->config->getAllElements();
		
		$fields_form = array();
		
		foreach ($fields_config as $group_key => $config_group)
		{
			$fields_form[$group_key]['form'] = array(
				'legend'	=> array(
					'title'	=> $this->l($config_group['name'])
				),
				'submit'	=> array(
					'title'	=> $this->l('Save'),
					'class'	=> 'button'
				)
			);
			foreach ($config_group['elements'] as $element)
			{
				$config = $element;
				$config['name'] = $this->generateVariableName($element['name']);
				$config['label'] = $this->l($element['name']);
				
				if(!isset($element['type']))
					$config['type'] = 'text';
					
				$fields_form[$group_key]['form']['input'][] = $config;
			}
		}
		
		$helper = new HelperForm();
		
		// Module, token and currentIndex
		$helper->module = $this;
		$helper->name_controller = $this->name;
		$helper->token = Tools::getAdminTokenLite('AdminModules');
		$helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;
		
		// Language
		$helper->default_form_language = $default_lang;
		$helper->allow_employee_form_lang = $default_lang;
		
		// Title and toolbar
		$helper->title = $this->displayName;
		$helper->show_toolbar = true;			// false -> remove toolbar
		$helper->toolbar_scroll = true;			// yes - > Toolbar is always visible on the top of the screen.
		$helper->submit_action = 'submit'.$this->name;
		$helper->toolbar_btn = array(
			'save' =>
				array(
					'desc' => $this->l('Save'),
					'href' => AdminController::$currentIndex.'&configure='.$this->name.'&save'.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules'),
				),
			'back'	=> 
				array(
					'href'	=> AdminController::$currentIndex.'&token='.Tools::getAdminTokenLite('AdminModules'),
					'desc'	=> $this->l('Back to list')
				)
			);
		
		// Load current value
		foreach ($this->config->getAllElementsFlat() as $config_element)
		{
			$variable_name = $this->generateVariableName($config_element['name']);
			$helper->fields_value[$variable_name] = Configuration::get($variable_name);
		}
		
		return $helper->generateForm($fields_form);
	}
	
	/******************
	 * Hook functions *
	 ******************/
	 
	public function hookActionObjectOrderUpdateAfter($params)
	{
	}
	
	public function hookActionOrderStatusUpdate($params)
	{
		if($params['newOrderStatus']->id == (int)Configuration::get($this->generateVariableName('Generate label on status')))
		{
			$current_order = new Order($params['id_order']);
			$current_carrier = new Carrier($current_order->id_carrier);
			
			if(Configuration::get($this->generateVariableName('DPD Carrier Only'))
				&& $current_carrier->external_module_name != 'dpdcarrier')
					return;

			$url = Configuration::get($this->generateVariableName('live server')) == 1 ? 'https://public-ws.dpd.com/services/' : 'https://public-ws-stage.dpd.com/services/';
		
			$login;
			if(!($login = unserialize(Configuration::get($this->generateVariableName('login'))))
				|| !($login->url == $url))
			{
				$delisID = Configuration::get($this->generateVariableName('delisid'));
				$delisPw = Configuration::get($this->generateVariableName('password'));
			
				try
				{
					$login = new DpdLogin($delisID, $delisPw, $url);
					$login->refreshed = true;
				}
				catch (Exception $e)
				{
					Logger::addLog('Something went wrong logging in to the DPD Web Services (' . $e->getMessage() . ')', 3, null, null, null, true);
					$this->context->controller->errors[] = Tools::displayError('Something went wrong logging in to the DPD Web Services (' . $e->getMessage() . ')');
				}
				if(!count($this->context->controller->errors))
					Configuration::updateValue($this->generateVariableName('login'), serialize($login));
			}
			
			if(!count($this->context->controller->errors))
			{
				//$recipient_address = new Address($current_order->id_address_invoice)
				if($current_carrier->external_module_name == 'dpdcarrier'
					&& $current_carrier->id_reference == $parcelshop_carrier->id_reference)
					$recipient_address = new Address($current_order->id_address_invoice);
				else
					$recipient_address = new Address($current_order->id_address_delivery);
					
				$recipient_customer = new Customer($current_order->id_customer);
				
				$current_order = new Order($params['id_order']);
				$old_order_carrier = new OrderCarrier($current_order->getIdOrderCarrier());
				
				$shipment = new DpdShipment($login);
				
				$shipment->request['order'] = array(
					'generalShipmentData' => array(
						'sendingDepot' => '0522' //$login->depot
						,'product' => 'CL'
						,'sender' => array(
							'name1' => Configuration::get('PS_SHOP_NAME')
							,'street' => Configuration::get('PS_SHOP_ADDR1')
							,'country' => Country::getIsoById(Configuration::get('PS_SHOP_COUNTRY_ID'))
							,'zipCode' => Configuration::get('PS_SHOP_CODE')
							,'city' => Configuration::get('PS_SHOP_CITY')
						)
						,'recipient' => array(
							'name1' => substr($recipient_address->firstname . ' ' . $recipient_address->lastname, 0, 35)
							,'street' => $recipient_address->address1
							,'country' => Country::getIsoById($recipient_address->id_country)
							,'zipCode' => $recipient_address->postcode
							,'city' => $recipient_address->city
						)
					)
					,'parcels' => array(
						'weight' => $old_order_carrier->weight * $this->getWeightMultiplier()
					)
				);
				
				$shipment->request['order']['productAndServiceData']['orderType'] = 'consignment';
				
				$parcelshop_carrier = new Carrier(Configuration::get($this->generateVariableName('DPD ParcelShop carrier id')));
				$classic_carrier = new Carrier(Configuration::get($this->generateVariableName('DPD Classic carrier id')));
				
				
				if($current_carrier->external_module_name == 'dpdcarrier'
					&& $current_carrier->id_reference == $parcelshop_carrier->id_reference)
				{
					$parcelshop_address = new Address($current_order->id_address_delivery);
					$shipment->request['order']['productAndServiceData']['parcelShopDelivery']['parcelShopId'] = $parcelshop_address->other;
					if($recipient_customer->email)
						$shipment->request['order']['productAndServiceData']['parcelShopDelivery']['parcelShopNotification'] = array(
							'channel' => '1'
							,'value' => $recipient_customer->email
							,'language' => Language::getIsoById($current_order->id_lang)
						);
					elseif($recipient_address->phone_mobile)
						$shipment->request['order']['productAndServiceData']['parcelShopDelivery']['parcelShopNotification'] = array(
							'channel' => '3'
							,'value' => $recipient_address->phone_mobile
							,'language' => Language::getIsoById($current_order->id_lang)
						);
				}
				elseif($current_carrier->external_module_name != 'dpdcarrier'
					|| $current_carrier->id_reference != $classic_carrier->id_reference)
					if($recipient_customer->email)
						$shipment->request['order']['productAndServiceData']['predict'] = array(
							'channel' => '1'
							,'value' => $recipient_customer->email
							,'language' => Language::getIsoById($current_order->id_lang)
						);
					elseif($recipient_address->phone_mobile)
						$shipment->request['order']['productAndServiceData']['predict'] = array(
							'channel' => '3'
							,'value' => $recipient_address->phone_mobile
							,'language' => Language::getIsoById($current_order->id_lang)
						);
				
				try
				{
					$shipment->send();
				} 
				catch (Exception $e)
				{
					Logger::addLog('Something went wrong while generating a DPD Label (' . $e->getMessage() . ')', 3, null, null, null, true);
					$this->context->controller->errors[] = Tools::displayError('Something went wrong while generating a DPD Label (' . $e->getMessage() . ')');
				}
				
				if(!count($this->context->controller->errors))
				{
					if($shipment->login->refreshed)
					{
						Logger::addLog('DPD Login Refreshed', 1, null, null, null, true);
						$shipment->login->refreshed = false;
						Configuration::updateValue($this->generateVariableName('login'), serialize($parcelshopfinder->login));
					}
					
					$parcel_label_number = $shipment->result->orderResult->shipmentResponses->parcelInformation->parcelLabelNumber;
					
					if(!($new_pdf = fopen($this->download_location . DS . $parcel_label_number . '.pdf', 'w')))
					{
						Logger::addLog('The new PDF (DPD Label) file could not be created on the file system', 3, null, null, null, true);
						$this->context->controller->errors[] = Tools::displayError('The new PDF file could not be created on the file system');
					}
					if(!fwrite($new_pdf, $shipment->result->orderResult->parcellabelsPDF))
					{
						Logger::addLog('The new PDF (DPD Label) file could not be written to file system', 3, null, null, null, true);
						$this->context->controller->errors[] = Tools::displayError('Label could not be written to file system');
					}
					fclose($new_pdf);
				
					$new_order_carrier = new OrderCarrier();
					
					$new_order_carrier->id_order = $old_order_carrier->id_order;
					$new_order_carrier->id_carrier = $old_order_carrier->id_carrier;
					$new_order_carrier->weight = $old_order_carrier->weight;
					$new_order_carrier->date_add = date("Y-m-d H:i:s");
					$new_order_carrier->tracking_number = $parcel_label_number;
					$new_order_carrier->save();
					
					if($old_order_carrier->tracking_number == '')
					{
						$old_order_carrier->tracking_number = $current_order->reference;
						$old_order_carrier->save();
					}
				}
			}
		}
	}
	
	public function hookActionOrderStatusPostUpdate($params)
	{
	}
	
	public function hookActionOrderHistoryAddAfter($params)
	{
		if($params['order_history']->id_order_state == (int)Configuration::get($this->generateVariableName('Generate label on status'))
			&& Configuration::get($this->generateVariableName('Propose download on status change')) == 1)
			if(!count($this->context->controller->errors))
				Tools::redirectAdmin($this->context->link->getAdminLink('AdminDpdLabels') . "&labelnumber=" . $this->getLastAddedLabel($params['order_history']->id_order));
	}
	
	public function hookDisplayAdminOrderTabOrder($params)
	{
		$output = '';
		$output .= '<li>';
		$output .= '	<a href="#labels">';
		$output .= '		<i class="icon-file-text"></i>';
		$output .= '		' . $this->l('Labels') . ' <span class="badge">' . (count($this->getOrderCarriers($params['order']->id)) - 1) . '</span>';
		$output .= '	</a>';
		$output .= '</li>';
		
		return $output;
	}
	
	public function hookDisplayAdminOrderContentOrder($params)
	{
		$labels = array();
		
		foreach($this->getOrderCarriers($params['order']->id) as $key => $order_carrier)
		{
			if(preg_match("/^[0-9]{14}$/", $order_carrier['tracking_number']))
			{
				$labels[$key] = new stdClass();
				$labels[$key]->number = $order_carrier['tracking_number'];
				$labels[$key]->date_add = $order_carrier['date_add'];
				$labels[$key]->weight = $order_carrier['weight'];
			}
		}

		$this->context->smarty->assign(
			array(
				'downloadLink' => $this->context->link->getAdminLink('AdminDpdLabels')
				,'labels' => $labels
			)
		);

		return $this->display(__FILE__, '_labels.tpl');
	}
	
	/*********************
	 * Private functions *
	 *********************/
	public static function generateVariableName($input)
	{
		$moduleName = 'dpdlabelgenerator';
		if(Module::isInstalled('dpdcarrier')
			&& Module::isEnabled('dpdcarrier'))
			$moduleName = 'dpdcarrier';
			
		return strtoupper($moduleName . '_' . str_replace(" ", "_", $input));
	}

	private function initDownloadDir()
	{
		if(file_exists($this->download_location))
			return true;
			
		if(!mkdir($this->download_location, '755'))
			return false;
		
		if(!copy(_PS_DOWNLOAD_DIR_ . DS . '.htaccess', $this->download_location . DS . '.htaccess'))
			return false;
			
		return true;
	}
	
	private function getOrderCarriers($id_order)
	{
		return Db::getInstance()->executeS('
			SELECT *
			FROM `'._DB_PREFIX_.'order_carrier`
			WHERE `id_order` = '.(int)$id_order);
	}
	
	private function getLastAddedLabel($id_order)
	{
		return Db::getInstance()->getValue('
			SELECT DISTINCT `tracking_number`
			FROM `'._DB_PREFIX_.'order_carrier`
			WHERE `id_order` = '.(int)$id_order.'
			ORDER BY `date_add` DESC');
	}
	
	private function installTab()
	{
		$tab = new Tab();
		$tab->active = 1;
		$tab->name = array();
		$tab->class_name = 'AdminDpdLabels';

		foreach (Language::getLanguages(true) as $lang)
			$tab->name[$lang['id_lang']] = 'DPD Shipping List';

		$tab->id_parent = (int)Tab::getIdFromClassName('AdminShipping');
		$tab->module = $this->name;

		return $tab->add();
	}

	private function uninstallTab()
	{
		$id_tab = (int)Tab::getIdFromClassName('AdminDpdLabels');

		if ($id_tab)
		{
			$tab = new Tab($id_tab);
			return $tab->delete();
		}

		return false;
	}

	private function getWeightMultiplier()
	{
		$weight_multiplier;
		switch(_PS_WEIGHT_UNIT_)
		{
			case 'mg':
				$weight_multiplier = 0.0001;
				break;
			case 'g':
				$weight_multiplier = 0.1;
				break;
			case 'Kg':
				$weight_multiplier = 100;
				break;
			case 'lbs':
				$weight_multiplier = 45.359237;
				break;
			case 'st':
				$weight_multiplier = 635.029318;
				break;
			default:
				$weight_multiplier = 100;
				break;
		}
		return $weight_multiplier;
	}
}