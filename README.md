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
D:\xampp\php\php.exe tests\phase3_http_test.php http://localhost/troddac  # 67 booking / dispatch / driver tests
D:\xampp\php\php.exe tests\phase4_engine_test.php http://localhost/troddac # 50 route assignment engine tests
D:\xampp\php\php.exe tests\phase5_http_test.php http://localhost/troddac  # 84 accounts / reports / fare rate tests
```
They clean up after themselves (the Phase 3 test needs the database to have no open bookings). Use the real folder name in the URL.
The Phase 3 test works offline too: without internet the road distance falls back to a straight-line estimate.

## Booking flow (Module A)
1. **Passenger** (`passenger_book.php`): taps *Use my location* (GPS) or the map for the pickup, picks the destination on the map or by search, sees the road route, distance and fare, then requests a tricycle. Only one open booking at a time.
2. **Operator** (`operator_dispatch.php`): the request appears within a few seconds with a sound. The operator picks a driver (nearest first) and a unit and assigns it.
3. **Driver** (`driver_dashboard.php`): goes *Online* (this starts GPS sharing). The assigned booking appears on the dashboard with the passenger, pickup, drop-off, route map and buttons: *On my way*, *Picked up*, *Decline*, *Complete trip*.
4. **Passenger** (`passenger_trip.php`): follows the status and the tricycle's live position on the map, and can cancel until the trip starts.

## Accounts, reports and fare rates (Phase 5)
- **Passenger sign-up** (`register.php`, public): First name, M.I., Last name, username, email, mobile, password. Officers cannot create passengers; passengers create their own.
- **Officer: Users** (`officer_users.php`; `officer_user.php` forwards to it): add drivers, operators and officers with First name, Last name, Middle initial and **Tricycle unit no.** (a text box; if the unit does not exist yet it is created, and a unit can belong to one driver only). Disable/enable and reset passwords from a confirmation sheet. On phones the list is a stack of cards and the forms open as bottom sheets.
- **No duplicate accounts:** username, email, phone (0917..., +63917... count as the same), identity (same first name + last name + middle initial), driver's license and unit are all checked *before* saving, with a plain message for each; unique indexes in the database catch two people submitting at the same instant.
- **Passenger reports:** passengers use **Report** (up to 5 a day, same text cannot be sent twice). The officer sees them on the **officer dashboard** and on **Reports**, where they can set *In review / Resolved / Dismissed* and reply; the passenger sees the status and reply.
- **Fare rates** (officer: **Fares**): the price is a **rate per km** (optionally with a starting fare covering the first km, and a minimum fare). One rate is active at a time; rates already used by bookings cannot be edited (copy them to make a new version); a live preview shows the price for 1, 2, 3, 5 and 10 km.
- **Database safety:** every query uses prepared statements; migrations check what already exists before changing anything (`011_names_and_units.php` is safe to run twice); and screens that need a new table or column explain "database is out of date, run migrate" instead of crashing.

## Automatic route assignment (Module B)
- The Association Officer's routes, schedules and driver queues live in the database (sample data included). The engine, `lib/AssignmentEngine.php`, gives each route slot to the next driver in a fair rotation.
- A driver is skipped (and keeps their turn) if they are outside their schedule windows, not eligible, resting, over the daily limit, offline when the route is about to start, on a passenger trip, or if the tricycle unit is busy.
- The database refuses any assignment that overlaps a passenger trip or another assignment, so Module A and Module B can never double-book a driver. A driver who is still on a passenger trip when their route starts is marked *missed* and the next driver covers the slot.
- **Running it:**
  - Automatically: it runs about once a minute while any driver or operator screen is open.
  - Hands-free: in Windows Task Scheduler create a task that repeats every minute and runs `D:\xampp\php\php.exe D:\xampp\htdocs\troddac\engine\run.php`.
  - By hand: the officer's *Run assignment now* button on the **Routes** screen, or `php engine\run.php`.
- Drivers see their route, terminals and route line on their dashboard. Operators and officers see every assignment and why the engine skipped a driver on the **Routes** screen.
- While a driver has an official route (or one about to start) they are not offered for passenger trips.

## If something says "database is out of date"
After you copy new files, run `D:\xampp\php\php.exe database\migrate.php migrate` (use `migrate.php status` to see what is pending). Staff also see a red banner when migrations are pending.

## Things to know
- **Internet is needed** for the map tiles (OpenStreetMap), address search (Nominatim) and road routing (OSRM demo server). Leaflet itself is bundled in `assets/vendor/leaflet`. Change the routing server in `config/config.php`.
- **GPS only works on https or localhost.** On the XAMPP computer use `http://localhost/troddac`. To test on a phone over Wi-Fi use `https://<computer-ip>/troddac` (XAMPP's built-in https, accept the certificate warning). Without GPS the passenger can still tap the map and the driver can still be dispatched, but the driver's position is not shared.
- Bookings must be inside the 25 km service circle around Dasmariñas (`service_area` in `config/config.php`).
- Dispatch is manual by the operator, as in the specification. Drivers cannot pick up requests themselves.
- The screens update by polling every 4 seconds.

## Accounts and roles
- Passengers register themselves at `register.php`. Registration can only create passengers.
- Drivers, operators and officers are created by an Association Officer in **Users**.
- The login uses CSRF tokens, bcrypt, a 15-minute lockout after 5 failed attempts, a 30-minute idle timeout, and re-checks the account on every request (disabling a user ends their session at once).
- The database refuses to disable, demote or delete the last active officer.

See `database/README.md` for the schema and conflict-prevention rules.
