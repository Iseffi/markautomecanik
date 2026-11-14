<?php
/* Copyright (C) 2026 Michel L'Hostis <michel.lhostis@iseffi.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file       markautomecanik/class/markautomecanikpush.class.php
 * \ingroup    markautomecanik
 * \brief      Synchronisation Dolibarr -> Webmecanik of contacts and third parties (push queue)
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/markautomecaniklead.class.php';
require_once __DIR__.'/markautomecaniksync.class.php';

/**
 * Sends Dolibarr contacts (and the third party they belong to) to Webmecanik Automation.
 *
 * Triggers only add the object to a queue (no API call while the user saves a record); the
 * scheduled job cronPush() sends the queue, with retries and exponential backoff.
 *
 * The Webmecanik ids are stored in the extrafields mautic_contact_id (contacts) and mautic_tier_id
 * (third parties), the ones used by the DoliMautic module, so that existing links are kept.
 *
 * Rules:
 * - Dolibarr is the reference for the fields it sends, but empty Dolibarr values are never sent:
 *   data collected by Webmecanik (forms) are not erased;
 * - only contacts with a valid email and in the configured scope (categories) are sent;
 * - a contact unsubscribed from mass emailings in Dolibarr is flagged "do not contact" in Webmecanik;
 * - the links contact <-> company created by Dolibarr are kept consistent with the Dolibarr third party;
 * - logs and the queue contain object ids only, no personal data.
 */
class MarkAutoMecanikPush
{
	/** Queue statuses */
	const STATUS_PENDING = 0;
	const STATUS_DONE = 1;
	const STATUS_SKIPPED = 2;
	const STATUS_CANCELLED = 3;
	const STATUS_FAILED = -1;

	/** Element types */
	const TYPE_CONTACT = 'contact';
	const TYPE_THIRDPARTY = 'thirdparty';

	/** Actions */
	const ACTION_UPSERT = 'upsert';
	const ACTION_DELETE = 'delete';

	/** Maximum attempts before an item is marked as failed */
	const MAX_ATTEMPTS = 8;

	/** Default number of items sent per run */
	const BATCH_SIZE = 200;

	/** Name of the lock constant */
	const LOCK_NAME = 'MARKAUTOMECANIK_PUSH_LOCK';

	/** Processed items kept in the queue (days) */
	const KEEP_DONE_DAYS = 30;

	/** @var array Extrafields storing the Webmecanik ids: type => array(table, column) */
	const LINK_COLUMNS = array(
		self::TYPE_CONTACT => array('socpeople_extrafields', 'mautic_contact_id'),
		self::TYPE_THIRDPARTY => array('societe_extrafields', 'mautic_tier_id'),
	);

	/** @var array Dolibarr civility code => Webmecanik title */
	const TITLES = array('MR' => 'Mr', 'MME' => 'Mrs', 'MLE' => 'Miss', 'DR' => 'Dr');

	/** @var array Dolibarr social network => Webmecanik field */
	const SOCIAL_FIELDS = array('facebook' => 'facebook', 'instagram' => 'instagram', 'linkedin' => 'linkedin', 'skype' => 'skype', 'twitter' => 'twitter');

	/** @var DoliDB */
	public $db;

	/** @var string Output of the last run */
	public $output = '';

	/** @var string Last error */
	public $error = '';

	/** @var array Counters of the last run */
	public $stats = array('received' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0);

	/** @var MarkAutoMecanikSync */
	protected $sync;

	/** @var array Webmecanik company id per third party, for the current run */
	protected $companyCache = array();

