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
 * \file       markautomecanik/lead_card.php
 * \ingroup    markautomecanik
 * \brief      Card of a marketing lead
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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once __DIR__.'/class/markautomecaniklead.class.php';
require_once __DIR__.'/class/markautomecaniksync.class.php';
require_once __DIR__.'/lib/markautomecanik.lib.php';

$langs->loadLangs(array('companies', 'other', 'markautomecanik@markautomecanik'));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'alpha');

// Security: module enabled and read permission
if (!isModEnabled('markautomecanik')) {
	accessforbidden('Module not enabled');
}
if (!$user->hasRight('markautomecanik', 'lead', 'read') || !empty($user->socid)) {
	accessforbidden();
}
$permissiontoadd = $user->hasRight('markautomecanik', 'lead', 'write');
$permissiontodelete = $user->hasRight('markautomecanik', 'lead', 'delete');

$object = new MarkAutoMecanikLead($db);
$form = new Form($db);

if ($id > 0) {
	if ($object->fetch($id) <= 0) {
		$langs->load('errors');
		accessforbidden($langs->trans('ErrorRecordNotFound'), 0);
	}
	// Ownership check: without "readall", only leads assigned to or created by the user
	if (!$object->canBeReadBy($user)) {
		accessforbidden();
	}
} elseif ($action !== 'create' && $action !== 'add') {
	header('Location: '.dol_buildpath('/markautomecanik/lead_list.php', 1));
	exit;
}

$hookmanager->initHooks(array('markautomecanikleadcard', 'globalcard'));

// Every action modifying data requires a valid CSRF token and the write permission
$writeActions = array('add', 'save', 'qualify', 'linksoc', 'linkcontact', 'unlinksoc', 'confirm_linksoc', 'confirm_linkproject', 'confirm_notify', 'pushtag');
$deleteActions = array('confirm_delete', 'confirm_anonymize');
if (in_array($action, $writeActions, true)) {
	if (!$permissiontoadd) {
		accessforbidden();
	}
	markautomecanikCheckToken();
}
if (in_array($action, $deleteActions, true)) {
	if (!$permissiontodelete) {
		accessforbidden();
	}
	markautomecanikCheckToken();
}
if (in_array($action, array('add', 'save', 'qualify', 'linksoc', 'linkcontact', 'unlinksoc'), true)) {
	markautomecanikRequirePost();
}
if (in_array($action, array('create', 'edit'), true) && !$permissiontoadd) {
	accessforbidden();
}
// Anonymised leads are read-only
if ($object->id > 0 && !empty($object->date_anonymized) && !in_array($action, array('', 'view', 'delete', 'confirm_delete'), true)) {
	accessforbidden($langs->trans('LeadIsAnonymized'));
}

/**
 * Read the identity fields of the lead from the request
 *
 * @param MarkAutoMecanikLead $lead Lead
 * @return void
 */
function markautomecanikReadPostedIdentity($lead)
{
	$lead->firstname = GETPOST('firstname', 'alphanohtml');
	$lead->lastname = GETPOST('lastname', 'alphanohtml');
	$lead->email = trim(GETPOST('email', 'alphanohtml'));
	$lead->phone = GETPOST('phone', 'alphanohtml');
	$lead->company = GETPOST('company', 'alphanohtml');
	$lead->city = GETPOST('city', 'alphanohtml');
	$lead->country = GETPOST('country', 'alphanohtml');
	$lead->website = GETPOST('website', 'alphanohtml');
	$lead->industry = GETPOST('industry', 'alphanohtml');
	$lead->interests = GETPOST('interests', 'alphanohtml');
	$lead->message = GETPOST('message', 'restricthtml');
	$lead->utm_source = GETPOST('utm_source', 'alphanohtml');
	$lead->utm_medium = GETPOST('utm_medium', 'alphanohtml');
	$lead->utm_campaign = GETPOST('utm_campaign', 'alphanohtml');
}

/**
 * Validate identity fields
 *
 * @param MarkAutoMecanikLead $lead Lead
 * @return string[] Errors
 */
