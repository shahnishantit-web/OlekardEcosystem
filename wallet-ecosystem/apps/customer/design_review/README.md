# Customer journey visual review

`after/01-sign-in-android.png` is a native Android capture from the installed debug build on a physical Android 13 device. It shows the unauthenticated sign-in screen at the device's current large text scale.

The remaining eight images are Flutter widget-rendered previews at 390 x 844 using synthetic demo content. They cover phone sign-in, PIN entry, home, event list and details, registration confirmation, ticket list, and QR ticket detail. The ticket QR uses a synthetic payload.

There is no matching pre-redesign screenshot set. The authenticated registration-to-ticket journey was not exercised on the physical device, so the widget previews verify layout only and do not prove backend or device end-to-end behavior.
