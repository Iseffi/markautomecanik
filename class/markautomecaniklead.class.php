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
 * \file       markautomecanik/class/markautomecaniklead.class.php
 * \ingroup    markautomecanik
 * \brief      Marketing lead business class
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';

/**
 * Marketing lead (contact imported from Webmecanik Automation, created manually or by REST API)
 */
class MarkAutoMecanikLead extends CommonObject
{
	/** @var string Module name */
	public $module = 'markautomecanik';

	/** @var string Element type */
	public $element = 'markautomecaniklead';

	/** @var string Table name without prefix */
	public $table_element = 'markautomecanik_lead';

	/** @var int 1 = multi-company managed with an entity field */
	public $ismultientitymanaged = 1;

	/** @var int Does object support extrafields */
	public $isextrafieldmanaged = 0;

	/** @var string Picto */
	public $picto = 'fa-bullhorn';

	const STATUS_NEW = 1;
	const STATUS_ASSIGNED = 2;
	const STATUS_INVALID = 3;
	const STATUS_ARCHIVED = 4;
	const STATUS_CONVERTED = 5;
	const STATUS_LOST = 6;

	/** @var array Status => translation key */
	const STATUS_TRANSKEYS = array(
		self::STATUS_NEW => 'LeadStatusNew',
		self::STATUS_ASSIGNED => 'LeadStatusAssigned',
		self::STATUS_INVALID => 'LeadStatusInvalid',
		self::STATUS_ARCHIVED => 'LeadStatusArchived',
		self::STATUS_CONVERTED => 'LeadStatusConverted',
		self::STATUS_LOST => 'LeadStatusLost',
	);

	/** @var array Status => code used for tags pushed to Webmecanik */
	const STATUS_CODES = array(
		self::STATUS_NEW => 'new',
		self::STATUS_ASSIGNED => 'assigned',
		self::STATUS_INVALID => 'invalid',
		self::STATUS_ARCHIVED => 'archived',
		self::STATUS_CONVERTED => 'converted',
		self::STATUS_LOST => 'lost',
	);

	/** @var array Status => badge type for dolGetStatus() */
	const STATUS_BADGES = array(
		self::STATUS_NEW => 'status1',
		self::STATUS_ASSIGNED => 'status3',
		self::STATUS_INVALID => 'status8',
		self::STATUS_ARCHIVED => 'status9',
		self::STATUS_CONVERTED => 'status4',
		self::STATUS_LOST => 'status6',
	);

	/** Origins */
	const ORIGIN_WEBMECANIK = 'webmecanik';
	const ORIGIN_MANUAL = 'manual';
	const ORIGIN_API = 'api';

	/** @var array Maximum length of text fields (characters) */
	const MAXLENGTHS = array(
		'firstname' => 128, 'lastname' => 128, 'email' => 255, 'phone' => 64, 'company' => 255,
		'city' => 128, 'country' => 128, 'lang' => 16, 'website' => 255, 'industry' => 255,
		'interests' => 2000, 'message' => 10000, 'utm_source' => 255, 'utm_medium' => 255,
		'utm_campaign' => 255, 'lost_reason' => 255, 'note_private' => 10000,
	);