function markautomecanikValidateIdentity($lead)
{
	global $langs;
	$errors = array();
	if (trim($lead->lastname) === '' && trim($lead->company) === '') {
		$errors[] = $langs->trans('ErrorLastnameOrCompanyRequired');
	}
	if ($lead->email !== '' && !isValidEmail($lead->email)) {
		$errors[] = $langs->trans('ErrorBadEMail', dol_escape_htmltag($lead->email));
	}
	if ($lead->website !== '' && !preg_match('#^https?://#i', $lead->website)) {
		$errors[] = $langs->trans('ErrorBadUrl', dol_escape_htmltag($lead->website));
	}
	return $errors;
}

/*
 * Actions
 */

$parameters = array('id' => $id);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	$cardUrl = dol_buildpath('/markautomecanik/lead_card.php', 1).'?id=';

	if ($cancel) {
		$action = ($object->id > 0 ? '' : 'create');
		if (!$object->id) {
			header('Location: '.dol_buildpath('/markautomecanik/lead_list.php', 1));
			exit;
		}
	}

	// Manual creation
	if ($action === 'add') {
		markautomecanikReadPostedIdentity($object);
		$object->note_private = GETPOST('note_private', 'restricthtml');
		$object->origin = MarkAutoMecanikLead::ORIGIN_MANUAL;
		$object->status = MarkAutoMecanikLead::STATUS_NEW;
		$errors = markautomecanikValidateIdentity($object);
		if (empty($errors)) {
			$newid = $object->create($user);
			if ($newid > 0) {
				header('Location: '.$cardUrl.((int) $newid));
				exit;
			}
			$errors = array_merge(array($object->error), $object->errors);
		}
		setEventMessages('', array_filter($errors), 'errors');
		$action = 'create';
	}

	// Edition of the identity
	if ($action === 'save') {
		markautomecanikReadPostedIdentity($object);
		$errors = markautomecanikValidateIdentity($object);
		if (empty($errors) && $object->update($user) > 0) {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
			header('Location: '.$cardUrl.((int) $object->id));
			exit;
		}
		setEventMessages($object->error, array_merge($errors, $object->errors), 'errors');
		$action = 'edit';
	}

	// Qualification: status, sales representative, private note
	if ($action === 'qualify') {
		$error = 0;
		$oldStatus = (int) $object->status;
		$oldAssign = (int) $object->fk_user_assign;
		$db->begin();

		$newAssign = GETPOSTINT('fk_user_assign');
		if ($newAssign < 0) {
			$newAssign = 0;
		}
		if ($newAssign != $oldAssign && $object->assign($user, $newAssign) < 0) {
			$error++;
		}
		// The status posted is applied only if the user changed it (or changed the lost reason):
		// otherwise the automatic status set by the assignment is kept
		$newStatus = GETPOSTINT('status');
		$statusChanged = ($newStatus > 0 && ($newStatus != $oldStatus || ($newStatus == MarkAutoMecanikLead::STATUS_LOST && GETPOST('lost_reason', 'alphanohtml') !== (string) $object->lost_reason)));
		if (!$error && $statusChanged && $object->setStatus($user, $newStatus, GETPOST('lost_reason', 'alphanohtml')) < 0) {
			$error++;
		}
		if (!$error && GETPOSTISSET('note_private')) {
			$object->note_private = GETPOST('note_private', 'restricthtml');
			if ($object->update($user) < 0) {
				$error++;
			}
		}

		if ($error) {
			$db->rollback();
			setEventMessages($langs->trans($object->error), $object->errors, 'errors');
		} else {
			$db->commit();
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');

			// Agenda event when the lead is assigned
			if ((int) $object->fk_user_assign > 0 && (int) $object->fk_user_assign != $oldAssign && isModEnabled('agenda')) {
				require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
				$rep = new User($db);
				$rep->fetch($object->fk_user_assign);
				$event = new ActionComm($db);
				$event->type_code = 'AC_OTH_AUTO';
				$event->code = 'AC_MAMLEAD_ASSIGN';
				$event->label = $langs->transnoentities('EventLeadAssigned', $object->id, $rep->getFullName($langs));
				$event->note_private = $langs->transnoentities('EventLeadAssigned', $object->id, $rep->getFullName($langs));
				$event->datep = dol_now();
				$event->datef = dol_now();
				$event->percentage = -1;
				$event->socid = (int) $object->fk_soc;
				$event->contact_id = (int) $object->fk_socpeople;
				$event->authorid = $user->id;
				$event->userownerid = (int) $object->fk_user_assign;
				$event->create($user);
			}
			// Email to the sales representative
			if ((int) $object->fk_user_assign > 0 && (int) $object->fk_user_assign != $oldAssign && GETPOST('notify_assign', 'int')) {
				$action = 'confirm_notify';
			}
			// Closed loop: status pushed to Webmecanik as a tag
			if ((int) $object->status != $oldStatus) {
				$sync = new MarkAutoMecanikSync($db);
				if ($sync->pushStatusTag($object) < 0) {
					setEventMessages($langs->trans('WarningTagNotPushed').' '.$sync->error, null, 'warnings');
				}
			}
		}
		if ($action !== 'confirm_notify') {
			header('Location: '.$cardUrl.((int) $object->id));
			exit;
		}
	}

	// Email notification to the assigned sales representative
	if ($action === 'confirm_notify' && ($confirm === 'yes' || GETPOST('notify_assign', 'int'))) {
		require_once DOL_DOCUMENT_ROOT.'/core/class/CMailFile.class.php';
		$rep = new User($db);
		if ($object->fk_user_assign > 0 && $rep->fetch($object->fk_user_assign) > 0 && isValidEmail($rep->email)) {
			$from = getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
			if (!empty($user->email) && isValidEmail($user->email)) {
				$from = $user->getFullName($langs).' <'.$user->email.'>';
			}
			$outlangs = new Translate('', $conf);
			$outlangs->setDefaultLang(empty($rep->lang) ? $langs->defaultlang : $rep->lang);
			$outlangs->loadLangs(array('main', 'markautomecanik@markautomecanik'));
			$subject = $outlangs->transnoentities('NotifySubject', $object->id);
			$body = $outlangs->transnoentities('NotifyBody', $user->getFullName($outlangs))."\n\n";
			$body .= $outlangs->transnoentities('Company').' : '.$object->company."\n";
			$body .= $outlangs->transnoentities('Link').' : '.dol_buildpath('/markautomecanik/lead_card.php', 2).'?id='.((int) $object->id)."\n";
			$mail = new CMailFile($subject, $rep->email, $from, $body, array(), array(), array(), '', '', 0, 0, '', '', 'mamlead'.$object->id);
			if ($mail->sendfile()) {
				setEventMessages($langs->trans('MailSuccessfulySent', '', dol_escape_htmltag($rep->email)), null, 'mesgs');
			} else {
				setEventMessages($langs->trans('ErrorFailedToSendMail', '', dol_escape_htmltag($rep->email)).' '.$mail->error, null, 'errors');
			}
		} else {
			setEventMessages($langs->trans('ErrorNoSalesRepEmail'), null, 'errors');
		}
		header('Location: '.$cardUrl.((int) $object->id));
		exit;
	}

	// Link to an existing thirdparty
	if ($action === 'linksoc' || ($action === 'confirm_linksoc' && $confirm === 'yes')) {
		if ($object->linkThirdparty($user, GETPOSTINT('socid')) > 0) {
			setEventMessages($langs->trans('LeadLinkedToThirdparty'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($object->error), $object->errors, 'errors');
		}
		header('Location: '.$cardUrl.((int) $object->id));
		exit;
	}
	if ($action === 'unlinksoc') {
		$object->fk_soc = null;
		$object->fk_socpeople = null;
		$object->update($user);
		header('Location: '.$cardUrl.((int) $object->id));
		exit;
	}

	// Link to a contact
	if ($action === 'linkcontact') {
		if ($object->linkContact($user, GETPOSTINT('contactid')) > 0) {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans($object->error), $object->errors, 'errors');
		}
		header('Location: '.$cardUrl.((int) $object->id));
		exit;
	}

	// Link to a project / opportunity created from the lead
	if ($action === 'confirm_linkproject' && $confirm === 'yes' && isModEnabled('project')) {
		require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
		$project = new Project($db);
		$projectid = GETPOSTINT('projectid');
		if ($projectid > 0 && $project->fetch($projectid) > 0 && in_array((int) $project->entity, array_map('intval', explode(',', getEntity('project'))), true)) {
			$object->fk_project = $projectid;
			if (empty($object->fk_soc) && $project->socid > 0) {
				$object->fk_soc = (int) $project->socid;
			}
			if ($object->update($user) > 0 && (int) $object->status !== MarkAutoMecanikLead::STATUS_CONVERTED) {
				$object->setStatus($user, MarkAutoMecanikLead::STATUS_CONVERTED);
				$sync = new MarkAutoMecanikSync($db);
				$sync->pushStatusTag($object);
			}
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		} else {
			setEventMessages($langs->trans('ErrorRecordNotFound'), null, 'errors');
		}
		header('Location: '.$cardUrl.((int) $object->id));
		exit;
	}

	// Manual push of the status tag
	if ($action === 'pushtag') {
		$sync = new MarkAutoMecanikSync($db);
		$r = $sync->pushStatusTag($object);
		if ($r > 0) {
			setEventMessages($langs->trans('TagPushed'), null, 'mesgs');
		} elseif ($r < 0) {
			setEventMessages($langs->trans('WarningTagNotPushed').' '.$sync->error, null, 'errors');
		}
		header('Location: '.$cardUrl.((int) $object->id));
		exit;
	}

	// GDPR anonymisation
	if ($action === 'confirm_anonymize' && $confirm === 'yes') {
		if ($object->anonymize($user) > 0) {
			setEventMessages($langs->trans('LeadAnonymized'), null, 'mesgs');
		} else {
			setEventMessages($object->error, null, 'errors');
		}
		header('Location: '.$cardUrl.((int) $object->id));
		exit;
	}

	// Deletion
	if ($action === 'confirm_delete' && $confirm === 'yes') {
		if ($object->delete($user) > 0) {
			setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
			header('Location: '.dol_buildpath('/markautomecanik/lead_list.php', 1).'?restore_lastsearch_values=1');
			exit;
		}
		setEventMessages($object->error, $object->errors, 'errors');
	}
}

