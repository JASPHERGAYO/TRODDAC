# TRODDAC database (Phase 1)

Database name: `troddac` (change it in `config/config.php`).

## Run it (XAMPP)
1. Start **Apache** and **MySQL** in the XAMPP Control Panel.
2. From the project folder:
   ```
   C:\xampp\php\php.exe database\migrate.php migrate     # create DB + tables, triggers, views
   C:\xampp\php\php.exe database\migrate.php seed        # sample users, routes, fares
   C:\xampp\php\php.exe database\verify.php              # 45 self-tests (rolled back)
   ```
   or in the browser (localhost only): `http://localhost/troddac/database/migrate.php?action=migrate`
3. Start over: `migrate.php fresh --yes` (drops the `troddac` database first).

## Sample logins (seed)
officer1 / officer123 · operator1 / operator123 · driver1..4 / driver123 · passenger1..2 / passenger123

## Tables
users, terminals, tricycle_units, drivers, driver_schedules, routes, route_schedules, rotation_settings,
driver_route_eligibility (rotation queue), rotation_pointers, fare_matrices, bookings, booking_status_history,
driver_locations, route_assignments, route_assignment_history.
Views: v_driver_board, v_assignment_overview, v_active_trips.

## Rules enforced by the database
- Only one active fare matrix; fares are calculated by `fn_calculate_fare()` (never trusted from the browser).
- A driver or unit can be in only one live passenger trip.
- A driver on a live trip cannot be given an overlapping official route, and a driver with an overlapping route cannot be given a trip (same for units).
- Route assignments need: active route, eligible driver, inside the driver's schedule windows (if any), no overlaps, same-day.
- Driver status `on_trip` is set only by a live booking and cannot be changed mid-trip.
- Booking status follows a fixed lifecycle; finished bookings/assignments are locked.
- Set `SET @app_user_id = <user id>;` on each connection so history rows record who acted.

Errors come back as SQLSTATE 45000 with a `TRD-Exxx:` prefix (listed at the top of migrations 005 and 006).
