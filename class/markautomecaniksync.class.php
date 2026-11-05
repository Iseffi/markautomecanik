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
 * \file       markautomecanik/class/markautomecaniksync.class.php
 * \ingroup    markautomecanik
 * \brief      Synchronisation service and scheduled jobs
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once __DIR__.'/markautomecaniklead.class.php';
require_once __DIR__.'/webmecanikclient.class.php';

/**
 * Synchronisation of Webmecanik contacts into marketing leads, and scheduled jobs of the module.
 * Methods cronSync(), cronRemind() and cronPurge() follow the Dolibarr scheduled jobs convention
 * (return 0 if OK, <0 if KO; $this->output and $this->error are displayed in the job history).
 */
class MarkAutoMecanikSync
{
	/** Page size used for the contacts API */
	const PAGE_SIZE = 100;

	/** Hard limit of pages per run (protects against endless loops) */
	const MAX_PAGES = 200;

	/** A lock older than this (seconds) is considered stale */
	const LOCK_TTL = 1800;

	/** Overlap added to the synchronisation window (seconds) */
	const WINDOW_OVERLAP = 3600;

	/** @var DoliDB */
	public $db;

	/** @var string Output of the last run (displayed in the scheduled jobs history) */
	public $output = '';

	/** @var string Last error */
	public $error = '';

	/** @var string[] Errors */
	public $errors = array();

	/** @var array Counters of the last run */
	public $stats = array('received' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0);

	/** @var MarkAutoMecanikWebmecanikClient */
	protected $client;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Lazy loading of the API client
	 *
	 * @return MarkAutoMecanikWebmecanikClient
	 */
	public function getClient()
	{
		if ($this->client === null) {
			$this->client = new MarkAutoMecanikWebmecanikClient($this->db);
		}
		return $this->client;
	}

	// -------------------------------------------------------------------------------------------
	// Lock and log
	// -------------------------------------------------------------------------------------------

	/**
	 * Take the synchronisation lock of the current entity
	 *
	 * @return bool
	 */
	protected function acquireLock()
	{
		global $conf;

		$now = dol_now();
		$name = 'MARKAUTOMECANIK_SYNC_LOCK';
		// Atomic update: only succeeds if there is no lock or if it is stale
		$sql = 'UPDATE '.MAIN_DB_PREFIX."const SET value = '".$this->db->escape((string) $now)."'";
		$sql .= " WHERE name = '".$this->db->escape($name)."' AND entity = ".((int) $conf->entity);
		$sql .= " AND (value = '' OR value = '0' OR CAST(value AS DECIMAL(20,0)) < ".((int) ($now - self::LOCK_TTL)).')';
		$resql = $this->db->query($sql);
		if ($resql && $this->db->affected_rows($resql) > 0) {
			return true;
		}
		// No lock row yet: create it
		$sql = 'SELECT value FROM '.MAIN_DB_PREFIX."const WHERE name = '".$this->db->escape($name)."' AND entity = ".((int) $conf->entity);
		$resql = $this->db->query($sql);
		if ($resql && $this->db->num_rows($resql) == 0) {
			return (dolibarr_set_const($this->db, $name, (string) $now, 'chaine', 0, '', $conf->entity) > 0);
		}
		return false;
	}

	/**
	 * Release the synchronisation lock
	 *
	 * @return void
	 */
	protected function releaseLock()
	{
		global $conf;
		dolibarr_set_const($this->db, 'MARKAUTOMECANIK_SYNC_LOCK', '0', 'chaine', 0, '', $conf->entity);
	}

