<?php
return ['default'=>env('FILESYSTEM_DISK','s3'), 'disks'=>[
 'local'=>['driver'=>'local','root'=>storage_path('app/private'),'throw'=>true],
 's3'=>['driver'=>'s3','key'=>env('AWS_ACCESS_KEY_ID'),'secret'=>env('AWS_SECRET_ACCESS_KEY'),'token'=>env('AWS_SESSION_TOKEN'),'region'=>env('AWS_DEFAULT_REGION'),'bucket'=>env('AWS_BUCKET'),'endpoint'=>env('AWS_ENDPOINT'),'use_path_style_endpoint'=>env('AWS_USE_PATH_STYLE_ENDPOINT',false),'http'=>['connect_timeout'=>5,'timeout'=>20],'visibility'=>'private','throw'=>true],
]];
