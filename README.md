# TRODDAC

Web-based operations, passenger booking and automatic driver route assignment portal.

## Setup (XAMPP)
1. Copy this folder into `htdocs` (for example `D:\xampp\htdocs\troddac`).
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. In a terminal inside the folder:
   ```
   D:\xampp\php\php.exe database\migrate.php migrate
   D:\xampp\php\php.exe database\migrate.php seed
   ```
4. Open `http://localhost/troddac/` and log in.

## Sample logins
| Role | Username | Password |
|---|---|---|
| Association Officer | officer1 | officer123 |
| Operator | operator1 | operator123 |
| Driver | driver1 .. driver4 | driver123 |
| Passenger | passenger1, passenger2 | passenger123 |

Change these passwords (My account page) before using real data.

## Tests
```
D:\xampp\php\php.exe database\verify.php                                  # 45 database tests
D:\xampp\php\php.exe tests\phase2_http_test.php http://localhost/troddac  # 71 login / role tests
```
Both clean up after themselves. Use the real folder name in the URL.

## Accounts and roles
- Passengers register themselves at `register.php`. Registration can only create passengers.
- Drivers, operators and officers are created by an Association Officer in **Users**.
- The login uses CSRF tokens, bcrypt, a 15-minute lockout after 5 failed attempts, a 30-minute idle timeout, and re-checks the account on every request (disabling a user ends their session at once).
- The database refuses to disable, demote or delete the last active officer.

See `database/README.md` for the schema and conflict-prevention rules.
