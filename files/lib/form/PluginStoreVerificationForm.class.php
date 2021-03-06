<?php
namespace wcf\form;
use wcf\data\user\group\UserGroup;
use wcf\data\user\group\PluginStoreVerificationGroupsCache; 
use wcf\data\user\UserEditor;
use wcf\system\exception\HTTPServerErrorException;
use wcf\system\exception\HTTPUnauthorizedException;
use wcf\system\exception\IllegalLinkException;
use wcf\system\exception\NamedUserException; 
use wcf\system\exception\SystemException;
use wcf\system\exception\UserInputException;
use wcf\system\request\LinkHandler;
use wcf\system\WCF;
use wcf\util\HeaderUtil;
use wcf\util\HTTPRequest;
use wcf\util\JSON;
use wcf\util\StringUtil;

/**
 * Verification Form.
 *
 * @author	Jeffrey Reichardt
 * @copyright	2012-2014 DevLabor UG (haftungsbeschränkt)
 * @license	GNU Lesser General Public License, version 2.1 <http://opensource.org/licenses/LGPL-2.1>
 * @package	com.devlabor.wcf.store.verification
 * @subpackage	form
 * @category	WoltLab Community Framework 2.0
 */
class PluginStoreVerificationForm extends AbstractForm {
	/**
	 * WoltLab Plugin-Store url
	 * @var	string
	 */
	const PLUGIN_STORE_API_URL = 'https://www.woltlab.com/api/1.1/customer/vendor/list.json';

	/**
	 * @see	\wcf\page\AbstractPage::$activeMenuItem
	 */
	public $activeMenuItem = 'wcf.header.menu.store.verification';

	/**
	 * @see \wcf\page\AbstractPage::$loginRequired
	 */
	public $loginRequired = true;
	
	/**
	 * selected group id
	 * @var	integer
	 */
	public $groupID = 0;

	/**
	 * saving credentials
	 * @var	boolean
	 */
	public $saveCredentials = false;

	/**
	 * store username
	 * @var	string
	 */
	public $woltlabID = '';

	/**
	 * store password
	 * @var	string
	 */
	public $pluginStoreApiKey = '';

	/**
	 * list of groups
	 * @var	array
	 */
	public $availableGroups = array();

	/**
	 * group object
	 * @var	\wcf\data\user\group\UserGroup
	 */
	public $group = null;

	/**
	 * @see	\wcf\page\IPage::readParameters()
	 */
	public function readParameters() {
		parent::readParameters();

		if (isset($_GET['id'])) $this->groupID = intval($_GET['id']);
	}

	/**
	 * @see	\wcf\form\IForm::readFormParameters()
	 */
	public function readFormParameters() {
		parent::readFormParameters();

		if (isset($_POST['groupID'])) $this->groupID = intval($_POST['groupID']);
		if (isset($_POST['saveCredentials'])) $this->saveCredentials = intval($_POST['saveCredentials']);
		if (isset($_POST['woltlabID'])) $this->woltlabID = StringUtil::trim($_POST['woltlabID']);
		if (isset($_POST['pluginStoreApiKey'])) $this->pluginStoreApiKey = StringUtil::trim($_POST['pluginStoreApiKey']);

		$this->group = new UserGroup($this->groupID);
		if (!$this->group->groupID) {
			throw new IllegalLinkException();
		}
	}

	/**
	 * @see	\wcf\page\IPage::readData()
	 */
	public function readData() {
		parent::readData();

		$this->readGroups();

		if (empty($_POST)) {
			$this->woltlabID = WCF::getUser()->woltlabID;
			$this->pluginStoreApiKey = WCF::getUser()->pluginStoreApiKey;
		}
	}

	/**
	 * Reads groups.
	 */
	protected function readGroups() {
		$this->availableGroups = PluginStoreVerificationGroupsCache::getInstance()->getAviableGroups();
		
		if (!count($this->availableGroups)) {
			throw new NamedUserException(WCF::getLanguage()->get('wcf.store.verification.noGroupsAviable')); 
		}
	}

	/**
	 * @see	\wcf\form\IForm::validate()
	 */
	public function validate() {
		parent::validate();

		$this->readGroups();

		// groupID
		if (!in_array($this->groupID, array_keys($this->availableGroups))) {
			throw new UserInputException('groupID');
		}

		// woltlabID
		if (empty($this->woltlabID)) {
			throw new UserInputException('woltlabID');
		}

		// pluginStoreApiKey
		if (empty($this->pluginStoreApiKey)) {
			throw new UserInputException('pluginStoreApiKey');
		}

		// send request
		try {
			$request = new HTTPRequest(self::PLUGIN_STORE_API_URL, array(
				'method' => 'POST'
			), array(
				'vendorID' => PLUGIN_STORE_VENDOR_ID,
				'apiKey' => PLUGIN_STORE_API_KEY,
				'woltlabID' => $this->woltlabID,
				'pluginStoreApiKey' => $this->pluginStoreApiKey,
			));
			$request->execute();

			$reply = $request->getReply();
			$jsonResponse = JSON::decode($reply['body'], false);

			if (!is_array($jsonResponse->fileIDs) || !in_array($this->group->getGroupOption('pluginStoreIdentifier'), $jsonResponse->fileIDs)) {
				throw new SystemException('Can not resolve file ID.');
			}
		}
		catch (HTTPUnauthorizedException $e) {
			throw new UserInputException('woltlabID', 'authFailed');
		}
		catch (HTTPServerErrorException $e) {
			throw new UserInputException('groupID', 'serverUnavailable');
		}
		catch (SystemException $e) {
			throw new UserInputException('groupID', 'unknownError');
		}
	}

	/**
	 * @see	\wcf\form\IForm::save()
	 */
	public function save() {
		parent::save();

		$userEditor = new UserEditor(WCF::getUser());
		$userEditor->addToGroup($this->group->groupID);

		// save credentials to user
		if ($this->saveCredentials) {
			$userEditor->update(array(
				'woltlabID' => $this->woltlabID,
				'pluginStoreApiKey' => $this->pluginStoreApiKey
			));
		}

		UserEditor::resetCache();

		$this->saved();

		$redirectURL = $this->group->getGroupOption('pluginStoreRedirectURL');
		if (empty($redirectURL)) $redirectURL = LinkHandler::getInstance()->getLink('');

		// forward to index
		HeaderUtil::delayedRedirect($redirectURL, WCF::getLanguage()->get('wcf.store.verification.redirect.message'));
		exit;
	}

	/**
	 * @see	\wcf\page\IPage::assignVariables()
	 */
	public function assignVariables() {
		parent::assignVariables();

		WCF::getTPL()->assign(array(
			'availableGroups' => $this->availableGroups,
			'groupID' => $this->groupID,
			'woltlabID' => $this->woltlabID,
			'pluginStoreApiKey' => $this->pluginStoreApiKey,
			'saveCredentials' => (!empty($this->woltlabID))
		));
	}
}