	/**
	 * @var array Fields definition (see CommonObject)
	 */
	public $fields = array(
		'rowid'            => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'index' => 1, 'position' => 1),
		'entity'           => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'visible' => 0, 'notnull' => 1, 'default' => 1, 'index' => 1, 'position' => 5),
		'date_creation'    => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'visible' => -2, 'notnull' => 1, 'position' => 500),
		'tms'              => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'visible' => -2, 'notnull' => 0, 'position' => 501),
		'fk_user_creat'    => array('type' => 'integer', 'label' => 'UserAuthor', 'enabled' => 1, 'visible' => -2, 'notnull' => -1, 'position' => 510),
		'fk_user_modif'    => array('type' => 'integer', 'label' => 'UserModif', 'enabled' => 1, 'visible' => -2, 'notnull' => -1, 'position' => 511),
		'import_key'       => array('type' => 'varchar(14)', 'label' => 'ImportId', 'enabled' => 1, 'visible' => -2, 'notnull' => -1, 'position' => 1000),
		'origin'           => array('type' => 'varchar(16)', 'label' => 'LeadOrigin', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => 'webmecanik', 'position' => 10),
		'wm_contact_id'    => array('type' => 'integer', 'label' => 'WebmecanikContactId', 'enabled' => 1, 'visible' => -1, 'notnull' => -1, 'index' => 1, 'position' => 11),
		'wm_date_added'    => array('type' => 'datetime', 'label' => 'WebmecanikDateAdded', 'enabled' => 1, 'visible' => -1, 'notnull' => -1, 'position' => 12),
		'wm_date_modified' => array('type' => 'datetime', 'label' => 'WebmecanikDateModified', 'enabled' => 1, 'visible' => -1, 'notnull' => -1, 'position' => 13),
		'wm_points'        => array('type' => 'integer', 'label' => 'WebmecanikPoints', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'default' => 0, 'position' => 14),
		'firstname'        => array('type' => 'varchar(128)', 'label' => 'Firstname', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 20, 'searchall' => 1),
		'lastname'         => array('type' => 'varchar(128)', 'label' => 'Lastname', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 21, 'searchall' => 1),
		'email'            => array('type' => 'varchar(255)', 'label' => 'Email', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'index' => 1, 'position' => 22, 'searchall' => 1),
		'phone'            => array('type' => 'varchar(64)', 'label' => 'Phone', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 23),
		'company'          => array('type' => 'varchar(255)', 'label' => 'Company', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 24, 'searchall' => 1),
		'city'             => array('type' => 'varchar(128)', 'label' => 'Town', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 25),
		'country'          => array('type' => 'varchar(128)', 'label' => 'Country', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 26),
		'lang'             => array('type' => 'varchar(16)', 'label' => 'Language', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 27),
		'website'          => array('type' => 'varchar(255)', 'label' => 'Website', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 28),
		'industry'         => array('type' => 'varchar(255)', 'label' => 'Industry', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 29),
		'interests'        => array('type' => 'text', 'label' => 'Interests', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 30),
		'message'          => array('type' => 'text', 'label' => 'Message', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 31),
		'utm_source'       => array('type' => 'varchar(255)', 'label' => 'UtmSource', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 32),
		'utm_medium'       => array('type' => 'varchar(255)', 'label' => 'UtmMedium', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 33),
		'utm_campaign'     => array('type' => 'varchar(255)', 'label' => 'UtmCampaign', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 34),
		'status'           => array('type' => 'smallint', 'label' => 'Status', 'enabled' => 1, 'visible' => 1, 'notnull' => 1, 'default' => 1, 'index' => 1, 'position' => 40),
		'date_status'      => array('type' => 'datetime', 'label' => 'DateStatus', 'enabled' => 1, 'visible' => -1, 'notnull' => -1, 'position' => 41),
		'lost_reason'      => array('type' => 'varchar(255)', 'label' => 'LostReason', 'enabled' => 1, 'visible' => 1, 'notnull' => 0, 'position' => 42),
		'fk_user_assign'   => array('type' => 'integer', 'label' => 'SalesRepresentative', 'enabled' => 1, 'visible' => 1, 'notnull' => -1, 'index' => 1, 'position' => 43),
		'fk_soc'           => array('type' => 'integer', 'label' => 'ThirdParty', 'enabled' => 1, 'visible' => 1, 'notnull' => -1, 'index' => 1, 'position' => 44),
		'fk_socpeople'     => array('type' => 'integer', 'label' => 'Contact', 'enabled' => 1, 'visible' => 1, 'notnull' => -1, 'position' => 45),
		'fk_project'       => array('type' => 'integer', 'label' => 'Project', 'enabled' => 1, 'visible' => 1, 'notnull' => -1, 'position' => 46),
		'note_private'     => array('type' => 'text', 'label' => 'NotePrivate', 'enabled' => 1, 'visible' => 0, 'notnull' => 0, 'position' => 60),
		'date_anonymized'  => array('type' => 'datetime', 'label' => 'DateAnonymized', 'enabled' => 1, 'visible' => -2, 'notnull' => -1, 'position' => 70),
	);

	public $rowid;
	public $entity;
	public $date_creation;
	public $tms;
	public $fk_user_creat;
	public $fk_user_modif;
	public $import_key;
	public $origin;
	public $wm_contact_id;
	public $wm_date_added;
	public $wm_date_modified;
	public $wm_points;
	public $firstname;
	public $lastname;
	public $email;
	public $phone;
	public $company;
	public $city;
	public $country;
	public $lang;
	public $website;
	public $industry;
	public $interests;
	public $message;
	public $utm_source;
	public $utm_medium;
	public $utm_campaign;
	public $status;
	public $date_status;
	public $lost_reason;
	public $fk_user_assign;
	public $fk_soc;
	public $fk_socpeople;
	public $fk_project;
	public $note_private;
	public $date_anonymized;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct(DoliDB $db)
	{
		$this->db = $db;
		$this->status = self::STATUS_NEW;
		$this->origin = self::ORIGIN_MANUAL;
	}

	// -------------------------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------------------------

	/**
	 * Clean a free text value coming from an external source: removes control characters
	 * (except new lines and tabulations), trims and truncates. Output must still be escaped.
	 *
	 * @param mixed $value     Raw value
	 * @param int   $maxlength Maximum length in characters
	 * @return string
	 */
	public static function cleanText($value, $maxlength = 255)
	{
		if (is_array($value) || is_object($value) || $value === null) {
			return '';
		}
		$value = (string) $value;
		if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
			$value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
		}
		$value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
		$value = trim((string) $value);
		return dol_substr($value, 0, $maxlength, 'UTF-8');
	}

	/**
	 * Normalise all text properties (length, control characters) before saving
	 *
	 * @return void
	 */
	protected function cleanProperties()
	{
		foreach (self::MAXLENGTHS as $field => $max) {
			if ($this->$field !== null && $this->$field !== '') {
				$this->$field = self::cleanText($this->$field, $max);
			}
		}
		if (!empty($this->email)) {
			$this->email = strtolower($this->email);
		}
		if (!in_array($this->origin, array(self::ORIGIN_WEBMECANIK, self::ORIGIN_MANUAL, self::ORIGIN_API), true)) {
			$this->origin = self::ORIGIN_MANUAL;
		}
		if (!array_key_exists((int) $this->status, self::STATUS_TRANSKEYS)) {
			$this->status = self::STATUS_NEW;
		}
		$this->wm_points = (int) $this->wm_points;
	}

	/**
	 * Translated status labels indexed by status id
	 *
	 * @return array
	 */
	public static function getStatusLabels()
	{
		global $langs;
		$langs->load('markautomecanik@markautomecanik');
		$labels = array();
		foreach (self::STATUS_TRANSKEYS as $id => $key) {
			$labels[$id] = $langs->trans($key);
		}
		return $labels;
	}

	/**
	 * Statuses considered as closed (no more action expected)
	 *
	 * @return int[]
	 */
	public static function getClosedStatuses()
	{
		return array(self::STATUS_INVALID, self::STATUS_ARCHIVED, self::STATUS_CONVERTED, self::STATUS_LOST);
	}

	/**
	 * SQL restriction applied to every query so that a user without the "readall" permission
	 * only sees leads assigned to him or created by him.
	 *
	 * @param User   $user  User
	 * @param string $alias Table alias
	 * @return string SQL fragment starting with " AND " (or empty string)
	 */
	public static function getSqlUserRestriction($user, $alias = 't')
	{
		if ($user->hasRight('markautomecanik', 'lead', 'readall')) {
			return '';
		}
		$uid = (int) $user->id;
		return ' AND ('.$alias.'.fk_user_assign = '.$uid.' OR '.$alias.'.fk_user_creat = '.$uid.')';
	}

	/**
	 * Check that the user can read this lead (permission + entity + ownership)
	 *
	 * @param User $user User
	 * @return bool
	 */
	public function canBeReadBy($user)
	{
		if (empty($this->id) || !$user->hasRight('markautomecanik', 'lead', 'read')) {
			return false;
		}
		if ($user->hasRight('markautomecanik', 'lead', 'readall')) {
			return true;
		}
		return ((int) $this->fk_user_assign === (int) $user->id || (int) $this->fk_user_creat === (int) $user->id);
	}

	/**
	 * Full name of the lead
	 *
	 * @param Translate $langs   Language object (unused, kept for compatibility)
	 * @param int       $option  Unused
	 * @param int       $nameorder Unused
	 * @param int       $maxlen  Max length
	 * @return string
	 */
	public function getFullName($langs = null, $option = 0, $nameorder = -1, $maxlen = 0)
	{
		$name = trim($this->firstname.' '.$this->lastname);
		if ($name === '') {
			$name = (string) $this->email;
		}
		if ($name === '') {
			$name = '#'.$this->id;
		}
		return $maxlen ? dol_trunc($name, $maxlen) : $name;
	}

	/**
	 * URL of the contact in Webmecanik Automation
	 *
	 * @return string Empty string if not available
	 */
	public function getWebmecanikUrl()
	{
		$base = rtrim(getDolGlobalString('MARKAUTOMECANIK_WM_URL'), '/');
		if (empty($base) || empty($this->wm_contact_id)) {
			return '';
		}
		return $base.'/s/contacts/view/'.((int) $this->wm_contact_id);
	}

	// -------------------------------------------------------------------------------------------
	// CRUD
	// -------------------------------------------------------------------------------------------

	/**
	 * Create lead in database
	 *
	 * @param User $user      User that creates
	 * @param bool $notrigger false=launch triggers, true=disable triggers
	 * @return int <0 if KO, id of created lead if OK
	 */
	public function create(User $user, $notrigger = false)
	{
		global $conf;

		$this->cleanProperties();
		$this->entity = (int) $conf->entity;
		if (empty($this->date_creation)) {
			$this->date_creation = dol_now();
		}
		if (empty($this->date_status)) {
			$this->date_status = $this->date_creation;
		}
		$this->tms = null;
		$this->date_anonymized = null;

		// A Dolibarr contact with the same email already exists: link the lead to it and to its thirdparty
		if (empty($this->fk_socpeople) && !empty($this->email)) {
			$sql = 'SELECT rowid, fk_soc FROM '.MAIN_DB_PREFIX.'socpeople';
			$sql .= " WHERE email = '".$this->db->escape($this->email)."'";
			$sql .= ' AND entity IN ('.getEntity('contact').')';
			$sql .= ' ORDER BY rowid DESC';
			$sql .= $this->db->plimit(1);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$this->fk_socpeople = (int) $obj->rowid;
				if (empty($this->fk_soc) && $obj->fk_soc > 0) {
					$this->fk_soc = (int) $obj->fk_soc;
				}
			}
		}

		return $this->createCommon($user, $notrigger);
	}

	/**
	 * Load lead from database (restricted to the entities visible from the current one)
	 *
	 * @param int $id Id of lead
	 * @return int <0 if KO, 0 if not found, >0 if OK
	 */
	public function fetch($id)
	{
		$id = (int) $id;
		if ($id <= 0) {
			return -1;
		}
		return $this->fetchCommon($id, null, ' AND t.entity IN ('.getEntity($this->element).')', 1);
	}

	/**
	 * Update lead in database
	 *
	 * @param User $user      User that modifies
	 * @param bool $notrigger false=launch triggers, true=disable triggers
	 * @return int <0 if KO, >0 if OK
	 */
	public function update(User $user, $notrigger = false)
	{
		$this->cleanProperties();
		// tms is managed by the database (ON UPDATE CURRENT_TIMESTAMP)
		$this->tms = null;
		$this->fk_user_modif = $user->id;
		return $this->updateCommon($user, $notrigger);
	}

	/**
	 * Delete lead from database
	 *
	 * @param User $user      User that deletes
	 * @param bool $notrigger false=launch triggers, true=disable triggers
	 * @return int <0 if KO, >0 if OK
	 */
	public function delete(User $user, $notrigger = false)
	{
		return $this->deleteCommon($user, $notrigger);
	}

	// -------------------------------------------------------------------------------------------
	// Workflow
	// -------------------------------------------------------------------------------------------

	/**
	 * Change status of the lead
	 *
	 * @param User   $user       User
	 * @param int    $status     New status
	 * @param string $lostReason Reason, mandatory for STATUS_LOST
	 * @return int <0 if KO, 0 if nothing changed, >0 if OK
	 */
	public function setStatus(User $user, $status, $lostReason = '')
	{
		global $langs;

		$status = (int) $status;
		if (!array_key_exists($status, self::STATUS_TRANSKEYS)) {
			$this->error = 'ErrorBadStatus';
			return -1;
		}
		$lostReason = self::cleanText($lostReason, 255);
		if ($status == self::STATUS_LOST && $lostReason === '') {
			$this->error = $langs->trans('ErrorLostReasonRequired');
			return -1;
		}
		if ($status == (int) $this->status && ($status != self::STATUS_LOST || $lostReason === (string) $this->lost_reason)) {
			return 0;
		}
		$this->status = $status;
		$this->lost_reason = ($status == self::STATUS_LOST ? $lostReason : null);
		$this->date_status = dol_now();
		return $this->update($user);
	}

	/**
	 * Assign the lead to a sales representative
	 *
	 * @param User $user   User doing the action
	 * @param int  $userid Sales representative (0 to unassign)
	 * @return int <0 if KO, 0 if nothing changed, >0 if OK
	 */
	public function assign(User $user, $userid)
	{
		$userid = (int) $userid;
		if ($userid == (int) $this->fk_user_assign) {
			return 0;
		}
		if ($userid > 0) {
			require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
			$rep = new User($this->db);
			if ($rep->fetch($userid) <= 0) {
				$this->error = 'ErrorBadSalesRepresentative';
				return -1;
			}
			$repStatus = isset($rep->status) ? $rep->status : $rep->statut;
			if (!empty($rep->socid) || empty($repStatus)) {
				$this->error = 'ErrorBadSalesRepresentative';
				return -1;
			}
		}
		$this->fk_user_assign = ($userid > 0 ? $userid : null);
		if ($userid > 0 && (int) $this->status == self::STATUS_NEW) {
			$this->status = self::STATUS_ASSIGNED;
		}
		if ($userid <= 0 && (int) $this->status == self::STATUS_ASSIGNED) {
			$this->status = self::STATUS_NEW;
		}
		$this->date_status = dol_now();
		$result = $this->update($user);
		if ($result > 0) {
			$this->addSalesRepToThirdparty($user);
		}
		return $result;
	}

	/**
	 * Link the lead to an existing thirdparty (and optionally a contact of it)
	 *
	 * @param User $user  User
	 * @param int  $socid Thirdparty id (0 to unlink)
	 * @return int <0 if KO, >0 if OK
	 */
	public function linkThirdparty(User $user, $socid)
	{
		$socid = (int) $socid;
		if ($socid > 0) {
			require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
			$soc = new Societe($this->db);
			if ($soc->fetch($socid) <= 0 || !in_array((int) $soc->entity, array_map('intval', explode(',', getEntity('societe'))), true)) {
				$this->error = 'ErrorRecordNotFound';
				return -1;
			}
		}
		$this->fk_soc = ($socid > 0 ? $socid : null);
		// A contact belonging to another thirdparty is no longer consistent
		if ($socid > 0 && $this->fk_socpeople > 0) {
			$sql = 'SELECT fk_soc FROM '.MAIN_DB_PREFIX.'socpeople WHERE rowid = '.((int) $this->fk_socpeople);
			$resql = $this->db->query($sql);
			if ($resql && ($obj = $this->db->fetch_object($resql)) && (int) $obj->fk_soc != $socid) {
				$this->fk_socpeople = null;
			}
		}
		$result = $this->update($user);
		if ($result > 0) {
			$this->addSalesRepToThirdparty($user);
		}
		return $result;
	}

	/**
	 * Link the lead to an existing contact of the linked thirdparty
	 *
	 * @param User $user      User
	 * @param int  $contactid Contact id
	 * @return int <0 if KO, >0 if OK
	 */
	public function linkContact(User $user, $contactid)
	{
		$contactid = (int) $contactid;
		$sql = 'SELECT rowid, fk_soc FROM '.MAIN_DB_PREFIX.'socpeople';
		$sql .= ' WHERE rowid = '.$contactid.' AND entity IN ('.getEntity('contact').')';
		$resql = $this->db->query($sql);
		if (!$resql || !($obj = $this->db->fetch_object($resql))) {
			$this->error = 'ErrorRecordNotFound';
			return -1;
		}
		$this->fk_socpeople = (int) $obj->rowid;
		if (empty($this->fk_soc) && $obj->fk_soc > 0) {
			$this->fk_soc = (int) $obj->fk_soc;
		}
		return $this->update($user);
	}

	/**
	 * Add the assigned sales representative to the sales representatives of the linked thirdparty
	 *
	 * @param User $user User
	 * @return int 0 if nothing to do, <0 if KO, >0 if OK
	 */
	public function addSalesRepToThirdparty(User $user)
	{
		if (!($this->fk_soc > 0) || !($this->fk_user_assign > 0)) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
		$soc = new Societe($this->db);
		if ($soc->fetch($this->fk_soc) <= 0) {
			return -1;
		}
		$existing = $soc->getSalesRepresentatives($user, 1);
		if (is_array($existing) && in_array((int) $this->fk_user_assign, array_map('intval', $existing), true)) {
			return 0;
		}
		return $soc->add_commercial($user, $this->fk_user_assign);
	}

	/**
	 * GDPR anonymisation: personal data are erased, statistical data (company, industry, dates,
	 * status, sales representative) are kept. The lead is archived.
	 *
	 * @param User $user User
	 * @return int <0 if KO, >0 if OK
	 */
	public function anonymize(User $user)
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element.' SET';
		$sql .= " firstname = NULL, lastname = NULL, email = NULL, phone = NULL, city = NULL, website = NULL,";
		$sql .= " message = NULL, interests = NULL, note_private = NULL, lost_reason = NULL,";
		$sql .= " wm_contact_id = NULL, fk_socpeople = NULL,";
		$sql .= ' status = '.self::STATUS_ARCHIVED.',';
		$sql .= " date_status = '".$this->db->idate(dol_now())."',";
		$sql .= " date_anonymized = '".$this->db->idate(dol_now())."',";
		$sql .= ' fk_user_modif = '.($user->id > 0 ? (int) $user->id : 'NULL');
		$sql .= ' WHERE rowid = '.((int) $this->id);
		$sql .= ' AND entity IN ('.getEntity($this->element).')';
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
		dol_syslog(__METHOD__.' lead id='.((int) $this->id).' anonymised by user id='.((int) $user->id), LOG_NOTICE);
		return 1;
	}

	// -------------------------------------------------------------------------------------------
	// Synchronisation helpers
	// -------------------------------------------------------------------------------------------

	/**
	 * Match a contact coming from Webmecanik against existing leads
	 *
	 * - an open lead exists (new, assigned): update it instead of creating a duplicate;
	 * - only closed leads exist: a new lead is created only if the contact had activity after the
	 *   last closed lead (re-engagement), except for invalid leads which are never re-created;
	 * - no lead: create.
	 *
	 * @param string $email        Email
	 * @param int    $wmContactId  Webmecanik contact id
	 * @param int    $activityTs   Timestamp of the last Webmecanik activity
	 * @return array array('action' => 'create'|'update'|'skip'|'error', 'id' => int)
	 */
	public function findForSync($email, $wmContactId = 0, $activityTs = 0)
	{
		$email = strtolower(trim((string) $email));
		$wmContactId = (int) $wmContactId;
		if ($email === '' && $wmContactId <= 0) {
			return array('action' => 'skip', 'id' => 0);
		}

		$where = array();
		if ($email !== '') {
			$where[] = "email = '".$this->db->escape($email)."'";
		}
		if ($wmContactId > 0) {
			$where[] = 'wm_contact_id = '.$wmContactId;
		}
		$sql = 'SELECT rowid, status, date_creation, wm_date_modified, date_status';
		$sql .= ' FROM '.MAIN_DB_PREFIX.$this->table_element;
		$sql .= ' WHERE ('.implode(' OR ', $where).')';
		$sql .= ' AND entity = '.((int) $GLOBALS['conf']->entity);
		$sql .= ' AND date_anonymized IS NULL';
		$sql .= ' ORDER BY rowid DESC';

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return array('action' => 'error', 'id' => 0);
		}

		$closed = self::getClosedStatuses();
		$lastClosed = null;
		while ($obj = $this->db->fetch_object($resql)) {
			if (!in_array((int) $obj->status, $closed, true)) {
				$this->db->free($resql);
				return array('action' => 'update', 'id' => (int) $obj->rowid);
			}
			if ($lastClosed === null) {
				$lastClosed = $obj;
			}
		}
		$this->db->free($resql);

		if ($lastClosed === null) {
			return array('action' => 'create', 'id' => 0);
		}
		if ((int) $lastClosed->status == self::STATUS_INVALID) {
			return array('action' => 'skip', 'id' => (int) $lastClosed->rowid);
		}
		$lastDate = max(
			(int) $this->db->jdate($lastClosed->wm_date_modified ? $lastClosed->wm_date_modified : $lastClosed->date_creation),
			(int) $this->db->jdate($lastClosed->date_status)
		);
		if ($activityTs > 0 && $activityTs > $lastDate) {
			return array('action' => 'create', 'id' => (int) $lastClosed->rowid);
		}
		return array('action' => 'skip', 'id' => (int) $lastClosed->rowid);
	}

	/**
	 * Refresh marketing data of an open lead found again by a synchronisation.
	 * The status date is not modified: marketing activity must not reset the processing delay.
	 *
	 * @param int $id         Lead id
	 * @param int $points     Points
	 * @param int $modifiedTs Webmecanik modification date
	 * @param int $wmId       Webmecanik contact id
	 * @return int <0 if KO, >0 if OK
	 */
	public function refreshFromSync($id, $points, $modifiedTs, $wmId)
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.$this->table_element.' SET';
		$sql .= ' wm_points = '.((int) $points);
		if ($modifiedTs > 0) {
			$sql .= ", wm_date_modified = '".$this->db->idate($modifiedTs)."'";
		}
		if ($wmId > 0) {
			$sql .= ', wm_contact_id = '.((int) $wmId);
		}
		$sql .= ' WHERE rowid = '.((int) $id).' AND entity = '.((int) $GLOBALS['conf']->entity);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
		return 1;
	}

	// -------------------------------------------------------------------------------------------
	// Dashboard and statistics
	// -------------------------------------------------------------------------------------------

	/**
	 * Date from which leads are considered by reminders and the "late" counter
	 *
	 * @return int Timestamp
	 */
	public static function getRemindSince()
	{
		return dol_time_plus_duree(dol_now(), -max(1, getDolGlobalInt('MARKAUTOMECANIK_REMIND_MAX_AGE_MONTHS', 3)), 'm');
	}

	/**
	 * Dashboard indicators for the widget
	 *
	 * @param User $user User (visibility restriction)
	 * @return array|false
	 */
	public function getDashboardData($user)
	{
		$slaDays = getDolGlobalInt('MARKAUTOMECANIK_SLA_DAYS');
		$limit = dol_now() - $slaDays * 86400;
		$since = dol_time_plus_duree(dol_now(), -12, 'm');
		$recent = "t.date_creation >= '".$this->db->idate(self::getRemindSince())."'";
		$excluded = self::STATUS_INVALID.', '.self::STATUS_ARCHIVED;

		$sql = 'SELECT';
		$sql .= ' SUM(CASE WHEN t.status = '.self::STATUS_NEW.' THEN 1 ELSE 0 END) as nb_new,';
		$sql .= ' SUM(CASE WHEN t.status = '.self::STATUS_ASSIGNED.' THEN 1 ELSE 0 END) as nb_assigned,';
		if ($slaDays > 0) {
			$sql .= ' SUM(CASE WHEN '.$recent.' AND t.status IN ('.self::STATUS_NEW.', '.self::STATUS_ASSIGNED.")";
			$sql .= " AND t.date_status < '".$this->db->idate($limit)."' THEN 1 ELSE 0 END) as nb_late,";
		} else {
			$sql .= ' 0 as nb_late,';
		}
		$sql .= " SUM(CASE WHEN t.date_creation >= '".$this->db->idate($since)."' AND t.status NOT IN (".$excluded.') THEN 1 ELSE 0 END) as total_12m,';
		$sql .= " SUM(CASE WHEN t.date_creation >= '".$this->db->idate($since)."' AND t.status = ".self::STATUS_CONVERTED.' THEN 1 ELSE 0 END) as converted_12m';
		$sql .= ' FROM '.MAIN_DB_PREFIX.$this->table_element.' as t';
		$sql .= ' WHERE t.entity IN ('.getEntity($this->element).')';
		$sql .= ' AND t.date_anonymized IS NULL';
		$sql .= self::getSqlUserRestriction($user, 't');

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		return array(
			'new' => (int) $obj->nb_new,
			'assigned' => (int) $obj->nb_assigned,
			'late' => (int) $obj->nb_late,
			'total_12m' => (int) $obj->total_12m,
			'converted_12m' => (int) $obj->converted_12m,
		);
	}

	/**
	 * Number of leads per month and status for a year
	 *
	 * @param int  $year Year
	 * @param User $user User (visibility restriction)
	 * @return array|false array(month => array(status => nb))
	 */
	public function getMonthlyStats($year, $user)
	{
		$year = (int) $year;
		$sql = 'SELECT t.date_creation, t.status, t.fk_user_assign';
		$sql .= ' FROM '.MAIN_DB_PREFIX.$this->table_element.' as t';
		$sql .= ' WHERE t.entity IN ('.getEntity($this->element).')';
		$sql .= " AND t.date_creation >= '".$this->db->idate(dol_mktime(0, 0, 0, 1, 1, $year))."'";
		$sql .= " AND t.date_creation <= '".$this->db->idate(dol_mktime(23, 59, 59, 12, 31, $year))."'";
		$sql .= self::getSqlUserRestriction($user, 't');

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return false;
		}
		$byMonth = array();
		$byUser = array();
		for ($m = 1; $m <= 12; $m++) {
			$byMonth[$m] = array_fill_keys(array_keys(self::STATUS_TRANSKEYS), 0);
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$month = (int) dol_print_date($this->db->jdate($obj->date_creation), '%m', 'tzserver');
			$status = (int) $obj->status;
			if ($month < 1 || $month > 12 || !isset($byMonth[$month][$status])) {
				continue;
			}
			$byMonth[$month][$status]++;
			$uid = (int) $obj->fk_user_assign;
			if (!isset($byUser[$uid])) {
				$byUser[$uid] = array_fill_keys(array_keys(self::STATUS_TRANSKEYS), 0);
			}
			$byUser[$uid][$status]++;
		}
		$this->db->free($resql);
		return array('month' => $byMonth, 'user' => $byUser);
	}

	// -------------------------------------------------------------------------------------------
	// Display
	// -------------------------------------------------------------------------------------------

	/**
	 * Return a link to the lead card
	 *
	 * @param int    $withpicto 0=No picto, 1=Include picto into link, 2=Only picto
	 * @param string $option    'nolink' to return only the label
	 * @param int    $notooltip 1=Disable tooltip
	 * @param int    $maxlen    Max length of label
	 * @return string
	 */
	public function getNomUrl($withpicto = 0, $option = '', $notooltip = 0, $maxlen = 0)
	{
		global $langs;

		$langs->load('markautomecanik@markautomecanik');
		$label = img_picto('', $this->picto).' <u class="paddingrightonly">'.$langs->trans('MarketingLead').'</u> '.$this->getLibStatut(5);
		$label .= '<br><b>'.$langs->trans('Ref').':</b> '.((int) $this->id);
		$label .= '<br><b>'.$langs->trans('Name').':</b> '.dol_escape_htmltag($this->getFullName());
		if (!empty($this->company)) {
			$label .= '<br><b>'.$langs->trans('Company').':</b> '.dol_escape_htmltag($this->company);
		}

		$url = dol_buildpath('/markautomecanik/lead_card.php', 1).'?id='.((int) $this->id);
		$linkclose = '';
		if (empty($notooltip)) {
			$linkclose .= ' title="'.dol_escape_htmltag($label, 1).'" class="classfortooltip"';
		}
		$linkstart = ($option === 'nolink') ? '<span'.$linkclose.'>' : '<a href="'.$url.'"'.$linkclose.'>';
		$linkend = ($option === 'nolink') ? '</span>' : '</a>';

		$result = $linkstart;
		if ($withpicto) {
			$result .= img_picto('', $this->picto, 'class="pictofixedwidth"');
		}
		if ($withpicto != 2) {
			$result .= dol_escape_htmltag($this->getFullName(null, 0, -1, $maxlen));
		}
		$result .= $linkend;
		return $result;
	}

	/**
	 * Return label of the status of the lead
	 *
	 * @param int $mode 0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
	 * @return string
	 */
	public function getLibStatut($mode = 0)
	{
		return $this->LibStatut($this->status, $mode);
	}

	// phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
	/**
	 * Return the label of a given status
	 *
	 * @param int $status Status
	 * @param int $mode   See getLibStatut()
	 * @return string
	 */
	public function LibStatut($status, $mode = 0)
	{
		// phpcs:enable
		$labels = self::getStatusLabels();
		$status = (int) $status;
		$label = isset($labels[$status]) ? $labels[$status] : '';
		$badge = isset(self::STATUS_BADGES[$status]) ? self::STATUS_BADGES[$status] : 'status0';
		return dolGetStatus($label, $label, '', $badge, $mode);
	}

	/**
	 * Initialise object with example values (used for previews)
	 *
	 * @return int
	 */
	public function initAsSpecimen()
	{
		$this->id = 0;
		$this->specimen = 1;
		$this->firstname = 'John';
		$this->lastname = 'DOE';
		$this->email = 'john.doe@example.com';
		$this->company = 'Example Ltd';
		$this->status = self::STATUS_NEW;
		$this->origin = self::ORIGIN_MANUAL;
		return 1;
	}
}
