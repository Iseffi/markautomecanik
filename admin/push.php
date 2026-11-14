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
 * \file       markautomecanik/admin/push.php
 * \ingroup    markautomecanik
 * \brief      Setup and tools of the synchronisation Dolibarr -> Webmecanik (contacts and third parties)
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
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formother.class.php';
require_once __DIR__.'/../lib/markautomecanik.lib.php';
require_once __DIR__.'/../class/markautomecanikpush.class.php';

$langs->loadLangs(array('admin', 'categories', 'markautomecanik@markautomecanik'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$push = new MarkAutoMecanikPush($db);

/*
 * Actions
 */

if (in_array($action, array('update', 'enqueue', 'process', 'retry'), true)) {
	markautomecanikRequirePost();
	markautomecanikCheckToken();
}

/**
 * Read a list of category ids posted by a multiselect
 *
 * @param string $name Field name
 * @return string Comma separated ids
 */
function markautomecanikPostedIds($name)
{
	$values = GETPOST($name, 'array');
	return implode(',', MarkAutoMecanikPush::parseIds(implode(',', is_array($values) ? $values : array())));
}

if ($action === 'update') {
	$entity = $conf->entity;
	dolibarr_set_const($db, 'MARKAUTOMECANIK_PUSH_ENABLED', GETPOST('MARKAUTOMECANIK_PUSH_ENABLED', 'int') ? 1 : 0, 'chaine', 0, '', $entity);
	if (isModEnabled('category')) {
		dolibarr_set_const($db, 'MARKAUTOMECANIK_PUSH_CATEGORIES', markautomecanikPostedIds('MARKAUTOMECANIK_PUSH_CATEGORIES'), 'chaine', 0, '', $entity);
		dolibarr_set_const($db, 'MARKAUTOMECANIK_PUSH_EXCLUDED_CATEGORIES', markautomecanikPostedIds('MARKAUTOMECANIK_PUSH_EXCLUDED_CATEGORIES'), 'chaine', 0, '', $entity);
	}
	dolibarr_set_const($db, 'MARKAUTOMECANIK_PUSH_DELETE', GETPOST('MARKAUTOMECANIK_PUSH_DELETE', 'int') ? 1 : 0, 'chaine', 0, '', $entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'enqueue') {
	$since = 0;
	$month = GETPOSTINT('since_month');
	$year = GETPOSTINT('since_year');
	if ($month > 0 && $year > 0) {
		$since = dol_mktime(0, 0, 0, $month, 1, $year);
	}
	$nb = $push->enqueueUnsynchronized($since);
	if ($nb < 0) {
		setEventMessages($push->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('PushContactsQueued', $nb), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'process') {
	if ($push->processQueue(MarkAutoMecanikPush::BATCH_SIZE, $user, 'manual') < 0) {
		setEventMessages($push->error, null, 'errors');
	}
	if ($push->output) {
		setEventMessages($push->output, null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'retry') {
	$nb = $push->retryFailed();
	if ($nb < 0) {
		setEventMessages($push->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('PushItemsRetried', $nb), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$form = new Form($db);
$formother = new FormOther($db);
$page_name = 'MarkAutoMecanikSetup';
llxHeader('', $langs->trans($page_name), '', '', 0, 0, '', '', '', 'mod-markautomecanik page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = markautomecanikAdminPrepareHead();
print dol_get_fiche_head($head, 'push', $langs->trans('ModuleMarkAutoMecanikName'), -1, 'fa-bullhorn');

print '<span class="opacitymedium">'.$langs->trans('PushSetupPage').'</span><br><br>';

if (getDolGlobalInt('MARKAUTOMECANIK_DOLIMAUTIC_IMPORTED')) {
	print info_admin($langs->trans('PushDoliMauticImported'), 0, 0, 'info');
}

/**
 * Print a setup row
 *
 * @param string $label Label
 * @param string $input Input HTML
 * @param string $help  Help text (already translated)
 * @return void
 */
function markautomecanikPushRow($label, $input, $help = '')
{
	print '<tr class="oddeven"><td class="titlefieldcreate">'.$label.'</td><td>'.$input;
	if ($help !== '') {
		print '<br><span class="opacitymedium small">'.$help.'</span>';
	}
	print '</td></tr>';
}

// Settings
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.img_picto('', 'contact', 'class="pictofixedwidth"').$langs->trans('PushSettings').'</td></tr>';
markautomecanikPushRow($langs->trans('PushEnabled'), '<input type="checkbox" name="MARKAUTOMECANIK_PUSH_ENABLED" value="1"'.(getDolGlobalInt('MARKAUTOMECANIK_PUSH_ENABLED') ? ' checked' : '').'>', $langs->trans('PushEnabledHelp'));
if (isModEnabled('category')) {
	require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
	$categories = $form->select_all_categories(Categorie::TYPE_CONTACT, '', 'parent', 64, 0, 1);
	if (!is_array($categories)) {
		$categories = array();
	}
	$selected = MarkAutoMecanikPush::parseIds(getDolGlobalString('MARKAUTOMECANIK_PUSH_CATEGORIES'));
	markautomecanikPushRow($langs->trans('PushCategories'), $form->multiselectarray('MARKAUTOMECANIK_PUSH_CATEGORIES', $categories, $selected, 0, 0, 'minwidth300 quatrevingtpercent', 0, 0), $langs->trans('PushCategoriesHelp'));
	$selected = MarkAutoMecanikPush::parseIds(getDolGlobalString('MARKAUTOMECANIK_PUSH_EXCLUDED_CATEGORIES'));
	markautomecanikPushRow($langs->trans('PushExcludedCategories'), $form->multiselectarray('MARKAUTOMECANIK_PUSH_EXCLUDED_CATEGORIES', $categories, $selected, 0, 0, 'minwidth300 quatrevingtpercent', 0, 0), $langs->trans('PushExcludedCategoriesHelp'));
} else {
	markautomecanikPushRow($langs->trans('PushCategories'), $langs->trans('PushAllContactsNoCategoryModule'));
}
markautomecanikPushRow($langs->trans('PushDelete'), '<input type="checkbox" name="MARKAUTOMECANIK_PUSH_DELETE" value="1"'.(getDolGlobalInt('MARKAUTOMECANIK_PUSH_DELETE') ? ' checked' : '').'>', $langs->trans('PushDeleteHelp'));
print '</table></div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

// Queue and tools
print '<br>';
print load_fiche_titre($langs->trans('PushQueue'), '', '');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
$counts = $push->getQueueCounts();
markautomecanikPushRow($langs->trans('PushQueuePending'), (string) $counts[MarkAutoMecanikPush::STATUS_PENDING]);
markautomecanikPushRow($langs->trans('PushQueueFailed'), (string) $counts[MarkAutoMecanikPush::STATUS_FAILED], $langs->trans('PushQueueFailedHelp', MarkAutoMecanikPush::MAX_ATTEMPTS));
markautomecanikPushRow($langs->trans('PushQueueDone'), (string) ($counts[MarkAutoMecanikPush::STATUS_DONE] + $counts[MarkAutoMecanikPush::STATUS_SKIPPED]), $langs->trans('PushQueueDoneHelp', MarkAutoMecanikPush::KEEP_DONE_DAYS));

$html = '<form class="inline-block" method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="enqueue">';
$html .= $langs->trans('PushSince').' '.$formother->select_month('', 'since_month', 1).' '.$formother->selectyear('', 'since_year', 1, 30, 0).' ';
$html .= '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('PushEnqueueUnsynchronized')).'"></form>';
markautomecanikPushRow($langs->trans('PushEnqueueUnsynchronized'), $html, $langs->trans('PushEnqueueUnsynchronizedHelp'));

$html = '<form class="inline-block" method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="process">';
$html .= '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('PushProcessNow')).'"></form>';
markautomecanikPushRow($langs->trans('PushProcessNow'), $html, $langs->trans('PushProcessNowHelp', MarkAutoMecanikPush::BATCH_SIZE));

if ($counts[MarkAutoMecanikPush::STATUS_FAILED] > 0) {
	$html = '<form class="inline-block" method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="retry">';
	$html .= '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('PushRetryFailed')).'"></form>';
	markautomecanikPushRow($langs->trans('PushRetryFailed'), $html);
}
print '</table></div>';

// Last errors (object ids and technical messages only, no personal data)
if ($counts[MarkAutoMecanikPush::STATUS_FAILED] > 0) {
	print '<br>';
	print load_fiche_titre($langs->trans('PushLastErrors'), '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans('Type').'</td><td>'.$langs->trans('Ref').'</td><td class="center">'.$langs->trans('PushAttempts').'</td><td>'.$langs->trans('Error').'</td></tr>';
	$sql = 'SELECT element_type, fk_object, attempts, last_error FROM '.MAIN_DB_PREFIX.'markautomecanik_pushqueue';
	$sql .= ' WHERE entity = '.((int) $conf->entity).' AND status = '.MarkAutoMecanikPush::STATUS_FAILED;
	$sql .= ' ORDER BY rowid DESC'.$db->plimit(20);
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$isSoc = ($obj->element_type === MarkAutoMecanikPush::TYPE_THIRDPARTY);
			$url = ($isSoc ? DOL_URL_ROOT.'/societe/card.php?socid=' : DOL_URL_ROOT.'/contact/card.php?id=').((int) $obj->fk_object);
			print '<tr class="oddeven"><td>'.dol_escape_htmltag($langs->trans($isSoc ? 'ThirdParty' : 'Contact')).'</td>';
			print '<td><a href="'.dol_escape_htmltag($url).'">#'.((int) $obj->fk_object).'</a></td>';
			print '<td class="center">'.((int) $obj->attempts).'</td>';
			print '<td>'.dol_escape_htmltag($obj->last_error).'</td></tr>';
		}
		$db->free($resql);
	}
	print '</table></div>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
