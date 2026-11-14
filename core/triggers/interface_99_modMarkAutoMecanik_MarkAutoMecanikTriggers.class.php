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
 * \file       markautomecanik/core/triggers/interface_99_modMarkAutoMecanik_MarkAutoMecanikTriggers.class.php
 * \ingroup    markautomecanik
 * \brief      Keeps marketing leads consistent when linked Dolibarr objects are deleted, and
 *             queues the contacts and third parties to send to Webmecanik
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Triggers of module MarkAutoMecanik
 */
class InterfaceMarkAutoMecanikTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = 'crm';
		$this->description = 'MarkAutoMecanik triggers: unlink marketing leads from deleted thirdparties, contacts and projects; queue contacts and thirdparties to send to Webmecanik';
		$this->version = '1.2.0';
		$this->picto = 'fa-bullhorn';
	}

	/**
	 * Called when a Dolibarr business event is done
	 *
	 * @param string    $action Event action code
	 * @param Object    $object Object
	 * @param User      $user   Object user
	 * @param Translate $langs  Object langs
	 * @param Conf      $conf   Object conf
	 * @return int <0 if KO, 0 if no triggered ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('markautomecanik')) {
			return 0;
		}

		// Dolibarr -> Webmecanik: the objects are only queued here. A failure must never prevent
		// the user from saving the record, so the push never makes the trigger fail.
		if (getDolGlobalInt('MARKAUTOMECANIK_PUSH_ENABLED')) {
			$this->queuePush($action, $object);
		}

		$columns = array(
			'COMPANY_DELETE' => array('fk_soc', 'fk_socpeople'),
			'CONTACT_DELETE' => array('fk_socpeople'),
			'PROJECT_DELETE' => array('fk_project'),
		);
		if (!isset($columns[$action]) || empty($object->id)) {
			return 0;
		}

		$set = array();
		foreach ($columns[$action] as $column) {
			$set[] = $column.' = NULL';
		}
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'markautomecanik_lead SET '.implode(', ', $set);
		if ($action === 'COMPANY_DELETE') {
			// Contacts of the thirdparty are deleted too
			$sql .= ' WHERE fk_soc = '.((int) $object->id);
		} else {
			$sql .= ' WHERE '.$columns[$action][0].' = '.((int) $object->id);
		}
		dol_syslog(__METHOD__.' '.$action.' id='.((int) $object->id), LOG_DEBUG);
		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			return -1;
		}
		return 1;
	}

	/**
	 * Queue the contacts and third parties to send to Webmecanik
	 *
	 * @param string $action Event action code
	 * @param object $object Object
	 * @return void
	 */
	protected function queuePush($action, $object)
	{
		require_once dirname(dirname(__DIR__)).'/class/markautomecanikpush.class.php';

		$id = empty($object->id) ? 0 : (int) $object->id;
		switch ($action) {
			case 'CONTACT_CREATE':
			case 'CONTACT_MODIFY':
				MarkAutoMecanikPush::enqueue($this->db, MarkAutoMecanikPush::TYPE_CONTACT, $id);
				break;

			case 'CONTACT_DELETE':
				// Called before the deletion: the Webmecanik id is still readable
				if (getDolGlobalInt('MARKAUTOMECANIK_PUSH_DELETE')) {
					$push = new MarkAutoMecanikPush($this->db);
					$wmId = $push->getLinkedId(MarkAutoMecanikPush::TYPE_CONTACT, $id);
					if ($wmId > 0) {
						MarkAutoMecanikPush::enqueue($this->db, MarkAutoMecanikPush::TYPE_CONTACT, $id, MarkAutoMecanikPush::ACTION_DELETE, $wmId);
					}
				}
				break;

			case 'COMPANY_MODIFY':
				// Only third parties already in Webmecanik are updated; the others are created with their contacts
				$push = new MarkAutoMecanikPush($this->db);
				if ($push->getLinkedId(MarkAutoMecanikPush::TYPE_THIRDPARTY, $id) > 0) {
					MarkAutoMecanikPush::enqueue($this->db, MarkAutoMecanikPush::TYPE_THIRDPARTY, $id);
				}
				break;

			case 'CATEGORY_LINK':
			case 'CATEGORY_UNLINK':
				// A contact entering or leaving a category may enter or leave the push scope
				$linked = (isset($object->context['linkto']) && is_object($object->context['linkto'])) ? $object->context['linkto'] : null;
				if ($linked !== null && isset($linked->element) && $linked->element === 'contact' && !empty($linked->id)) {
					MarkAutoMecanikPush::enqueue($this->db, MarkAutoMecanikPush::TYPE_CONTACT, (int) $linked->id);
				}
				break;
		}
	}
}