	/**
	 * Write a line in the synchronisation history
	 *
	 * @param string $trigger 'cron', 'manual' or 'webhook'
	 * @param int    $start   Start timestamp
	 * @param int    $status  1=OK, -1=KO
	 * @param string $message Message (must not contain personal data)
	 * @param User   $user    User
	 * @return void
	 */
	protected function writeLog($trigger, $start, $status, $message, $user)
	{
		global $conf;

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'markautomecanik_synclog';
		$sql .= ' (entity, date_start, date_end, trigger_type, status, nb_received, nb_created, nb_updated, nb_skipped, nb_errors, message, fk_user)';
		$sql .= ' VALUES ('.((int) $conf->entity).", '".$this->db->idate($start)."', '".$this->db->idate(dol_now())."'";
		$sql .= ", '".$this->db->escape(dol_substr($trigger, 0, 16))."', ".((int) $status);
		$sql .= ', '.((int) $this->stats['received']).', '.((int) $this->stats['created']).', '.((int) $this->stats['updated']);
		$sql .= ', '.((int) $this->stats['skipped']).', '.((int) $this->stats['errors']);
		$sql .= ", '".$this->db->escape(dol_substr($message, 0, 1024))."'";
		$sql .= ', '.(is_object($user) && $user->id > 0 ? (int) $user->id : 'NULL').')';
		if (!$this->db->query($sql)) {
			dol_syslog(__METHOD__.' '.$this->db->lasterror(), LOG_ERR);
		}
	}

	// -------------------------------------------------------------------------------------------
	// Mapping
	// -------------------------------------------------------------------------------------------

	/**
	 * Flatten the fields of a Mautic contact into alias => scalar value
	 *
	 * @param array $contact Contact as returned by the API
	 * @return array
	 */
	public static function flattenFields($contact)
	{
		$fields = array();
		if (empty($contact['fields']) || !is_array($contact['fields'])) {
			return $fields;
		}
		if (!empty($contact['fields']['all']) && is_array($contact['fields']['all'])) {
			foreach ($contact['fields']['all'] as $alias => $value) {
				if (is_scalar($value)) {
					$fields[(string) $alias] = (string) $value;
				}
			}
			return $fields;
		}
		foreach ($contact['fields'] as $group => $list) {
			if (!is_array($list)) {
				continue;
			}
			foreach ($list as $alias => $def) {
				if (is_array($def)) {
					$value = isset($def['normalizedValue']) && is_scalar($def['normalizedValue']) ? $def['normalizedValue'] : (isset($def['value']) && is_scalar($def['value']) ? $def['value'] : '');
					$fields[(string) $alias] = (string) $value;
				} elseif (is_scalar($def)) {
					$fields[(string) $alias] = (string) $def;
				}
			}
		}
		return $fields;
	}

	/**
	 * Convert a Webmecanik contact into lead properties
	 *
	 * @param array $contact Contact as returned by the API
	 * @return array
	 */
	public static function mapContact($contact)
	{
		$fields = self::flattenFields($contact);
		$get = function ($alias) use ($fields) {
			$alias = (string) $alias;
			return ($alias !== '' && isset($fields[$alias])) ? $fields[$alias] : '';
		};

		$utm = array('utm_source' => '', 'utm_medium' => '', 'utm_campaign' => '');
		if (!empty($contact['utmtags']) && is_array($contact['utmtags'])) {
			$last = end($contact['utmtags']);
			if (is_array($last)) {
				foreach (array_keys($utm) as $key) {
					$utm[$key] = (isset($last[$key]) && is_scalar($last[$key])) ? (string) $last[$key] : '';
				}
			}
		}

		$phone = $get('phone');
		if ($phone === '') {
			$phone = $get('mobile');
		}

		$toTs = function ($value) {
			if (empty($value) || !is_string($value)) {
				return 0;
			}
			$ts = strtotime($value);
			return ($ts === false ? 0 : $ts);
		};

		return array(
			'wm_contact_id'    => (int) (isset($contact['id']) ? $contact['id'] : 0),
			'wm_points'        => (int) (isset($contact['points']) ? $contact['points'] : 0),
			'wm_date_added'    => $toTs(isset($contact['dateAdded']) ? $contact['dateAdded'] : ''),
			'wm_date_modified' => $toTs(isset($contact['dateModified']) ? $contact['dateModified'] : ''),
			'firstname'        => $get('firstname'),
			'lastname'         => $get('lastname'),
			'email'            => strtolower(trim($get('email'))),
			'phone'            => $phone,
			'company'          => $get('company'),
			'city'             => $get('city'),
			'country'          => $get('country'),
			'lang'             => $get('preferred_locale'),
			'website'          => $get('website'),
			'industry'         => $get(getDolGlobalString('MARKAUTOMECANIK_FIELD_INDUSTRY')),
			'interests'        => $get(getDolGlobalString('MARKAUTOMECANIK_FIELD_INTERESTS')),
			'message'          => $get(getDolGlobalString('MARKAUTOMECANIK_FIELD_MESSAGE')),
			'utm_source'       => $utm['utm_source'],
			'utm_medium'       => $utm['utm_medium'],
			'utm_campaign'     => $utm['utm_campaign'],
		);
	}

