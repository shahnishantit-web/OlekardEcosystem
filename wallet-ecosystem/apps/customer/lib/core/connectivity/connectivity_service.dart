import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final connectivityStreamProvider = StreamProvider<ConnectivityResult>((ref) {
  return Connectivity()
      .onConnectivityChanged
      .map((results) => results.isNotEmpty ? results.first : ConnectivityResult.none);
});

final isOfflineProvider = Provider<bool>((ref) {
  final result = ref.watch(connectivityStreamProvider);
  return result.when(
    data: (r) => r == ConnectivityResult.none,
    loading: () => false,
    error: (_, __) => false,
  );
});
