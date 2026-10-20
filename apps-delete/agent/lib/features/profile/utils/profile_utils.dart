import 'dart:math';

/// Shared utilities for the agent profile feature.
class AgentProfileUtils {
  AgentProfileUtils._();

  static String idempotencyKey() {
    final r = Random.secure();
    final bytes = List<int>.generate(16, (_) => r.nextInt(256));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    String hex(int b) => b.toRadixString(16).padLeft(2, '0');
    final h = bytes.map(hex).toList();
    return '${h.sublist(0, 4).join()}-${h.sublist(4, 6).join()}-'
        '${h.sublist(6, 8).join()}-${h.sublist(8, 10).join()}-'
        '${h.sublist(10).join()}';
  }
}
