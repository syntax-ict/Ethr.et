# Attendance photo retention

The policy for attendance selfies, as built on 2026-10-17. A selfie is
biometric data under Proclamation 1321/2024 (Arts. 2(5), 2(6)), so it is kept
only as long as attendance needs it and seen only by the people who check
attendance. Consent to take it is a separate matter:
[`ATTENDANCE-PHOTO-CONSENT.md`](ATTENDANCE-PHOTO-CONSENT.md).

## The policy

| Step | What happens |
|---|---|
| Capture | The phone shrinks the selfie to at most 1280 px and 1.5 MB, stepping JPEG quality and then size down until it fits (`src/src/lib/uploads/selfie.ts`). The server refuses a phone selfie over 1.5 MB and stores it re-encoded at 1080 px, about 200 KB |
| Keep | For the organisation's retention period after the record's last punch: **7 days** unless changed in Attendance settings (1 to 90 days, `attendance_settings.photo_retention_days`) |
| Notify | When the period has passed, HR and the employee's department manager are told once, in one message each (in the app and by email), and a **48-hour** review opens |
| Hold | During or after the review, HR or the department manager can hold a record's photos for an unresolved complaint, with a reason. A held photo is never deleted. **Only HR releases a hold**, and a released photo is announced again before it is deleted |
| Delete | When the review ends, the photo files of every unheld record are permanently deleted. The attendance record, its check-in and check-out times, its status and everything payroll reads stay. Each deletion is an audit-log entry, `attendance.photos_deleted` |

Every view of a selfie is an audit-log entry too, `attendance.photo_viewed`,
as are holds and releases (`attendance.photo_held`,
`attendance.photo_hold_released`).

## Who sees a selfie

The `attendance.viewPhotos` ability, granted to tenant admins, HR admins and
department admins, and to custom roles that include it. It reaches exactly
the employees the holder's scope reaches: HR the whole organisation, a
department admin their own department. Supervisors and finance do not hold
it. The page is **Attendance, Photos** (`/attendance/photos`).

| Endpoint | Rule |
|---|---|
| `GET /attendance/photos` | Records with a selfie in the caller's scope. `status=review` (due for deletion) or `status=held` |
| `GET /attendance/photos/{record}/{check-in\|check-out}` | The image, `Cache-Control: private, no-store`, audited. 404 outside the caller's scope |
| `POST /attendance/photos/{record}/hold` | Needs `reason` (3 to 500 characters) |
| `DELETE /attendance/photos/{record}/hold` | Needs `attendance.manage` (HR) as well |

## How it runs

`attendance:photo-retention`, hourly (`routes/console.php`), for every
operational organisation. Each run first deletes photos whose review ended,
then opens the review for photos past the retention period, so a photo is
never deleted in the run that announced it. It is idempotent: a record under
review is not announced twice, and a record with no photo has nothing to
delete. A photo that arrives after its record's photos were deleted (a late
offline check-out) goes through the whole cycle again.

Deletion of a photo is final; backups taken before it (`ethr:backup`) may
still hold a copy until they age out, which is the backup
retention, not this one.

## First run after deploying

Photos taken before this release have no retention marker. On the first run,
every photo already past its organisation's retention period enters the same
48-hour review, and HR and the managers are told, so **nothing is deleted
without notice**. Expect one larger message per reviewer on that day.

## Deploying it

Needs `migrate` (one migration adds `photo_retention_days` to
`attendance_settings` and the review, hold and deletion columns to
`attendance_records`) and `seed` (the `attendance.viewPhotos` ability), then
`clear` and `optimize`. The consent notice changed with this release, so its
version is `2026-10-17.2` and employees are asked to agree again before their
next selfie.
