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
 * \defgroup   markautomecanik     Module MarkAutoMecanik
 * \brief      Webmecanik marketing automation - incoming marketing leads for Dolibarr
 *
 * \file       markautomecanik/core/modules/modMarkAutoMecanik.class.php
 * \ingroup    markautomecanik
 * \brief      Description and activation file for module MarkAutoMecanik
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module MarkAutoMecanik
 */
class modMarkAutoMecanik extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Unique module id. 500000-599999 is the range for non-registered modules.
		// Ask for an official id on https://wiki.dolibarr.org/index.php/List_of_modules_id before publishing on Dolistore.
		$this->numero = 500030;
		$this->rights_class = 'markautomecanik';
		$this->family = 'crm';
		$this->module_position = '91';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'Webmecanik marketing automation: import qualified contacts as marketing leads';
		$this->descriptionlong = 'Imports qualified contacts (points threshold, optional segment) from Webmecanik Automation as marketing leads, with qualification workflow, sales assignment, reminders and GDPR retention.';
		$this->editor_name = 'Iseffi';
		$this->editor_url = 'https://github.com/iseffi/markautomecanik';
		$this->version = '1.0.1';
		$this->url_last_version = '';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'fa-bullhorn';

		$this->module_parts = array(
			'triggers' => 1,
			'login' => 0,
			'substitutions' => 0,
			'menus' => 0,
			'tpl' => 0,
			'barcode' => 0,
			'models' => 0,
			'printing' => 0,
			'theme' => 0,
			'css' => array(),
			'js' => array(),
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		$this->dirs = array('/markautomecanik/temp');
		$this->config_page_url = array('setup.php@markautomecanik');

		$this->hidden = false;
		$this->depends = array('modSociete');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('markautomecanik@markautomecanik');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(19, 0);
		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Constants created at activation (name, type, value, description, visible, entity, deleteonunactive)
		$this->const = array(
			0 => array('MARKAUTOMECANIK_WM_AUTH', 'chaine', 'oauth2', 'Webmecanik authentication mode (oauth2 or basic)', 0, 'current', 0),
			1 => array('MARKAUTOMECANIK_MIN_POINTS', 'chaine', '40', 'Minimum Webmecanik points to import a contact', 0, 'current', 0),
			2 => array('MARKAUTOMECANIK_REMIND_MAX_AGE_MONTHS', 'chaine', '3', 'Leads older than this are ignored by reminders', 0, 'current', 0),
			3 => array('MARKAUTOMECANIK_TAG_PREFIX', 'chaine', 'dolibarr-', 'Prefix of status tags pushed to Webmecanik', 0, 'current', 0),
		);

		if (!isset($conf->markautomecanik) || !isset($conf->markautomecanik->enabled)) {
			$conf->markautomecanik = new stdClass();
			$conf->markautomecanik->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();

		// Widgets
		$this->boxes = array(
			0 => array('file' => 'markautomecanikwidget1.php@markautomecanik', 'note' => 'Marketing leads dashboard', 'enabledbydefaulton' => 'Home'),
		);

		// Scheduled jobs (all disabled by default: the administrator enables them once the connector is configured)
		$this->cronjobs = array(
			0 => array(
				'label' => 'Webmecanik - synchronise marketing leads',
				'jobtype' => 'method',
				'class' => '/markautomecanik/class/markautomecaniksync.class.php',
				'objectname' => 'MarkAutoMecanikSync',
				'method' => 'cronSync',
				'parameters' => '',
				'comment' => 'Import qualified contacts from Webmecanik Automation as marketing leads',
				'frequency' => 1,
				'unitfrequency' => 3600,
				'status' => 0,
				'test' => 'isModEnabled("markautomecanik")',
				'priority' => 50,
			),
			1 => array(
				'label' => 'Webmecanik - remind pending marketing leads',
				'jobtype' => 'method',
				'class' => '/markautomecanik/class/markautomecaniksync.class.php',
				'objectname' => 'MarkAutoMecanikSync',
				'method' => 'cronRemind',
				'parameters' => '',
				'comment' => 'Email sales representatives about leads waiting beyond the processing delay',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 0,
				'test' => 'isModEnabled("markautomecanik")',
				'priority' => 50,
			),
			2 => array(
				'label' => 'Webmecanik - GDPR purge of marketing leads',
				'jobtype' => 'method',
				'class' => '/markautomecanik/class/markautomecaniksync.class.php',
				'objectname' => 'MarkAutoMecanikSync',
				'method' => 'cronPurge',
				'parameters' => '',
				'comment' => 'GDPR: anonymise unconverted leads older than the retention period',
				'frequency' => 1,
				'unitfrequency' => 86400,
				'status' => 0,
				'test' => 'isModEnabled("markautomecanik")',
				'priority' => 50,
			),
		);

		// Permissions
		$this->rights = array();
		$r = 0;

		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'Read marketing leads assigned to or created by the user';
		$this->rights[$r][4] = 'lead';
		$this->rights[$r][5] = 'read';
		$r++;

		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'Read all marketing leads (including unassigned leads)';
		$this->rights[$r][4] = 'lead';
		$this->rights[$r][5] = 'readall';
		$r++;

		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'Create and qualify marketing leads (status, assignment, links)';
		$this->rights[$r][4] = 'lead';
		$this->rights[$r][5] = 'write';
		$r++;

		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'Delete and anonymise marketing leads';
		$this->rights[$r][4] = 'lead';
		$this->rights[$r][5] = 'delete';
		$r++;

		$this->rights[$r][0] = $this->numero.sprintf('%02d', $r + 1);
		$this->rights[$r][1] = 'Run the Webmecanik synchronisation manually';
		$this->rights[$r][4] = 'sync';
		$this->rights[$r][5] = 'run';
		$r++;

		// Menus
		$this->menu = array();
		$r = 0;

		$this->menu[$r++] = array(
			'fk_menu' => '',
			'type' => 'top',
			'titre' => 'MarkAutoMecanikMenu',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'markautomecanik',
			'leftmenu' => '',
			'url' => '/markautomecanik/lead_list.php',
			'langs' => 'markautomecanik@markautomecanik',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("markautomecanik")',
			'perms' => '$user->hasRight("markautomecanik", "lead", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=markautomecanik',
			'type' => 'left',
			'titre' => 'MarketingLeads',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle paddingright"'),
			'mainmenu' => 'markautomecanik',
			'leftmenu' => 'markautomecanik_lead',
			'url' => '/markautomecanik/lead_list.php',
			'langs' => 'markautomecanik@markautomecanik',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("markautomecanik")',
			'perms' => '$user->hasRight("markautomecanik", "lead", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=markautomecanik,fk_leftmenu=markautomecanik_lead',
			'type' => 'left',
			'titre' => 'NewLead',
			'mainmenu' => 'markautomecanik',
			'leftmenu' => 'markautomecanik_lead_new',
			'url' => '/markautomecanik/lead_card.php?action=create',
			'langs' => 'markautomecanik@markautomecanik',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("markautomecanik")',
			'perms' => '$user->hasRight("markautomecanik", "lead", "write")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=markautomecanik,fk_leftmenu=markautomecanik_lead',
			'type' => 'left',
			'titre' => 'LeadsToProcess',
			'mainmenu' => 'markautomecanik',
			'leftmenu' => 'markautomecanik_lead_new_list',
			'url' => '/markautomecanik/lead_list.php?search_status=1',
			'langs' => 'markautomecanik@markautomecanik',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("markautomecanik")',
			'perms' => '$user->hasRight("markautomecanik", "lead", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=markautomecanik,fk_leftmenu=markautomecanik_lead',
			'type' => 'left',
			'titre' => 'MyLeads',
			'mainmenu' => 'markautomecanik',
			'leftmenu' => 'markautomecanik_lead_mine',
			'url' => '/markautomecanik/lead_list.php?search_user_assign=-2',
			'langs' => 'markautomecanik@markautomecanik',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("markautomecanik")',
			'perms' => '$user->hasRight("markautomecanik", "lead", "read")',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=markautomecanik,fk_leftmenu=markautomecanik_lead',
			'type' => 'left',
			'titre' => 'Statistics',
			'mainmenu' => 'markautomecanik',
			'leftmenu' => 'markautomecanik_stats',
			'url' => '/markautomecanik/stats.php',
			'langs' => 'markautomecanik@markautomecanik',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("markautomecanik")',
			'perms' => '$user->hasRight("markautomecanik", "lead", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 * Function called when module is enabled.
	 * The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 * It also creates data directories.
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/markautomecanik/sql/');
		if ($result < 0) {
			return -1;
		}

		// Remove permissions of a previous activation before re-adding them
		$this->remove($options);

		$sql = array();

		return $this->_init($sql, $options);
	}

	/**
	 * Function called when module is disabled.
	 * Remove from database constants, boxes and permissions from Dolibarr database.
	 * Data directories and tables are not deleted.
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
