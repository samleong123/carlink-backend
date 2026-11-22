<?php
return ['ses' => ['key'=>env('AWS_ACCESS_KEY_ID'),'secret'=>env('AWS_SECRET_ACCESS_KEY'),'token'=>env('AWS_SESSION_TOKEN'),'region'=>env('AWS_DEFAULT_REGION','ap-southeast-1'),'http'=>['connect_timeout'=>5,'timeout'=>20],'retries'=>1]];