	/**
	 * Import one Webmecanik contact (create, refresh or skip)
	 *
	 * @param array $contact Contact as returned by the API
	 * @param User  $user    User used as author
	 * @return string 'created', 'updated', 'skipped' or 'error'
	 */
	public function importContact($contact, $user)
	{
		$this->stats['received']++;
		if (!is_array($contact)) {
			$this->stats['skipped']++;
			return 'skipped';
		}
		$data = self::mapContact($contact);
		$ref = 'contact #'.$data['wm_contact_id'];

		if ($data['email'] === '' || !isValidEmail($data['email'])) {
			$this->stats['skipped']++;
			dol_syslog(__METHOD__.' '.$ref.' skipped: no valid email', LOG_DEBUG);
			return 'skipped';
		}
		if ($data['wm_points'] < getDolGlobalInt('MARKAUTOMECANIK_MIN_POINTS', 40)) {
			$this->stats['skipped']++;
			dol_syslog(__METHOD__.' '.$ref.' skipped: points below threshold', LOG_DEBUG);
			return 'skipped';
		}

		$lead = new MarkAutoMecanikLead($this->db);
		$match = $lead->findForSync($data['email'], $data['wm_contact_id'], $data['wm_date_modified']);

		if ($match['action'] === 'error') {
			$this->stats['errors']++;
			return 'error';
		}
		if ($match['action'] === 'skip') {
			$this->stats['skipped']++;
			return 'skipped';
		}
		if ($match['action'] === 'update') {
			if ($lead->refreshFromSync($match['id'], $data['wm_points'], $data['wm_date_modified'], $data['wm_contact_id']) < 0) {
				$this->stats['errors']++;
				return 'error';
			}
			$this->stats['updated']++;
			return 'updated';
		}

		foreach ($data as $key => $value) {
			$lead->$key = $value;
		}
		foreach (array('wm_date_added', 'wm_date_modified') as $key) {
			if (empty($lead->$key)) {
				$lead->$key = null;
			}
		}
		if ($lead->lastname === '' && $lead->firstname === '') {
			$lead->lastname = $lead->email;
		}
		$lead->origin = MarkAutoMecanikLead::ORIGIN_WEBMECANIK;
		$lead->status = MarkAutoMecanikLead::STATUS_NEW;
		$lead->date_creation = dol_now();

		$id = $lead->create($user);
		if ($id <= 0) {
			$this->stats['errors']++;
			dol_syslog(__METHOD__.' '.$ref.' creation failed: '.$lead->error.' '.implode(', ', $lead->errors), LOG_ERR);
			return 'error';
		}
		$this->stats['created']++;
		dol_syslog(__METHOD__.' '.$ref.' imported as lead id='.$id, LOG_INFO);
		return 'created';
	}

	// -------------------------------------------------------------------------------------------
	// Synchronisation
	// -------------------------------------------------------------------------------------------

