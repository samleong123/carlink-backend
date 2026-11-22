<?php
return [
 'default' => env('DB_CONNECTION', 'pgsql'),
 'connections' => [
  'pgsql' => ['driver' => 'pgsql', 'url' => env('DATABASE_URL'), 'host' => env('DB_HOST','127.0.0.1'), 'port' => env('DB_PORT','5432'), 'database' => env('DB_DATABASE','carlink'), 'username' => env('DB_USERNAME','carlink'), 'password' => env('DB_PASSWORD',''), 'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true, 'search_path' => 'public', 'timezone' => 'UTC', 'sslmode' => env('DB_SSLMODE','prefer')],
  'logging' => ['driver' => 'pgsql', 'host' => env('LOG_DB_HOST','127.0.0.1'), 'port' => env('LOG_DB_PORT','5432'), 'database' => env('LOG_DB_DATABASE','carlink_logs'), 'username' => env('LOG_DB_USERNAME','carlink_logs'), 'password' => env('LOG_DB_PASSWORD',''), 'charset' => 'utf8', 'prefix' => '', 'prefix_indexes' => true, 'search_path' => 'public', 'timezone' => 'UTC', 'sslmode' => env('LOG_DB_SSLMODE','prefer')],
  'sqlite' => ['driver' => 'sqlite', 'url' => env('DB_URL'), 'database' => env('DB_DATABASE', database_path('database.sqlite')), 'prefix' => '', 'foreign_key_constraints' => true],
 ],
 'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
 'redis' => ['client' => env('REDIS_CLIENT','phpredis'), 'options' => ['cluster' => 'redis', 'prefix' => env('REDIS_PREFIX','carlink_database_')], 'default' => ['url' => env('REDIS_URL'), 'host' => env('REDIS_HOST','127.0.0.1'), 'username' => env('REDIS_USERNAME'), 'password' => env('REDIS_PASSWORD'), 'port' => env('REDIS_PORT','6379'), 'database' => env('REDIS_DB','0')], 'cache' => ['host' => env('REDIS_HOST','127.0.0.1'), 'username' => env('REDIS_USERNAME'), 'password' => env('REDIS_PASSWORD'), 'port' => env('REDIS_PORT','6379'), 'database' => env('REDIS_CACHE_DB','1')]],
];