/*
 * View
 */

$title = $langs->trans('MarketingLead');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-markautomecanik page-card');

/**
 * Print a row of the card
 *
 * @param string $label Label (already translated)
 * @param string $html  Value, already escaped
 * @return void
 */
function markautomecanikPrintRow($label, $html)
{
	print '<tr><td class="titlefield">'.$label.'</td><td>'.$html.'</td></tr>';
}

/**
 * Print an input row
 *
 * @param string $label Label (already translated)
 * @param string $name  Field name
 * @param string $value Value
 * @param string $type  Input type
 * @param bool   $required Required field
 * @return void
 */
function markautomecanikPrintInput($label, $name, $value, $type = 'text', $required = false)
{
	print '<tr><td class="titlefield'.($required ? ' fieldrequired' : '').'"><label for="'.$name.'">'.$label.'</label></td><td>';
	if ($type === 'textarea') {
		print '<textarea id="'.$name.'" name="'.$name.'" class="quatrevingtpercent" rows="'.ROWS_5.'">'.dol_escape_htmltag($value).'</textarea>';
	} else {
		print '<input type="'.$type.'" id="'.$name.'" name="'.$name.'" class="minwidth300" maxlength="255" value="'.dol_escape_htmltag($value).'">';
	}
	print '</td></tr>';
}