	/**
	 * Synchronise contacts modified since the last successful synchronisation
	 *
	 * @param string $trigger       'cron' or 'manual'
	 * @param User   $user          User used as author of created leads
	 * @param int    $windowSeconds Force the window (seconds), 0 = since last successful run
	 * @return int 0 if OK, <0 if KO
	 */
	public function synchronize($trigger, $user, $windowSeconds = 0)
	{
		global $conf, $langs;

		$langs->load('markautomecanik@markautomecanik');
		$this->stats = array('received' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0);
		$start = dol_now();

		$client = $this->getClient();
		if (!$client->isConfigured()) {
			$this->error = $client->error;
			return -1;
		}
		if (!$this->acquireLock()) {
			$this->error = $langs->trans('ErrorSyncAlreadyRunning');
			return -2;
		}

		$lastSync = getDolGlobalInt('MARKAUTOMECANIK_LAST_SYNC');
		$maxWindow = max(1, getDolGlobalInt('MARKAUTOMECANIK_MAX_WINDOW_DAYS', 30)) * 86400;
		if ($windowSeconds > 0) {
			$window = min((int) $windowSeconds, $maxWindow);
		} elseif ($lastSync > 0) {
			$window = min(max($start - $lastSync, 0) + self::WINDOW_OVERLAP, $maxWindow);
		} else {
			$window = 86400;
		}
		// Mautic stores dates in UTC
		$since = gmdate('Y-m-d H:i:s', $start - $window);

		$where = array(
			array('col' => 'date_modified', 'expr' => 'gte', 'val' => $since),
			array('col' => 'points', 'expr' => 'gte', 'val' => (string) getDolGlobalInt('MARKAUTOMECANIK_MIN_POINTS', 40)),
		);
		$segment = getDolGlobalString('MARKAUTOMECANIK_SEGMENT');
		$search = ($segment !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $segment)) ? 'segment:'.$segment : '';

		$failed = false;
		$offset = 0;
		$page = 0;
		do {
			$page++;
			$res = $client->getContacts($where, $offset, self::PAGE_SIZE, $search);
			if ($res === null) {
				$this->error = $client->error;
				$failed = true;
				break;
			}
			foreach ($res['contacts'] as $contact) {
				$this->importContact($contact, $user);
			}
			$count = count($res['contacts']);
			$offset += self::PAGE_SIZE;
		} while ($count > 0 && $offset < $res['total'] && $page < self::MAX_PAGES);

		if (!$failed && $page >= self::MAX_PAGES && $offset < $res['total']) {
			$this->error = 'Maximum number of pages reached, the remaining contacts will be processed by the next run';
			$failed = true;
		}
		if (!$failed && $this->stats['errors'] > 0) {
			$this->error = $this->stats['errors'].' contact(s) could not be imported, see the logs';
			$failed = true;
		}

		$this->output = $langs->trans('SyncSummary', $this->stats['received'], $this->stats['created'], $this->stats['updated'], $this->stats['skipped'], $this->stats['errors']);
		$this->output .= ' ('.$langs->trans('SyncWindowSince', $since.' UTC').')';

		if (!$failed) {
			// The window only moves forward on success, a failed run is caught up by the next one
			dolibarr_set_const($this->db, 'MARKAUTOMECANIK_LAST_SYNC', (string) $start, 'chaine', 0, '', $conf->entity);
		}
		$this->writeLog($trigger, $start, ($failed ? -1 : 1), ($failed ? $this->error : $this->output), $user);
		$this->releaseLock();

