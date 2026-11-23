# wallet_agent

OleKard agent Flutter application.

## API endpoint configuration

The app does not select an API host in Dart code. Supply `API_BASE_URL` at build/run time with a Flutter config file or `--dart-define`.

Run the Flutter Web preview against the local API:

```powershell
flutter run -d chrome --dart-define-from-file=config/local.web.json
```

Run on an Android emulator or build a debug APK for that emulator:

```powershell
flutter run --dart-define-from-file=config/local.android-emulator.json
flutter build apk --debug --dart-define-from-file=config/local.android-emulator.json
```

For another environment or a physical device, provide a config file with a reachable API origin, or pass `--dart-define=API_BASE_URL=<api-origin>`. Do not put credentials in these config files.

## Getting Started

This project is a starting point for a Flutter application.

A few resources to get you started if this is your first Flutter project:

- [Learn Flutter](https://docs.flutter.dev/get-started/learn-flutter)
- [Write your first Flutter app](https://docs.flutter.dev/get-started/codelab)
- [Flutter learning resources](https://docs.flutter.dev/reference/learning-resources)

For help getting started with Flutter development, view the
[online documentation](https://docs.flutter.dev/), which offers tutorials,
samples, guidance on mobile development, and a full API reference.
