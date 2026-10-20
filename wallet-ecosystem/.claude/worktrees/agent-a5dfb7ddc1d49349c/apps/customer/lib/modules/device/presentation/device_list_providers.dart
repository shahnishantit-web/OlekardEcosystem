import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/di/providers.dart';
import '../data/repositories/device_remote_data_source.dart';
import '../data/repositories/device_repository_impl.dart';
import '../domain/entities/device.dart';
import '../domain/repositories/device_repository.dart';

final deviceRemoteDataSourceProvider = Provider<DeviceRemoteDataSource>((ref) {
  return DeviceRemoteDataSource(ref.watch(apiClientProvider));
});

final deviceRepositoryProvider = Provider<DeviceRepository>((ref) {
  return DeviceRepositoryImpl(ref.watch(deviceRemoteDataSourceProvider));
});

final deviceListProvider = FutureProvider.family<
  ({List<Device> devices, int total, int currentPage, int lastPage}),
  int
>((ref, page) async {
  return ref.watch(deviceRepositoryProvider).listDevices(page: page);
});