/**
 * Print the identity form fields
 *
 * @param MarkAutoMecanikLead $lead Lead
 * @return void
 */
function markautomecanikPrintIdentityForm($lead)
{
	global $langs;
	markautomecanikPrintInput($langs->trans('Lastname'), 'lastname', $lead->lastname, 'text', true);
	markautomecanikPrintInput($langs->trans('Firstname'), 'firstname', $lead->firstname);
	markautomecanikPrintInput($langs->trans('Email'), 'email', $lead->email, 'email');
	markautomecanikPrintInput($langs->trans('Phone'), 'phone', $lead->phone, 'tel');
	markautomecanikPrintInput($langs->trans('Company'), 'company', $lead->company);
	markautomecanikPrintInput($langs->trans('Town'), 'city', $lead->city);
	markautomecanikPrintInput($langs->trans('Country'), 'country', $lead->country);
	markautomecanikPrintInput($langs->trans('Website'), 'website', $lead->website, 'url');
	markautomecanikPrintInput($langs->trans('Industry'), 'industry', $lead->industry);
	markautomecanikPrintInput($langs->trans('Interests'), 'interests', $lead->interests);
	markautomecanikPrintInput($langs->trans('UtmSource'), 'utm_source', $lead->utm_source);
	markautomecanikPrintInput($langs->trans('UtmMedium'), 'utm_medium', $lead->utm_medium);
	markautomecanikPrintInput($langs->trans('UtmCampaign'), 'utm_campaign', $lead->utm_campaign);
	markautomecanikPrintInput($langs->trans('Message'), 'message', $lead->message, 'textarea');
}

