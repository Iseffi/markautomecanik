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
 * \file       markautomecanik/admin/synclog.php
 * \ingroup    markautomecanik
 * \brief      History of synchronisation runs
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
if (!$res && file_exists('../../main.inc.php')) {
	$res = @include '../../main.inc.php';
}
if (!$res && file_exists('../../../main.inc.php')) {
	$res = @include '../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once __DIR__.'/../lib/markautomecanik.lib.php';

$langs->loadLangs(array('admin', 'markautomecanik@markautomecanik'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$limit = 50;
$page = max(0, GETPOSTINT('page'));
$offset = $limit * $page;

if ($action === 'purgelog') {
	markautomecanikRequirePost();
	markautomecanikCheckToken();
	$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'markautomecanik_synclog WHERE entity = '.((int) $conf->entity);
	$sql .= " AND date_start < '".$db->idate(dol_time_plus_duree(dol_now(), -3, 'm'))."'";
	if ($db->query($sql)) {
		setEventMessages($langs->trans('SyncLogPurged'), null, 'mesgs');
	} else {
		setEventMessages($db->lasterror(), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

llxHeader('', $langs->trans('SyncHistory'), '', '', 0, 0, '', '', '', 'mod-markautomecanik page-admin-synclog');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('MarkAutoMecanikSetup'), $linkback, 'title_setup');

$head = markautomecanikAdminPrepareHead();
print dol_get_fiche_head($head, 'synclog', $langs->trans('ModuleMarkAutoMecanikName'), -1, 'fa-bullhorn');

$sql = 'SELECT l.rowid, l.date_start, l.date_end, l.trigger_type, l.status, l.nb_received, l.nb_created, l.nb_updated, l.nb_skipped, l.nb_errors, l.message, l.fk_user';
$sql .= ' FROM '.MAIN_DB_PREFIX.'markautomecanik_synclog as l';
$sql .= ' WHERE l.entity = '.((int) $conf->entity);
$sql .= $db->order('l.date_start', 'DESC');
$sql .= $db->plimit($limit + 1, $offset);
$resql = $db->query($sql);
if (!$resql) {
	dol_print_error($db);
} else {
	$num = $db->num_rows($resql);
	print_barre_liste('', $page, $_SERVER['PHP_SELF'], '', '', '', '', $num, -1, '', 0, '', '', $limit, 1);
	print '<div class="div-table-responsive"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('DateStart').'</td><td>'.$langs->trans('Duration').'</td><td>'.$langs->trans('Trigger').'</td>';
	print '<td class="center">'.$langs->trans('Status').'</td><td class="right">'.$langs->trans('Received').'</td><td class="right">'.$langs->trans('Created').'</td>';
	print '<td class="right">'.$langs->trans('Updated').'</td><td class="right">'.$langs->trans('Skipped').'</td><td class="right">'.$langs->trans('Errors').'</td><td>'.$langs->trans('Message').'</td></tr>';
	$i = 0;
	while ($i < min($num, $limit)) {
		$obj = $db->fetch_object($resql);
		$start = $db->jdate($obj->date_start);
		$end = $db->jdate($obj->date_end);
		print '<tr class="oddeven">';
		print '<td class="nowraponall">'.dol_print_date($start, 'dayhoursec', 'tzuser').'</td>';
		print '<td class="nowraponall">'.($end ? ($end - $start).' s' : '').'</td>';
		print '<td>'.dol_escape_htmltag($obj->trigger_type).'</td>';
		print '<td class="center">'.($obj->status > 0 ? dolGetStatus($langs->trans('SyncOk'), '', '', 'status4', 3) : dolGetStatus($langs->trans('Error'), '', '', 'status8', 3)).'</td>';
		print '<td class="right">'.((int) $obj->nb_received).'</td><td class="right">'.((int) $obj->nb_created).'</td><td class="right">'.((int) $obj->nb_updated).'</td>';
		print '<td class="right">'.((int) $obj->nb_skipped).'</td><td class="right">'.((int) $obj->nb_errors).'</td>';
		print '<td class="tdoverflowmax500" title="'.dol_escape_htmltag($obj->message).'">'.dol_escape_htmltag($obj->message).'</td>';
		print '</tr>';
		$i++;
	}
	if ($num == 0) {
		print '<tr><td colspan="10"><span class="opacitymedium">'.$langs->trans('NoRecordFound').'</span></td></tr>';
	}
	print '</table></div>';
	$db->free($resql);
}

print '<br><form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="purgelog">';
print '<input type="submit" class="button button-cancel" value="'.dol_escape_htmltag($langs->trans('PurgeSyncLog')).'">';
print '</form>';

print dol_get_fiche_end();
llxFooter();
$db->close();
