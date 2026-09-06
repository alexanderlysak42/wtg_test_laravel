#!/bin/bash
set -e

mysql -u root -p"${MYSQL_ROOT_PASSWORD}" <<-EOSQL
    CREATE DATABASE IF NOT EXISTS wtg_test_testing;
    GRANT ALL PRIVILEGES ON wtg_test_testing.* TO '${MYSQL_USER}'@'%';
    FLUSH PRIVILEGES;
EOSQL
