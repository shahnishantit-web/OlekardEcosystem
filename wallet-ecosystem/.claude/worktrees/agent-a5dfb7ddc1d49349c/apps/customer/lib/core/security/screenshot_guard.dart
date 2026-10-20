import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';

class ScreenshotGuard {
  static const _channel = MethodChannel('screenshot_guard');

  Future<void> enable() async {
    if (kIsWeb) return;
    try {
      await _channel.invokeMethod('enable');
    } on MissingPluginException {
      // Platform channel not available in test environments
    }
  }

  Future<void> disable() async {
    if (kIsWeb) return;
    try {
      await _channel.invokeMethod('disable');
    } on MissingPluginException {
      // Platform channel not available in test environments
    }
  }
}
