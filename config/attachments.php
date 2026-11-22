<?php
return [
    // Private uploads through PHP; authorized reads issue short-lived S3 SigV4 bearer URLs.
    'disk'=>'s3',
    'max_bytes'=>10485760,
    'photo_max_bytes'=>2097152,
];
