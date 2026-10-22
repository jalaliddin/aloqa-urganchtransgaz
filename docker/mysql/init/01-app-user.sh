#!/bin/bash
# Runs once, on first initialisation of the MySQL data volume.
# - creates the application database
# - creates a restricted application user when DB_USERNAME is not root
# - in non-production environments, creates <db>_test for the automated test suite
set -euo pipefail

db="${DB_DATABASE:?DB_DATABASE is required}"
user="${DB_USERNAME:-root}"
pass="${DB_PASSWORD:-}"
test_db="${db}_test"

sql="CREATE DATABASE IF NOT EXISTS \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

if [ "${APP_ENV:-local}" != "production" ]; then
    sql+="CREATE DATABASE IF NOT EXISTS \`${test_db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
fi

if [ "${user}" != "root" ]; then
    sql+="CREATE USER IF NOT EXISTS '${user}'@'%' IDENTIFIED BY '${pass}';"
    sql+="GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, CREATE TEMPORARY TABLES, LOCK TABLES, TRIGGER ON \`${db}\`.* TO '${user}'@'%';"
    if [ "${APP_ENV:-local}" != "production" ]; then
        sql+="GRANT ALL PRIVILEGES ON \`${test_db}\`.* TO '${user}'@'%';"
    fi
    sql+="FLUSH PRIVILEGES;"
fi

mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" -e "${sql}"
echo "[mysql-init] database '${db}' ready (user: ${user})"
