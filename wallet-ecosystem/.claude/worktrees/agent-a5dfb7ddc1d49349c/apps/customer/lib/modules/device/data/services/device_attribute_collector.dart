import 'dart:io';
import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/foundation.dart';

class DeviceAttributeCollector {
  const DeviceAttributeCollector();

  Future<({String platform, Map<String, dynamic> attributes})> collect() async {
    final plugin = DeviceInfoPlugin();

    if (kIsWeb) {
      final info = await plugin.webBrowserInfo;
      return (
        platform: 'web',
        attributes: {
          'user_agent': info.userAgent ?? '',
          'timezone':   DateTime.now().timeZoneName,
          'browser':    info.browserName.name,
        },
      );
    }

    if (Platform.isAndroid) {
      final info = await plugin.androidInfo;
      return (
        platform: 'android',
        attributes: {
          'os_version':     info.version.release,
          'hardware_model': info.model,
          'android_id':     info.id,
          'brand':          info.brand,
          'sdk_int':        info.version.sdkInt.toString(),
        },
      );
    }

    if (Platform.isIOS) {
      final info = await plugin.iosInfo;
      return (
        platform: 'ios',
        attributes: {
          'os_version':     info.systemVersion,
          'hardware_model': info.utsname.machine,
          'idfv':           info.identifierForVendor ?? '',
          'device_name':    info.name,
        },
      );
    }

    // Fallback for other platforms (macOS, Linux, Windows)
    return (
      platform: 'web',
      attributes: {
        'user_agent': Platform.operatingSystem,
        'timezone':   DateTime.now().timeZoneName,
      },
    );
  }
}
