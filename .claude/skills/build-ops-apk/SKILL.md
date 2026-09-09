---
name: build-ops-apk
description: Build the release APK for the operations field app (OPERATION-MOBILE-APP / "My Jobs"). Use when asked to build, rebuild, or create the APK for the ops app, the field app, the mobile app, or the operations app. Covers the local Android build, the release keystore, and the checks that stop a broken APK reaching staff phones.
---

# Building the ops field app APK

The app in `OPERATION-MOBILE-APP/` is handed to cleaners and technicians as an
APK over WhatsApp. It does not go through the Play Store.

Build **locally**. The toolchain is installed on this Mac and a warm build takes
about two minutes.

There is no EAS path. Expo's build servers were set up once and then removed on
purpose: they hold their own release keystore, so an EAS APK is signed with a
different key and a phone carrying a locally built app refuses it as an update.
Do not re-introduce `eas.json` or suggest `eas build` — one build path means one
signing key.

## Before building

**1. Bump `versionCode`** in `OPERATION-MOBILE-APP/app.config.ts`.

Android refuses to install an APK carrying the same or a lower code than the one
already on the phone, and it fails in a way that looks to the person holding it
like the update simply did not happen. Do this every single build that gets
handed out.

**2. Typecheck** — two minutes here beats ten on a build that was never going to
work:

```bash
cd OPERATION-MOBILE-APP && npx tsc --noEmit
```

**3. Check the server half is real.** This app is one half of a feature; the
other half lives in `api/mobile/ops/`. An APK built against server code that is
still only on this machine fails *silently* — an unknown `tab` value, for
instance, falls back to `today` rather than erroring, so the new tab shows the
wrong jobs and nobody sees a failure. `git status` on the api/ and
modules/operations/ paths, and say what has to be uploaded.

## The build

```bash
cd /Users/ainalreem/Sites/saifsys/OPERATION-MOBILE-APP
export JAVA_HOME=/opt/homebrew/opt/openjdk@17
export ANDROID_HOME=/opt/homebrew/share/android-commandlinetools
export ANDROID_SDK_ROOT=$ANDROID_HOME
set -a && . ./.env.local && set +a
rm -rf android
npx expo prebuild -p android
echo "sdk.dir=$ANDROID_HOME" > android/local.properties
cd android && ./gradlew assembleRelease
```

Run it in the background and watch the log — a cold build is 10 minutes, a warm
one under 2.

Result: `android/app/build/outputs/apk/release/app-release.apk` (~127 MB).

### Why each line is there

- **`set -a && . ./.env.local`** — `OPS_APP_KEY` is read by `app.config.ts` and
  embedded at bundle time. Without it you get an APK that installs, opens, and
  401s on its first request. Not optional.
- **`rm -rf android` rather than `prebuild --clean`** — once a build has run,
  `android/app/build` holds output that prebuild's own delete chokes on:
  `ENOTEMPTY: directory not empty`. Removing the folder outright always works.
- **`local.properties` after prebuild** — prebuild deletes the folder it lives
  in, so writing it earlier accomplishes nothing.
- **`JAVA_HOME`** — `openjdk@17` is a keg-only Homebrew formula and is not on
  PATH. Gradle will not find Java without this.

## Verify before handing it over

Never report success from "BUILD SUCCESSFUL" alone. All three of these have
failed silently at least once:

```bash
A=OPERATION-MOBILE-APP/android/app/build/outputs/apk/release/app-release.apk

# 1. Signed with the real key, not React Native's public debug key.
#    Must read CN=AR Properties. CN=Android Debug means credentials/ was missing.
$ANDROID_HOME/build-tools/36.0.0/apksigner verify --print-certs $A | head -3

# 2. versionCode bumped, app key embedded, right server.
unzip -p $A assets/app.config | python3 -c "
import sys,json
d=json.load(sys.stdin); e=d.get('extra',{})
k=e.get('opsAppKey','')
print('versionCode  :', d.get('android',{}).get('versionCode'))
print('opsApiBaseUrl:', e.get('opsApiBaseUrl'))
print('opsAppKey    :', ('SET, %d chars' % len(k)) if k else 'EMPTY  <-- would 401')
"
```

## The keystore

`OPERATION-MOBILE-APP/credentials/` holds `release.jks` and its passwords. It is
gitignored and `.easignore`d. `plugins/withReleaseSigning.js` wires it in on
every prebuild — necessary because prebuild regenerates `android/` from a
template that signs release builds with React Native's **debug** key, whose
password is published in the RN docs.

If `credentials/` goes missing the build does not fail. It falls back to that
debug key and produces a working APK signed by the wrong thing, which is why the
`apksigner` check above matters.

The user backed this folder up off the machine on 2026-09-09. Do not nag about
it again; if the keystore is ever regenerated or moved, it needs backing up
afresh, because losing it means no future build can install as an update over
what is on staff phones — each phone would have to uninstall first.

## Known, not yet done

The APK is 127 MB because it ships native libs for four CPU architectures,
including x86 and x86_64 — emulator-only, ~55 MB of the total, no phone uses
them. Restricting to `arm64-v8a` gets it to roughly 70 MB. Offered and not taken
up; mention it if size comes up, do not do it unasked.
