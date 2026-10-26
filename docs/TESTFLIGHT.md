# TestFlight launch checklist

This document is the step-by-step runbook for pushing the first ReaDex iOS build to TestFlight. It assumes an Apple Developer Program membership is active (confirmed 2026-10-25) and that chore/0025, feature/0028, and feature/0029 have landed on `main`.

Nothing in this document ships code. Every step is executed by a human on a Mac with Xcode installed and the Apple ID that owns the Developer Program membership signed in.

## 1. Pre-flight requirements

Before you touch the build, confirm every item in this list. Each one is a hard dependency; missing any one of them will stop the upload.

- **Apple Developer Program membership is active.** Visit [developer.apple.com/account](https://developer.apple.com/account) and verify that your team shows a current membership (not "Expired" or "Pending"). Note the **Team ID** (a 10-character alphanumeric string, e.g. `ABCDE12345`). You will need it in step 2 and in `.env`.
- **Xcode 15 or newer is installed** and launched at least once so the command-line tools and simulators are provisioned. `xcode-select -p` should point inside `/Applications/Xcode.app`.
- **Xcode is signed in with the Apple ID that owns the Developer Program membership.** Xcode -> Settings -> Accounts -> add your Apple ID -> confirm the team appears in the list and shows role "Account Holder" or "Admin".
- **App ID is registered.** Go to [developer.apple.com/account/resources/identifiers](https://developer.apple.com/account/resources/identifiers) and verify an App ID exists with Bundle ID `com.lajennylove.readex` (the value of `NATIVEPHP_APP_ID` in `.env` and `.env.example`). If it does not exist, create one: "App IDs" -> "+" -> "App" -> explicit Bundle ID `com.lajennylove.readex` -> description "ReaDex". No capabilities need to be enabled for the first build.
- **App record exists in App Store Connect.** Go to [appstoreconnect.apple.com](https://appstoreconnect.apple.com) -> My Apps -> "+" -> New App. Platform iOS, name "ReaDex" (or whatever final store name you choose), primary language English, Bundle ID = the App ID above, SKU any unique string (e.g. `readex-ios-001`), user access Full Access. You cannot upload a build until this record exists.
- **Distribution certificate and provisioning profile are available.** Xcode's automatic signing will create both on first archive if you let it. If you prefer to pre-create them, use Xcode -> Settings -> Accounts -> Manage Certificates -> "+" -> Apple Distribution. The provisioning profile is created automatically the first time you Archive with a Distribution signing identity selected.

## 2. Project configuration before the build

Edit these values on `main` (via a PR; `main` is protected) before the first build.

- **`config/nativephp.php` version.** The default is `env('NATIVEPHP_APP_VERSION', 'DEBUG')`. For TestFlight the marketing version (`CFBundleShortVersionString`) must be a real SemVer-ish string, e.g. `0.1.0`. Set `NATIVEPHP_APP_VERSION=0.1.0` and `NATIVEPHP_APP_VERSION_CODE=1` in `.env.production` (or whatever env file the build picks up). Both values are read by the NativePHP packager.
- **Team ID.** Set `NATIVEPHP_DEVELOPMENT_TEAM=<your 10-char Team ID>` in `.env.production`. The packager writes this into the Xcode project. If left blank, Xcode will refuse to sign in CI-style automation and will prompt interactively when you Archive.
- **Bundle ID.** Confirm `NATIVEPHP_APP_ID=com.lajennylove.readex` matches the App ID registered in step 1. These two strings must be byte-for-byte identical.
- **Export compliance.** Add `ITSAppUsesNonExemptEncryption = false` to the Info.plist so App Store Connect does not block the build on an encryption questionnaire. The file lives at `nativephp/ios/NativePHP/Info.plist`. ReaDex uses only standard TLS via the system networking stack, which qualifies for the exemption.
- **Privacy manifest.** iOS 17+ requires a `PrivacyInfo.xcprivacy` for the app bundle (not just vendored SDKs). Verify one exists under `nativephp/ios/NativePHP/`. The build log shows that `ZIPFoundation` ships one; the app's own manifest must declare required-reason APIs (file timestamp, user defaults, system boot time, disk space — the usual four). If the file is missing, add a minimal one declaring the four categories with reason code `CA92.1` (the catch-all "declared app functionality" reason) before the first upload. Apple will warn at upload time if it is missing.
- **App icon and launch screen.** Verify `public/icon.png` is at least 1024x1024 (App Store icon requirement). The Android pipeline consumes the same source; iOS will fail validation if the icon has an alpha channel — flatten it against a solid background first.

## 3. Build steps

The commands below assume you are at the repo root with a clean working tree on `main`.

```bash
# 1. Install PHP + JS dependencies for a production bundle.
composer install --optimize-autoloader --no-dev --prefer-dist --no-interaction
npm ci
npm run build

# 2. Hand off to NativePHP Mobile. The packager prepares the Xcode project,
#    writes versions, and runs the archive/export pipeline.
php artisan native:package ios --build-type=release --export-method=app-store
```

Key notes about `native:package ios`:

- The command is defined in `vendor/nativephp/mobile/src/Commands/PackageCommand.php` (signature `native:package {platform?}` with `--ios` as a shorthand). It is the direct iOS counterpart to the Android `php artisan native:package android --keystore=...` invocation already used on this project.
- `--export-method=app-store` is the correct value for TestFlight and App Store distribution. `ad-hoc` is for device-restricted internal builds; `development` is for local Xcode runs.
- The Xcode project the packager writes/updates lives at `nativephp/ios/NativePHP.xcworkspace`. CocoaPods dependencies (`Pods/`) and the workspace structure are already in place from previous local builds.
- `--validate-only` is useful on a dry run: it archives and validates against App Store Connect's rules but does not export the `.ipa`.
- The packager accepts `--upload-to-app-store` plus `--api-key`/`--api-key-id`/`--api-issuer-id` for fully automated upload via App Store Connect's API. **For the first build, do not pass these.** Upload via Xcode Organizer (step 4) so you can see every validation message in a GUI.
- `--clean-caches` nukes Xcode's DerivedData and the SPM cache. Only use it if a build mysteriously fails after a dependency change.

If `native:package ios` cannot complete the archive non-interactively (common on first-run because signing certificates have not been cached), it will exit after preparing the project. Open the workspace and finish the archive manually:

```bash
php artisan native:open ios   # opens nativephp/ios/NativePHP.xcworkspace in Xcode
```

## 4. Archive and upload via Xcode

With `nativephp/ios/NativePHP.xcworkspace` open in Xcode:

1. Select the **NativePHP** scheme in the scheme picker (top of the window, next to the run/stop buttons).
2. Set the run destination to **Any iOS Device (arm64)**. You cannot archive against a simulator.
3. **Signing & Capabilities** -> confirm:
   - "Automatically manage signing" is checked.
   - Team is set to your Apple Developer team (the one with Team ID matching `NATIVEPHP_DEVELOPMENT_TEAM`).
   - Bundle Identifier = `com.lajennylove.readex`.
   - Provisioning Profile shows "Xcode Managed Profile" and Signing Certificate shows "Apple Distribution".
4. Product -> **Archive**. Build takes a few minutes.
5. When the Organizer window opens with the new archive, click **Distribute App** -> **App Store Connect** -> **Upload** -> accept defaults -> **Next** through the signing summary -> **Upload**.
6. Xcode validates and uploads. On success you will see "Upload Successful". If validation fails, Xcode shows the exact rejection reason — the most common first-upload failures are missing export compliance (fixed by the Info.plist key in step 2), missing privacy manifest, or a bundle ID mismatch.

## 5. TestFlight setup

Once the upload lands, App Store Connect takes 10-30 minutes to process the build. You will receive an email when it is ready to distribute.

### Internal testing (immediate)

1. App Store Connect -> My Apps -> ReaDex -> TestFlight tab.
2. Internal Testing -> "+" next to "Testers" (left sidebar) -> create a group named "Internal" if none exists.
3. Add your own Apple ID (and anyone else on the team you want to test). Internal testers must have a role on your App Store Connect team — add them under Users and Access first if needed.
4. Assign the processed build to the group. Testers get an email with a TestFlight redemption link within a minute.

Internal testing requires **no App Store review** and supports up to 100 testers across up to 25 team members.

### External testing (24 hour review)

1. External Testing -> create a group -> add testers by email (they do not need an Apple Developer account).
2. Assign the build. The first build for external testing requires Beta App Review, which typically takes 24 hours. Subsequent builds in the same major version usually skip review.
3. External testing supports up to 10,000 testers per build. Builds expire 90 days after upload.

## 6. Pre-submission gotchas specific to ReaDex

- **Copy neutrality applies to the store listing.** `CLAUDE.md` section 6 and `docs/COPY-NEUTRALITY.md` describe the in-app policy; the App Store Connect listing must obey the same rule. The app description, promotional text, keywords, screenshots, and screen recordings must not name any specific third-party content site (manga aggregator, streaming service, piracy host). Any such mention is a near-guaranteed App Store rejection and would also contradict the EULA the app shows on first launch.
- **Suggested description text** (safe default; adapt as you like):
  > ReaDex is a reader client for user-configured content sources. On first launch you provide the URL of a source you choose; ReaDex fetches, caches, and renders what that source returns. The app ships with no catalog, no bundled content, and no suggested sources. All content stays on your device.
- **No pre-filled catalog explanation.** Because ReaDex has no built-in content, the first screen a normal user sees is first-run Setup asking for a source URL. For App Review we cut a review build that points the app's single API endpoint at a demo GitHub Pages site via `SOURCE_API_URL` / `SOURCE_COVERS_URL`; the reviewer never has to touch Setup. See section 10 for the full flow.
- **Reviewer demo account.** Not applicable — ReaDex has no accounts. Leave the demo account fields blank and say so in the Notes field.
- **Age rating.** The questionnaire should be answered honestly for user-generated content: the app does render content from a URL the user supplies, so declare "Unrestricted Web Access = Yes" and "User-generated content = No" (ReaDex does not host or share user content). Expect a 17+ rating as a consequence of unrestricted web access. This is the correct and honest answer.
- **Privacy nutrition label.** ReaDex collects no data (no accounts, no analytics, no telemetry — see `CLAUDE.md` section 5). Select "Data Not Collected" on every category. If a future release adds opt-in telemetry, update the label.
- **Export compliance.** With the Info.plist key from step 2, App Store Connect auto-approves export compliance without a questionnaire.

## 7. First-build sanity checklist

Tick these off before Archive.

- [ ] `./vendor/bin/pest --parallel` is green on `main`.
- [ ] `./vendor/bin/pint --test` is green.
- [ ] `tests/Feature/CopyNeutralityTest.php` is green (covered by the suite above but worth a direct eye).
- [ ] `NATIVEPHP_APP_VERSION` and `NATIVEPHP_APP_VERSION_CODE` are set to the intended values in `.env.production`.
- [ ] `NATIVEPHP_DEVELOPMENT_TEAM` is set to your 10-character Apple Team ID.
- [ ] `.env.production` has `APP_ENV=production` and `APP_DEBUG=false`.
- [ ] `php artisan native:jump` works end-to-end against a local device or simulator — if the PHP side crashes locally it will crash on TestFlight too.
- [ ] Bundle Identifier in Xcode matches `com.lajennylove.readex` and matches the App ID in App Store Connect.
- [ ] "Automatically manage signing" is checked, Team is correct, Signing Certificate is "Apple Distribution".
- [ ] `ITSAppUsesNonExemptEncryption = false` is in `nativephp/ios/NativePHP/Info.plist`.
- [ ] `PrivacyInfo.xcprivacy` exists in the app target.
- [ ] App icon is 1024x1024, no alpha channel.

## 8. Debug and rollback hints

- **Build log.** `native:package` writes to `nativephp/ios-build.log`. Check it first on any failure.
- **TestFlight-processed version crashes on open.**
  1. Open Xcode -> Window -> Devices and Simulators -> your device.
  2. Click "View Device Logs" to see crash reports. Filter by "ReaDex" or the bundle ID.
  3. Alternative: Console.app -> select your device in the sidebar -> filter by process name.
  4. For PHP-side issues, the on-device Laravel log is at the app sandbox's `storage/logs/laravel.log`. Pull it off the device via Finder -> device -> Files -> ReaDex -> Documents.
- **Re-uploading a fix.** Every upload needs a unique `CFBundleVersion` (build number). Bump `NATIVEPHP_APP_VERSION_CODE` (e.g. `1` -> `2`) and re-run the packager. `CFBundleShortVersionString` (the marketing version) can stay the same; only the build number has to increase.
- **Rollback.** TestFlight builds cannot be "unshipped", but you can expire a build in App Store Connect -> TestFlight -> Build -> Expire. Testers lose access immediately. Upload a fixed build with a higher build number and distribute that instead.
- **If `native:package ios` fails on first invocation,** try the GUI path: `php artisan native:open ios` -> Product -> Archive. The GUI surfaces signing errors much more clearly than the CLI.

## 9. After the first upload lands

- Capture App Store Connect's processed build number and tag the commit it was built from: `git tag -a v0.1.0-testflight.1 -m "First TestFlight upload" && git push origin v0.1.0-testflight.1`. Tags on `main` only, per `CLAUDE.md` section 4.5.
- Open the next planning ticket for Beta App Review feedback (external testing) so you can turn around any requested changes quickly.

## 10. Demo content source for App Review

Apple's reviewer needs a working endpoint the moment they launch the app, or review will reject with "demo content missing". We serve one from GitHub Pages — a tiny invented catalog (three titles, two chapters each, placeholder SVG pages, no third-party content) at `demo-source/` on `feature/0034-demo-source-review-pages`. The review build points `config('source.api_url')` at that site, so the reviewer never touches Setup — the app just works.

### Enable Pages

1. Make sure `feature/0034-demo-source-review-pages` is pushed to GitHub.
2. GitHub repo -> Settings -> Pages -> Source: "Deploy from a branch". Branch: `feature/0034-demo-source-review-pages`. Folder: `/demo-source`.
3. Wait for Pages to publish. The published root is `https://<owner>.github.io/<repo>/demo-source/`.
4. The demo endpoints live under `.../demo-source/api` (JSON catalog + chapter responses) and the assets under `/covers`, `/data`, `/data-saver`.

### Cut the review build

In the environment used by the review build (`.env.production`, or whatever you deploy with):

    SOURCE_API_URL=https://<owner>.github.io/<repo>/demo-source/api
    SOURCE_COVERS_URL=https://<owner>.github.io/<repo>/demo-source/covers
    SOURCE_REPORT_URL=https://<owner>.github.io/<repo>/demo-source/report

Everything else stays identical to the normal production build. Nothing in the app's UI changes — the reviewer sees the catalog on first launch because the config now points at the demo endpoint.

The regular production build leaves those env vars unset; `config/source.php` falls back to its production defaults.

### What to put in App Review Information

In App Store Connect -> App Information -> App Review Information -> **Notes**:

> This build is configured against a demo content source hosted on GitHub Pages, included so you can exercise the app without any setup. Launch the app, dismiss the EULA on first run, and you will see three placeholder titles. Open any title, tap Download, then Read. The demo exercises the catalog, chapter list, download, and reader flows. No account or login is required. The production build will ask the user to supply their own content source URL on first run; this is explained in the EULA and in the app description.

### How the single-endpoint source works end-to-end

- `source.api_url` is the one hostname the HTTP client talks to. Setting `SOURCE_API_URL` swings every request at the demo origin.
- The demo's `at-home/server/<chapter>` JSON returns an empty `baseUrl`. `AtHomeRepository::resolveBaseUrl()` falls back to `source.api_url` when the response is empty or non-absolute, so chapter images resolve to the Pages origin automatically. No deploy-time URL rewriting of the demo JSON needed.
- Covers come from `source.covers_url`; set that env var to the Pages `/covers` path and the thumbnail pipeline uses them unchanged.
