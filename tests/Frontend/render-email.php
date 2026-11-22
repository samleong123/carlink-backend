<?php
// Synthetic preview only: no database reads, provider calls, or real login credentials.
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['app.frontend_url'=>'https://carlink.example.test']);
$template=app(\App\Services\Mail\EmailTemplate::class);
$url='https://carlink.example.test/';
$loginUrl='https://carlink.example.test/auth/confirm?token='.str_repeat('a',64);
$messages=[
    'login'=>$template->render('Your sign-in link','One secure link. No password to remember. Open CarLink and confirm to sign in.',['Expires'=>'10 minutes','Account'=>'owner@example.test'],$loginUrl,'Sign in to CarLink','This link works once. If you did not request it, you can safely ignore this email.'),
    'security'=>$template->render('New sign-in to CarLink','Your email sign-in link was successfully confirmed.',['IP address'=>'203.0.113.14','Signed in at'=>'17 Nov 2026, 23:04:37 +08:00 (Asia/Kuala_Lumpur)','User agent'=>'Mozilla/5.0 '.str_repeat('LongUserAgentWithoutSpaces',12).' <script>not executable</script>'],$url,'Open CarLink','Browser details are client-reported, not proof of device identity.'),
    'status'=>$template->render('Account access suspended','Your account access has changed. This notice applies only to the access scope shown below.',['Access scope'=>'Organization membership','Status'=>'suspended','Updated at'=>'17 Nov 2026, 23:10:00 +08:00 (Asia/Kuala_Lumpur)'],$url,'Open CarLink','Contact your organization owner if you believe this status change was made in error.'),
    'status_activated'=>$template->render('Account access activated','Your account access has changed. This notice applies only to the access scope shown below.',['Access scope'=>'CarLink account','Status'=>'active','Updated at'=>'17 Nov 2026, 23:11:00 +08:00 (Asia/Kuala_Lumpur)'],$url,'Open CarLink'),
    'fleet'=>$template->render('Your fleet’s daily driving summary','A snapshot of the whole organization for the completed local day.',['Local report date'=>'2026-11-16','Report timezone'=>'Asia/Kuala_Lumpur','Reported samples'=>1200,'Estimated distance (km)'=>84.32,'Estimated fuel (litres)'=>null,'Harsh braking observations'=>3,'Rapid acceleration observations'=>1],$url,'Open CarLink','Synthetic preview. Production summaries use reported measurements only. Partial-coverage estimates; unknown values are not zero.'),
    'appeal'=>$template->render('Your appeal was approved','A compliance-authorized reviewer has decided your appeal.',['Appeal reference'=>'10000000-0000-4000-8000-000000000001','Decision'=>'approved','Reviewer’s explanation'=>'A sensor discrepancy was verified. This observation has been excluded from the experimental score.'],$url,'Open CarLink','The score is experimental, not a disciplinary finding.'),
    'compliance'=>$template->render('Compliance threshold reached','A vehicle compliance threshold requires review.',['Vehicle'=>'WXY1234','Threshold'=>'due_soon','Due date'=>'2026-12-05','Days remaining'=>18,'Compliance'=>'Road tax','Configured warning days'=>30],$url,'Open CarLink','Only verified coverage is used. Review and renew the applicable record in CarLink.'),
    'device_linked'=>$template->render('Your vehicle device is enabled','The technician recorded installation and enabled the device linked to your vehicle.',['Vehicle'=>'WXY1234','Device serial'=>'carlink-pi-042','Status'=>'active','Installed at'=>'17 Nov 2026, 23:12:00 +08:00 (Asia/Kuala_Lumpur)'],$url,'Open CarLink'),
];
echo json_encode($messages,JSON_THROW_ON_ERROR);