// ----- Creation form -----
if ($action === 'create') {
	print load_fiche_titre($langs->trans('NewLead'), '', $object->picto);
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';
	print dol_get_fiche_head(array(), '');
	print '<table class="border centpercent tableforfieldcreate">';
	markautomecanikPrintIdentityForm($object);
	markautomecanikPrintInput($langs->trans('NotePrivate'), 'note_private', $object->note_private, 'textarea');
	print '</table>';
	print dol_get_fiche_end();
	print $form->buttonsSaveCancel('Create');
	print '</form>';

	llxFooter();
	$db->close();
	exit;
}

// ----- Card -----
$head = markautomecanikLeadPrepareHead($object);
print dol_get_fiche_head($head, 'card', $langs->trans('MarketingLead'), -1, $object->picto);

// Confirmation dialogs
$formconfirm = '';
if ($action === 'delete' && $permissiontodelete) {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('DeleteLead'), $langs->trans('ConfirmDeleteLead'), 'confirm_delete', '', 0, 1);
}
if ($action === 'anonymize' && $permissiontodelete) {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('AnonymizeLead'), $langs->trans('ConfirmAnonymizeLead'), 'confirm_anonymize', '', 0, 1);
}
if ($action === 'notify' && $permissiontoadd) {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('NotifySalesRep'), $langs->trans('ConfirmNotifySalesRep'), 'confirm_notify', '', 0, 1);
}
// Return from the thirdparty creation page: confirmation before linking (the token of the
// back URL cannot be trusted after a round trip, so the link is confirmed by the user)
if ($action === 'confirmlinksoc' && $permissiontoadd && GETPOSTINT('socid') > 0) {
	$soc = new Societe($db);
	if ($soc->fetch(GETPOSTINT('socid')) > 0) {
		$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id.'&socid='.((int) $soc->id), $langs->trans('LinkToThirdparty'), $langs->trans('ConfirmLinkToThirdparty', dol_escape_htmltag($soc->name)), 'confirm_linksoc', '', 'yes', 1);
	}
}
if ($action === 'confirmlinkproject' && $permissiontoadd && GETPOSTINT('projectid') > 0 && isModEnabled('project')) {
	require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
	$project = new Project($db);
	if ($project->fetch(GETPOSTINT('projectid')) > 0) {
		$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id.'&projectid='.((int) $project->id), $langs->trans('LinkToProject'), $langs->trans('ConfirmLinkToProject', dol_escape_htmltag($project->ref)), 'confirm_linkproject', '', 'yes', 1);
	}
}
print $formconfirm;

