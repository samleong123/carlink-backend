<?php
return ['default' => env('CACHE_STORE','redis'), 'stores' => [
 'array' => ['driver' => 'array', 'serialize' => false],
 'database' => ['driver' => 'database', 'connection' => null, 'table' => 'cache', 'lock_connection' => null, 'lock_table' => 'cache_locks'],
 'redis' => ['driver' => 'redis', 'connection' => 'cache', 'lock_connection' => 'default'],
 'file' => ['driver' => 'file', 'path' => storage_path('framework/cache/data'), 'lock_path' => storage_path('framework/cache/data')],
], 'prefix' => env('CACHE_PREFIX','carlink_cache_')];
