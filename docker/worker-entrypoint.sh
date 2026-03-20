#!/bin/sh
set -eu

mkdir -p /data

echo "[INFO] Waiting for MySQL at ${DB_HOST:-db}:${DB_PORT:-3306}"
until php -r '
$host = getenv("DB_HOST") ?: "db";
$port = getenv("DB_PORT") ?: "3306";
$user = getenv("DB_USER") ?: "";
$pass = getenv("DB_PASS") ?: "";

try {
    new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
'; do
  sleep 3
done

echo "[INFO] Bootstrapping database schema"
until php /usr/local/bin/proxbet/bootstrap-schema.php; do
  sleep 3
done

if [ -n "${BACK_START_ARGS:-}" ]; then
  exec sh -c "python3 /var/www/html/back_start.py ${BACK_START_ARGS}"
fi

exec python3 /var/www/html/back_start.py
