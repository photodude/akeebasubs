<?php
/**
 * @package		akeebasubs
 * @copyright	Copyright (c)2010-2013 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

defined('_JEXEC') or die();

class plgAkeebasubsK2 extends JPlugin
{
	/** @var array Levels to Groups to Add mapping */
	private $addGroups = array();
	
	/** @var array Levels to Groups to Remove mapping */
	private $removeGroups = array();

	public function __construct(& $subject, $config = array())
	{
		if(!is_object($config['params'])) {
			jimport('joomla.registry.registry');
			$config['params'] = new JRegistry($config['params']);
		}

		parent::__construct($subject, $config);
		
		$this->loadLanguage();
		
		// Do we have values from the Olden Days?
		$strAddGroups = $this->params->get('addgroups','');
		$strRemoveGroups = $this->params->get('removegroups','');

		if(!empty($strAddGroups) || !empty($strAddGroups)) {
			// Load level to group mapping from plugin parameters		
			$this->addGroups = $this->parseGroups($strAddGroups);
			$this->removeGroups = $this->parseGroups($strRemoveGroups);
			// Do a transparent upgrade
			$this->upgradeSettings();
		} else {
			$this->loadGroupAssignments();
		}
	}
	
	/**
	 * Renders the configuration page in the component's back-end
	 * 
	 * @param AkeebasubsTableLevel $level
	 * @return object
	 */
	public function onSubscriptionLevelFormRender(AkeebasubsTableLevel $level)
	{
		jimport('joomla.filesystem.file');
		$filename = dirname(__FILE__).'/override/default.php';
		if(!JFile::exists($filename)) {
			$filename = dirname(__FILE__).'/tmpl/default.php';
		}
		
		if(!property_exists($level->params, 'k2_addgroups')) {
			$level->params->k2_addgroups = array();
		}
		if(!property_exists($level->params, 'k2_removegroups')) {
			$level->params->k2_removegroups = array();
		}
		
		@ob_start();
		include_once $filename;
		$html = @ob_get_clean();
		
		$ret = (object)array(
			'title'	=> JText::_('PLG_AKEEBASUBS_K2_TAB_TITLE'),
			'html'	=> $html
		);
		
		return $ret;
	}

	/**
	 * Called whenever a subscription is modified. Namely, when its enabled status,
	 * payment status or valid from/to dates are changed.
	 */
	public function onAKSubscriptionChange($row, $info)
	{
		if(is_null($info['modified']) || empty($info['modified'])) return;
		if(array_key_exists('enabled', (array)$info['modified'])) {
			$this->onAKUserRefresh($row->user_id);
		}
	}
	
	/**
	 * Called whenever the administrator asks to refresh integration status.
	 * 
	 * @param $user_id int The Joomla! user ID to refresh information for.
	 */
	public function onAKUserRefresh($user_id)
	{
		// Make sure we're configured
		if(empty($this->addGroups) && empty($this->removeGroups)) return;
		
		// Get all of the user's subscriptions
		$subscriptions = FOFModel::getTmpInstance('Subscriptions','AkeebasubsModel')
			->user_id($user_id)
			->getList();
			
		// Make sure there are subscriptions set for the user
		if(!count($subscriptions)) return;
		
		// Get the initial list of groups to add/remove from
		$addGroups = array();
		$removeGroups = array();
		foreach($subscriptions as $sub) {
			$level = $sub->akeebasubs_level_id;
			if($sub->enabled) {
				// Enabled subscription, add groups
				if(empty($this->addGroups)) continue;
				if(!array_key_exists($level, $this->addGroups)) continue;
				$groups = $this->addGroups[$level];
				foreach($groups as $group) {
					if(!in_array($group, $addGroups) && ($group > 0)) {
						$addGroups[] = $group;
					}
				}
			} else {
				// Disabled subscription, remove groups
				if(empty($this->removeGroups)) continue;
				if(!array_key_exists($level, $this->removeGroups)) continue;
				$groups = $this->removeGroups[$level];
				
				foreach($groups as $group) {
					if(!in_array($group, $removeGroups) && ($group > 0)) {
						$removeGroups[] = $group;
					}
				}
			}
		}
		
		// If no groups are detected, do nothing
		if(empty($addGroups) && empty($removeGroups)) return;
		
		// Sort the lists
		asort($addGroups);
		asort($removeGroups);
		
		// Clean up the remove groups: if we are asked to both add and remove a user
		// from a group, add wins.
		if(!empty($removeGroups) && !empty($addGroups)) {
			$temp = $removeGroups;
			$removeGroups = array();
			foreach($temp as $group) {
				if(!in_array($group, $addGroups)) {
					$removeGroups[] = $group;
				}
			}
		}
		
		// Get DB connection
		$db = JFactory::getDBO();
		$query = $db->getQuery(true)
			->select('COUNT(*)')
			->from($db->qn('#__k2_users'))
			->where($db->qn('userID').' = '.$db->q($user_id));
		$db->setQuery($query);
		$numRecords = $db->loadResult();
		
		if(empty($addGroups) && empty($removeGroups)) {
			// Case 1: Don't add to groups, don't remove from groups
			return;
		} elseif(!empty($addGroups)) {
			// Case 1: Add to groups
			if($numRecords) {
				// Case 1a. Update an existing record
				$group = array_pop($addGroups);
				
				$query = $db->getQuery(true)
					->update($db->qn('#__k2_users'))
					->set($db->qn('group').' = '.$db->q($group))
					->where($db->qn('userID').' = '.$db->q($user_id));
				$db->setQuery($query);
				$db->query();
			} else {
				// Case 1b. Add a new record
				$user = JFactory::getUser($user_id);
				
				$query = $db->getQuery(true)
					->insert($db->qn('#__k2_users'))
					->columns(array(
						$db->qn('userID'),
						$db->qn('group'),
						$db->qn('userName'),
						$db->qn('description'),
					))->values(
						$db->q($user_id).', '.$db->q($group).', '.$db->q($user->name).', '.$db->q('')
					);
				$db->setQuery($query);
				$db->query();
			}
		} elseif(!empty($removeGroups)) {
			// Case 2: Don't add to groups, remove from groups
			if($numRecords) {
				// Case 2a. Update an existing record
				$query = $db->getQuery(true)
					->select($db->qn('group'))
					->from($db->qn('#__k2_users'))
					->where($db->qn('userID').' = '.$db->q($user_id));
				$db->setQuery($query);
				$group = $db->loadResult();
				if(in_array($group, $removeGroups)) {
					$query = $db->getQuery(true)
						->update($db->qn('#__k2_users'))
						->set($db->qn('group').' = '.$db->q('0'))
						->where($db->qn('userID').' = '.$db->q($user_id));
					$db->setQuery($query);
					$db->query();
				}
			} else {
				
			}
		}
	}
	
	private function loadGroupAssignments()
	{
		$this->addGroups = array();
		$this->removeGroups = array();
		
		$model = FOFModel::getTmpInstance('Levels','AkeebasubsModel');
		$levels = $model->getList(true);
		if(!empty($levels)) {
			foreach($levels as $level)
			{
				$save = false;
				if(is_string($level->params)) {
					$level->params = @json_decode($level->params);
					if(empty($level->params)) {
						$level->params = new stdClass();
					}
				} elseif(empty($level->params)) {
					continue;
				}
				if(property_exists($level->params, 'k2_addgroups'))
				{
					$this->addGroups[$level->akeebasubs_level_id] = $level->params->k2_addgroups;
				}
				if(property_exists($level->params, 'k2_removegroups'))
				{
					$this->removeGroups[$level->akeebasubs_level_id] = $level->params->k2_removegroups;
				}
			}
		}
	}
	
	/**
	 * =========================================================================
	 * !!! CRUFT WARNING !!!
	 * =========================================================================
	 * 
	 * The following methods are leftovers from the Olden Days (before 2.4.5).
	 * At some point (most likely 2.6) they will be removed. For now they will
	 * stay here so that we can do a transparent migration.
	 */
	
	/**
	 * Moves this plugin's settings from the plugin into each subscription
	 * level's configuration parameters.
	 */
	private function upgradeSettings()
	{
		$model = FOFModel::getTmpInstance('Levels','AkeebasubsModel');
		$levels = $model->getList(true);
		if(!empty($levels)) {
			foreach($levels as $level)
			{
				$save = false;
				if(is_string($level->params)) {
					$level->params = @json_decode($level->params);
					if(empty($level->params)) {
						$level->params = new stdClass();
					}
				} elseif(empty($level->params)) {
					$level->params = new stdClass();
				}
				if(array_key_exists($level->akeebasubs_level_id, $this->addGroups)) {
					if(empty($level->params->k2_addgroups)) {
						$level->params->k2_addgroups = $this->addGroups[$level->akeebasubs_level_id];
						$save = true;
					}
				}
				if(array_key_exists($level->akeebasubs_level_id, $this->removeGroups)) {
					if(empty($level->params->k2_removegroups)) {
						$level->params->k2_removegroups = $this->removeGroups[$level->akeebasubs_level_id];
						$save = true;
					}
				}
				if($save) {
					$level->params = json_encode($level->params);
					$result = $model->setId($level->akeebasubs_level_id)->save( $level );
				}
			}
		}
		
		// Remove the plugin parameters
		$this->params->set('addgroups', '');
		$this->params->set('removegroups', '');
		$param_string = $this->params->toString();
		
		$db = JFactory::getDbo();
		$query = $db->getQuery(true)
			->update($db->qn('#__extensions'))
			->where($db->qn('type').'='.$db->q('plugin'))
			->where($db->qn('element').'='.$db->q('k2'))
			->where($db->qn('folder').'='.$db->q('akeebasubs'))
			->set($db->qn('params').' = '.$db->q($param_string));
		$db->setQuery($query);
		$db->query();
	}
	
	/**
	 * Converts an Akeeba Subscriptions level to a numeric ID
	 * 
	 * @param $title string The level's name to be converted to an ID
	 *
	 * @return int The subscription level's ID or -1 if no match is found
	 */
	private function ASLevelToId($title)
	{
		static $levels = null;
		
		// Don't process invalid titles
		if(empty($title)) return -1;
		
		// Fetch a list of subscription levels if we haven't done so already
		if(is_null($levels)) {
			$levels = array();
			$list = FOFModel::getTmpInstance('Levels','AkeebasubsModel')
				->getList();
			if(count($list)) foreach($list as $level) {
				$thisTitle = strtoupper($level->title);
				$levels[$thisTitle] = $level->akeebasubs_level_id;
			}
		}
		
		$title = strtoupper($title);
		if(array_key_exists($title, $levels)) {
			// Mapping found
			return($levels[$title]);
		} elseif( (int)$title == $title ) {
			// Numeric ID passed
			return (int)$title;
		} else {
			// No match!
			return -1;
		}
	}
	
	private function K2GroupToId($title)
	{
		static $groups = null;
		
		if(empty($title)) return -1;
		
		if(is_null($groups)) {
			$groups = array();
			
			$db = JFactory::getDBO();
			$query = $db->getQuery(true)
				->select(array(
					$db->qn('name').' AS '.$db->qn('title'),
					$db->qn('id'),
				))->from($db->qn('#__k2_user_groups'));
			$db->setQuery($query);
			$res = $db->loadObjectList();
			
			if(!empty($res)) {
				foreach($res as $item) {
					$t = strtoupper(trim($item->title));
					$groups[$t] = $item->id;
				}
			}
		}

		$title = strtoupper(trim($title));
		if(array_key_exists($title, $groups)) {
			// Mapping found
			return($groups[$title]);
		} elseif( (int)$title == $title ) {
			// Numeric ID passed
			return (int)$title;
		} else {
			// No match!
			return -1;
		}
	}

	private function parseGroups($rawData)
	{
		if(empty($rawData)) return array();
		
		$ret = array();
		
		// Just in case something funky happened...
		$rawData = str_replace("\\n", "\n", $rawData);
		$rawData = str_replace("\r", "\n", $rawData);
		$rawData = str_replace("\n\n", "\n", $rawData);
		
		$lines = explode("\n", $rawData);
		
		foreach($lines as $line) {
			$line = trim($line);
			$parts = explode('=', $line, 2);
			if(count($parts) != 2) continue;
			
			$level = $parts[0];
			$rawGroups = $parts[1];
			
			$groups = explode(',', $rawGroups);
			if(empty($groups)) continue;
			if(!is_array($groups)) $groups = array($groups);
			
			$levelId = $this->ASLevelToId($level);
			$groupIds = array();
			foreach($groups as $groupTitle) {
				$groupIds[] = $this->K2GroupToId($groupTitle);
			}
			
			$ret[$levelId] = $groupIds;
		}
		
		return $ret;
	}
}