$linkback = '<a href="'.dol_buildpath('/markautomecanik/lead_list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
$morehtmlref = '<div class="refidno">'.dol_escape_htmltag($object->getFullName());
if (!empty($object->company)) {
	$morehtmlref .= '<br>'.img_picto('', 'company', 'class="pictofixedwidth"').dol_escape_htmltag($object->company);
}
$morehtmlref .= '</div>';
$object->ref = $object->id;
dol_banner_tab($object, 'id', $linkback, ($user->hasRight('markautomecanik', 'lead', 'readall') ? 1 : 0), 'rowid', 'ref', $morehtmlref);

if (!empty($object->date_anonymized)) {
	print info_admin($langs->trans('LeadAnonymizedOn', dol_print_date($object->date_anonymized, 'dayhour', 'tzuser')), 0, 0, 'warning');
}

print '<div class="fichecenter"><div class="fichehalfleft">';
print '<div class="underbanner clearboth"></div>';

if ($action === 'edit') {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save">';
	print '<table class="border centpercent tableforfield">';
	markautomecanikPrintIdentityForm($object);
	print '</table>';
	print $form->buttonsSaveCancel();
	print '</form>';
} else {
	print '<table class="border centpercent tableforfield">';
	$email = $object->email ? dol_print_email($object->email, 0, 0, 1) : '';
	markautomecanikPrintRow($langs->trans('Email'), $email);
	markautomecanikPrintRow($langs->trans('Phone'), $object->phone ? dol_print_phone($object->phone, '', 0, 0, 'AC_TEL') : '');
	markautomecanikPrintRow($langs->trans('Company'), dol_escape_htmltag($object->company));
	markautomecanikPrintRow($langs->trans('Town'), dol_escape_htmltag(trim($object->city.($object->country ? ' ('.$object->country.')' : ''))));
	markautomecanikPrintRow($langs->trans('Language'), dol_escape_htmltag($object->lang));
	if ($object->website && preg_match('#^https?://#i', $object->website)) {
		markautomecanikPrintRow($langs->trans('Website'), '<a href="'.dol_escape_htmltag($object->website).'" target="_blank" rel="noopener noreferrer nofollow">'.dol_escape_htmltag($object->website).'</a>');
	} else {
		markautomecanikPrintRow($langs->trans('Website'), dol_escape_htmltag($object->website));
	}
	markautomecanikPrintRow($langs->trans('Industry'), dol_escape_htmltag($object->industry));
	markautomecanikPrintRow($langs->trans('Interests'), dol_escape_htmltag($object->interests));
	markautomecanikPrintRow($langs->trans('Message'), dol_nl2br(dol_escape_htmltag($object->message)));
	$utm = array_filter(array($object->utm_source, $object->utm_medium, $object->utm_campaign));
	markautomecanikPrintRow($langs->trans('UtmTags'), dol_escape_htmltag(implode(' / ', $utm)));
	print '</table>';
}

print '</div><div class="fichehalfright">';
print '<div class="underbanner clearboth"></div>';
print '<table class="border centpercent tableforfield">';

$origins = array(
	MarkAutoMecanikLead::ORIGIN_WEBMECANIK => $langs->trans('OriginWebmecanik'),
	MarkAutoMecanikLead::ORIGIN_MANUAL => $langs->trans('OriginManual'),
	MarkAutoMecanikLead::ORIGIN_API => $langs->trans('OriginApi'),
);
markautomecanikPrintRow($langs->trans('LeadOrigin'), dol_escape_htmltag(isset($origins[$object->origin]) ? $origins[$object->origin] : $object->origin));
markautomecanikPrintRow($langs->trans('DateCreation'), dol_print_date($object->date_creation, 'dayhour', 'tzuser'));
markautomecanikPrintRow($langs->trans('WebmecanikPoints'), (int) $object->wm_points);
if ($object->wm_contact_id > 0) {
	$wmUrl = $object->getWebmecanikUrl();
	$html = '#'.((int) $object->wm_contact_id);
	if ($wmUrl) {
		$html = '<a href="'.dol_escape_htmltag($wmUrl).'" target="_blank" rel="noopener noreferrer">'.img_picto('', 'globe', 'class="pictofixedwidth"').$langs->trans('OpenInWebmecanik').' #'.((int) $object->wm_contact_id).'</a>';
	}
	markautomecanikPrintRow($langs->trans('WebmecanikContact'), $html);
	markautomecanikPrintRow($langs->trans('WebmecanikDateModified'), dol_print_date($object->wm_date_modified, 'dayhour', 'tzuser'));
}

// Thirdparty
$html = '';
if ($object->fk_soc > 0) {
	$soc = new Societe($db);
	if ($soc->fetch($object->fk_soc) > 0) {
		$html = $soc->getNomUrl(1);
		if ($permissiontoadd && empty($object->date_anonymized)) {
			$html .= ' <form class="inline-block" method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">';
			$html .= '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="unlinksoc">';
			$html .= '<button type="submit" class="buttonreset" title="'.dol_escape_htmltag($langs->trans('Unlink')).'">'.img_picto($langs->trans('Unlink'), 'unlink').'</button></form>';
		}
	}
} elseif ($permissiontoadd && empty($object->date_anonymized)) {
	$html = '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">';
	$html .= '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="linksoc">';
	$html .= $form->select_company(0, 'socid', '', 1, 0, 0, array(), 0, 'minwidth200 maxwidth300');
	$html .= ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Link')).'">';
	$html .= '</form>';
}
markautomecanikPrintRow($langs->trans('ThirdParty'), $html);

// Contact
$html = '';
if ($object->fk_socpeople > 0) {
	$contact = new Contact($db);
	if ($contact->fetch($object->fk_socpeople) > 0) {
		$html = $contact->getNomUrl(1);
	}
} elseif ($object->fk_soc > 0 && $permissiontoadd && empty($object->date_anonymized)) {
	$html = '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">';
	$html .= '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="linkcontact">';
	$html .= $form->selectcontacts($object->fk_soc, '', 'contactid', 1, '', '', 0, 'minwidth200 maxwidth300');
	$html .= ' <input type="submit" class="button smallpaddingimp" value="'.dol_escape_htmltag($langs->trans('Link')).'">';
	$html .= '</form>';
}
markautomecanikPrintRow($langs->trans('Contact'), $html);

// Project / opportunity
if (isModEnabled('project') && $object->fk_project > 0) {
	require_once DOL_DOCUMENT_ROOT.'/projet/class/project.class.php';
	$project = new Project($db);
	if ($project->fetch($object->fk_project) > 0) {
		markautomecanikPrintRow($langs->trans('Project'), $project->getNomUrl(1));
	}
}

markautomecanikPrintRow($langs->trans('DateStatus'), dol_print_date($object->date_status, 'dayhour', 'tzuser'));
print '</table>';

// Qualification form
print '<br>';
print load_fiche_titre($langs->trans('Qualification'), '', '');
$canQualify = ($permissiontoadd && empty($object->date_anonymized));
if ($canQualify) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="qualify">';
}
print '<table class="border centpercent tableforfield">';
if ($canQualify) {
	markautomecanikPrintRow($langs->trans('Status'), $form->selectarray('status', MarkAutoMecanikLead::getStatusLabels(), $object->status, 0, 0, 0, '', 0, 0, 0, '', 'minwidth200'));
	markautomecanikPrintRow($langs->trans('LostReason'), '<input type="text" name="lost_reason" class="minwidth300" maxlength="255" value="'.dol_escape_htmltag($object->lost_reason).'" placeholder="'.dol_escape_htmltag($langs->trans('LostReasonPlaceholder')).'">');
	$html = $form->select_dolusers($object->fk_user_assign, 'fk_user_assign', 1, null, 0, '', '', '0', 0, 0, '', 0, '', 'minwidth200 maxwidth300', 1);
	$html .= ' <label class="nowraponall"><input type="checkbox" name="notify_assign" value="1" checked> '.$langs->trans('NotifyByEmail').'</label>';
	markautomecanikPrintRow($langs->trans('SalesRepresentative'), $html);
	markautomecanikPrintRow($langs->trans('NotePrivate'), '<textarea name="note_private" class="quatrevingtpercent" rows="'.ROWS_4.'">'.dol_escape_htmltag($object->note_private).'</textarea>');
} else {
	markautomecanikPrintRow($langs->trans('Status'), $object->getLibStatut(5));
	if ((int) $object->status === MarkAutoMecanikLead::STATUS_LOST) {
		markautomecanikPrintRow($langs->trans('LostReason'), dol_escape_htmltag($object->lost_reason));
	}
	$html = '';
	if ($object->fk_user_assign > 0) {
		$rep = new User($db);
		if ($rep->fetch($object->fk_user_assign) > 0) {
			$html = $rep->getNomUrl(-1);
		}
	}
	markautomecanikPrintRow($langs->trans('SalesRepresentative'), $html);
	markautomecanikPrintRow($langs->trans('NotePrivate'), dol_nl2br(dol_escape_htmltag($object->note_private)));
}
print '</table>';
if ($canQualify) {
	print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
	print '</form>';
}

