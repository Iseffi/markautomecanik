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
 * \file       markautomecanik/lead_list.php
 * \ingroup    markautomecanik
 * \brief      List of marketing leads
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)).'/main.inc.php')) {
	$res = @include substr($tmp, 0, ($i + 1)).'/main.inc.php';
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php')) {
	$res = @include dirname(substr($tmp, 0, ($i + 1))).'/main.inc.php';
}
if (!$res && file_exists('../main.inc.php')) {
	$res = @include '../main.inc.php';
}
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once __DIR__.'/class/markautomecaniklead.class.php';
require_once __DIR__.'/class/markautomecaniksync.class.php';
require_once __DIR__.'/lib/markautomecanik.lib.php';

$langs->loadLangs(array('companies', 'other', 'markautomecanik@markautomecanik'));

if (!isModEnabled('markautomecanik')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('markautomecanik', 'lead', 'read') || !empty($user->socid)) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$massaction = GETPOST('massaction', 'alpha');
$toselect = GETPOST('toselect', 'array');
$contextpage = 'markautomecaniklist';

$search_all = trim(GETPOST('search_all', 'alphanohtml'));
$search_status = GETPOSTISSET('search_status') ? GETPOSTINT('search_status') : 0;
$search_user_assign = GETPOSTINT('search_user_assign');
$search_year = GETPOSTINT('search_year');
$search_origin = GETPOST('search_origin', 'aZ09');
$search_late = GETPOSTINT('search_late');

if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	$search_all = '';
	$search_status = 0;
	$search_user_assign = 0;
	$search_year = 0;
	$search_origin = '';
	$search_late = 0;
	$toselect = array();
}

// Pagination and sort (sort field restricted to a white list)
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$limit = min(max(1, $limit), 1000);
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT('page');
if ($page < 0) {
	$page = 0;
}
$offset = $limit * $page;
$sortfields = array(
	't.rowid' => 't.rowid', 't.date_creation' => 't.date_creation', 't.lastname' => 't.lastname', 't.email' => 't.email',
	't.company' => 't.company', 't.wm_points' => 't.wm_points', 't.status' => 't.status', 't.fk_user_assign' => 't.fk_user_assign',
	't.date_status' => 't.date_status', 's.nom' => 's.nom',
);
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = strtoupper(GETPOST('sortorder', 'aZ09')) === 'ASC' ? 'ASC' : 'DESC';
if (!isset($sortfields[$sortfield])) {
	$sortfield = 't.date_creation';
}

$object = new MarkAutoMecanikLead($db);
$hookmanager->initHooks(array('markautomecaniklist'));
$form = new Form($db);
$formother = new FormOther($db);

$permissiontoadd = $user->hasRight('markautomecanik', 'lead', 'write');
$permissiontodelete = $user->hasRight('markautomecanik', 'lead', 'delete');
$permissiontosync = $user->hasRight('markautomecanik', 'sync', 'run');

/*
 * Actions
 */