		dol_syslog(__METHOD__.' '.$this->output.($failed ? ' ERROR: '.$this->error : ''), $failed ? LOG_ERR : LOG_NOTICE);
		return ($failed ? -1 : 0);
	}

	/**
	 * Import a list of contacts by id (used by the webhook)
	 *
	 * @param int[] $ids  Contact ids
	 * @param User  $user User used as author
	 * @return int 0 if OK, <0 if KO
	 */
	public function synchronizeContactIds($ids, $user)
	{
		$this->stats = array('received' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0);
		$start = dol_now();
		$client = $this->getClient();
		if (!$client->isConfigured()) {
			$this->error = $client->error;
			return -1;
		}
		foreach ($ids as $id) {
			$contact = $client->getContact((int) $id);
			if ($contact === null) {
				$this->stats['errors']++;
				$this->error = $client->error;
				continue;
			}
			$this->importContact($contact, $user);
		}
		$failed = ($this->stats['errors'] > 0);
		$this->output = 'received='.$this->stats['received'].' created='.$this->stats['created'].' updated='.$this->stats['updated'].' skipped='.$this->stats['skipped'].' errors='.$this->stats['errors'];
		$this->writeLog('webhook', $start, ($failed ? -1 : 1), ($failed ? $this->error : $this->output), $user);
		return ($failed ? -1 : 0);
	}

	/**
	 * Push the status of a lead to Webmecanik as a tag (closed loop reporting).
	 * Adds <prefix><status> and removes the tags of the other statuses.
	 *
	 * @param MarkAutoMecanikLead $lead Lead
	 * @return int 0 if nothing to do, <0 if KO, >0 if OK
	 */
	public function pushStatusTag($lead)
	{
		if (!getDolGlobalInt('MARKAUTOMECANIK_PUSH_TAGS') || empty($lead->wm_contact_id) || !isset(MarkAutoMecanikLead::STATUS_CODES[(int) $lead->status])) {
			return 0;
		}
		$prefix = preg_replace('/[^A-Za-z0-9_.-]/', '', getDolGlobalString('MARKAUTOMECANIK_TAG_PREFIX', 'dolibarr-'));
		$tags = array();
		foreach (MarkAutoMecanikLead::STATUS_CODES as $status => $code) {
			$tags[] = ($status == (int) $lead->status ? '' : '-').$prefix.$code;
		}
		$client = $this->getClient();
		if (!$client->setContactTags($lead->wm_contact_id, $tags)) {
			$this->error = $client->error;
			return -1;
		}
		return 1;
	}

	// -------------------------------------------------------------------------------------------
	// Scheduled jobs
	// -------------------------------------------------------------------------------------------

	/**
	 * Scheduled job: synchronisation
	 *
	 * @return int 0 if OK, <0 if KO
	 */
	public function cronSync()
	{
		global $user;
		$result = $this->synchronize('cron', $user);
		return ($result < 0 ? -1 : 0);
	}

	/**
	 * Scheduled job: email reminders for leads waiting beyond the processing delay
	 * (MARKAUTOMECANIK_SLA_DAYS). Assigned leads: one summary per sales representative.
	 * Unassigned leads: one summary to MARKAUTOMECANIK_ALERT_EMAIL if set.
	 *
	 * @return int 0 if OK, <0 if KO
	 */
	public function cronRemind()
	{
		global $conf, $langs;

		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
		$langs->load('markautomecanik@markautomecanik');

		$slaDays = getDolGlobalInt('MARKAUTOMECANIK_SLA_DAYS');
		if ($slaDays <= 0) {
			$this->output = $langs->trans('RemindersDisabled');
			return 0;
		}
		$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
		if (empty($from)) {
			$this->error = 'Sender email address is not configured (MAIN_MAIL_EMAIL_FROM)';
			return -1;
		}
		$limit = dol_now() - $slaDays * 86400;
		$cardUrl = dol_buildpath('/markautomecanik/lead_card.php', 2).'?id=';
		$nbMails = 0;
		$error = 0;

		$base = ' FROM '.MAIN_DB_PREFIX.'markautomecanik_lead as t';
		$base .= ' WHERE t.entity = '.((int) $conf->entity);
		$base .= " AND t.date_status < '".$this->db->idate($limit)."'";
		$base .= " AND t.date_creation >= '".$this->db->idate(MarkAutoMecanikLead::getRemindSince())."'";
		$base .= ' AND t.date_anonymized IS NULL';

		// Assigned leads, grouped by sales representative
		$sql = 'SELECT t.rowid, t.fk_user_assign, t.company'.$base;
		$sql .= ' AND t.status = '.MarkAutoMecanikLead::STATUS_ASSIGNED.' AND t.fk_user_assign > 0';
		$sql .= ' ORDER BY t.fk_user_assign, t.date_status';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$byUser = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$byUser[(int) $obj->fk_user_assign][] = $obj;
		}
		$this->db->free($resql);

		foreach ($byUser as $userId => $leads) {
			$rep = new User($this->db);
			if ($rep->fetch($userId) <= 0 || empty($rep->email) || !isValidEmail($rep->email)) {
				continue;
			}
			$repStatus = isset($rep->status) ? $rep->status : $rep->statut;
			if (empty($repStatus)) {
				continue;
			}
			$outlangs = new Translate('', $conf);
			$outlangs->setDefaultLang(empty($rep->lang) ? $langs->defaultlang : $rep->lang);
			$outlangs->loadLangs(array('main', 'markautomecanik@markautomecanik'));

			// Only the lead reference and the company are sent by email (data minimisation)
			$body = $outlangs->transnoentities('ReminderBodyAssigned', $slaDays)."\n\n";
			foreach ($leads as $lead) {
				$body .= '- #'.((int) $lead->rowid).($lead->company ? ' - '.$lead->company : '').' : '.$cardUrl.((int) $lead->rowid)."\n";
			}
			$subject = $outlangs->transnoentities('ReminderSubjectAssigned', count($leads));
			$mail = new CMailFile($subject, $rep->email, $from, $body, array(), array(), array(), '', '', 0, 0, '', '', 'mamremind'.$userId);
			if ($mail->sendfile()) {
				$nbMails++;
			} else {
				$error++;
				dol_syslog(__METHOD__.' failed to send reminder to user id='.$userId.': '.$mail->error, LOG_ERR);
			}
		}

		// Unassigned leads: summary to the alert address
		$alertEmail = getDolGlobalString('MARKAUTOMECANIK_ALERT_EMAIL');
		$nbLate = 0;
		if ($alertEmail !== '' && isValidEmail($alertEmail)) {
			$sql = 'SELECT t.rowid, t.company'.$base.' AND t.status = '.MarkAutoMecanikLead::STATUS_NEW.' ORDER BY t.date_status';
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->error = $this->db->lasterror();
				return -1;
			}
			$body = $langs->transnoentities('ReminderBodyNew', $slaDays)."\n\n";
			while ($obj = $this->db->fetch_object($resql)) {
				$body .= '- #'.((int) $obj->rowid).($obj->company ? ' - '.$obj->company : '').' : '.$cardUrl.((int) $obj->rowid)."\n";
				$nbLate++;
			}
			$this->db->free($resql);
			if ($nbLate > 0) {
				$mail = new CMailFile($langs->transnoentities('ReminderSubjectNew', $nbLate), $alertEmail, $from, $body, array(), array(), array(), '', '', 0, 0, '', '', 'mamremindall');
				if ($mail->sendfile()) {
					$nbMails++;
				} else {
					$error++;
					dol_syslog(__METHOD__.' failed to send alert email: '.$mail->error, LOG_ERR);
				}
			}
		}

		$this->output = $langs->trans('RemindersSent', $nbMails, $nbLate);
		if ($error) {
			$this->error = $error.' email(s) could not be sent, see the logs';
			return -1;
		}
		return 0;
	}

	/**
	 * Scheduled job: GDPR anonymisation of unconverted leads older than MARKAUTOMECANIK_RETENTION_MONTHS
	 * without any activity during that period. Converted leads and leads linked to a thirdparty are kept.
	 *
	 * @return int 0 if OK, <0 if KO
	 */
	public function cronPurge()
	{
		global $conf, $langs, $user;

		$langs->load('markautomecanik@markautomecanik');
		$months = getDolGlobalInt('MARKAUTOMECANIK_RETENTION_MONTHS');
		if ($months <= 0) {
			$this->output = $langs->trans('PurgeDisabled');
			return 0;
		}
		$limit = dol_time_plus_duree(dol_now(), -$months, 'm');

		$sql = 'SELECT t.rowid FROM '.MAIN_DB_PREFIX.'markautomecanik_lead as t';
		$sql .= ' WHERE t.entity = '.((int) $conf->entity);
		$sql .= " AND t.date_creation < '".$this->db->idate($limit)."'";
		$sql .= " AND (t.date_status IS NULL OR t.date_status < '".$this->db->idate($limit)."')";
		$sql .= ' AND t.date_anonymized IS NULL';
		$sql .= ' AND (t.fk_soc IS NULL OR t.fk_soc = 0)';
		$sql .= ' AND t.status NOT IN ('.MarkAutoMecanikLead::STATUS_CONVERTED.', '.MarkAutoMecanikLead::STATUS_ASSIGNED.')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		$ids = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$ids[] = (int) $obj->rowid;
		}
		$this->db->free($resql);

		$nb = 0;
		$lead = new MarkAutoMecanikLead($this->db);
		foreach ($ids as $id) {
			$lead->id = $id;
			if ($lead->anonymize($user) > 0) {
				$nb++;
			}
		}
		$this->output = $langs->trans('PurgeDone', $nb, $months);
		if ($nb < count($ids)) {
			$this->error = (count($ids) - $nb).' lead(s) could not be anonymised, see the logs';
			return -1;
		}
		return 0;
	}
}
