#!/bin/bash
# Creates the test database alongside the development one.
#
# Runs only on FIRST initialisation of an empty postgres data volume.
# omnihear (dev) is created by POSTGRES_DB; this adds omnihear_test,
# which phpunit.xml targets so that RefreshDatabase never truncates the
# development database.
#
# Temporary per-agent databases (test_tmp_<suffix>) are created and
# dropped explicitly by name at run time — never by wildcard.

set -euo pipefail

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-EOSQL
    CREATE DATABASE omnihear_test OWNER $POSTGRES_USER;
EOSQL

echo "init-test-db: omnihear_test created"
