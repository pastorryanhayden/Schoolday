# Dedicated Android tablet

## Device status

The target device is the **Lenovo TB-8506F / Tab M8 (3rd Gen)**, supplied by Ryan. Lenovo lists an 8-inch 1280 × 800 IPS touchscreen, 5 MP autofocus rear camera, 2 MP front camera, and 2 GB/3 GB RAM variants. The 2 GB version shipped with Android 11 Go; the 3 GB version with Android 11. RAM variant, installed OS, density/font scale, and browser version have not yet been read from this device. Sources: [Lenovo model reference](https://psref.lenovo.com/Detail/Tab_M8_3rd_Gen?M=ZA870021PE), [family datasheet](https://psref.lenovo.com/syspool/Sys/PDF/datasheet/Lenovo%20Tab%20M8%20%283rd%20Gen%29_datasheet_EN.pdf).

Lenovo lists security maintenance ending **March 31, 2024**, with no major OS upgrade planned for TB-8506F. A current browser does not replace missing OS security updates. Keep the student account on this tablet and avoid using a parent session or storing other sensitive accounts on it. Source: [Lenovo Android upgrade matrix](https://support.lenovo.com/pr/en//solutions/ht501098-android-upgrade-matrix).

The app uses a small Blade/Alpine frontend, sequential single-photo uploads, lazy-loading image previews, no continuous camera stream, and a persistent help/break bar at compact tablet widths. Checks cover 600 × 960 and 800 × 1280 portrait browser viewports; these are responsive simulations, not measurements of the tablet’s CSS viewport.

During development, the Mac’s USB inventory and `adb devices -l` returned no Android device. Actual camera capture, Chrome installability and kiosk escape behavior remain to be checked on the physical tablet. Responsive browser checks are recorded separately in the README.

The Android platform tools are available on this Mac at:

```sh
/Users/ryanhayden/Library/Android/sdk/platform-tools/adb devices -l
```

Unlock the tablet, connect with a data-capable USB cable, enable Developer options and USB debugging, and accept the Mac’s authorization prompt. The exact menu path depends on the tablet vendor and Android version. This enables development testing; disable debugging again when device setup is complete.

Once it shows as `device`, inspect it without changing system configuration:

```sh
adb shell getprop ro.product.manufacturer
adb shell getprop ro.product.model
adb shell getprop ro.build.version.release
adb shell wm size
adb shell wm density
adb shell dumpsys package com.android.chrome
```

Physical screen pixels and CSS pixels differ. Check `window.innerWidth`, orientation, and font scaling in the actual browser. The app adapts to phone, compact portrait tablet, and desktop widths, with larger form text and touch targets on smaller screens.

## Local USB preview

With the Laravel server on port 8000 and built assets (`npm run build`):

```sh
adb reverse tcp:8000 tcp:8000
adb shell am start -a android.intent.action.VIEW -d http://127.0.0.1:8000
```

On this Mac, substitute the absolute adb path above if adb is not on PATH. The reverse is temporary, depends on the USB debugging connection, and is not the school deployment. Loopback is useful for PWA development; plain HTTP to a LAN IP is not equivalent to a secure HTTPS deployment. Never bypass certificate warnings. For real use at school, deploy to a trusted HTTPS host reachable from school Wi-Fi.

## Initial setup

1. Update the OS and browser through their supported update mechanisms. Check that this particular tablet still receives security updates.
2. Open Schoolday’s trusted HTTPS address in Chrome or the tablet’s supported browser. Sign in as Noah, with “Keep me signed in” selected. Never save a parent login on his tablet.
3. Use the browser’s Install/Add to home screen option if offered. The exact label and installation behavior depend on browser version and whether the device includes Google services. The app works as a normal browser page too. See [Google’s manifest documentation](https://web.dev/articles/add-manifest).
4. Check the rear camera capture button and the separate gallery button. Take a full worksheet photo, confirm orientation and legibility, remove it, retake, then submit. Android’s native camera/file picker is used; the app does not keep a video stream open.
5. Test interrupted Wi-Fi: type a draft, select a photo, reconnect, retry upload, reload, and confirm recovery. Do not clear browser storage with pending work. Camera images over 10 MB must be reduced in the camera settings or exported smaller.
6. Test the software keyboard, text scaling, portrait/landscape rotation, back button, screen sleep/resume, and sending a contextual help request. Verify that the parent sees a check-in independently from an activity event.
7. Test the complete corrections loop on the real camera, with the parent using a different browser/session.

## Device-level kiosk setup

Installing the PWA or entering fullscreen **does not prevent exiting the application**. Server authorization prevents a student session from using parent controls regardless of kiosk settings.

For an initial supervised setup, Android offers app/screen pinning, with an option to require the device credential to unpin. The menu and supported navigation gestures vary by model and Android version; follow [Google’s app pinning instructions](https://support.google.com/android/answer/9455138?hl=en) and the manufacturer’s guide for the identified device. Keep that credential with the adults. Pinning Chrome can still permit navigation inside Chrome; pinning the installed web app must be tested separately. Test whether launching camera/gallery temporarily leaves the pinned task or is blocked.

For stronger device management, Android’s [dedicated-device lock task mode](https://developer.android.com/work/dpc/dedicated-devices/lock-task-mode) uses a device policy controller and an allowlist of permitted apps. Provisioning and support depend on device capabilities, Android version, vendor management support, and account state; setup can require a factory reset. Do not reset or enroll the tablet until its exact provisioning path and data backup are agreed. No reset, enrollment, management installation, or policy change has been performed by this project.

Allow the camera/file picker flows needed for worksheet evidence when choosing a kiosk solution. Confirm the adult exit/recovery path, reboot behavior, Wi-Fi reconnect, update handling, and accessibility before leaving Noah to work independently. No kiosk configuration can turn a connection indicator or timer into proof of work.