print '</div></div>';
print '<div class="clearboth"></div>';
print dol_get_fiche_end();

// Action buttons
if ($action !== 'edit') {
	print '<div class="tabsAction">';
	$parameters = array();
	$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
	if (empty($reshook) && empty($object->date_anonymized)) {
		$self = $_SERVER['PHP_SELF'].'?id='.((int) $object->id);

		print dolGetButtonAction('', $langs->trans('Modify'), 'default', $self.'&action=edit&token='.newToken(), '', $permissiontoadd);

		if ($object->fk_user_assign > 0) {
			print dolGetButtonAction('', $langs->trans('NotifySalesRep'), 'default', $self.'&action=notify&token='.newToken(), '', $permissiontoadd);
		}

		// Create a thirdparty prefilled with the lead data, then come back to confirm the link
		if (empty($object->fk_soc) && $user->hasRight('societe', 'creer')) {
			$back = dol_buildpath('/markautomecanik/lead_card.php', 1).'?id='.((int) $object->id).'&action=confirmlinksoc&socid=--IDFORBACKTOPAGE--';
			$url = DOL_URL_ROOT.'/societe/card.php?action=create&type=p&client=2';
			$url .= '&name='.urlencode((string) ($object->company ? $object->company : $object->getFullName()));
			$url .= '&email='.urlencode((string) $object->email).'&phone='.urlencode((string) $object->phone);
			$url .= '&town='.urlencode((string) $object->city).'&url='.urlencode((string) $object->website);
			$url .= '&backtopage='.urlencode($back);
			$url .= '&backtopageforcancel='.urlencode(dol_buildpath('/markautomecanik/lead_card.php', 1).'?id='.((int) $object->id));
			print dolGetButtonAction('', $langs->trans('CreateThirdParty'), 'default', $url, '', $permissiontoadd);
		}

		// Create a contact of the linked thirdparty
		if ($object->fk_soc > 0 && empty($object->fk_socpeople) && $user->hasRight('societe', 'contact', 'creer')) {
			$url = DOL_URL_ROOT.'/contact/card.php?action=create&socid='.((int) $object->fk_soc);
			$url .= '&lastname='.urlencode((string) $object->lastname).'&firstname='.urlencode((string) $object->firstname);
			$url .= '&email='.urlencode((string) $object->email).'&phone_pro='.urlencode((string) $object->phone);
			$url .= '&backtopage='.urlencode(dol_buildpath('/markautomecanik/lead_card.php', 1).'?id='.((int) $object->id));
			print dolGetButtonAction('', $langs->trans('AddContact'), 'default', $url, '', $permissiontoadd);
		}

		// Create an opportunity (project) from the lead
		if (isModEnabled('project') && $object->fk_soc > 0 && empty($object->fk_project) && $user->hasRight('projet', 'creer')) {
			$back = dol_buildpath('/markautomecanik/lead_card.php', 1).'?id='.((int) $object->id).'&action=confirmlinkproject&projectid=--IDFORBACKTOPAGE--';
			$url = DOL_URL_ROOT.'/projet/card.php?action=create&socid='.((int) $object->fk_soc);
			$url .= '&usage_opportunity=1&title='.urlencode($langs->transnoentities('OpportunityFromLead', $object->company ? $object->company : $object->getFullName()));
			$url .= '&backtopage='.urlencode($back);
			print dolGetButtonAction('', $langs->trans('CreateOpportunity'), 'default', $url, '', $permissiontoadd);
		}

		if (getDolGlobalInt('MARKAUTOMECANIK_PUSH_TAGS') && $object->wm_contact_id > 0) {
			print dolGetButtonAction('', $langs->trans('PushStatusTag'), 'default', $self.'&action=pushtag&token='.newToken(), '', $permissiontoadd);
		}

		print dolGetButtonAction('', $langs->trans('AnonymizeLead'), 'delete', $self.'&action=anonymize&token='.newToken(), '', $permissiontodelete);
	}
	print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=delete&token='.newToken(), '', $permissiontodelete);
	print '</div>';
}

// Last events of the linked thirdparty
if ($action !== 'edit' && $object->fk_soc > 0 && isModEnabled('agenda') && ($user->hasRight('agenda', 'myactions', 'read') || $user->hasRight('agenda', 'allactions', 'read'))) {
	include_once DOL_DOCUMENT_ROOT.'/core/class/html.formactions.class.php';
	$formactions = new FormActions($db);
	$soc = new Societe($db);
	if ($soc->fetch($object->fk_soc) > 0) {
		print '<div class="fichecenter"><div class="fichehalfright">';
		$formactions->showactions($soc, 'societe', $soc->id, 1, '', 10);
		print '</div></div>';
	}
}

llxFooter();
$db->close();
