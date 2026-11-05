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
 * \file       markautomecanik/admin/setup.php
 * \ingroup    markautomecanik
 * \brief      Setup page of module MarkAutoMecanik
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
require_once __DIR__.'/../class/webmecanikclient.class.php';
require_once __DIR__.'/../class/markautomecaniksync.class.php';

$langs->loadLangs(array('admin', 'markautomecanik@markautomecanik'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */

if (in_array($action, array('update', 'test', 'sync', 'resetwindow'), true)) {
	markautomecanikRequirePost();
	markautomecanikCheckToken();
}

if ($action === 'update') {
	$error = 0;
	$entity = $conf->entity;

	// Connection
	$rawUrl = trim(GETPOST('MARKAUTOMECANIK_WM_URL', 'alphanohtml'));
	$url = MarkAutoMecanikWebmecanikClient::normalizeBaseUrl($rawUrl);
	if ($rawUrl !== '' && $url === '') {
		setEventMessages($langs->trans('ErrorWebmecanikUrlMustBeHttps'), null, 'errors');
		$error++;
	} else {
		dolibarr_set_const($db, 'MARKAUTOMECANIK_WM_URL', $url, 'chaine', 0, '', $entity);
	}
	$auth = GETPOST('MARKAUTOMECANIK_WM_AUTH', 'aZ09') === 'basic' ? 'basic' : 'oauth2';
	dolibarr_set_const($db, 'MARKAUTOMECANIK_WM_AUTH', $auth, 'chaine', 0, '', $entity);
	dolibarr_set_const($db, 'MARKAUTOMECANIK_WM_CLIENT_ID', dol_substr(trim(GETPOST('MARKAUTOMECANIK_WM_CLIENT_ID', 'alphanohtml')), 0, 255), 'chaine', 0, '', $entity);
	dolibarr_set_const($db, 'MARKAUTOMECANIK_WM_BASIC_USER', dol_substr(trim(GETPOST('MARKAUTOMECANIK_WM_BASIC_USER', 'alphanohtml')), 0, 255), 'chaine', 0, '', $entity);
	// Secrets: read without filter (any character is allowed), stored encrypted, kept when left empty
	foreach (array('MARKAUTOMECANIK_WM_CLIENT_SECRET', 'MARKAUTOMECANIK_WM_BASIC_PASSWORD', 'MARKAUTOMECANIK_WEBHOOK_SECRET') as $secretName) {
		$secret = trim((string) GETPOST($secretName, 'none'));
		if ($secret !== '') {
			if (strlen($secret) > 512) {
				setEventMessages($langs->trans('ErrorSecretTooLong'), null, 'errors');
				$error++;
				continue;
			}
			dolibarr_set_const($db, $secretName, MarkAutoMecanikWebmecanikClient::encryptSecret($secret), 'chaine', 0, '', $entity);
		}
		if (GETPOST('clear_'.$secretName, 'int')) {
			dolibarr_del_const($db, $secretName, $entity);
		}
	}

	// Import
	dolibarr_set_const($db, 'MARKAUTOMECANIK_MIN_POINTS', max(0, GETPOSTINT('MARKAUTOMECANIK_MIN_POINTS')), 'chaine', 0, '', $entity);
	foreach (array('MARKAUTOMECANIK_SEGMENT', 'MARKAUTOMECANIK_FIELD_MESSAGE', 'MARKAUTOMECANIK_FIELD_INTERESTS', 'MARKAUTOMECANIK_FIELD_INDUSTRY') as $aliasName) {
		$alias = trim(GETPOST($aliasName, 'alphanohtml'));
		if ($alias !== '' && !preg_match('/^[A-Za-z0-9_-]{1,191}$/', $alias)) {
			setEventMessages($langs->trans('ErrorBadAlias', dol_escape_htmltag($alias)), null, 'errors');
			$error++;
			continue;
		}
		dolibarr_set_const($db, $aliasName, $alias, 'chaine', 0, '', $entity);
	}
	dolibarr_set_const($db, 'MARKAUTOMECANIK_MAX_WINDOW_DAYS', min(365, max(1, GETPOSTINT('MARKAUTOMECANIK_MAX_WINDOW_DAYS'))), 'chaine', 0, '', $entity);

	// Workflow and GDPR
	dolibarr_set_const($db, 'MARKAUTOMECANIK_SLA_DAYS', min(365, max(0, GETPOSTINT('MARKAUTOMECANIK_SLA_DAYS'))), 'chaine', 0, '', $entity);
	dolibarr_set_const($db, 'MARKAUTOMECANIK_REMIND_MAX_AGE_MONTHS', min(60, max(1, GETPOSTINT('MARKAUTOMECANIK_REMIND_MAX_AGE_MONTHS'))), 'chaine', 0, '', $entity);
	dolibarr_set_const($db, 'MARKAUTOMECANIK_RETENTION_MONTHS', min(240, max(0, GETPOSTINT('MARKAUTOMECANIK_RETENTION_MONTHS'))), 'chaine', 0, '', $entity);
	$alertEmail = trim(GETPOST('MARKAUTOMECANIK_ALERT_EMAIL', 'alphanohtml'));
	if ($alertEmail !== '' && !isValidEmail($alertEmail)) {
		setEventMessages($langs->trans('ErrorBadEMail', dol_escape_htmltag($alertEmail)), null, 'errors');
		$error++;
	} else {
		dolibarr_set_const($db, 'MARKAUTOMECANIK_ALERT_EMAIL', $alertEmail, 'chaine', 0, '', $entity);
	}

	// Closed loop and webhook
	dolibarr_set_const($db, 'MARKAUTOMECANIK_PUSH_TAGS', GETPOST('MARKAUTOMECANIK_PUSH_TAGS', 'int') ? 1 : 0, 'chaine', 0, '', $entity);
	$prefix = trim(GETPOST('MARKAUTOMECANIK_TAG_PREFIX', 'alphanohtml'));
	if (!preg_match('/^[A-Za-z0-9_.-]{0,50}$/', $prefix)) {
		setEventMessages($langs->trans('ErrorBadTagPrefix'), null, 'errors');
		$error++;
	} else {
		dolibarr_set_const($db, 'MARKAUTOMECANIK_TAG_PREFIX', $prefix, 'chaine', 0, '', $entity);
	}
	$webhook = GETPOST('MARKAUTOMECANIK_WEBHOOK_ENABLED', 'int') ? 1 : 0;
	if ($webhook && getDolGlobalString('MARKAUTOMECANIK_WEBHOOK_SECRET') === '' && trim((string) GETPOST('MARKAUTOMECANIK_WEBHOOK_SECRET', 'none')) === '') {
		setEventMessages($langs->trans('ErrorWebhookSecretRequired'), null, 'errors');
		$webhook = 0;
		$error++;
	}
	dolibarr_set_const($db, 'MARKAUTOMECANIK_WEBHOOK_ENABLED', $webhook, 'chaine', 0, '', $entity);
	$webhookUser = GETPOSTINT('MARKAUTOMECANIK_WEBHOOK_USER');
	dolibarr_set_const($db, 'MARKAUTOMECANIK_WEBHOOK_USER', ($webhookUser > 0 ? $webhookUser : ''), 'chaine', 0, '', $entity);

	if (!$error) {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'test') {
	$client = new MarkAutoMecanikWebmecanikClient($db);
	$total = $client->testConnection();
	if ($total === false) {
		setEventMessages($langs->trans('ConnectionFailed').' : '.$client->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('ConnectionOk', $total), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'sync') {
	$sync = new MarkAutoMecanikSync($db);
	$days = min(365, max(0, GETPOSTINT('sync_days')));
	if ($sync->synchronize('manual', $user, $days * 86400) < 0) {
		setEventMessages($sync->error, null, 'errors');
	}
	if ($sync->output) {
		setEventMessages($sync->output, null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action === 'resetwindow') {
	dolibarr_del_const($db, 'MARKAUTOMECANIK_LAST_SYNC', $conf->entity);
	dolibarr_set_const($db, 'MARKAUTOMECANIK_SYNC_LOCK', '0', 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SyncWindowReset'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * View
 */

$form = new Form($db);
$page_name = 'MarkAutoMecanikSetup';
llxHeader('', $langs->trans($page_name), '', '', 0, 0, '', '', '', 'mod-markautomecanik page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

$head = markautomecanikAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans('ModuleMarkAutoMecanikName'), -1, 'fa-bullhorn');

print '<span class="opacitymedium">'.$langs->trans('MarkAutoMecanikSetupPage').'</span><br><br>';

if (!function_exists('curl_init')) {
	print info_admin($langs->trans('ErrorCurlMissing'), 0, 0, 'error');
}

/**
 * Print a setup row
 *
 * @param string $label Label
 * @param string $input Input HTML
 * @param string $help  Help text (already translated, escaped)
 * @return void
 */
function markautomecanikSetupRow($label, $input, $help = '')
{
	print '<tr class="oddeven"><td class="titlefieldcreate">'.$label.'</td><td>'.$input;
	if ($help !== '') {
		print '<br><span class="opacitymedium small">'.$help.'</span>';
	}
	print '</td></tr>';
}

/**
 * Input for a secret: never pre-filled, shows whether a value is stored
 *
 * @param string $name Constant name
 * @return string
 */
function markautomecanikSecretInput($name)
{
	global $langs;
	$isSet = getDolGlobalString($name) !== '';
	$html = '<input type="password" name="'.$name.'" class="minwidth300" value="" autocomplete="new-password" placeholder="'.dol_escape_htmltag($isSet ? $langs->trans('SecretStoredLeaveEmpty') : '').'">';
	if ($isSet) {
		$html .= ' <label class="nowraponall"><input type="checkbox" name="clear_'.$name.'" value="1"> '.$langs->trans('ClearValue').'</label>';
	}
	return $html;
}

$auth = getDolGlobalString('MARKAUTOMECANIK_WM_AUTH', 'oauth2');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';

// Connection
print '<tr class="liste_titre"><td colspan="2">'.img_picto('', 'globe', 'class="pictofixedwidth"').$langs->trans('WebmecanikConnection').'</td></tr>';
markautomecanikSetupRow($langs->trans('WebmecanikUrl'), '<input type="url" name="MARKAUTOMECANIK_WM_URL" class="minwidth400" value="'.dol_escape_htmltag(getDolGlobalString('MARKAUTOMECANIK_WM_URL')).'" placeholder="https://mycompany.webmecanik.com" pattern="https://.*">', $langs->trans('WebmecanikUrlHelp'));
markautomecanikSetupRow($langs->trans('AuthenticationMode'), $form->selectarray('MARKAUTOMECANIK_WM_AUTH', array('oauth2' => $langs->trans('AuthOAuth2'), 'basic' => $langs->trans('AuthBasic')), $auth, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200'), $langs->trans('AuthenticationModeHelp'));
markautomecanikSetupRow($langs->trans('OAuth2ClientId'), '<input type="text" name="MARKAUTOMECANIK_WM_CLIENT_ID" class="minwidth300" autocomplete="off" value="'.dol_escape_htmltag(getDolGlobalString('MARKAUTOMECANIK_WM_CLIENT_ID')).'">');
markautomecanikSetupRow($langs->trans('OAuth2ClientSecret'), markautomecanikSecretInput('MARKAUTOMECANIK_WM_CLIENT_SECRET'));
markautomecanikSetupRow($langs->trans('BasicUser'), '<input type="text" name="MARKAUTOMECANIK_WM_BASIC_USER" class="minwidth300" autocomplete="off" value="'.dol_escape_htmltag(getDolGlobalString('MARKAUTOMECANIK_WM_BASIC_USER')).'">');
markautomecanikSetupRow($langs->trans('BasicPassword'), markautomecanikSecretInput('MARKAUTOMECANIK_WM_BASIC_PASSWORD'));

// Import
print '<tr class="liste_titre"><td colspan="2">'.img_picto('', 'download', 'class="pictofixedwidth"').$langs->trans('ImportRules').'</td></tr>';
markautomecanikSetupRow($langs->trans('MinPoints'), '<input type="number" min="0" name="MARKAUTOMECANIK_MIN_POINTS" class="width75" value="'.getDolGlobalInt('MARKAUTOMECANIK_MIN_POINTS', 40).'">', $langs->trans('MinPointsHelp'));
markautomecanikSetupRow($langs->trans('SegmentAlias'), '<input type="text" name="MARKAUTOMECANIK_SEGMENT" class="minwidth200" pattern="[A-Za-z0-9_-]*" value="'.dol_escape_htmltag(getDolGlobalString('MARKAUTOMECANIK_SEGMENT')).'">', $langs->trans('SegmentAliasHelp'));
markautomecanikSetupRow($langs->trans('FieldMessage'), '<input type="text" name="MARKAUTOMECANIK_FIELD_MESSAGE" class="minwidth200" pattern="[A-Za-z0-9_-]*" value="'.dol_escape_htmltag(getDolGlobalString('MARKAUTOMECANIK_FIELD_MESSAGE')).'">', $langs->trans('FieldAliasHelp'));
markautomecanikSetupRow($langs->trans('FieldInterests'), '<input type="text" name="MARKAUTOMECANIK_FIELD_INTERESTS" class="minwidth200" pattern="[A-Za-z0-9_-]*" value="'.dol_escape_htmltag(getDolGlobalString('MARKAUTOMECANIK_FIELD_INTERESTS')).'">');
markautomecanikSetupRow($langs->trans('FieldIndustry'), '<input type="text" name="MARKAUTOMECANIK_FIELD_INDUSTRY" class="minwidth200" pattern="[A-Za-z0-9_-]*" value="'.dol_escape_htmltag(getDolGlobalString('MARKAUTOMECANIK_FIELD_INDUSTRY')).'">');
markautomecanikSetupRow($langs->trans('MaxWindowDays'), '<input type="number" min="1" max="365" name="MARKAUTOMECANIK_MAX_WINDOW_DAYS" class="width75" value="'.getDolGlobalInt('MARKAUTOMECANIK_MAX_WINDOW_DAYS', 30).'">', $langs->trans('MaxWindowDaysHelp'));

// Workflow
print '<tr class="liste_titre"><td colspan="2">'.img_picto('', 'bell', 'class="pictofixedwidth"').$langs->trans('RemindersAndGdpr').'</td></tr>';
markautomecanikSetupRow($langs->trans('SlaDays'), '<input type="number" min="0" max="365" name="MARKAUTOMECANIK_SLA_DAYS" class="width75" value="'.getDolGlobalInt('MARKAUTOMECANIK_SLA_DAYS').'">', $langs->trans('SlaDaysHelp'));
markautomecanikSetupRow($langs->trans('AlertEmail'), '<input type="email" name="MARKAUTOMECANIK_ALERT_EMAIL" class="minwidth300" value="'.dol_escape_htmltag(getDolGlobalString('MARKAUTOMECANIK_ALERT_EMAIL')).'">', $langs->trans('AlertEmailHelp'));
markautomecanikSetupRow($langs->trans('RemindMaxAgeMonths'), '<input type="number" min="1" max="60" name="MARKAUTOMECANIK_REMIND_MAX_AGE_MONTHS" class="width75" value="'.getDolGlobalInt('MARKAUTOMECANIK_REMIND_MAX_AGE_MONTHS', 3).'">', $langs->trans('RemindMaxAgeMonthsHelp'));
markautomecanikSetupRow($langs->trans('RetentionMonths'), '<input type="number" min="0" max="240" name="MARKAUTOMECANIK_RETENTION_MONTHS" class="width75" value="'.getDolGlobalInt('MARKAUTOMECANIK_RETENTION_MONTHS').'">', $langs->trans('RetentionMonthsHelp'));

// Closed loop
print '<tr class="liste_titre"><td colspan="2">'.img_picto('', 'refresh', 'class="pictofixedwidth"').$langs->trans('ClosedLoop').'</td></tr>';
markautomecanikSetupRow($langs->trans('PushTags'), '<input type="checkbox" name="MARKAUTOMECANIK_PUSH_TAGS" value="1"'.(getDolGlobalInt('MARKAUTOMECANIK_PUSH_TAGS') ? ' checked' : '').'>', $langs->trans('PushTagsHelp'));
markautomecanikSetupRow($langs->trans('TagPrefix'), '<input type="text" name="MARKAUTOMECANIK_TAG_PREFIX" class="minwidth150" pattern="[A-Za-z0-9_.-]*" value="'.dol_escape_htmltag(getDolGlobalString('MARKAUTOMECANIK_TAG_PREFIX', 'dolibarr-')).'">');

// Webhook
$webhookUrl = dol_buildpath('/markautomecanik/public/webhook.php', 2).'?entity='.((int) $conf->entity);
print '<tr class="liste_titre"><td colspan="2">'.img_picto('', 'technic', 'class="pictofixedwidth"').$langs->trans('Webhook').'</td></tr>';
markautomecanikSetupRow($langs->trans('WebhookEnabled'), '<input type="checkbox" name="MARKAUTOMECANIK_WEBHOOK_ENABLED" value="1"'.(getDolGlobalInt('MARKAUTOMECANIK_WEBHOOK_ENABLED') ? ' checked' : '').'>', $langs->trans('WebhookEnabledHelp'));
markautomecanikSetupRow($langs->trans('WebhookUrl'), '<input type="text" readonly class="minwidth500" value="'.dol_escape_htmltag($webhookUrl).'">', $langs->trans('WebhookUrlHelp'));
markautomecanikSetupRow($langs->trans('WebhookSecret'), markautomecanikSecretInput('MARKAUTOMECANIK_WEBHOOK_SECRET'), $langs->trans('WebhookSecretHelp'));
markautomecanikSetupRow($langs->trans('WebhookUser'), $form->select_dolusers(getDolGlobalInt('MARKAUTOMECANIK_WEBHOOK_USER'), 'MARKAUTOMECANIK_WEBHOOK_USER', 1, null, 0, '', '', '0', 0, 0, '', 0, '', 'minwidth200', 1), $langs->trans('WebhookUserHelp'));

print '</table></div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

// Tools
print '<br>';
print load_fiche_titre($langs->trans('SyncTools'), '', '');
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
$lastSync = getDolGlobalInt('MARKAUTOMECANIK_LAST_SYNC');
markautomecanikSetupRow($langs->trans('LastSuccessfulSync'), ($lastSync ? dol_print_date($lastSync, 'dayhour', 'tzuser') : $langs->trans('Never')));

$html = '<form class="inline-block" method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="test">';
$html .= '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('TestConnection')).'"></form>';
markautomecanikSetupRow($langs->trans('TestConnection'), $html);

$html = '<form class="inline-block" method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="sync">';
$html .= $langs->trans('SyncLastDays').' <input type="number" min="0" max="365" name="sync_days" class="width50" value="0"> ';
$html .= '<input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('SynchronizeNow')).'"></form>';
markautomecanikSetupRow($langs->trans('SynchronizeNow'), $html, $langs->trans('SyncLastDaysHelp'));

$html = '<form class="inline-block" method="POST" action="'.$_SERVER['PHP_SELF'].'"><input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="resetwindow">';
$html .= '<input type="submit" class="button button-cancel smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('ResetSyncWindow')).'"></form>';
markautomecanikSetupRow($langs->trans('ResetSyncWindow'), $html, $langs->trans('ResetSyncWindowHelp'));

$html = '<a href="'.DOL_URL_ROOT.'/cron/list.php?search_label='.urlencode('Webmecanik').'">'.$langs->trans('ScheduledJobs').'</a>';
markautomecanikSetupRow($langs->trans('ScheduledJobs'), $html, $langs->trans('ScheduledJobsHelp'));
print '</table></div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
