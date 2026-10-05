"""
Set a LOCAL admin account's password -- your XAMPP database only.
Default account admin@remedi.com; pass another email to choose it.

The password is typed into this prompt (hidden), hashed by PHP with bcrypt,
and only the hash is written to `users.password`. It is never printed, logged
or stored anywhere else. Never run this against the live database.

    python docs/forecast-study/set_local_admin_password.py
    python docs/forecast-study/set_local_admin_password.py rehearsal-admin@remedi.test
"""
import getpass
import os
import subprocess
import sys

import pymysql

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, "..", "..", "resources", "python"))
import generate_forecasts as gf  # noqa: E402

EMAIL = sys.argv[1].strip().lower() if len(sys.argv) > 1 else "admin@remedi.com"
e = gf.db_credentials(os.path.join(HERE, "..", "..", ".env"))

host = e.get("DB_HOST", "127.0.0.1")
if host not in ("127.0.0.1", "localhost", "::1"):
    raise SystemExit(f"Refusing: DB_HOST is {host}, not this machine. This script is for the local database only.")

pw = getpass.getpass(f"New password for {EMAIL} (typing is hidden): ")
if len(pw) < 8:
    raise SystemExit("At least 8 characters, please. Nothing changed.")
if pw != getpass.getpass("Type it again: "):
    raise SystemExit("The two entries differ. Nothing changed.")

hashed = subprocess.run(
    ["php", "-r", "echo password_hash(rtrim(stream_get_contents(STDIN), \"\\r\\n\"), PASSWORD_BCRYPT, ['cost' => 12]);"],
    input=pw, capture_output=True, text=True, check=True,
).stdout.strip()
if not hashed.startswith("$2y$"):
    raise SystemExit("PHP did not return a bcrypt hash. Nothing changed.")

conn = pymysql.connect(host=host, port=int(e.get("DB_PORT", 3306)), user=e.get("DB_USERNAME"),
                       password=e.get("DB_PASSWORD"), database=e.get("DB_DATABASE"))
with conn.cursor() as cur:
    n = cur.execute(
        "UPDATE users SET password = %s, must_change_password = 0, password_reset_requested_at = NULL "
        "WHERE email = %s AND archived_at IS NULL", (hashed, EMAIL))
conn.commit()
conn.close()
print(f"Done: {EMAIL} on {e.get('DB_DATABASE')} @ {host} now uses the password you typed." if n
      else f"No active account {EMAIL} found. Nothing changed.")
