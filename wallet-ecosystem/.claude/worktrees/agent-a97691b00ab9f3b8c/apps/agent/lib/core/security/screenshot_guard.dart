import 'package:flutter/services.dart';

class ScreenshotGuard {
  static const _channel = MethodChannel('screenshot_guard');

  static Future<void> enable() async {
    await _channel.invokeMethod<void>('enable');
  }

  static Future<void> disable() async {
    await _channel.invokeMethod<void>('disable');
  }
}
