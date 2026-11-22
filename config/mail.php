<?php
return ['default' => env('MAIL_MAILER','log'), 'mailers' => ['log' => ['transport'=>'log','channel'=>env('MAIL_LOG_CHANNEL')], 'ses' => ['transport'=>'ses'], 'array' => ['transport'=>'array']], 'from' => ['address'=>env('MAIL_FROM_ADDRESS','notifications@example.com'),'name'=>env('MAIL_FROM_NAME','CarLink')]];
