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
 * \file       markautomecanik/public/webhook.php
 * \ingroup    markautomecanik
 * \brief      Public endpoint receiving Webmecanik (Mautic) webhooks
 *
 * Security model:
 * - disabled unless explicitly enabled in the module setup;
 * - POST only, JSON only, body size limited;
 * - every request must carry a valid "Webhook-Signature" header: base64(HMAC-SHA256(raw body, secret)),
 *   compared in constant time;
 * - the payload is only used to extract contact ids: contact data are then read back from the
 *   Webmecanik API with the configured credentials, so a forged or replayed payload cannot inject data;
 * - the number of contacts processed per call is limited.
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', '1');
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

// Entity (multicompany): must be defined before loading the environment
$entity = (!empty($_GET['entity']) ? (int) $_GET['entity'] : 1);
if ($entity > 0) {
	define('DOLENTITY', $entity);
}

/** Maximum body size accepted (bytes) */
const MARKAUTOMECANIK_WEBHOOK_MAX_BODY = 1048576;
/** Maximum number of contacts processed per call */
const MARKAUTOMECANIK_WEBHOOK_MAX_CONTACTS = 50;

/**
 * Send a JSON response and stop
 *
 * @param int   $code HTTP code
 * @param array $data Data
 * @return void
 */
function markautomecanikWebhookRespond($code, $data)
{
	http_response_code($code);
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	header('X-Content-Type-Options: nosniff');
	echo json_encode($data);
	exit;
}

// Cheap checks before loading the whole environment
if (empty($_SERVER['REQUEST_METHOD']) || strtoupper($_SERVER['REQUEST_METHOD']) !== 'POST') {
	header('Allow: POST');
	markautomecanikWebhookRespond(405, array('error' => 'Method not allowed'));
}
if (isset($_SERVER['CONTENT_LENGTH']) && (int) $_SERVER['CONTENT_LENGTH'] > MARKAUTOMECANIK_WEBHOOK_MAX_BODY) {
	markautomecanikWebhookRespond(413, array('error' => 'Payload too large'));
}

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
	markautomecanikWebhookRespond(500, array('error' => 'Environment not available'));
}

require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once __DIR__.'/../class/webmecanikclient.class.php';
require_once __DIR__.'/../class/markautomecaniksync.class.php';

$remote = getUserRemoteIP();

if (!isModEnabled('markautomecanik') || !getDolGlobalInt('MARKAUTOMECANIK_WEBHOOK_ENABLED')) {
	markautomecanikWebhookRespond(404, array('error' => 'Not found'));
}
$secret = MarkAutoMecanikWebmecanikClient::decryptSecret(getDolGlobalString('MARKAUTOMECANIK_WEBHOOK_SECRET'));
if ($secret === '') {
	dol_syslog('markautomecanik webhook: no secret configured, request refused', LOG_WARNING);
	markautomecanikWebhookRespond(503, array('error' => 'Not configured'));
}

// Read the raw body with a hard size limit (Content-Length may be absent with chunked encoding)
$raw = (string) file_get_contents('php://input', false, null, 0, MARKAUTOMECANIK_WEBHOOK_MAX_BODY + 1);
if (strlen($raw) > MARKAUTOMECANIK_WEBHOOK_MAX_BODY) {
	markautomecanikWebhookRespond(413, array('error' => 'Payload too large'));
}

// Signature check (constant time)
$signature = isset($_SERVER['HTTP_WEBHOOK_SIGNATURE']) ? trim((string) $_SERVER['HTTP_WEBHOOK_SIGNATURE']) : '';
$expected = base64_encode(hash_hmac('sha256', $raw, $secret, true));
if ($signature === '' || !hash_equals($expected, $signature)) {
	dol_syslog('markautomecanik webhook: invalid signature from '.$remote, LOG_WARNING);
	markautomecanikWebhookRespond(401, array('error' => 'Invalid signature'));
}

$payload = json_decode($raw, true, 32);
if (!is_array($payload)) {
	markautomecanikWebhookRespond(400, array('error' => 'Invalid JSON'));
}

// Extract contact ids: { "mautic.lead_post_save_update": [ { "contact": { "id": 12, ... } } ], ... }
$ids = array();
foreach ($payload as $eventName => $events) {
	if (!is_string($eventName) || !is_array($events)) {
		continue;
	}
	foreach ($events as $event) {
		if (!is_array($event)) {
			continue;
		}
		foreach (array('contact', 'lead') as $key) {
			if (isset($event[$key]['id']) && is_numeric($event[$key]['id']) && (int) $event[$key]['id'] > 0) {
				$ids[(int) $event[$key]['id']] = (int) $event[$key]['id'];
			}
		}
	}
}
$ids = array_values($ids);
$truncated = (count($ids) > MARKAUTOMECANIK_WEBHOOK_MAX_CONTACTS);
$ids = array_slice($ids, 0, MARKAUTOMECANIK_WEBHOOK_MAX_CONTACTS);

if (empty($ids)) {
	markautomecanikWebhookRespond(200, array('received' => 0));
}

// Technical user used as author of the leads created by the webhook
$webhookUser = new User($db);
if (getDolGlobalInt('MARKAUTOMECANIK_WEBHOOK_USER') > 0) {
	$webhookUser->fetch(getDolGlobalInt('MARKAUTOMECANIK_WEBHOOK_USER'));
}

$sync = new MarkAutoMecanikSync($db);
$result = $sync->synchronizeContactIds($ids, $webhookUser);
dol_syslog('markautomecanik webhook from '.$remote.': '.$sync->output, ($result < 0 ? LOG_WARNING : LOG_INFO));

markautomecanikWebhookRespond(($result < 0 ? 502 : 200), array(
	'received' => count($ids),
	'created' => $sync->stats['created'],
	'updated' => $sync->stats['updated'],
	'skipped' => $sync->stats['skipped'],
	'errors' => $sync->stats['errors'],
	'truncated' => $truncated,
));
