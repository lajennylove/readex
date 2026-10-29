<p align="center">
  <img src="../demo-source/assets/readex-icon.png" width="96" alt="ReaDex">
</p>

# Play Store Internal Testing launch checklist

This document is the step-by-step runbook for pushing the first ReaDex Android build to Google Play's **Internal testing** track. It assumes the Google Play Console developer account identity verification has cleared and that a `.env` with the Android keystore paths is in place.

Nothing in this document ships code. Every step is executed by a human on a Mac with the Android SDK + command-line tools installed, plus `openssl`, `python3` (for the Play Developer API client), and either `fastlane` or the `google-api-python-client` package.

> **Why Internal and not Open?** Internal testing bypasses Google content review entirely and goes live within minutes of rollout. External and open testing require Beta App Review (~hours → days). Start Internal, graduate to Open when the first review clears.

## 1. Pre-flight requirements

- **Google Play Console developer account identity-verified.** Visit [play.google.com/console](https://play.google.com/console) and verify the top banner does NOT say "Google valide votre identité" / "Google is validating your identity". If it does, wait — nothing in this doc works until Google clears the ID check (2–7 days typical).
- **Contact phone number validated** in Account details → Developer contact info. The option only unlocks after identity clears.
- **An app record exists** on the Play Console: Dashboard → "Create app" → App name "ReaDex", default language, **App** (not Game), **Free**, confirm the two declarations. The `com.lajennylove.readex` package name is locked to the account on first upload — pick carefully.
- **A signing keystore exists on disk.** If you don't have one yet:
  ```bash
  mkdir -p ~/.keystores
  openssl rand -base64 24 | tr -d '/+=' | head -c 24 > ~/.keystores/readex-upload.pw
  chmod 600 ~/.keystores/readex-upload.pw
  PW=$(cat ~/.keystores/readex-upload.pw)
  keytool -genkeypair -v \
    -keystore ~/.keystores/readex-upload.jks \
    -alias readex-upload -keyalg RSA -keysize 2048 -validity 25000 \
    -storepass "$PW" -keypass "$PW" \
    -dname "CN=Your Name, OU=ReaDex, O=yourorg, L=City, ST=State, C=XX"
  ```
  **Back `readex-upload.jks` and `readex-upload.pw` up to a password manager.** Losing the keystore means never updating this app on Play again (recoverable through Play's upload-key reset flow, but a huge pain).
- **`.env` has the Android package values** (defaults are correct; adjust `NATIVEPHP_APP_VERSION_CODE` to the next integer above whatever's live on the Internal testing track):
  ```
  NATIVEPHP_APP_ID=com.lajennylove.readex
  NATIVEPHP_APP_VERSION=0.1.0
  NATIVEPHP_APP_VERSION_CODE=56
  ```

## 2. "Set up your app" dashboard — every row must be green

Play Console blocks Internal testing until every row under **Dashboard → Set up your app** shows a green tick. Each is a short form; this is where new accounts spend 30–60 min on the first app.

Fill each row with these answers (ReaDex is a URL-driven reader with no backend of its own):

- **Set privacy policy** → paste a public URL. Example in this repo: `https://lajennylove.github.io/readex/privacy-policy.html` — hosted from the `gh-pages` branch so it can be swapped by editing `demo-source/privacy-policy.html`.
- **Sign in details** → "No, my app does not require users to sign in to access its content".
- **Ads** → "No, my app does not contain ads".
- **Content ratings** → category "Reference, News, or Educational" → answer **No** to every violence / sexual / gambling / drug prompt. (ReaDex's render target is user-supplied; answer about **the app itself**, not what users might configure.)
- **Target audience** → age bands "13–15, 16–17, 18+" (uncheck 12 and under). "Does your app unintentionally appeal to children?" → **No**.
- **Data safety** → "Does your app collect or share any of the required user data types?" → **No**. All reading history + library + favourites stay in the app's own sandbox; nothing is sent to the developer.
- **Government apps / Financial features / Health** → "None of these are applicable" for each.
- **Select an app category and provide contact details** → category **Books & Reference**. Developer contact email required. Website optional (point at the landing page, `https://lajennylove.github.io/readex/`).
- **Set up your store listing** → App name, 80-char short description, 4000-char full description, **512×512 icon** (`nativephp/android/app/play-store-icon.png`), **1024×500 feature graphic** (`nativephp/android/app/play-feature-graphic.png`), minimum 2 phone screenshots (adb-capture from a configured Pixel/Bigme is ideal — see `~/Desktop/readex-play-screenshots/` from this project's screenshot pass).

## 3. Enable automated uploads via a Google Cloud service account (optional, recommended)

The first AAB upload MUST go through the Play Console UI once to enroll the app in **Play App Signing**. Every upload after that can be scripted via the Play Developer API. Set up the service account **before** the first upload so step 5 can use either path.

### 3a. Create the service-account project

Google's "Secure by Default" policy blocks service-account key creation inside Workspace-managed organizations. To avoid it, create the project **with no organization**.

1. [console.cloud.google.com/projectcreate](https://console.cloud.google.com/projectcreate) → Project name **deploy-automation** → Location → change to **"No organization"** → Create.
2. Switch into the new project via the project selector (top-left of the Cloud Console).
3. Enable the API: [console.cloud.google.com/apis/library/androidpublisher.googleapis.com](https://console.cloud.google.com/apis/library/androidpublisher.googleapis.com) → **Enable**.
4. Create the service account: [console.cloud.google.com/iam-admin/serviceaccounts/create](https://console.cloud.google.com/iam-admin/serviceaccounts/create) → name **readex-play-upload** → Done (skip the optional role + principal steps).
5. Open the service account → **Keys tab → Add Key → Create new key → JSON** → downloads `deploy-automation-xxxxxxxx.json`.
6. Move + lock down:
   ```bash
   mkdir -p ~/.google-play
   mv ~/Downloads/deploy-automation-*.json ~/.google-play/readex-play-upload.json
   chmod 600 ~/.google-play/readex-play-upload.json
   ```

### 3b. Grant the service account access to the Play Console app

1. [play.google.com/console/u/0/developers/<DEV_ID>/users-and-permissions](https://play.google.com/console) → **Invite new users**.
2. Email address: paste the `client_email` from the JSON (format `readex-play-upload@deploy-automation-xxxxxx.iam.gserviceaccount.com`).
3. **App permissions → Add app → tick ReaDex → Apply.**
4. In the ReaDex permissions popup, tick **"Admin (all permissions)"** (this scopes only to ReaDex, not the whole account) → Apply.
5. Click **Invite user → Send invite**. The service account appears under Users as "Active" within seconds.

### 3c. Smoke test the credential

```bash
python3 - << 'EOF'
import json, time, jwt, urllib.request
key = json.load(open('/Users/USERNAME/.google-play/readex-play-upload.json'))
now = int(time.time())
tok = jwt.encode(
    {'iss': key['client_email'],
     'scope': 'https://www.googleapis.com/auth/androidpublisher',
     'aud': 'https://oauth2.googleapis.com/token',
     'exp': now+3600, 'iat': now},
    key['private_key'], algorithm='RS256'
)
req = urllib.request.Request('https://oauth2.googleapis.com/token',
    data=f'grant_type=urn:ietf:params:oauth:grant-type:jwt-bearer&assertion={tok}'.encode(),
    headers={'Content-Type':'application/x-www-form-urlencoded'})
access_token = json.load(urllib.request.urlopen(req))['access_token']

req = urllib.request.Request(
    'https://androidpublisher.googleapis.com/androidpublisher/v3/applications/com.lajennylove.readex/edits',
    data=b'', method='POST',
    headers={'Authorization': f'Bearer {access_token}', 'Content-Type':'application/json'})
print(json.load(urllib.request.urlopen(req)))
EOF
```
A JSON response with an `id` and `expiryTimeSeconds` means the credential works. If it returns 403, the user permission in Play Console hasn't propagated yet — wait 2–5 min and retry.

## 4. Build the signed AAB

```bash
composer install --optimize-autoloader --no-dev --prefer-dist --no-interaction
npm ci && npm run build

PW=$(cat ~/.keystores/readex-upload.pw)
php artisan native:package android \
  --build-type=bundle \
  --keystore=/Users/USERNAME/.keystores/readex-upload.jks \
  --keystore-password="$PW" \
  --key-alias=readex-upload \
  --key-password="$PW" \
  --no-interaction --no-tty
```

Output: `nativephp/android/app/build/outputs/bundle/release/app-release.aab` (~38 MB).

**Gotcha — `--no-tty` is required.** Without it, NativePHP's TTY-mode detection raises `TTY mode requires /dev/tty to be read/writable` when called from any non-interactive shell (including a background job).

**Gotcha — `--build-type=bundle`, not `release`.** The default is `release` which produces an APK; Play Store requires an AAB.

## 5. First upload — manual, via Play Console UI

The first AAB upload per package MUST go through the Play Console UI to initialize **Play App Signing**. Later uploads can be fully automated (step 6).

1. Play Console → ReaDex → **Testing → Internal testing → Create new release**.
2. First time only: Play prompts "Choose a signing key" → pick **"Use Play App Signing"**. Google generates and holds its own signing key; your upload keystore just signs the upload. Permanent, recommended.
3. **App bundles → upload** `app-release.aab`.
4. Play reads version 0.1.0 (56) automatically from the AAB.
5. **Release name:** auto-fills to the build number ("56" or whatever versionCode you built with). Leave it.
6. **Release notes:** plain text, no `<en-US>` wrapper, max 500 chars. Example:
   ```
   First internal test build of ReaDex. Reader for a content source you provide; everything stored on-device.
   ```
7. **Save → Review release → Start rollout to Internal testing**. Confirm.

Three warnings on the review page are expected and non-blocking for Internal:
- *"No testers specified yet"* — fix in step 7.
- *"No R8/proguard deobfuscation file"* — not a problem; the app has little obfuscated code anyway.
- *"No native debug symbols"* — same.

## 6. Subsequent uploads — automated via Play Developer API

Once the app is enrolled in Play App Signing, every later upload can be scripted. Minimal Python (uses `google-api-python-client`, install via `pip3 install --user google-api-python-client google-auth`):

```python
#!/usr/bin/env python3
import sys
from googleapiclient.discovery import build
from googleapiclient.http import MediaFileUpload
from google.oauth2 import service_account

PACKAGE = 'com.lajennylove.readex'
TRACK   = 'internal'
AAB     = 'nativephp/android/app/build/outputs/bundle/release/app-release.aab'
NOTES   = sys.argv[1] if len(sys.argv) > 1 else 'Automated internal build.'

creds = service_account.Credentials.from_service_account_file(
    '/Users/USERNAME/.google-play/readex-play-upload.json',
    scopes=['https://www.googleapis.com/auth/androidpublisher'],
)
svc = build('androidpublisher', 'v3', credentials=creds)
edit = svc.edits().insert(packageName=PACKAGE, body={}).execute()
bundle = svc.edits().bundles().upload(
    packageName=PACKAGE, editId=edit['id'],
    media_body=MediaFileUpload(AAB, mimetype='application/octet-stream'),
).execute()
svc.edits().tracks().update(
    packageName=PACKAGE, editId=edit['id'], track=TRACK,
    body={'releases': [{
        'name': f"{bundle['versionCode']}",
        'versionCodes': [str(bundle['versionCode'])],
        'releaseNotes': [{'language': 'en-US', 'text': NOTES}],
        'status': 'completed',
    }]},
).execute()
print(svc.edits().commit(packageName=PACKAGE, editId=edit['id']).execute())
```

Save this as `scripts/play-upload.py`. One-liner uploads from then on:
```bash
python3 scripts/play-upload.py "Fixes the home screen crash on cold launch."
```

Alternative: `fastlane supply` is a mature CLI alternative that does the same thing. Install with `gem install fastlane`, then:
```bash
fastlane supply \
  --package_name com.lajennylove.readex \
  --json_key ~/.google-play/readex-play-upload.json \
  --aab nativephp/android/app/build/outputs/bundle/release/app-release.aab \
  --track internal
```

## 7. Give testers the opt-in link

Internal testing is **email-gated**: the public opt-in URL only installs for Google-account emails that are on your tester list.

1. Play Console → Internal testing → **Testers tab → Create email list** → name it e.g. "Core testers" → paste comma-separated Gmail addresses.
2. Back on the Testers tab, tick the new list under "Email list(s)" → **Save changes**.
3. Copy the **Opt-in URL** (`https://play.google.com/apps/internaltest/XXXXXXXXXXXXXXX`) → send it to testers.
4. Testers open the link on Android → tap **"Become a tester"** → tap **"Download it on Google Play"** → install.

Propagation of a newly-added tester email is 2–10 min globally. If a tester hits "You're not an authorized tester" on the first try, wait and retry.

## Pre-flight sanity checklist (ship or hold)

- [ ] Dashboard "Set up your app" — every row green.
- [ ] Keystore + password backed up to a password manager outside the Mac.
- [ ] `NATIVEPHP_APP_VERSION_CODE` strictly greater than the latest already on the Internal track.
- [ ] AAB builds without `--no-tty` errors.
- [ ] (If automating) `/_meta.json` smoke-test from §3c returns a JSON edit id, not 403.
- [ ] (First upload only) Play App Signing enrollment accepted during Create new release.
- [ ] Testers tab lists all intended Gmail addresses before you send the opt-in URL.

## Gotchas, in rough frequency order

- **`versionCode` must strictly increase per upload.** Play rejects duplicates with "Version code N has already been used." Bump `NATIVEPHP_APP_VERSION_CODE` before every build. iOS and Android version codes don't have to match — but keeping them in lockstep (both =N for every release) is a sanity-saver.
- **`--build-type=bundle` not the default.** Default is APK. Play requires AAB.
- **`--no-tty` required in non-interactive shells.** NativePHP's TTY detection fails without it in background jobs.
- **Upload keystore lost = app is dead.** Play's upload-key reset flow exists but takes days and requires proof of ownership. Back it up before the first upload.
- **minSdkVersion floor.** Play raises it ~yearly. Current floor is 23 (Android 6.0). NativePHP Mobile v4 already targets 24+ so this is handled.
- **Halt a rollout:** Internal testing → open the live release → "Halt rollout". Does not roll devices back; stops new installs. Fix the issue, bump versionCode, re-upload.
- **The service-account email appears briefly before Play Console's permissions UI accepts it.** If "Invite user" doesn't resolve the email, wait 1–2 min and retry — Google's directory takes a moment to propagate the SA across its auth systems.

## Links

- [Play Console](https://play.google.com/console) — the entry point.
- [Play App Signing — overview](https://support.google.com/googleplay/android-developer/answer/9842756).
- [Play Developer API — Edits.bundles.upload](https://developers.google.com/android-publisher/api-ref/rest/v3/edits.bundles/upload).
- [`google-api-python-client` reference](https://googleapis.github.io/google-api-python-client/docs/).
- [`fastlane supply`](https://docs.fastlane.tools/actions/supply/) — Ruby CLI alternative.
