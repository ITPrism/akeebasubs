<?php
/**
 * @package		akeebasubs
 * @subpackage	plugins.akeebasubs.adminemails
 * @copyright	Copyright (c)2011-2012 ZOOlanders.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

class plgAkeebasubsAdminemails extends JPlugin
{
	protected $emails = array();

	/**
	 * Public constructor. Overridden to load the language strings.
	 */
	public function __construct(& $subject, $config = array())
	{
		if(!version_compare(JVERSION, '1.6.0', 'ge')) {
			if(!is_object($config['params'])) {
				$config['params'] = new JParameter($config['params']);
			}
		}
		parent::__construct($subject, $config);

		$this->emails = explode(',', $this->params->get('emails', ''));
	}

	/**
	 * Called whenever a subscription is modified. Namely, when its enabled status,
	 * payment status or valid from/to dates are changed.
	 */
	public function onAKSubscriptionChange($row, $info)
	{
		// No point running if there are no emails defined, right?
		if(empty($this->emails)) return;
		
		// No payment has been made yet; do not contact the user
		if($row->state == 'N') return;
		
		// Did the payment status just change to C or P? It's a new subscription
		if(array_key_exists('state', (array)$info['modified']) && in_array($row->state, array('P','C'))) {
			if($row->enabled) {
				if(is_object($info['previous']) && $info['previous']->state == 'P') {
					// A pending subscription just got paid
					$this->sendEmail($row, 'paid');
				} else {
					// A new subscription just got paid; send new subscription notification
					$this->sendEmail($row, 'new_active');
				}
			} elseif($row->state == 'C') {
				// A new subscription which is for a renewal (will be active in a future date)
				$this->sendEmail($row, 'new_renewal');
			} else {
				// A new subscription which is pending payment by the processor
				$this->sendEmail($row, 'new_pending');
			}
		} elseif(array_key_exists('state', (array)$info['modified']) && ($row->state == 'X')) {
			// The payment just got refused
			if(!is_object($info['previous']) || $info['previous']->state == 'N') {
				// A new subscription which could not be paid
				$this->sendEmail($row, 'cancelled_new');
			} else {
				// A pending or paid subscription which was cancelled/refunded/whatever
				$this->sendEmail($row, 'cancelled_existing');
			}
		} elseif($info['status'] == 'modified') {
			// If the subscription got disabled and contact_flag is 3, do not send out
			// an expiration notification. The flag is set to 3 only when a user has
			// already renewed his subscription.
			if(array_key_exists('enabled', (array)$info['modified']) && !$row->enabled && ($row->contact_flag == 3)) {
				return;
			} elseif(array_key_exists('enabled', (array)$info['modified']) && !$row->enabled) {
				// Disabled subscription, suppose expired
				$this->sendEmail($row, 'expired');
			} elseif(array_key_exists('enabled', (array)$info['modified']) && $row->enabled) {
				// Subscriptions just enabled, suppose date triggered
				$this->sendEmail($row, 'published');
			} elseif(array_key_exists('contact_flag', (array)$info['modified']) ) {
				// Only contact_flag change; ignore
				return;
			} else {
				// All other cases: generic email
				$this->sendEmail($row, 'generic');
			}
		}
	}
	
	/**
	 * Sends out the email to the owner of the subscription.
	 * 
	 * @param $row AkeebasubsTableSubscription The subscription row object
	 * @param $type string The type of the email to send (generic, new,)
	 */
	private function sendEmail($row, $type = '')
	{
		// Get the site name
		$config = JFactory::getConfig();
		$sitename = $config->getValue('config.sitename');
	
		// Get the user object
		$user = JFactory::getUser($row->user_id);
		
		// Load the language files and their overrides
		$jlang = JFactory::getLanguage();
		// -- English (default fallback)
		$jlang->load('plg_akeebasubs_adminemails', JPATH_ADMINISTRATOR, 'en-GB', true);
		$jlang->load('plg_akeebasubs_adminemails.override', JPATH_ADMINISTRATOR, 'en-GB', true);
		// -- Default site language
		$jlang->load('plg_akeebasubs_adminemails', JPATH_ADMINISTRATOR, $jlang->getDefault(), true);
		$jlang->load('plg_akeebasubs_adminemails.override', JPATH_ADMINISTRATOR, $jlang->getDefault(), true);
		// -- Current site language
		$jlang->load('plg_akeebasubs_adminemails', JPATH_ADMINISTRATOR, null, true);
		$jlang->load('plg_akeebasubs_adminemails.override', JPATH_ADMINISTRATOR, null, true);
		// -- User's preferred language
		$uparams = is_object($user->params) ? $user->params : new JParameter($user->params);
		$userlang = $uparams->getValue('language','');
		if(!empty($userlang)) {
			$jlang->load('plg_akeebasubs_adminemails', JPATH_ADMINISTRATOR, $userlang, true);
			$jlang->load('plg_akeebasubs_adminemails.override', JPATH_ADMINISTRATOR, $userlang, true);
		}
		
		// Get the user's name
		$fullname = $user->name;
		$nameParts = explode(' ',$fullname, 2);
		$firstname = array_shift($nameParts);
		$lastname = !empty($nameParts) ? array_shift($nameParts) : '';
		
		// Get the level
		$level = FOFModel::getTmpInstance('Levels','AkeebasubsModel')
			->setId($row->akeebasubs_level_id)
			->getItem();
			
		// Get the from/to dates
		jimport('joomla.utilities.date');
		$jFrom = new JDate($row->publish_up);
		$jTo = new JDate($row->publish_down);
		
		// Get the "my subscriptions" URL
		$baseURL = JURI::base();
		$baseURL = str_replace('/administrator', '', $baseURL);
		$subpathURL = JURI::base(true);
		$subpathURL = str_replace('/administrator', '', $subpathURL);
		
		if(JFactory::getApplication()->isAdmin()) {
			$url = 'index.php?option=com_akeebasubs&view=subscriptions&layout=default';
		} else {
			$url = str_replace('&amp;','&', JRoute::_('index.php?option=com_akeebasubs&view=subscriptions&layout=default'));
		}
		$url = ltrim($url, '/');
		$subpathURL = ltrim($subpathURL, '/');
		if(substr($url,0,strlen($subpathURL)+1) == "$subpathURL/") $url = substr($url,strlen($subpathURL)+1);
		$url = rtrim($baseURL,'/').'/'.ltrim($url,'/');

		$replacements = array(
			"\\n"			=> "\n",
			'[SITENAME]'	=> $sitename,
			'[FULLNAME]'	=> $fullname,
			'[FIRSTNAME]'	=> $firstname,
			'[LASTNAME]'	=> $lastname,
			'[USERNAME]'	=> $user->username,
			'[USEREMAIL]'	=> $user->email,
			'[LEVEL]'		=> $level->title,
			'[ENABLED]'		=> JText::_('PLG_AKEEBASUBS_AFFEMAILS_COMMON_'. ($row->enabled ? 'ENABLED' : 'DISABLED')),
			'[PAYSTATE]'	=> JText::_('COM_AKEEBASUBS_SUBSCRIPTION_STATE_'.$row->state),
			'[PUBLISH_UP]'	=> version_compare(JVERSION, '1.6', 'ge') ? $jFrom->format(JText::_('DATE_FORMAT_LC2')) : $jFrom->toFormat(JText::_('DATE_FORMAT_LC2')),
			'[PUBLISH_DOWN]' => version_compare(JVERSION, '1.6', 'ge') ? $jTo->format(JText::_('DATE_FORMAT_LC2')) : $jTo->toFormat(JText::_('DATE_FORMAT_LC2')),
			'[MYSUBSURL]'	=> $url
		);
		
		$subject = JText::_('PLG_AKEEBASUBS_ADMINEMAILS_HEAD_'.strtoupper($type));
		$body = JText::_('PLG_AKEEBASUBS_ADMINEMAILS_BODY_'.strtoupper($type));
		
		foreach($replacements as $key => $value) {
			$subject = str_replace($key, $value, $subject);
			$body = str_replace($key, $value, $body);
		}
		
		// Process merge tags
		require_once JPATH_SITE.'/components/com_akeebasubs/helpers/message.php';
		$subject = AkeebasubsHelperMessage::processSubscriptionTags($subject, $row);
		$body = AkeebasubsHelperMessage::processSubscriptionTags($body, $row);
		
		// If the subject or the body is empty, skip the email
		if(empty($subject) || empty($body)) return;
		
		// DEBUG ---
		/* *
		echo "<p><strong>From</strong>: ".$config->getvalue('config.fromname')." &lt;".$config->getvalue('config.mailfrom')."&gt;<br/><strong>To: </strong>".$user->email."</p><hr/><p>$subject</p><hr/><p>".nl2br($body)."</p>"; die();
		/* */
		// -- DEBUG


		// Send the email
		$mailer = JFactory::getMailer();
		$mailer->setSender(array( $config->getvalue('config.mailfrom'), $config->getvalue('config.fromname') ));
		$mailer->addRecipient($this->emails);
		$mailer->setSubject($subject);
		$mailer->setBody($body);
		$mailer->Send();
	}
}