	/** @var int[]|null Categories in scope (with their descendants), null = all contacts */
	protected $scopeCategories = false;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->sync = new MarkAutoMecanikSync($db);
	}

	/**
	 * Webmecanik API client
	 *
	 * @return MarkAutoMecanikWebmecanikClient
	 */
	protected function getClient()
	{
		return $this->sync->getClient();
	}

	// -------------------------------------------------------------------------------------------
	// Queue
	// -------------------------------------------------------------------------------------------

	/**
	 * Add an object to the push queue (called by the triggers). Nothing is done when the push is
	 * disabled. A pending item for the same object and action is not duplicated.
	 *
	 * @param DoliDB $db     Database handler
	 * @param string $type   TYPE_CONTACT or TYPE_THIRDPARTY
	 * @param int    $id     Object id
	 * @param string $action ACTION_UPSERT or ACTION_DELETE
	 * @param int    $wmId   Webmecanik id (for a deletion, the object no longer exists afterwards)
	 * @return int <0 if KO, 0 if nothing done, >0 if queued
	 */
	public static function enqueue($db, $type, $id, $action = self::ACTION_UPSERT, $wmId = 0)
	{
		global $conf;

		$id = (int) $id;
		if (!getDolGlobalInt('MARKAUTOMECANIK_PUSH_ENABLED') || $id <= 0 || !isset(self::LINK_COLUMNS[$type])) {
			return 0;
		}
		$table = MAIN_DB_PREFIX.'markautomecanik_pushqueue';
		$where = ' WHERE entity = '.((int) $conf->entity)." AND element_type = '".$db->escape($type)."' AND fk_object = ".$id;

		if ($action === self::ACTION_DELETE) {
			// The object is being deleted: pending updates are useless
			$db->query('UPDATE '.$table.' SET status = '.self::STATUS_CANCELLED.$where.' AND status = '.self::STATUS_PENDING);
		} else {
			$resql = $db->query('SELECT rowid FROM '.$table.$where." AND action = '".$db->escape($action)."' AND status = ".self::STATUS_PENDING);
			if ($resql && $db->num_rows($resql) > 0) {
				return 0;
			}
		}

		$now = $db->idate(dol_now());
		$sql = 'INSERT INTO '.$table.' (entity, element_type, fk_object, action, wm_id, status, attempts, date_creation, date_next_try)';
		$sql .= ' VALUES ('.((int) $conf->entity).", '".$db->escape($type)."', ".$id.", '".$db->escape($action)."'";
		$sql .= ', '.((int) $wmId > 0 ? (int) $wmId : 'NULL').', '.self::STATUS_PENDING.", 0, '".$now."', '".$now."')";
		if (!$db->query($sql)) {
			dol_syslog(__METHOD__.' '.$db->lasterror(), LOG_ERR);
			return -1;
		}
		return 1;
	}

	/**
	 * Queue all the contacts in scope that are not yet linked to Webmecanik
	 *
	 * @param int $since Only contacts created since this timestamp (0 = all)
	 * @return int <0 if KO, number of contacts queued
	 */
	public function enqueueUnsynchronized($since = 0)
	{
		global $conf;

		if (!$this->checkSchema()) {
			return -1;
		}
		$now = $this->db->idate(dol_now());
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'markautomecanik_pushqueue (entity, element_type, fk_object, action, status, attempts, date_creation, date_next_try)';
		$sql .= ' SELECT '.((int) $conf->entity).", '".self::TYPE_CONTACT."', p.rowid, '".self::ACTION_UPSERT."', ".self::STATUS_PENDING.", 0, '".$now."', '".$now."'";
		$sql .= ' FROM '.MAIN_DB_PREFIX.'socpeople as p';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'socpeople_extrafields as ef ON ef.fk_object = p.rowid';
		$sql .= ' WHERE p.entity IN ('.getEntity('contact').')';
		$sql .= " AND p.email IS NOT NULL AND p.email <> '' AND p.statut = 1";
		$sql .= ' AND (ef.mautic_contact_id IS NULL OR ef.mautic_contact_id = 0)';
		if ($since > 0) {
			$sql .= " AND p.datec >= '".$this->db->idate($since)."'";
		}
		$sql .= $this->getSqlScopeRestriction('p');
		$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'markautomecanik_pushqueue as q';
		$sql .= ' WHERE q.entity = '.((int) $conf->entity)." AND q.element_type = '".self::TYPE_CONTACT."' AND q.fk_object = p.rowid AND q.status = ".self::STATUS_PENDING.')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
		return (int) $this->db->affected_rows($resql);
	}

	/**
	 * Put the failed items back in the queue
	 *
	 * @return int <0 if KO, number of items
	 */
	public function retryFailed()
	{
		global $conf;
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'markautomecanik_pushqueue';
		$sql .= ' SET status = '.self::STATUS_PENDING.", attempts = 0, date_next_try = '".$this->db->idate(dol_now())."'";
		$sql .= ' WHERE entity = '.((int) $conf->entity).' AND status = '.self::STATUS_FAILED;
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return (int) $this->db->affected_rows($resql);
	}

	/**
	 * Number of queue items per status for the current entity
	 *
	 * @return array status => number
	 */
	public function getQueueCounts()
	{
		global $conf;
		$counts = array(self::STATUS_PENDING => 0, self::STATUS_DONE => 0, self::STATUS_SKIPPED => 0, self::STATUS_CANCELLED => 0, self::STATUS_FAILED => 0);
		$sql = 'SELECT status, COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'markautomecanik_pushqueue';
		$sql .= ' WHERE entity = '.((int) $conf->entity).' GROUP BY status';
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$counts[(int) $obj->status] = (int) $obj->nb;
			}
			$this->db->free($resql);
		}
		return $counts;
	}

	/**
	 * Delay before the next attempt (exponential backoff: 5 min, 10 min, 20 min... up to 24 h)
	 *
	 * @param int $attempts Number of attempts already done
	 * @return int Seconds
	 */
	public static function getRetryDelay($attempts)
	{
		$attempts = max(1, (int) $attempts);
		return (int) min(86400, 300 * pow(2, $attempts - 1));
	}

	/**
	 * Check that the tables and extrafields used by the push exist
	 *
	 * @return bool
	 */
	protected function checkSchema()
	{
		$checks = array(
			'markautomecanik_pushqueue' => 'rowid',
			'socpeople_extrafields' => 'mautic_contact_id',
			'societe_extrafields' => 'mautic_tier_id',
		);
		foreach ($checks as $table => $column) {
			$resql = $this->db->query('SELECT '.$column.' FROM '.MAIN_DB_PREFIX.$table.' WHERE 1 = 0');
			if (!$resql) {
				$this->error = 'Column '.MAIN_DB_PREFIX.$table.'.'.$column.' is missing: disable and re-enable the module to complete its upgrade';
				dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
				return false;
			}
			$this->db->free($resql);
		}
		return true;
	}

	/**
	 * Send the pending items of the queue
	 *
	 * @param int  $limit Maximum number of items
	 * @param User $user  User (for the history)
	 * @param string $trigger 'cron' or 'manual'
	 * @return int 0 if OK, <0 if KO
	 */
	public function processQueue($limit, $user, $trigger = 'cron')
	{
		global $conf, $langs;

		$langs->load('markautomecanik@markautomecanik');
		$this->stats = array('received' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0);
		$this->companyCache = array();
		$start = dol_now();

		if (!getDolGlobalInt('MARKAUTOMECANIK_PUSH_ENABLED')) {
			$this->output = $langs->trans('PushDisabled');
			return 0;
		}
		$client = $this->getClient();
		if (!$client->isConfigured()) {
			$this->error = $client->error;
			return -1;
		}
		if (!$this->checkSchema()) {
			return -1;
		}
		if (!$this->sync->acquireLock(self::LOCK_NAME)) {
			$this->error = $langs->trans('ErrorPushAlreadyRunning');
			return -2;
		}

		$table = MAIN_DB_PREFIX.'markautomecanik_pushqueue';
		// Housekeeping
		$this->db->query('DELETE FROM '.$table.' WHERE entity = '.((int) $conf->entity).' AND status IN ('.self::STATUS_DONE.', '.self::STATUS_SKIPPED.', '.self::STATUS_CANCELLED.")
			AND date_creation < '".$this->db->idate(dol_now() - self::KEEP_DONE_DAYS * 86400)."'");

		$sql = 'SELECT rowid, element_type, fk_object, action, wm_id, attempts FROM '.$table;
		$sql .= ' WHERE entity = '.((int) $conf->entity).' AND status = '.self::STATUS_PENDING;
		$sql .= " AND date_next_try <= '".$this->db->idate(dol_now())."'";
		$sql .= ' ORDER BY rowid';
		$sql .= $this->db->plimit(max(1, (int) $limit));
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			$this->sync->releaseLock(self::LOCK_NAME);
			return -1;
		}
		$items = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$items[] = $obj;
		}
		$this->db->free($resql);

		foreach ($items as $item) {
			$this->stats['received']++;
			$this->error = '';
			if ($item->action === self::ACTION_DELETE) {
				$result = $this->deleteContact((int) $item->wm_id);
			} elseif ($item->element_type === self::TYPE_THIRDPARTY) {
				$result = $this->pushThirdpartyItem((int) $item->fk_object);
			} else {
				$result = $this->pushContact((int) $item->fk_object);
			}
			$this->saveItemResult($item, $result);
		}

		$this->output = $langs->trans('PushSummary', $this->stats['received'], $this->stats['created'], $this->stats['updated'], $this->stats['skipped'], $this->stats['errors']);
		$failed = ($this->stats['errors'] > 0);
		if ($failed) {
			$this->error = $this->stats['errors'].' item(s) could not be sent, they will be retried (see the logs)';
		}
		if ($this->stats['received'] > 0) {
			$this->sync->stats = $this->stats;
			$this->sync->writeLog('push', $start, ($failed ? -1 : 1), ($failed ? $this->error : $this->output), $user);
		}
		$this->sync->releaseLock(self::LOCK_NAME);
		dol_syslog(__METHOD__.' '.$this->output, $failed ? LOG_WARNING : LOG_INFO);
		return ($failed ? -1 : 0);
	}

	/**
	 * Store the result of a queue item and update the counters
	 *
	 * @param object $item   Queue item
	 * @param string $result 'created', 'updated', 'skipped' or 'error'
	 * @return void
	 */
	protected function saveItemResult($item, $result)
	{
		$now = dol_now();
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'markautomecanik_pushqueue SET';
		if ($result === 'error') {
			$this->stats['errors']++;
			$attempts = (int) $item->attempts + 1;
			$sql .= ' attempts = '.$attempts;
			if ($attempts >= self::MAX_ATTEMPTS) {
				$sql .= ', status = '.self::STATUS_FAILED;
			} else {
				$sql .= ", date_next_try = '".$this->db->idate($now + self::getRetryDelay($attempts))."'";
			}
			$sql .= ", last_error = '".$this->db->escape(dol_substr((string) $this->error, 0, 255))."'";
		} else {
			$this->stats[$result === 'skipped' ? 'skipped' : $result]++;
			$sql .= ' status = '.($result === 'skipped' ? self::STATUS_SKIPPED : self::STATUS_DONE);
			$sql .= ", date_done = '".$this->db->idate($now)."', last_error = ".($result === 'skipped' && $this->error !== '' ? "'".$this->db->escape(dol_substr($this->error, 0, 255))."'" : 'NULL');
		}
		$sql .= ' WHERE rowid = '.((int) $item->rowid);
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
		}
	}

	/**
	 * Scheduled job: send the queue
	 *
	 * @return int 0 if OK, <0 if KO
	 */
	public function cronPush()
	{
		global $user;
		$result = $this->processQueue(self::BATCH_SIZE, $user, 'cron');
		return ($result < 0 ? -1 : 0);
	}

	// -------------------------------------------------------------------------------------------
	// Links between Dolibarr and Webmecanik ids (extrafields shared with DoliMautic)
	// -------------------------------------------------------------------------------------------

	/**
	 * Webmecanik id of a Dolibarr object
	 *
	 * @param string $type TYPE_CONTACT or TYPE_THIRDPARTY
	 * @param int    $id   Object id
	 * @return int 0 if not linked
	 */
	public function getLinkedId($type, $id)
	{
		list($table, $column) = self::LINK_COLUMNS[$type];
		$resql = $this->db->query('SELECT '.$column.' as wmid FROM '.MAIN_DB_PREFIX.$table.' WHERE fk_object = '.((int) $id));
		if (!$resql) {
			return 0;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return ($obj ? (int) $obj->wmid : 0);
	}

	/**
	 * Store the Webmecanik id of a Dolibarr object. Done in SQL on purpose: an update of the object
	 * would call the triggers again and queue the object once more.
	 *
	 * @param string $type TYPE_CONTACT or TYPE_THIRDPARTY
	 * @param int    $id   Object id
	 * @param int    $wmId Webmecanik id (0 to unlink)
	 * @return bool
	 */
	public function setLinkedId($type, $id, $wmId)
	{
		list($table, $column) = self::LINK_COLUMNS[$type];
		$value = ((int) $wmId > 0 ? (int) $wmId : 'NULL');
		$resql = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.$table.' WHERE fk_object = '.((int) $id));
		if (!$resql) {
			return false;
		}
		$exists = ($this->db->num_rows($resql) > 0);
		$this->db->free($resql);
		if ($exists) {
			$sql = 'UPDATE '.MAIN_DB_PREFIX.$table.' SET '.$column.' = '.$value.' WHERE fk_object = '.((int) $id);
		} else {
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.$table.' (fk_object, '.$column.') VALUES ('.((int) $id).', '.$value.')';
		}
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
			return false;
		}
		return true;
	}

	// -------------------------------------------------------------------------------------------
	// Scope
	// -------------------------------------------------------------------------------------------

	/**
	 * Parse a comma separated list of ids
	 *
	 * @param string $value List
	 * @return int[]
	 */
	public static function parseIds($value)
	{
		return array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $value)), function ($v) {
			return $v > 0;
		})));
	}

	/**
	 * Categories in scope (configured categories and all their descendants)
	 *
	 * @return int[]|null null when every contact is in scope
	 */
	protected function getScopeCategories()
	{
		if ($this->scopeCategories !== false) {
			return $this->scopeCategories;
		}
		$ids = self::parseIds(getDolGlobalString('MARKAUTOMECANIK_PUSH_CATEGORIES'));
		if (empty($ids)) {
			$this->scopeCategories = null;
			return null;
		}
		$all = array_combine($ids, $ids);
		$level = $ids;
		for ($depth = 0; $depth < 20 && !empty($level); $depth++) {
			$resql = $this->db->query('SELECT rowid FROM '.MAIN_DB_PREFIX.'categorie WHERE fk_parent IN ('.implode(',', $level).')');
			$level = array();
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					if (!isset($all[(int) $obj->rowid])) {
						$all[(int) $obj->rowid] = (int) $obj->rowid;
						$level[] = (int) $obj->rowid;
					}
				}
				$this->db->free($resql);
			}
		}
		$this->scopeCategories = array_values($all);
		return $this->scopeCategories;
	}

	/**
	 * SQL restriction of the contacts (alias $alias) to the push scope
	 *
	 * @param string $alias Alias of llx_socpeople
	 * @return string SQL fragment starting with " AND " (or empty string)
	 */
	protected function getSqlScopeRestriction($alias)
	{
		$sql = '';
		$scope = $this->getScopeCategories();
		if ($scope !== null) {
			$sql .= ' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'categorie_contact as mamcc WHERE mamcc.fk_socpeople = '.$alias.'.rowid AND mamcc.fk_categorie IN ('.implode(',', $scope).'))';
		}
		$excluded = self::parseIds(getDolGlobalString('MARKAUTOMECANIK_PUSH_EXCLUDED_CATEGORIES'));
		if (!empty($excluded)) {
			$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'categorie_contact as mamcx WHERE mamcx.fk_socpeople = '.$alias.'.rowid AND mamcx.fk_categorie IN ('.implode(',', $excluded).'))';
		}
		return $sql;
	}

	/**
	 * Check that a contact is in the push scope
	 *
	 * @param int $contactId Contact id
	 * @return bool
	 */
	public function isContactInScope($contactId)
	{
		$sql = 'SELECT p.rowid FROM '.MAIN_DB_PREFIX.'socpeople as p WHERE p.rowid = '.((int) $contactId);
		$sql .= $this->getSqlScopeRestriction('p');
		$resql = $this->db->query($sql);
		$ok = ($resql && $this->db->num_rows($resql) > 0);
		if ($resql) {
			$this->db->free($resql);
		}
		return $ok;
	}

	// -------------------------------------------------------------------------------------------
	// Payloads
	// -------------------------------------------------------------------------------------------

	/**
	 * Label of a dictionary entry
	 *
	 * @param string $table  Dictionary table without prefix
	 * @param string $column Label column
	 * @param int    $id     Row id
	 * @return string
	 */
	protected function getDictionaryLabel($table, $column, $id)
	{
		if ((int) $id <= 0) {
			return '';
		}
		$resql = $this->db->query('SELECT '.$column.' as label FROM '.MAIN_DB_PREFIX.$table.' WHERE rowid = '.((int) $id));
		if (!$resql) {
			return '';
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return ($obj ? (string) $obj->label : '');
	}

	/**
	 * Remove empty values and clean the others (single line, bounded length)
	 *
	 * @param array $data Field => value
	 * @return array
	 */
	public static function cleanPayload($data)
	{
		$out = array();
		foreach ($data as $field => $value) {
			$value = MarkAutoMecanikLead::cleanText($value, 255);
			if ($value !== '') {
				$out[$field] = $value;
			}
		}
		return $out;
	}

	/**
	 * Webmecanik fields of a contact
	 *
	 * @param Contact $contact Contact
	 * @return array
	 */
	public function buildContactPayload($contact)
	{
		$civility = !empty($contact->civility_code) ? $contact->civility_code : (is_numeric($contact->civility_id) ? '' : (string) $contact->civility_id);
		$data = array(
			'title'     => isset(self::TITLES[$civility]) ? self::TITLES[$civility] : '',
			'firstname' => $contact->firstname,
			'lastname'  => $contact->lastname,
			'email'     => strtolower(trim((string) $contact->email)),
			'position'  => $contact->poste,
			'phone'     => $contact->phone_pro,
			'mobile'    => $contact->phone_mobile,
			'fax'       => $contact->fax,
			'address1'  => $contact->address,
			'zipcode'   => $contact->zip,
			'city'      => $contact->town,
			'state'     => $this->getDictionaryLabel('c_departements', 'nom', $contact->state_id),
			'country'   => $this->getDictionaryLabel('c_country', 'label', $contact->country_id),
			'preferred_locale' => $contact->default_lang,
		);
		if (!empty($contact->socialnetworks) && is_array($contact->socialnetworks)) {
			foreach (self::SOCIAL_FIELDS as $dolibarrKey => $wmField) {
				if (!empty($contact->socialnetworks[$dolibarrKey])) {
					$data[$wmField] = $contact->socialnetworks[$dolibarrKey];
				}
			}
		}
		return self::cleanPayload($data);
	}

	/**
	 * Webmecanik fields of a third party
	 *
	 * @param Societe $soc Third party
	 * @return array
	 */
	public function buildCompanyPayload($soc)
	{
		$website = (string) $soc->url;
		if ($website !== '' && !preg_match('#^https?://#i', $website)) {
			$website = 'https://'.$website;
		}
		$email = strtolower(trim((string) $soc->email));
		return self::cleanPayload(array(
			'companyname'     => $soc->name,
			'companyemail'    => MarkAutoMecanikLead::isSafeEmail($email) ? $email : '',
			'companyphone'    => $soc->phone,
			'companyfax'      => $soc->fax,
			'companyaddress1' => $soc->address,
			'companyzipcode'  => $soc->zip,
			'companycity'     => $soc->town,
			'companystate'    => $this->getDictionaryLabel('c_departements', 'nom', $soc->state_id),
			'companycountry'  => $this->getDictionaryLabel('c_country', 'label', $soc->country_id),
			'companywebsite'  => $website,
		));
	}

	/**
	 * Check whether an email is unsubscribed from mass emailings in Dolibarr
	 *
	 * @param string $email Email
	 * @return bool
	 */
	protected function isUnsubscribed($email)
	{
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mailing_unsubscribe';
		$sql .= " WHERE email = '".$this->db->escape($email)."' AND entity IN (".getEntity('mailing', 0).')';
		$resql = $this->db->query($sql);
		$found = ($resql && $this->db->num_rows($resql) > 0);
		if ($resql) {
			$this->db->free($resql);
		}
		return $found;
	}

	// -------------------------------------------------------------------------------------------
	// Push
	// -------------------------------------------------------------------------------------------

	/**
	 * Send a contact (and its third party) to Webmecanik
	 *
	 * @param int $contactId Contact id
	 * @return string 'created', 'updated', 'skipped' or 'error'
	 */
	public function pushContact($contactId)
	{
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$client = $this->getClient();
		$ref = 'contact id='.((int) $contactId);

		$contact = new Contact($this->db);
		if ($contact->fetch($contactId) <= 0) {
			$this->error = 'Contact not found (deleted)';
			return 'skipped';
		}
		$email = strtolower(trim((string) $contact->email));
		if (!MarkAutoMecanikLead::isSafeEmail($email)) {
			$this->error = 'No valid email';
			return 'skipped';
		}
		if (!$this->isContactInScope($contact->id)) {
			$this->error = 'Out of the push scope';
			return 'skipped';
		}
		$linkedId = $this->getLinkedId(self::TYPE_CONTACT, $contact->id);
		$status = isset($contact->statut) ? $contact->statut : (isset($contact->status) ? $contact->status : 1);
		if (empty($status) && $linkedId <= 0) {
			$this->error = 'Disabled contact';
			return 'skipped';
		}

		// Company first, so that the contact can be linked to it
		$companyWmId = 0;
		if ($contact->socid > 0) {
			$companyWmId = $this->pushThirdparty($contact->socid);
			if ($companyWmId < 0) {
				return 'error';
			}
		}

		$payload = $this->buildContactPayload($contact);
		$result = 'updated';
		$wmId = 0;
		if ($linkedId > 0) {
			$res = $client->request('PATCH', '/api/contacts/'.$linkedId.'/edit', array(), $payload);
			if ($res !== null) {
				$wmId = $linkedId;
			} elseif ($client->lastHttpCode !== 404) {
				$this->error = $client->error;
				return 'error';
			}
			// 404: the contact was deleted in Webmecanik, it is created again below
		}
		if ($wmId <= 0) {
			// Webmecanik merges the contact with an existing one having the same email
			$res = $client->request('POST', '/api/contacts/new', array(), $payload);
			if ($res === null || empty($res['contact']['id'])) {
				$this->error = ($client->error !== '' ? $client->error : 'Webmecanik did not return the contact id');
				return 'error';
			}
			$wmId = (int) $res['contact']['id'];
			$result = ($linkedId > 0 ? 'updated' : 'created');
		}
		if ($wmId !== $linkedId && !$this->setLinkedId(self::TYPE_CONTACT, $contact->id, $wmId)) {
			$this->error = 'Unable to store the Webmecanik id of the contact';
			return 'error';
		}

		if (!$this->reconcileCompanyLinks($wmId, $companyWmId)) {
			return 'error';
		}

		// Unsubscribed in Dolibarr: "do not contact" by email in Webmecanik (never removed from here:
		// an unsubscription made in Webmecanik must not be cancelled by Dolibarr)
		if ($this->isUnsubscribed($email)) {
			$res = $client->request('POST', '/api/contacts/'.$wmId.'/dnc/email/add', array(), array('reason' => 1, 'comments' => 'Unsubscribed in Dolibarr'));
			if ($res === null) {
				$this->error = $client->error;
				return 'error';
			}
		}

		dol_syslog(__METHOD__.' '.$ref.' '.$result.' as Webmecanik contact #'.$wmId, LOG_DEBUG);
		return $result;
	}

	/**
	 * Queue item of a third party: only third parties already linked to Webmecanik are updated
	 * (a third party is created in Webmecanik when one of its contacts is sent)
	 *
	 * @param int $socid Third party id
	 * @return string 'updated', 'skipped' or 'error'
	 */
	protected function pushThirdpartyItem($socid)
	{
		if ($this->getLinkedId(self::TYPE_THIRDPARTY, $socid) <= 0) {
			$this->error = 'Third party not linked to Webmecanik';
			return 'skipped';
		}
		$res = $this->pushThirdparty($socid);
		if ($res < 0) {
			return 'error';
		}
		return ($res > 0 ? 'updated' : 'skipped');
	}

	/**
	 * Create or update a third party in Webmecanik (once per run)
	 *
	 * @param int $socid Third party id
	 * @return int Webmecanik company id, 0 if nothing to send, <0 if KO
	 */
	public function pushThirdparty($socid)
	{
		$socid = (int) $socid;
		if (isset($this->companyCache[$socid])) {
			return $this->companyCache[$socid];
		}
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$client = $this->getClient();

		$soc = new Societe($this->db);
		if ($soc->fetch($socid) <= 0) {
			$this->companyCache[$socid] = 0;
			return 0;
		}
		$payload = $this->buildCompanyPayload($soc);
		if (empty($payload['companyname'])) {
			$this->companyCache[$socid] = 0;
			return 0;
		}

		$linkedId = $this->getLinkedId(self::TYPE_THIRDPARTY, $socid);
		$wmId = 0;
		if ($linkedId > 0) {
			$res = $client->request('PATCH', '/api/companies/'.$linkedId.'/edit', array(), $payload);
			if ($res !== null) {
				$wmId = $linkedId;
			} elseif ($client->lastHttpCode !== 404) {
				$this->error = $client->error;
				return -1;
			}
		}
		if ($wmId <= 0) {
			// Reuse a company with exactly the same name rather than creating a duplicate
			$found = $client->request('GET', '/api/companies', array(
				'limit' => 1,
				'minimal' => 'true',
				'where[0][col]' => 'companyname',
				'where[0][expr]' => 'eq',
				'where[0][val]' => $payload['companyname'],
			));
			if ($found !== null && !empty($found['companies']) && is_array($found['companies'])) {
				$first = reset($found['companies']);
				$candidate = (is_array($first) && !empty($first['id'])) ? (int) $first['id'] : 0;
				if ($candidate > 0 && $client->request('PATCH', '/api/companies/'.$candidate.'/edit', array(), $payload) !== null) {
					$wmId = $candidate;
				}
			}
		}
		if ($wmId <= 0) {
			$res = $client->request('POST', '/api/companies/new', array(), $payload);
			if ($res === null || empty($res['company']['id'])) {
				$this->error = ($client->error !== '' ? $client->error : 'Webmecanik did not return the company id');
				return -1;
			}
			$wmId = (int) $res['company']['id'];
		}
		if ($wmId !== $linkedId && !$this->setLinkedId(self::TYPE_THIRDPARTY, $socid, $wmId)) {
			$this->error = 'Unable to store the Webmecanik id of the third party';
			return -1;
		}
		$this->companyCache[$socid] = $wmId;
		return $wmId;
	}

	/**
	 * Link the contact to the company of its Dolibarr third party, and remove its links to the
	 * other companies created from Dolibarr (links created in Webmecanik to other companies are kept)
	 *
	 * @param int $wmContactId Webmecanik contact id
	 * @param int $wmCompanyId Webmecanik company id of the third party (0 if none)
	 * @return bool
	 */
	protected function reconcileCompanyLinks($wmContactId, $wmCompanyId)
	{
		$client = $this->getClient();
		$res = $client->request('GET', '/api/contacts/'.((int) $wmContactId).'/companies');
		if ($res === null) {
			$this->error = $client->error;
			return false;
		}
		$current = array();
		if (!empty($res['companies']) && is_array($res['companies'])) {
			foreach ($res['companies'] as $company) {
				$id = is_array($company) ? (int) (isset($company['company_id']) ? $company['company_id'] : (isset($company['id']) ? $company['id'] : 0)) : 0;
				if ($id > 0) {
					$current[$id] = $id;
				}
			}
		}

		if ($wmCompanyId > 0 && !isset($current[$wmCompanyId])) {
			if ($client->request('POST', '/api/companies/'.((int) $wmCompanyId).'/contact/'.((int) $wmContactId).'/add') === null) {
				$this->error = $client->error;
				return false;
			}
		}
		unset($current[$wmCompanyId]);
		if (empty($current)) {
			return true;
		}
		// Only the companies linked to a Dolibarr third party are managed from Dolibarr
		$sql = 'SELECT mautic_tier_id FROM '.MAIN_DB_PREFIX.'societe_extrafields WHERE mautic_tier_id IN ('.implode(',', array_map('intval', $current)).')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$owned = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$owned[] = (int) $obj->mautic_tier_id;
		}
		$this->db->free($resql);
		foreach ($owned as $companyId) {
			if ($client->request('POST', '/api/companies/'.$companyId.'/contact/'.((int) $wmContactId).'/remove') === null && $client->lastHttpCode !== 404) {
				$this->error = $client->error;
				return false;
			}
		}
		return true;
	}

	/**
	 * Delete a contact in Webmecanik (option MARKAUTOMECANIK_PUSH_DELETE)
	 *
	 * @param int $wmContactId Webmecanik contact id
	 * @return string 'updated', 'skipped' or 'error'
	 */
	public function deleteContact($wmContactId)
	{
		if (!getDolGlobalInt('MARKAUTOMECANIK_PUSH_DELETE') || $wmContactId <= 0) {
			$this->error = 'Deletion in Webmecanik is disabled';
			return 'skipped';
		}
		$client = $this->getClient();
		if ($client->request('DELETE', '/api/contacts/'.((int) $wmContactId).'/delete') === null && $client->lastHttpCode !== 404) {
			$this->error = $client->error;
			return 'error';
		}
		return 'updated';
	}
}
