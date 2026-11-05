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
 * \file       markautomecanik/core/boxes/markautomecanikwidget1.php
 * \ingroup    markautomecanik
 * \brief      Home page widget: marketing leads dashboard
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

/**
 * Widget "Marketing leads": leads to process, late, assigned and conversion rate
 */
class markautomecanikwidget1 extends ModeleBoxes
{
	public $boxcode = 'markautomecanikbox';
	public $boximg = 'fa-bullhorn';
	public $boxlabel;
	public $depends = array('markautomecanik');
	public $param;
	public $info_box_head = array();
	public $info_box_contents = array();

	/**
	 * Constructor
	 *
	 * @param DoliDB $db    Database handler
	 * @param string $param More parameters
	 */
	public function __construct(DoliDB $db, $param = '')
	{
		global $user, $langs;
		$langs->loadLangs(array('boxes', 'markautomecanik@markautomecanik'));
		parent::__construct($db, $param);
		$this->boxlabel = $langs->transnoentitiesnoconv('LeadDashboardBox');
		$this->param = $param;
		$this->hidden = !$user->hasRight('markautomecanik', 'lead', 'read');
	}

	/**
	 * Load data of the widget
	 *
	 * @param int $max Maximum number of records to load
	 * @return void
	 */
	public function loadBox($max = 5)
	{
		global $langs, $user;

		if (!empty($this->hidden)) {
			return;
		}
		require_once dol_buildpath('/markautomecanik/class/markautomecaniklead.class.php');

		$this->max = $max;
		$listUrl = dol_buildpath('/markautomecanik/lead_list.php', 1);
		$this->info_box_head = array(
			'text' => $langs->trans('LeadDashboardBox'),
			'sublink' => $listUrl,
			'subpicto' => 'list',
			'subtext' => $langs->trans('MarketingLeads'),
			'limit' => 0,
		);

		$lead = new MarkAutoMecanikLead($this->db);
		$data = $lead->getDashboardData($user);
		if ($data === false) {
			$this->info_box_contents[0][0] = array('td' => 'class="center"', 'text' => $langs->trans('Error'));
			return;
		}

		$labels = MarkAutoMecanikLead::getStatusLabels();
		$slaDays = getDolGlobalInt('MARKAUTOMECANIK_SLA_DAYS');
		$lines = array(
			array($labels[MarkAutoMecanikLead::STATUS_NEW], $data['new'], $listUrl.'?search_status='.MarkAutoMecanikLead::STATUS_NEW),
		);
		if ($slaDays > 0) {
			$lines[] = array($langs->trans('LeadLate', $slaDays), $data['late'], $listUrl.'?search_late=1');
		}
		$lines[] = array($labels[MarkAutoMecanikLead::STATUS_ASSIGNED], $data['assigned'], $listUrl.'?search_status='.MarkAutoMecanikLead::STATUS_ASSIGNED);
		$rate = ($data['total_12m'] > 0 ? round(100 * $data['converted_12m'] / $data['total_12m']).' %' : '-');
		$lines[] = array($langs->trans('LeadConversionRate12Months'), $rate.' <span class="opacitymedium">('.((int) $data['converted_12m']).' / '.((int) $data['total_12m']).')</span>', '');

		$i = 0;
		foreach ($lines as $line) {
			$this->info_box_contents[$i][0] = array('td' => '', 'text' => $line[0], 'url' => $line[2]);
			$this->info_box_contents[$i][1] = array('td' => 'class="right"', 'text' => $line[1], 'asis' => 1);
			$i++;
		}
	}

	/**
	 * Show the widget
	 *
	 * @param array $head     Array with properties of box title
	 * @param array $contents Array with properties of box lines
	 * @param int   $nooutput No print, only return string
	 * @return string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