// Manual synchronisation (POST only, CSRF token, dedicated permission)
if ($action === 'synchronize') {
	if (!$permissiontosync) {
		accessforbidden();
	}
	markautomecanikRequirePost();
	markautomecanikCheckToken();
	$sync = new MarkAutoMecanikSync($db);
	if ($sync->synchronize('manual', $user) < 0) {
		setEventMessages($sync->error, null, 'errors');
		if ($sync->output) {
			setEventMessages($sync->output, null, 'warnings');
		}
	} else {
		setEventMessages($sync->output, null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Mass actions
if ($massaction && GETPOST('confirmmassaction', 'alpha') && !empty($toselect)) {
	markautomecanikCheckToken();
	$ids = array_unique(array_filter(array_map('intval', (array) $toselect)));
	$nbok = 0;
	foreach ($ids as $leadid) {
		$lead = new MarkAutoMecanikLead($db);
		if ($lead->fetch($leadid) <= 0 || !$lead->canBeReadBy($user) || !empty($lead->date_anonymized)) {
			continue;
		}
		if ($massaction === 'archive' && $permissiontoadd) {
			if ($lead->setStatus($user, MarkAutoMecanikLead::STATUS_ARCHIVED) >= 0) {
				$nbok++;
			}
		} elseif ($massaction === 'invalid' && $permissiontoadd) {
			if ($lead->setStatus($user, MarkAutoMecanikLead::STATUS_INVALID) >= 0) {
				$nbok++;
			}
		} elseif ($massaction === 'assignme' && $permissiontoadd) {
			if ($lead->assign($user, $user->id) >= 0) {
				$nbok++;
			}
		}
	}
	setEventMessages($langs->trans('RecordsModified', $nbok), null, 'mesgs');
	$toselect = array();
	$massaction = '';
}

/*
 * View
 */

$title = $langs->trans('MarketingLeads');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-markautomecanik page-list');

$sqlfrom = ' FROM '.MAIN_DB_PREFIX.'markautomecanik_lead as t';
// Only the third parties the user is allowed to see are joined (name displayed and searchable)
$sqlfrom .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = t.fk_soc'.MarkAutoMecanikLead::getSqlThirdpartyRestriction($user, 's');
$sqlwhere = ' WHERE t.entity IN ('.getEntity($object->element).')';
$sqlwhere .= MarkAutoMecanikLead::getSqlUserRestriction($user, 't');
if ($search_status > 0) {
	$sqlwhere .= ' AND t.status = '.((int) $search_status);
}
if ($search_user_assign == -2) {
	$sqlwhere .= ' AND t.fk_user_assign = '.((int) $user->id);
} elseif ($search_user_assign == -3) {
	$sqlwhere .= ' AND t.fk_user_assign IS NULL';
} elseif ($search_user_assign > 0) {
	$sqlwhere .= ' AND t.fk_user_assign = '.((int) $search_user_assign);
}
if ($search_year > 0) {
	$sqlwhere .= " AND t.date_creation >= '".$db->idate(dol_mktime(0, 0, 0, 1, 1, $search_year))."'";
	$sqlwhere .= " AND t.date_creation <= '".$db->idate(dol_mktime(23, 59, 59, 12, 31, $search_year))."'";
}
if (in_array($search_origin, array(MarkAutoMecanikLead::ORIGIN_WEBMECANIK, MarkAutoMecanikLead::ORIGIN_MANUAL, MarkAutoMecanikLead::ORIGIN_API), true)) {
	$sqlwhere .= " AND t.origin = '".$db->escape($search_origin)."'";
}
if ($search_late && getDolGlobalInt('MARKAUTOMECANIK_SLA_DAYS') > 0) {
	$sqlwhere .= ' AND t.status IN ('.MarkAutoMecanikLead::STATUS_NEW.', '.MarkAutoMecanikLead::STATUS_ASSIGNED.')';
	$sqlwhere .= " AND t.date_status < '".$db->idate(dol_now() - getDolGlobalInt('MARKAUTOMECANIK_SLA_DAYS') * 86400)."'";
	$sqlwhere .= " AND t.date_creation >= '".$db->idate(MarkAutoMecanikLead::getRemindSince())."'";
}
if ($search_all !== '') {
	$sqlwhere .= natural_search(array('t.firstname', 't.lastname', 't.email', 't.company', 't.phone', 't.city', 's.nom'), $search_all);
}

// Count
$nbtotalofrecords = 0;
$resql = $db->query('SELECT COUNT(t.rowid) as nb'.$sqlfrom.$sqlwhere);
if ($resql && ($obj = $db->fetch_object($resql))) {
	$nbtotalofrecords = (int) $obj->nb;
}
if ($offset > $nbtotalofrecords) {
	$page = 0;
	$offset = 0;
}

$sql = 'SELECT t.rowid, t.date_creation, t.firstname, t.lastname, t.email, t.phone, t.company, t.wm_points, t.status,';
$sql .= ' t.fk_user_assign, t.date_status, t.origin, t.date_anonymized, s.rowid as socid, s.nom as socname';
$sql .= $sqlfrom.$sqlwhere;
$sql .= $db->order($sortfields[$sortfield].', t.rowid', $sortorder.', DESC');
$sql .= $db->plimit($limit + 1, $offset);

$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
	llxFooter();
	$db->close();
	exit;
}
$num = $db->num_rows($resql);

$param = '';
if ($limit != $conf->liste_limit) {
	$param .= '&limit='.((int) $limit);
}
if ($search_all !== '') {
	$param .= '&search_all='.urlencode($search_all);
}
if ($search_status) {
	$param .= '&search_status='.((int) $search_status);
}
if ($search_user_assign) {
	$param .= '&search_user_assign='.((int) $search_user_assign);
}
if ($search_year) {
	$param .= '&search_year='.((int) $search_year);
}
if ($search_origin) {
	$param .= '&search_origin='.urlencode($search_origin);
}
if ($search_late) {
	$param .= '&search_late=1';
}

$arrayofmassactions = array();
if ($permissiontoadd) {
	$arrayofmassactions['assignme'] = img_picto('', 'user', 'class="pictofixedwidth"').$langs->trans('AssignToMe');
	$arrayofmassactions['invalid'] = img_picto('', 'close_title', 'class="pictofixedwidth"').$langs->trans('SetInvalid');
	$arrayofmassactions['archive'] = img_picto('', 'archive', 'class="pictofixedwidth"').$langs->trans('Archive');
}
$massactionbutton = $form->selectMassAction('', $arrayofmassactions);

$newcardbutton = '';
if ($permissiontosync) {
	$newcardbutton .= '<form class="inline-block" method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	$newcardbutton .= '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="synchronize">';
	$newcardbutton .= '<button type="submit" class="btnTitle reposition" title="'.dol_escape_htmltag($langs->trans('SynchronizeNow')).'"><span class="fa fa-sync-alt valignmiddle btnTitle-icon"></span></button>';
	$newcardbutton .= '</form>';
}
$newcardbutton .= dolGetButtonTitle($langs->trans('NewLead'), '', 'fa fa-plus-circle', dol_buildpath('/markautomecanik/lead_card.php', 1).'?action=create', '', $permissiontoadd);

print '<form method="POST" id="searchFormList" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="formfilteraction" id="formfilteraction" value="list">';
print '<input type="hidden" name="sortfield" value="'.dol_escape_htmltag($sortfield).'">';
print '<input type="hidden" name="sortorder" value="'.dol_escape_htmltag($sortorder).'">';
print '<input type="hidden" name="page" value="'.((int) $page).'">';
print '<input type="hidden" name="contextpage" value="'.$contextpage.'">';

print_barre_liste($title, $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, $massactionbutton, $num, $nbtotalofrecords, 'fa-bullhorn', 0, $newcardbutton, '', $limit, 0, 0, 1);

if ($search_all !== '') {
	print '<div class="divsearchfieldfilter">'.$langs->trans('FilterOnInto', dol_escape_htmltag($search_all)).' '.$langs->trans('Name').', '.$langs->trans('Email').', '.$langs->trans('Company').'</div>';
}

$lastSync = getDolGlobalInt('MARKAUTOMECANIK_LAST_SYNC');
print '<div class="opacitymedium small marginbottomonly">'.$langs->trans('LastSuccessfulSync').' : '.($lastSync ? dol_print_date($lastSync, 'dayhour', 'tzuser') : $langs->trans('Never')).'</div>';

print '<div class="div-table-responsive">';
print '<table class="tagtable nobottomiftotal liste">';

// Filters
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre"><input type="text" class="flat maxwidth100" name="search_all" value="'.dol_escape_htmltag($search_all).'" placeholder="'.dol_escape_htmltag($langs->trans('Search')).'"></td>';
print '<td class="liste_titre center">'.$formother->selectyear($search_year ? $search_year : -1, 'search_year', 1, 10, 0, 0, 0, '', 'maxwidth75').'</td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre"></td>';
print '<td class="liste_titre right">';
print $form->selectarray('search_origin', array('webmecanik' => $langs->trans('OriginWebmecanik'), 'manual' => $langs->trans('OriginManual'), 'api' => $langs->trans('OriginApi')), $search_origin, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth100');
print '</td>';
print '<td class="liste_titre center">'.$form->selectarray('search_status', MarkAutoMecanikLead::getStatusLabels(), $search_status, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
$assignOptions = array(-2 => $langs->trans('AssignedToMe'), -3 => $langs->trans('NotAssigned'));
if ($user->hasRight('markautomecanik', 'lead', 'readall')) {
	$resu = $db->query('SELECT DISTINCT u.rowid, u.firstname, u.lastname FROM '.MAIN_DB_PREFIX.'user as u INNER JOIN '.MAIN_DB_PREFIX.'markautomecanik_lead as t ON t.fk_user_assign = u.rowid WHERE t.entity IN ('.getEntity($object->element).') ORDER BY u.lastname');
	while ($resu && ($o = $db->fetch_object($resu))) {
		$assignOptions[(int) $o->rowid] = trim($o->firstname.' '.$o->lastname);
	}
}
print '<td class="liste_titre">'.$form->selectarray('search_user_assign', $assignOptions, $search_user_assign, 1, 0, 0, '', 0, 0, 0, '', 'maxwidth150').'</td>';
print '<td class="liste_titre center">';
if (getDolGlobalInt('MARKAUTOMECANIK_SLA_DAYS') > 0) {
	print '<label><input type="checkbox" name="search_late" value="1"'.($search_late ? ' checked' : '').'> '.$langs->trans('Late').'</label>';
}
print '</td>';
print '<td class="liste_titre center maxwidthsearch">'.$form->showFilterButtons().'</td>';
print '</tr>';

// Titles
print '<tr class="liste_titre">';
print_liste_field_titre('Ref', $_SERVER['PHP_SELF'], 't.rowid', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DateCreation', $_SERVER['PHP_SELF'], 't.date_creation', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('Name', $_SERVER['PHP_SELF'], 't.lastname', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('Company', $_SERVER['PHP_SELF'], 't.company', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('WebmecanikPoints', $_SERVER['PHP_SELF'], 't.wm_points', '', $param, '', $sortfield, $sortorder, 'right ');
print_liste_field_titre('Status', $_SERVER['PHP_SELF'], 't.status', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre('SalesRepresentative', $_SERVER['PHP_SELF'], 't.fk_user_assign', '', $param, '', $sortfield, $sortorder);
print_liste_field_titre('DateStatus', $_SERVER['PHP_SELF'], 't.date_status', '', $param, '', $sortfield, $sortorder, 'center ');
print_liste_field_titre($form->showCheckAddButtons('checkforselect', 1), $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'center maxwidthsearch ');
print '</tr>';

$lead = new MarkAutoMecanikLead($db);
$soc = new Societe($db);
$usercache = array();
$slaLimit = getDolGlobalInt('MARKAUTOMECANIK_SLA_DAYS') > 0 ? dol_now() - getDolGlobalInt('MARKAUTOMECANIK_SLA_DAYS') * 86400 : 0;

$i = 0;
while ($i < min($num, $limit)) {
	$obj = $db->fetch_object($resql);
	$lead->id = (int) $obj->rowid;
	$lead->firstname = $obj->firstname;
	$lead->lastname = $obj->lastname;
	$lead->email = $obj->email;
	$lead->company = $obj->company;
	$lead->status = (int) $obj->status;

	print '<tr class="oddeven">';
	print '<td class="nowraponall">'.$lead->getNomUrl(1, '', 0, 0).'</td>';
	print '<td class="center nowraponall">'.dol_print_date($db->jdate($obj->date_creation), 'day', 'tzuser').'</td>';
	print '<td class="tdoverflowmax200">'.dol_escape_htmltag(trim($obj->firstname.' '.$obj->lastname));
	if ($obj->email) {
		print '<br><span class="opacitymedium small">'.dol_escape_htmltag($obj->email).'</span>';
	}
	print '</td>';
	print '<td class="tdoverflowmax200">';
	if ($obj->socid > 0) {
		$soc->id = (int) $obj->socid;
		$soc->name = $obj->socname;
		print $soc->getNomUrl(1);
	} else {
		print dol_escape_htmltag($obj->company);
	}
	print '</td>';
	print '<td class="right">'.((int) $obj->wm_points).'</td>';
	print '<td class="center nowraponall">'.$lead->getLibStatut(5).'</td>';
	print '<td class="tdoverflowmax150">';
	if ($obj->fk_user_assign > 0) {
		if (!isset($usercache[$obj->fk_user_assign])) {
			$u = new User($db);
			$u->fetch($obj->fk_user_assign);
			$usercache[$obj->fk_user_assign] = $u;
		}
		print $usercache[$obj->fk_user_assign]->getNomUrl(-1);
	}
	print '</td>';
	$dateStatus = $db->jdate($obj->date_status);
	$isLate = ($slaLimit && in_array((int) $obj->status, array(MarkAutoMecanikLead::STATUS_NEW, MarkAutoMecanikLead::STATUS_ASSIGNED), true) && $dateStatus && $dateStatus < $slaLimit);
	print '<td class="center nowraponall">'.dol_print_date($dateStatus, 'day', 'tzuser').($isLate ? ' '.img_warning($langs->trans('Late')) : '').'</td>';
	print '<td class="nowrap center">';
	if ($massactionbutton) {
		$selected = in_array((int) $obj->rowid, array_map('intval', (array) $toselect), true);
		print '<input id="cb'.((int) $obj->rowid).'" class="flat checkforselect" type="checkbox" name="toselect[]" value="'.((int) $obj->rowid).'"'.($selected ? ' checked' : '').'>';
	}
	print '</td>';
	print '</tr>';
	$i++;
}
if ($num == 0) {
	print '<tr><td colspan="9"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
}
$db->free($resql);

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
