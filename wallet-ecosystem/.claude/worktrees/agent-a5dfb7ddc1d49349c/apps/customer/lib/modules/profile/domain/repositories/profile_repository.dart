import '../entities/profile.dart';

abstract class ProfileRepository {
  Future<CustomerProfile> getProfile();
  Future<CustomerProfile> updateProfile(UpdateProfileRequest request);
}
