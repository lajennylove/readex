# MdxStorage — Android

Kotlin twin of `resources/ios/MdxStorageFunctions.swift`.

## Bridge functions

Registered in `nativephp.json`; each PHP-side call lands on a class in
`MdxStorageFunctions`.

| Bridge name                   | Kotlin class                                | Returns                                   |
| ----------------------------- | ------------------------------------------- | ----------------------------------------- |
| `MdxStorage.PreferredLanguage`| `MdxStorageFunctions.PreferredLanguage`     | `{ "language": "<BCP-47 tag>" }`          |
| `MdxStorage.TimeZone`         | `MdxStorageFunctions.TimeZone`              | `{ "zone": "<IANA id>" }`                 |
| `MdxStorage.CachesPath`       | `MdxStorageFunctions.CachesPath`            | `{ "path": "<absolute cacheDir/readex>" }`|
| `MdxStorage.ExcludeFromBackup`| `MdxStorageFunctions.ExcludeFromBackup`     | `{ "excluded": true }` (no-op; see below) |

## Backup exclusion is app-wide on Android

Android has no per-file `isExcludedFromBackup` flag. `ExcludeFromBackup` therefore
exists only so the PHP-side bridge call succeeds without platform branching — it does
not touch the file.

Real opt-out happens through `backup_rules.xml` (shipped alongside this README). The
host app's generated `AndroidManifest.xml` must reference it:

```xml
<application
    android:fullBackupContent="@xml/backup_rules"
    ...>
```

The file lands under `app/src/main/res/xml/backup_rules.xml` of the generated Android
project. `php artisan native:install android` is expected to copy this plugin's
`resources/android/backup_rules.xml` into that location (Phase A1, Jenny's manual
step verifies).

## Minimum SDK

API 24 — required for `Configuration.getLocales().get(0)` and
`java.util.Locale.toLanguageTag()` used by `PreferredLanguage`. Declared in
`nativephp.json` as `android.min_version: 24`.
