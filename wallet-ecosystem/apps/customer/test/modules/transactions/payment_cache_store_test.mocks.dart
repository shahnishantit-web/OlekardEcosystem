// ignore_for_file: type=lint
// ignore_for_file: override_on_non_overriding_member
// ignore_for_file: argument_type_not_assignable
import 'dart:async' as _i2;
import 'package:flutter_secure_storage/flutter_secure_storage.dart' as _i1;
import 'package:mockito/mockito.dart' as _i3;

// Parameters are nullable to allow anyNamed() matchers in when() calls.
class MockFlutterSecureStorage extends _i3.Mock
    implements _i1.FlutterSecureStorage {
  MockFlutterSecureStorage() {
    _i3.throwOnMissingStub(this);
  }

  @override
  _i2.Future<void> write({
    required String? key,
    required String? value,
    _i1.AppleOptions? iOptions,
    _i1.AndroidOptions? aOptions,
    _i1.LinuxOptions? lOptions,
    _i1.WebOptions? webOptions,
    _i1.AppleOptions? mOptions,
    _i1.WindowsOptions? wOptions,
  }) =>
      (super.noSuchMethod(
        Invocation.method(#write, [], {
          #key: key,
          #value: value,
          #iOptions: iOptions,
          #aOptions: aOptions,
          #lOptions: lOptions,
          #webOptions: webOptions,
          #mOptions: mOptions,
          #wOptions: wOptions,
        }),
        returnValue: _i2.Future<void>.value(),
        returnValueForMissingStub: _i2.Future<void>.value(),
      ) as _i2.Future<void>);

  @override
  _i2.Future<String?> read({
    required String? key,
    _i1.AppleOptions? iOptions,
    _i1.AndroidOptions? aOptions,
    _i1.LinuxOptions? lOptions,
    _i1.WebOptions? webOptions,
    _i1.AppleOptions? mOptions,
    _i1.WindowsOptions? wOptions,
  }) =>
      (super.noSuchMethod(
        Invocation.method(#read, [], {
          #key: key,
          #iOptions: iOptions,
          #aOptions: aOptions,
          #lOptions: lOptions,
          #webOptions: webOptions,
          #mOptions: mOptions,
          #wOptions: wOptions,
        }),
        returnValue: _i2.Future<String?>.value(),
      ) as _i2.Future<String?>);

  @override
  _i2.Future<void> delete({
    required String? key,
    _i1.AppleOptions? iOptions,
    _i1.AndroidOptions? aOptions,
    _i1.LinuxOptions? lOptions,
    _i1.WebOptions? webOptions,
    _i1.AppleOptions? mOptions,
    _i1.WindowsOptions? wOptions,
  }) =>
      (super.noSuchMethod(
        Invocation.method(#delete, [], {
          #key: key,
          #iOptions: iOptions,
          #aOptions: aOptions,
          #lOptions: lOptions,
          #webOptions: webOptions,
          #mOptions: mOptions,
          #wOptions: wOptions,
        }),
        returnValue: _i2.Future<void>.value(),
        returnValueForMissingStub: _i2.Future<void>.value(),
      ) as _i2.Future<void>);

  @override
  _i2.Future<void> deleteAll({
    _i1.AppleOptions? iOptions,
    _i1.AndroidOptions? aOptions,
    _i1.LinuxOptions? lOptions,
    _i1.WebOptions? webOptions,
    _i1.AppleOptions? mOptions,
    _i1.WindowsOptions? wOptions,
  }) =>
      (super.noSuchMethod(
        Invocation.method(#deleteAll, [], {
          #iOptions: iOptions,
          #aOptions: aOptions,
          #lOptions: lOptions,
          #webOptions: webOptions,
          #mOptions: mOptions,
          #wOptions: wOptions,
        }),
        returnValue: _i2.Future<void>.value(),
        returnValueForMissingStub: _i2.Future<void>.value(),
      ) as _i2.Future<void>);

  @override
  _i2.Future<Map<String, String>> readAll({
    _i1.AppleOptions? iOptions,
    _i1.AndroidOptions? aOptions,
    _i1.LinuxOptions? lOptions,
    _i1.WebOptions? webOptions,
    _i1.AppleOptions? mOptions,
    _i1.WindowsOptions? wOptions,
  }) =>
      (super.noSuchMethod(
        Invocation.method(#readAll, [], {
          #iOptions: iOptions,
          #aOptions: aOptions,
          #lOptions: lOptions,
          #webOptions: webOptions,
          #mOptions: mOptions,
          #wOptions: wOptions,
        }),
        returnValue: _i2.Future<Map<String, String>>.value(<String, String>{}),
      ) as _i2.Future<Map<String, String>>);
}
