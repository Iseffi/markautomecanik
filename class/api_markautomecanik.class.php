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
 * \file       markautomecanik/class/api_markautomecanik.class.php
 * \ingroup    markautomecanik
 * \brief      REST API of module MarkAutoMecanik (/api/index.php/markautomecanik/...)
 */

use Luracast\Restler\RestException;

require_once __DIR__.'/markautomecaniklead.class.php';

/**
 * REST API for marketing leads
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class Markautomecanik extends DolibarrApi
{
	/** @var array Fields accepted on creation (white list) */
	const WRITABLE_FIELDS = array(
		'firstname', 'lastname', 'email', 'phone', 'company', 'city', 'country', 'lang', 'website',
		'industry', 'interests', 'message', 'utm_source', 'utm_medium', 'utm_campaign',
	);

	/** @var array Fields returned by the API (white list) */
	const READABLE_FIELDS = array(
		'id', 'origin', 'wm_contact_id', 'wm_points', 'wm_date_added', 'wm_date_modified', 'firstname', 'lastname',
		'email', 'phone', 'company', 'city', 'country', 'lang', 'website', 'industry', 'interests', 'message',
		'utm_source', 'utm_medium', 'utm_campaign', 'status', 'date_status', 'lost_reason', 'fk_user_assign',
		'fk_soc', 'fk_socpeople', 'fk_project', 'date_creation', 'date_anonymized',
	);

	/** @var array Allowed sort fields */
	const SORT_FIELDS = array('t.rowid', 't.date_creation', 't.date_status', 't.status', 't.wm_points');

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
	}

	/**
	 * Common access checks
	 *
	 * @param string $right 'read', 'write'
	 * @return void
	 * @throws RestException
	 */
	protected function checkAccess($right)
	{
		if (!isModEnabled('markautomecanik')) {
			throw new RestException(501, 'Module markautomecanik is not enabled');
		}
		if (!empty(DolibarrApiAccess::$user->socid)) {
			throw new RestException(403, 'Not allowed for external users');
		}
		if (!DolibarrApiAccess::$user->hasRight('markautomecanik', 'lead', $right)) {
			throw new RestException(403, 'Permission markautomecanik/lead/'.$right.' required');
		}
	}

	/**
	 * Export a lead as an array limited to the readable fields
	 *
	 * @param MarkAutoMecanikLead $lead Lead
	 * @return array
	 */
	protected function exportLead($lead)
	{
		$out = array();
		foreach (self::READABLE_FIELDS as $field) {
			$out[$field] = $lead->$field;
		}
		// Links to records the user is not allowed to see are not disclosed
		$apiUser = DolibarrApiAccess::$user;
		if ($out['fk_soc'] > 0 && !MarkAutoMecanikLead::userCanReadThirdparty($this->db, $apiUser, $out['fk_soc'])) {
			$out['fk_soc'] = null;
		}
		if ($out['fk_socpeople'] > 0 && !MarkAutoMecanikLead::userCanReadContact($this->db, $apiUser, $out['fk_socpeople'])) {
			$out['fk_socpeople'] = null;
		}
		if ($out['fk_project'] > 0 && MarkAutoMecanikLead::fetchProjectIfReadable($this->db, $apiUser, $out['fk_project']) === null) {
			$out['fk_project'] = null;
		}
		$out['status_label'] = MarkAutoMecanikLead::getStatusLabels()[(int) $lead->status] ?? '';
		return $out;
	}

	/**
	 * Get a marketing lead
	 *
	 * @param int $id Id of the lead
	 * @return array
	 *
	 * @url GET leads/{id}
	 * @throws RestException
	 */
	public function getLead($id)
	{
		$this->checkAccess('read');
		$lead = new MarkAutoMecanikLead($this->db);
		if ($lead->fetch((int) $id) <= 0 || !$lead->canBeReadBy(DolibarrApiAccess::$user)) {
			throw new RestException(404, 'Lead not found');
		}
		return $this->exportLead($lead);
	}

	/**
	 * List marketing leads
	 *
	 * @param string $sortfield Sort field (t.rowid, t.date_creation, t.date_status, t.status, t.wm_points)
	 * @param string $sortorder Sort order (ASC or DESC)
	 * @param int    $limit     Limit (max 500)
	 * @param int    $page      Page number (starting at 0)
	 * @param int    $status    Filter on status (1=new, 2=assigned, 3=invalid, 4=archived, 5=converted, 6=lost)
	 * @return array
	 *
	 * @url GET leads
	 * @throws RestException
	 */
	public function listLeads($sortfield = 't.rowid', $sortorder = 'DESC', $limit = 100, $page = 0, $status = 0)
	{
		$this->checkAccess('read');
		if (!in_array($sortfield, self::SORT_FIELDS, true)) {
			$sortfield = 't.rowid';
		}
		$sortorder = (strtoupper($sortorder) === 'ASC' ? 'ASC' : 'DESC');
		$limit = min(500, max(1, (int) $limit));
		$page = max(0, (int) $page);

		$sql = 'SELECT t.rowid FROM '.MAIN_DB_PREFIX.'markautomecanik_lead as t';
		$sql .= ' WHERE t.entity IN ('.getEntity('markautomecaniklead').')';
		$sql .= MarkAutoMecanikLead::getSqlUserRestriction(DolibarrApiAccess::$user, 't');
		if ((int) $status > 0) {
			$sql .= ' AND t.status = '.((int) $status);
		}
		$sql .= $this->db->order($sortfield, $sortorder);
		$sql .= $this->db->plimit($limit, $limit * $page);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new RestException(503, 'Error when retrieving leads');
		}
		$result = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$lead = new MarkAutoMecanikLead($this->db);
			if ($lead->fetch($obj->rowid) > 0) {
				$result[] = $this->exportLead($lead);
			}
		}
		$this->db->free($resql);
		return $result;
	}

	/**
	 * Create a marketing lead (for example from a website form or another tool)
	 *
	 * @param array $request_data Lead data: firstname, lastname, email, phone, company, city, country, lang, website, industry, interests, message, utm_source, utm_medium, utm_campaign
	 * @return int Id of the created lead
	 *
	 * @url POST leads
	 * @throws RestException
	 */
	public function postLead($request_data = null)
	{
		$this->checkAccess('write');
		$request_data = (array) $request_data;

		$lead = new MarkAutoMecanikLead($this->db);
		foreach (self::WRITABLE_FIELDS as $field) {
			if (isset($request_data[$field]) && is_scalar($request_data[$field])) {
				if ($field === 'email') {
					// Validated before cleaning, which would silently empty an unsafe email
					$email = strtolower(MarkAutoMecanikLead::cleanText($request_data[$field], MarkAutoMecanikLead::MAXLENGTHS['email']));
					if ($email !== '' && !MarkAutoMecanikLead::isSafeEmail($email)) {
						throw new RestException(400, 'Invalid email');
					}
				}
				$lead->$field = MarkAutoMecanikLead::cleanField($field, $request_data[$field]);
			}
		}
		if (trim((string) $lead->lastname) === '' && trim((string) $lead->company) === '') {
			throw new RestException(400, 'Field lastname or company is required');
		}
		if (!empty($lead->website) && !preg_match('#^https?://#i', $lead->website)) {
			throw new RestException(400, 'Invalid website URL');
		}
		$lead->origin = MarkAutoMecanikLead::ORIGIN_API;
		$lead->status = MarkAutoMecanikLead::STATUS_NEW;

		$id = $lead->create(DolibarrApiAccess::$user);
		if ($id <= 0) {
			throw new RestException(500, 'Error when creating the lead');
		}
		return (int) $id;
	}
}
