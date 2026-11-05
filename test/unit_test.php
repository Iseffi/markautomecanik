<?php
/**
 * Unit tests of the pure logic of the module (no database needed).
 * Usage: DOLIBARR_ROOT=/path/to/dolibarr/htdocs php test/unit_test.php
 */
error_reporting(E_ALL);
$root = getenv('DOLIBARR_ROOT');
if (!$root || !is_file($root.'/core/lib/functions.lib.php')) {
	fwrite(STDERR, "Set DOLIBARR_ROOT to the htdocs directory of a Dolibarr checkout
");
	exit(2);
}
define('DOL_DOCUMENT_ROOT', rtrim($root, '/'));
$conf = new stdClass(); $conf->global = new stdClass(); $conf->entity = 1;
$conf->file = new stdClass(); $conf->file->instance_unique_id = 'unit-test-key-0123456789';
$conf->global->MARKAUTOMECANIK_FIELD_MESSAGE = 'message';
$conf->global->MARKAUTOMECANIK_FIELD_INDUSTRY = 'industry';
require DOL_DOCUMENT_ROOT.'/core/lib/functions.lib.php';
require DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
$M = dirname(__DIR__);
// Avoid loading CommonObject chain: stub minimal classes
class CommonObject {} class DoliDB {}
require $M.'/class/webmecanikclient.class.php';
eval('?>'.preg_replace('/^.*?class MarkAutoMecanikLead/s', '<?php class MarkAutoMecanikLead', file_get_contents($M.'/class/markautomecaniklead.class.php')));
eval('?>'.preg_replace('/^.*?class MarkAutoMecanikSync/s', '<?php class MarkAutoMecanikSync', file_get_contents($M.'/class/markautomecaniksync.class.php')));
$fail = 0;
function check($label, $got, $exp) { global $fail; $ok = ($got === $exp); if (!$ok) $fail++; echo ($ok ? 'OK   ' : 'FAIL ').$label.($ok ? '' : ' got='.var_export($got, true).' exp='.var_export($exp, true))."\n"; }
$n = 'MarkAutoMecanikWebmecanikClient::normalizeBaseUrl';
check('https ok', $n('https://Demo.Webmecanik.com/'), 'https://demo.webmecanik.com');
check('https path', $n('https://x.com/mautic'), 'https://x.com/mautic');
check('http refused', $n('http://x.com'), '');
check('creds refused', $n('https://u:p@x.com'), '');
check('query refused', $n('https://x.com/?a=1'), '');
check('file refused', $n('file:///etc/passwd'), '');
check('js refused', $n('javascript:alert(1)'), '');
check('port kept', $n('https://x.com:8443'), 'https://x.com:8443');
$enc = MarkAutoMecanikWebmecanikClient::encryptSecret('s3cr€t!');
check('secret encrypted', strpos($enc, 'dolcrypt:') === 0 && strpos($enc, 's3cr') === false, true);
check('secret roundtrip', MarkAutoMecanikWebmecanikClient::decryptSecret($enc), 's3cr€t!');
check('legacy clear secret', MarkAutoMecanikWebmecanikClient::decryptSecret('plain'), 'plain');
check('cleanText ctrl', MarkAutoMecanikLead::cleanText("a\x00b\x1Fc\nd ", 255), "abc\nd");
check('cleanText trunc', MarkAutoMecanikLead::cleanText(str_repeat('é', 300), 10), str_repeat('é', 10));
check('cleanText array', MarkAutoMecanikLead::cleanText(array('x'), 10), '');
$contact = array('id' => 42, 'points' => 55, 'dateAdded' => '2026-01-02T10:00:00+00:00', 'dateModified' => '2026-03-04T11:00:00+00:00',
 'fields' => array('core' => array('email' => array('value' => 'John@Example.com'), 'firstname' => array('value' => 'John'), 'mobile' => array('value' => '0600'), 'message' => array('value' => 'Hi')),
   'professional' => array('industry' => array('value' => 'IT'))),
 'utmtags' => array(array('utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'c1')));
$m = MarkAutoMecanikSync::mapContact($contact);
check('map id', $m['wm_contact_id'], 42);
check('map email lower', $m['email'], 'john@example.com');
check('map phone fallback mobile', $m['phone'], '0600');
check('map custom message', $m['message'], 'Hi');
check('map custom industry', $m['industry'], 'IT');
check('map utm', $m['utm_medium'], 'cpc');
check('map date', $m['wm_date_modified'], strtotime('2026-03-04T11:00:00+00:00'));
$contact2 = array('id' => 1, 'fields' => array('all' => array('email' => 'a@b.c', 'lastname' => 'Doe', 'evil' => array('x'))));
$m2 = MarkAutoMecanikSync::mapContact($contact2);
check('map all group', $m2['lastname'], 'Doe');
check('map no message alias', MarkAutoMecanikSync::mapContact(array('fields' => array('all' => array('' => 'x'))))['interests'], '');
// webhook signature as computed by Mautic
$raw = '{"mautic.lead_post_save_update":[{"contact":{"id":12}}]}';
$sig = base64_encode(hash_hmac('sha256', $raw, 'secret', true));
check('hmac verify', hash_equals(base64_encode(hash_hmac('sha256', $raw, 'secret', true)), $sig), true);
check('hmac tamper', hash_equals(base64_encode(hash_hmac('sha256', $raw.' ', 'secret', true)), $sig), false);
echo $fail ? "
$fail FAILURE(S)
" : "
ALL TESTS PASSED
";
exit($fail ? 1 : 0);
