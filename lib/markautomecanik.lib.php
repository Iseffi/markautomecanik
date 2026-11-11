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
 * \file       markautomecanik/lib/markautomecanik.lib.php
 * \ingroup    markautomecanik
 * \brief      Common functions of module MarkAutoMecanik
 */

/**
 * Tabs of the setup pages
 *
 * @return array
 */
function markautomecanikAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load('markautomecanik@markautomecanik');
	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/markautomecanik/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dol_buildpath('/markautomecanik/admin/synclog.php', 1);
	$head[$h][1] = $langs->trans('SyncHistory');
	$head[$h][2] = 'synclog';
	$h++;

	$head[$h][0] = dol_buildpath('/markautomecanik/admin/about.php', 1);
	$head[$h][1] = $langs->trans('About');
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'markautomecanik@markautomecanik');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'markautomecanik@markautomecanik', 'remove');

	return $head;
}

/**
 * Tabs of the lead card
 *
 * @param MarkAutoMecanikLead $object Lead
 * @return array
 */
function markautomecanikLeadPrepareHead($object)
{
	global $langs, $conf;

	$langs->load('markautomecanik@markautomecanik');
	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/markautomecanik/lead_card.php', 1).'?id='.((int) $object->id);
	$head[$h][1] = $langs->trans('Card');
	$head[$h][2] = 'card';
	$h++;

	complete_head_from_modules($conf, $langs, $object, $head, $h, 'markautomecaniklead@markautomecanik');
	complete_head_from_modules($conf, $langs, $object, $head, $h, 'markautomecaniklead@markautomecanik', 'remove');

	return $head;
}

/**
 * Check the CSRF token of the request. Done explicitly, in addition to the core check,
 * because the core check can be disabled by $dolibarr_nocsrfcheck.
 *
 * @return void Exits with an access forbidden page if the token is invalid
 */
function markautomecanikCheckToken()
{
	$token = GETPOST('token', 'alpha');
	if (empty($token) || !hash_equals((string) currentToken(), (string) $token)) {
		accessforbidden('Bad value for CSRF token');
	}
}

/**
 * Check that the request is a POST request
 *
 * @return void Exits with an access forbidden page otherwise
 */
function markautomecanikRequirePost()
{
	if (empty($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
		accessforbidden('This action requires a POST request');
	}
}

/**
 * HTML of an email address with a mailto link. Built here instead of using dol_print_email(),
 * which inserts its argument without escaping when it adds a link.
 *
 * @param string $email Email
 * @return string HTML, escaped
 */
function markautomecanikEmailHtml($email)
{
	$email = (string) $email;
	if ($email === '') {
		return '';
	}
	$label = dol_escape_htmltag($email);
	if (!MarkAutoMecanikLead::isSafeEmail($email)) {
		return $label;
	}
	return '<a href="mailto:'.dol_escape_htmltag($email).'">'.img_picto('', 'email', 'class="pictofixedwidth"').$label.'</a>';
}

/**
 * HTML of a phone number with a tel: link. Built here instead of using dol_print_phone(),
 * which returns its argument without escaping.
 *
 * @param string $phone Phone number
 * @return string HTML, escaped
 */
function markautomecanikPhoneHtml($phone)
{
	$phone = (string) $phone;
	if ($phone === '') {
		return '';
	}
	$label = dol_escape_htmltag($phone);
	$dial = (string) preg_replace('/[^0-9+]/', '', $phone);
	if ($dial === '') {
		return $label;
	}
	return '<a href="tel:'.dol_escape_htmltag($dial).'">'.img_picto('', 'phoning', 'class="pictofixedwidth"').$label.'</a>';
}
