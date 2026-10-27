import 'dart:async';

class SessionEventBus {
  SessionEventBus._();
  static final instance = SessionEventBus._();

  final _logoutController = StreamController<void>.broadcast();
  final _accountSwitchController = StreamController<void>.broadcast();

  Stream<void> get onLogout => _logoutController.stream;
  Stream<void> get onAccountSwitch => _accountSwitchController.stream;

  void fireLogout() => _logoutController.add(null);
  void fireAccountSwitch() => _accountSwitchController.add(null);
}
