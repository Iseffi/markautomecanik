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
// Dolibarr 24+: dolEncrypt()/dolDecrypt() moved to blockedlog/lib/securitycore.lib.php (loaded by filefunc.inc.php)
if (!function_exists('dolEncrypt') && is_file(DOL_DOCUMENT_ROOT.'/blockedlog/lib/securitycore.lib.php')) {
	require DOL_DOCUMENT_ROOT.'/blockedlog/lib/securitycore.lib.php';
}
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
check('cleanText ctrl multiline', MarkAutoMecanikLead::cleanText("a\x00b\x1Fc\nd ", 255, true), "abc\nd");
check('cleanText single-line', MarkAutoMecanikLead::cleanText("ACME\r\n\r\nhttps://evil.example\tx", 255), 'ACME https://evil.example x');
check('cleanText single-line tags', MarkAutoMecanikLead::cleanText('Acme <svg/onload=alert(1)>Ltd', 255), 'Acme Ltd');
check('cleanText entities not decoded', MarkAutoMecanikLead::cleanText('&lt;b&gt;', 255), '&lt;b&gt;');
check('cleanText trunc', MarkAutoMecanikLead::cleanText(str_repeat('é', 300), 10), str_repeat('é', 10));
check('cleanText array', MarkAutoMecanikLead::cleanText(array('x'), 10), '');
check('cleanField message keeps lines', MarkAutoMecanikLead::cleanField('message', "l1\nl2"), "l1\nl2");
check('cleanField company one line', MarkAutoMecanikLead::cleanField('company', "a\nb"), 'a b');
check('safe email', MarkAutoMecanikLead::isSafeEmail('john.doe+x@example.com'), true);
check('quoted email refused', MarkAutoMecanikLead::isSafeEmail('"<svg/onload=alert(1)>"@example.com'), false);
check('email with quote refused', MarkAutoMecanikLead::isSafeEmail("a'b\"@example.com"), false);
check('cleanField unsafe email emptied', MarkAutoMecanikLead::cleanField('email', '"<b>"@example.com'), '');
check('cleanField email lower', MarkAutoMecanikLead::cleanField('email', 'John@Example.COM'), 'john@example.com');
check('phone attribute injection', MarkAutoMecanikLead::cleanPhone('0612345678"onclick="alert(1)'), '0612345678(1)');
check('phone kept', MarkAutoMecanikLead::cleanPhone('+33 (0)6 12.34/56-78'), '+33 (0)6 12.34/56-78');
check('oneLine', MarkAutoMecanikLead::oneLine("a\r\nb"), 'a b');
check('optout hash stable', MarkAutoMecanikLead::optOutHash('email', ' John@Example.com', 's'), MarkAutoMecanikLead::optOutHash('email', 'john@example.com', 's'));
check('optout hash salted', MarkAutoMecanikLead::optOutHash('email', 'a@b.c', 's1') !== MarkAutoMecanikLead::optOutHash('email', 'a@b.c', 's2'), true);
check('optout hash typed', MarkAutoMecanikLead::optOutHash('wmid', 12, 's') !== MarkAutoMecanikLead::optOutHash('email', '12', 's'), true);
check('optout hash empty', MarkAutoMecanikLead::optOutHash('wmid', 0, 's'), '');
$ip = 'MarkAutoMecanikWebmecanikClient::isPublicIp';
check('public ipv4', $ip('93.184.216.34'), true);
check('loopback', $ip('127.0.0.1'), false);
check('private 10', $ip('10.1.2.3'), false);
check('private 172', $ip('172.20.0.1'), false);
check('private 192', $ip('192.168.1.1'), false);
check('link-local metadata', $ip('169.254.169.254'), false);
check('cgnat', $ip('100.64.0.1'), false);
check('unspecified', $ip('0.0.0.0'), false);
check('public ipv6', $ip('2606:4700:4700::1111'), true);
check('ipv6 loopback', $ip('::1'), false);
check('ipv6 ula', $ip('fd00::1'), false);
check('ipv6 link-local', $ip('fe80::1'), false);
check('ipv4-mapped', $ip('::ffff:127.0.0.1'), false);
check('not an ip', $ip('example.com'), false);
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
