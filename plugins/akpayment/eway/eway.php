<?php
/**
 * @package		akeebasubs
 * @copyright	Copyright (c)2010-2011 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

jimport('joomla.plugin.plugin');

class plgAkpaymentEway extends JPlugin
{
	private $ppName = 'eway';
	private $ppKey = 'PLG_AKPAYMENT_EWAY_TITLE';
	
	private $responseCodes = array(
		'CX'	=> 'Customer Cancelled Transaction',
		'00'	=> 'Transaction Approved',
		'02'	=> 'Refer to Issuer',
		'03'	=> 'No Merchant',
		'04'	=> 'Pick Up Card',
		'05'	=> 'Do Not Honour',
		'06'	=> 'Error',
		'07'	=> 'Pick Up Card, Special',
		'08'	=> 'Honour With Identification',
		'09'	=> 'Request In Progress',
		'10'	=> 'Approved For Partial Amount',
		'11'	=> 'Approved, VIP',
		'12'	=> 'Invalid Transaction',
		'13'	=> 'Invalid Amount',
		'14'	=> 'Invalid Card Number',
		'15'	=> 'No Issuer',
		'16'	=> 'Approved, Update Track 3',
		'19'	=> 'Re-enter Last Transaction',
		'21'	=> 'No Action Taken',
		'22'	=> 'Suspected Malfunction',
		'23'	=> 'Unacceptable Transaction Fee',
		'25'	=> 'Unable to Locate Record On File',
		'30'	=> 'Format Error',
		'31'	=> 'Bank Not Supported By Switch',
		'33'	=> 'Expired Card, Capture',
		'34'	=> 'Suspected Fraud, Retain Card',
		'35'	=> 'Card Acceptor, Contact Acquirer, Retain Card',
		'36'	=> 'Restricted Card, Retain Card',
		'37'	=> 'Contact Acquirer Security Department, Retain Card',
		'38'	=> 'PIN Tries Exceeded, Capture',
		'39'	=> 'No Credit Account',
		'40'	=> 'Function Not Supported',
		'41'	=> 'Lost Card',
		'42'	=> 'No Universal Account',
		'43'	=> 'Stolen Card',
		'44'	=> 'No Investment Account',
		'51'	=> 'Insufficient Funds',
		'52'	=> 'No Cheque Account',
		'53'	=> 'No Savings Account',
		'54'	=> 'Expired Card',
		'55'	=> 'Incorrect PIN',
		'56'	=> 'No Card Record',
		'57'	=> 'Function Not Permitted To Cardholder',
		'58'	=> 'Function Not Permitted To Terminal',
		'59'	=> 'Suspected Fraud',
		'60'	=> 'Acceptor Contact Acquirer',
		'61'	=> 'Exceeds Withdrawal Limit',
		'62'	=> 'Restricted Card',
		'63'	=> 'Security Violation',
		'64'	=> 'Original Amount Incorrect',
		'66'	=> 'Acceptor Contact Acquirer, Security',
		'67'	=> 'Capture Card',
		'75'	=> 'PIN Tries Exceeded',
		'82'	=> 'CVV Validation Error',
		'90'	=> 'Cutoff in Progress',
		'91'	=> 'Card Issuer Unavailable',
		'92'	=> 'Unable to Route Transaction',
		'93'	=> 'Cannot Complete, Violation Of The Law',
		'94'	=> 'Duplicate Transaction',
		'96'	=> 'System Error'
	);
	
	public function __construct(&$subject, $config = array())
	{
		if(!version_compare(JVERSION, '1.6.0', 'ge')) {
			if(!is_object($config['params'])) {
				$config['params'] = new JParameter($config['params']);
			}
		}
		parent::__construct($subject, $config);
		
		// Load the language files
		$jlang =& JFactory::getLanguage();
		$jlang->load('plg_akpayment_eway', JPATH_ADMINISTRATOR, 'en-GB', true);
		$jlang->load('plg_akpayment_eway', JPATH_ADMINISTRATOR, $jlang->getDefault(), true);
		$jlang->load('plg_akpayment_eway', JPATH_ADMINISTRATOR, null, true);
	}
	
	public function onAKPaymentGetIdentity()
	{
		$title = $this->params->get('title','');
		if(empty($title)) $title = JText::_($this->ppKey);
		$ret = array(
			'name'		=> $this->ppName,
			'title'		=> $title
		);
		return (object)$ret;
	}
	
	/**
	 * Returns the payment form to be submitted by the user's browser. The form must have an ID of
	 * "paymentForm" and a visible submit button.
	 * 
	 * @param string $paymentmethod
	 * @param JUser $user
	 * @param KDatabaseRow $level
	 * @param KDatabaseRow $subscription
	 * @return string
	 */
	public function onAKPaymentNew($paymentmethod, $user, $level, $subscription)
	{
		// Check that this is the requested payment plugin
		if($paymentmethod != $this->ppName) return false;
		
		// Split the name in first and last name
		$nameParts = explode(' ', $user->name, 2);
		$firstName = $nameParts[0];
		if(count($nameParts) > 1) {
			$lastName = $nameParts[1];
		} else {
			$lastName = '';
		}
		
		// Get the base URL without the path
		$rootURL = rtrim(JURI::base(),'/');
		$subpathURL = JURI::base(true);
		if(!empty($subpathURL) && ($subpathURL != '/')) {
			$rootURL = substr($rootURL, 0, -1 * strlen($subpathURL));
		}
		
		// Get the level's slug
		$slug = KFactory::get('com://admin/akeebasubs.model.levels')
				->id($subscription->akeebasubs_level_id)
				->getItem()
				->slug;
		
		// Fetch our extended user information
		$kuser = KFactory::get('com://admin/akeebasubs.model.users')
			->user_id($user->id)
			->getItem();
		
		// Construct the transaction key request URL
		jimport('joomla.environment.uri');
		
		switch($this->params->get('site', 0))
		{
			case '0':
			default:
				$apiURL = 'https://au.ewaygateway.com/Request';
				break;
			case '1':
				$apiURL = 'https://payment.ewaygateway.com/Request';
				break;
			case '2':
				$apiURL = 'https://nz.ewaygateway.com/Request';
				break;
		}
		
		$eWayURL = new JURI($apiURL);
		$eWayURL->setVar('CustomerID', $this->params->get('customerid',''));
		$eWayURL->setVar('UserName', $this->params->get('username',''));
		$eWayURL->setVar('Amount', sprintf('%0.2f',$subscription->gross_amount));
		$eWayURL->setVar('Currency', strtoupper(KFactory::get('com://site/akeebasubs.model.configs')->getConfig()->currency));
		$eWayURL->setVar('ReturnURL', JURI::base().'index.php?option=com_akeebasubs&view=callback&paymentmethod=paypal');
		$eWayURL->setVar('CancelURL', $rootURL.str_replace('&amp;','&',JRoute::_('index.php?option=com_akeebasubs&view=message&slug='.$slug.'&layout=cancel')));
		if($this->params->get('companylogo','')) $eWayURL->setVar('CompanyLogo', $this->params->get('companylogo',''));
		if($this->params->get('pagebanner','')) $eWayURL->setVar('Pagebanner', $this->params->get('pagebanner',''));
		$eWayURL->setVar('ModifiableCustomerDetails', 'True');
		if($this->params->get('language','')) $eWayURL->setVar('Language', $this->params->get('language',''));
		if($this->params->get('companyname','')) $eWayURL->setVar('CompanyName', $this->params->get('companyname',''));
		$eWayURL->setVar('CustomerFirstName', $firstName);
		$eWayURL->setVar('CustomerLastName', $lastName);
		$eWayURL->setVar('CustomerAddress', $kuser->address.(empty($kuser->address2)?'':', '.$kuser->address2));
		$eWayURL->setVar('CustomerCity', $kuser->city);
		$eWayURL->setVar('CustomerState', $kuser->state);
		$eWayURL->setVar('CustomerPostCode', $kuser->zip);
		$eWayURL->setVar('CustomerCountry', $kuser->country);
		$eWayURL->setVar('CustomerEmail', $user->email);
		$eWayURL->setVar('InvoiceDescription', $level->title . ' - [ ' . $user->username . ' ]');
		$eWayURL->setVar('MerchantReference', $subscription->id);
		if($this->params->get('pagetitle','')) $eWayURL->setVar('PageTitle', $this->params->get('pagetitle',''));
		if($this->params->get('pagedescription','')) $eWayURL->setVar('PageDescription', $this->params->get('pagedescription',''));
		if($this->params->get('pagefooter','')) $eWayURL->setVar('PageFooter', $this->params->get('pagefooter',''));
		
		$postURL = $eWayURL->toString();
		$postURL = str_replace('Request?', 'Request/?', $postURL);
		
		// Send the transaction key request
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $postURL);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		if(defined('CURL_PROXY_REQUIRED')) if (CURL_PROXY_REQUIRED == 'True') 
		{
			$proxy_tunnel_flag = (defined('CURL_PROXY_TUNNEL_FLAG') && strtoupper(CURL_PROXY_TUNNEL_FLAG) == 'FALSE') ? false : true;
			curl_setopt ($ch, CURLOPT_HTTPPROXYTUNNEL, $proxy_tunnel_flag);
			curl_setopt ($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
			curl_setopt ($ch, CURLOPT_PROXY, CURL_PROXY_SERVER_DETAILS);
		}
		
		$response = curl_exec($ch);
		
		$responsemode = $this->fetch_data($response, '<result>', '</result>');
	    $responseurl = $this->fetch_data($response, '<uri>', '</uri>');
		   
		if($responsemode=="True") {
			@ob_start();
			include dirname(__FILE__).'/eway/form.php';
			$html = @ob_get_clean();
			return $html;
		}
		else {
			JError::raiseError(500, 'You have an error in your eWay setup: '.$response);
		}
	}
	
	public function onAKPaymentCallback($paymentmethod, $data)
	{
		jimport('joomla.utilities.date');
		
		// Check if we're supposed to handle this
		if($paymentmethod != $this->ppName) return false;
		
		jimport('joomla.environment.uri');
		
		switch($this->params->get('site', 0))
		{
			case '0':
			default:
				$apiURL = 'https://au.ewaygateway.com/Result';
				break;
			case '1':
				$apiURL = 'https://payment.ewaygateway.com/Result';
				break;
			case '2':
				$apiURL = 'https://nz.ewaygateway.com/Result';
				break;
		}
		
		$eWayURL = new JURI($apiURL);
		$eWayURL->setVar('CustomerID', $this->params->get('customerid',''));
		$eWayURL->setVar('UserName', $this->params->get('username',''));
		$eWayURL->setVar('AccessPaymentCode', $data['AccessPaymentCode']);
		
		$posturl=$eWayURL->toString();
		$posturl = str_replace('Result?', 'Result/?', $posturl);
		
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $posturl);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_HEADER, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
		if(defined('CURL_PROXY_REQUIRED')) if (CURL_PROXY_REQUIRED == 'True')
		{
			$proxy_tunnel_flag = (defined('CURL_PROXY_TUNNEL_FLAG') && strtoupper(CURL_PROXY_TUNNEL_FLAG) == 'FALSE') ? false : true;
			curl_setopt ($ch, CURLOPT_HTTPPROXYTUNNEL, $proxy_tunnel_flag);
			curl_setopt ($ch, CURLOPT_PROXYTYPE, CURLPROXY_HTTP);
			curl_setopt ($ch, CURLOPT_PROXY, CURL_PROXY_SERVER_DETAILS);
		}
		
		$response = curl_exec($ch);
		$authecode = $this->fetch_data($response, '<authCode>', '</authCode>');
		$responsecode = $this->fetch_data($response, '<responsecode>', '</responsecode>');
		$retrunamount = $this->fetch_data($response, '<returnamount>', '</returnamount>');
		$trxnnumber = $this->fetch_data($response, '<trxnnumber>', '</trxnnumber>');
		$trxnstatus = $this->fetch_data($response, '<trxnstatus>', '</trxnstatus>');
		$trxnresponsemessage = $this->fetch_data($response, '<trxnresponsemessage>', '</trxnresponsemessage>');
		$merchantreference = $this->fetch_data($response, '<merchantreference>', '</merchantreference>');
		
		$isValid = true;

		// Load the relevant subscription row
		if($isValid) {
			$id = $merchantreference;
			$subscription = null;
			if($id > 0) {
				$subscription = KFactory::get('com://admin/akeebasubs.model.subscriptions')
					->id($id)
					->getItem();
				if( ($subscription->id <= 0) || ($subscription->id != $id) ) {
					$subscription = null;
					$isValid = false;
				}
			} else {
				$isValid = false;
			}
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'The referenced subscription ID ("MerchantReference" field) is invalid';
		}
		
		// Check that the amount is correct
		if($isValid && !is_null($subscription)) {
			$mc_gross = floatval($retrunamount);
			$gross = $subscription->gross_amount;
			// Important: NEVER, EVER compare two floating point values for equality.
			$isValid = ($gross - $mc_gross) < 0.01;
			if(!$isValid) $data['akeebasubs_failure_reason'] = 'Paid amount does not match the subscription amount';
		}
		
		// Log the IPN data
		$this->logIPN($response . "\n" . ($isValid ? '' : $data['akeebasubs_failure_reason']."\n"), $isValid);
		
		// Fraud attempt? Do nothing more!
		if(!$isValid) die('Hacking attempt; payment processing refused');
		
		// Load the subscription level and get its slug
		$slug = KFactory::get('com://admin/akeebasubs.model.levels')
				->id($subscription->akeebasubs_level_id)
				->getItem()
				->slug;
		
		$rootURL = rtrim(JURI::base(),'/');
		$subpathURL = JURI::base(true);
		if(!empty($subpathURL) && ($subpathURL != '/')) {
			$rootURL = substr($rootURL, 0, -1 * strlen($subpathURL));
		}
		
		switch($trxnstatus) {
			case 'true':
				$newStatus = 'C';
				$returnURL = $rootURL.str_replace('&amp;','&',JRoute::_('index.php?option=com_akeebasubs&view=message&layout=default&slug='.$slug.'&layout=order'));
				break;
			
			default:
				$newStatus = 'X';
				$returnURL = $rootURL.str_replace('&amp;','&',JRoute::_('index.php?option=com_akeebasubs&view=message&layout=default&slug='.$slug.'&layout=cancel'));
				break;
		}
		
		// Update subscription status (this also automatically calls the plugins)
		$updates = array(
			'id'				=> $id,
			'processor_key'		=> $trxnnumber,
			'state'				=> $newStatus,
			'enabled'			=> 0
		);
		if($newStatus == 'C') {
			// Fix the starting date if the payment was accepted after the subscription's start date. This
			// works around the case where someone pays by e-Check on January 1st and the check is cleared
			// on January 5th. He'd lose those 4 days without this trick. Or, worse, if it was a one-day pass
			// the user would have paid us and we'd never given him a subscription!
			$jNow = new JDate();
			$jStart = new JDate($subscription->publish_up);
			$jEnd = new JDate($subscription->publish_down);
			$now = $jNow->toUnix();
			$start = $jStart->toUnix();
			$end = $jEnd->toUnix();
			
			if($start < $now) {
				$duration = $end - $start;
				$start = $now;
				$end = $start + $duration;
				$jStart = new JDate($start);
				$jEnd = new JDate($end);
			}
			
			$updates['publish_up'] = $jStart->toMySQL();
			$updates['publish_down'] = $jEnd->toMySQL();
			$updates['enabled'] = 1;
		}
		$subscription->setData($updates)->save();
		
		$app = JFactory::getApplication();
		$app->redirect($returnURL);
		
		return true;
	}
	
	private function fetch_data($string, $start_tag, $end_tag)
	{
		$position = stripos($string, $start_tag);  
		$str = substr($string, $position);  		
		$str_second = substr($str, strlen($start_tag));  		
		$second_positon = stripos($str_second, $end_tag);  		
		$str_third = substr($str_second, 0, $second_positon);  		
		$fetch_data = trim($str_third);		
		return $fetch_data; 
	}
	
	private function getPaymentReason($responsecode)
	{
		if($responsecode == 0) return "Successful transaction";
		if(!array_key_exists($responsecode, $this->responseCodes)) return "Unknown status code";
		return $this->responseCodes[$responsecode];
	}
	
	private function logIPN($data, $isValid)
	{
		$config = JFactory::getConfig();
		$logpath = $config->getValue('log_path');
		$logFile = $logpath.'/akpayment_eway_ipn.php';
		jimport('joomla.filesystem.file');
		if(!JFile::exists($logFile)) {
			$dummy = "<?php die(); ?>\n";
			JFile::write($logFile, $dummy);
		} else {
			if(@filesize($logFile) > 1048756) {
				$altLog = $logpath.'/akpayment_eway_ipn-1.php';
				if(JFile::exists($altLog)) {
					JFile::delete($altLog);
				}
				JFile::copy($logFile, $altLog);
				JFile::delete($logFile);
				$dummy = "<?php die(); ?>\n";
				JFile::write($logFile, $dummy);
			}
		}
		$logData = JFile::read($logFile);
		if($logData === false) $logData = '';
		$logData .= "\n" . str_repeat('-', 80);
		$logData .= $isValid ? 'Valid eWay callback' : 'INVALID eWay CALLBACK *** FRAUD ATTEMPT OR FAILED TRANSACTION ***';
		$logData .= "\nDate/time : ".gmdate('Y-m-d H:i:s')." GMT\n\n";
		$logData .= $data."\n";
		$logData .= "\n";
		JFile::write($logFile, $logData);
	}
}