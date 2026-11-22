#!/bin/sh
set -eu
# Runs only on the first empty PostgreSQL data volume. Secrets come from container environment, never source.
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres \
  --set=app_user="$DB_USERNAME" --set=app_password="$DB_PASSWORD" --set=app_database="$DB_DATABASE" \
  --set=log_user="$LOG_DB_USERNAME" --set=log_password="$LOG_DB_PASSWORD" --set=log_database="$LOG_DB_DATABASE" <<'SQL'
CREATE ROLE :"app_user" LOGIN PASSWORD :'app_password';
CREATE DATABASE :"app_database" OWNER :"app_user";
CREATE ROLE :"log_user" LOGIN PASSWORD :'log_password';
CREATE DATABASE :"log_database" OWNER :"log_user";
REVOKE ALL ON DATABASE :"app_database" FROM PUBLIC;
REVOKE ALL ON DATABASE :"log_database" FROM PUBLIC;
SQL
