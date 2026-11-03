<?php
/** Pure classification and response correlation: never makes HTTP requests. */
declare(strict_types=1);
define('DAY_IN_SECONDS',86400);
require __DIR__.'/../../src/includes/AbuseIPDB/Evidence.php';
require __DIR__.'/../../src/includes/Services/IpValidator.php';
require __DIR__.'/../../src/includes/AbuseIPDB/Reporter.php';
use WPCF\FirewallSync\AbuseIPDB\Evidence;
use WPCF\FirewallSync\AbuseIPDB\Reporter;
function ae_ok(bool $value,string $label):void { if (!$value) { throw new RuntimeException($label); } echo "PASS: {$label}\n"; }
foreach (['SQL injection attack'=>16,'Remote file inclusion attack'=>21,'Web spam detected'=>10,'Comment spam detected'=>12,
'Exceeded the maximum number of requests per minute for crawlers.'=>19,
"Exceeded the maximum number of login failures which is: 5. The last username they tried to sign in with was: 'someone'"=>18] as $reason=>$category) {
  ae_ok(Evidence::classify($reason)['categories']===(string)$category,'Actual source mapping '.$category);
}
foreach (['','Unknown','Generic WAF block','Generic 404 page','blocked access via country blocking','Not SQL injection','Possible SQL injection','Potential SQL injection','Could be SQL injection','SQL injection?','Unconfirmed remote code execution','Blocked by Wordfence Security Network','Manual block by administrator','Accessed a banned URL','POST received with blank user-agent and referer','GET https://example.test/sql injection', 'Exceeded the maximum number of page not found errors per minute for a crawler.'] as $reason) {
  ae_ok(Evidence::classify($reason)===null,'Reject unsupported evidence: '.$reason);
}
ae_ok(Evidence::classify('Known Malicious User-Agents')['categories']==='19','Real Wordfence malicious user-agent detection');
ae_ok(Evidence::classify('Known Malicious User-Agents',true)===null,'Manual blocks cannot impersonate Wordfence detections');
ae_ok(Evidence::incident('8.8.8.8',time()-10,'hit:164386','Known Malicious User-Agents')['categories']==='19','Real WAF detection produces reportable incident');
ae_ok(Evidence::classify('Brute force authentication attacks observed',true)['categories']==='18','Manual authentication evidence');
ae_ok(Evidence::classify('Abusive automated web crawling observed',true)['categories']==='19','Manual crawling evidence');
ae_ok(Evidence::incident('8.8.8.8',time()-30*86400,'hit:1','SQL injection attack')===null,'Expiry boundary cannot resurrect incidents');
$a=Evidence::incident('8.8.8.8',time()-10,'hit:1','SQL injection attack');
$b=Evidence::incident('8.8.8.8',time()-10,'hit:1','SQL injection attack');
ae_ok($a===$b && strlen($a['comment'])<255,'Stable identity and bounded fixed description');
$promotion_at=time()-15;
$temporary=Evidence::incident('8.8.8.8',$promotion_at,'active:8','SQL injection attack');
$permanent=Evidence::incident('8.8.8.8',$promotion_at,'active:9','SQL injection attack');
ae_ok($temporary['incident']===$permanent['incident'],'Temporary-to-permanent promotion cannot duplicate unchanged attack evidence');
$rows=[['ip'=>'8.8.8.8'],['ip'=>'8.8.4.4']];
$bulk=new ReflectionMethod(Reporter::class,'bulk_statuses');
ae_ok($bulk->invoke(null,$rows,['savedReports'=>1,'invalidReports'=>[['input'=>'8.8.4.4','rowNumber'=>2,'error'=>'invalid']]])===['accepted','rejected'],'Bulk correlates exact IP and count rather than assumed row indexes');
ae_ok($bulk->invoke(null,$rows,['savedReports'=>2,'invalidReports'=>[['input'=>'8.8.4.4']]])===['uncertain','uncertain'],'Inconsistent bulk acknowledgement remains uncertain');
ae_ok($bulk->invoke(null,$rows,['savedReports'=>1,'invalidReports'=>[['input'=>'9.9.9.9']]])===['uncertain','uncertain'],'Unrecognized rejected IP cannot create success');
$root=dirname(__DIR__,2);
foreach (['readme.txt','src/includes/Admin/Settings.php'] as $file) { ae_ok(str_contains(file_get_contents($root.'/'.$file),'https://ko-fi.com/greyscalezone'),'Official donation link '.$file); }
ae_ok(str_contains(file_get_contents($root.'/src/includes/Admin/LogTable.php'),"'reported'"),'Existing log has Reported column');
echo "ABUSEIPDB PURE REGRESSION PASS; no HTTP capability invoked\n";
