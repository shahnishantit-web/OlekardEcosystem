/// Data models for E02 Profile API responses (Customer Flutter).
/// All fields mirror the profile-api.md contracts exactly.
/// profile_photo_key MUST NOT appear here — only the signed URL is stored.

class AddressData {
  const AddressData({
    this.street,
    this.city,
    this.region,
    this.postalCode,
    this.country,
  });

  final String? street;
  final String? city;
  final String? region;
  final String? postalCode;
  final String? country;

  factory AddressData.fromJson(Map<String, dynamic> json) => AddressData(
        street: json['street'] as String?,
        city: json['city'] as String?,
        region: json['region'] as String?,
        postalCode: json['postal_code'] as String?,
        country: json['country'] as String?,
      );
}

/// Immutable profile DTO.  profile_photo_url is a server-issued signed URL.
class ProfileData {
  const ProfileData({
    required this.userId,
    this.firstName,
    this.lastName,
    this.dateOfBirth,
    this.gender,
    this.email,
    this.mobileNumber,
    required this.accountStatus,
    this.profilePhotoUrl,
    this.address,
    this.preferredLanguage,
  });

  final String userId;
  final String? firstName;
  final String? lastName;
  final String? dateOfBirth;
  final String? gender;
  final String? email;
  final String? mobileNumber;
  final String accountStatus;
  final String? profilePhotoUrl;
  final AddressData? address;
  final String? preferredLanguage;

  factory ProfileData.fromJson(Map<String, dynamic> json) => ProfileData(
        userId: json['user_id'] as String,
        firstName: json['first_name'] as String?,
        lastName: json['last_name'] as String?,
        dateOfBirth: json['date_of_birth'] as String?,
        gender: json['gender'] as String?,
        email: json['email'] as String?,
        mobileNumber: json['mobile_number'] as String?,
        accountStatus: json['account_status'] as String,
        profilePhotoUrl: json['profile_photo_url'] as String?,
        address: json['address'] != null
            ? AddressData.fromJson(json['address'] as Map<String, dynamic>)
            : null,
        preferredLanguage: json['preferred_language'] as String?,
      );

  String get displayName {
    final parts = [firstName, lastName].whereType<String>().join(' ');
    return parts.isNotEmpty ? parts : 'Profile';
  }
}

/// Single account status history row.
class StatusHistoryEntry {
  const StatusHistoryEntry({
    required this.fromStatus,
    required this.toStatus,
    this.reason,
    required this.actorType,
    required this.createdAt,
  });

  final String fromStatus;
  final String toStatus;
  final String? reason;
  final String actorType;
  final DateTime createdAt;

  factory StatusHistoryEntry.fromJson(Map<String, dynamic> json) =>
      StatusHistoryEntry(
        fromStatus: json['from_status'] as String,
        toStatus: json['to_status'] as String,
        reason: json['reason'] as String?,
        actorType: json['actor_type'] as String,
        createdAt: DateTime.parse(json['created_at'] as String),
      );
}

/// Account status response carrying current status and history.
class AccountStatusData {
  const AccountStatusData({
    required this.status,
    required this.history,
    this.closureObligations,
  });

  final String status;
  final List<StatusHistoryEntry> history;
  final List<String>? closureObligations;

  factory AccountStatusData.fromJson(Map<String, dynamic> json) =>
      AccountStatusData(
        status: json['status'] as String,
        history: (json['history'] as List<dynamic>)
            .map((e) => StatusHistoryEntry.fromJson(e as Map<String, dynamic>))
            .toList(),
        closureObligations: (json['closure_obligations'] as List<dynamic>?)
            ?.map((e) => e as String)
            .toList(),
      );
}

/// OTP challenge issued by the server for contact change.
class ContactChallengeData {
  const ContactChallengeData({
    required this.challengeId,
    required this.expiresAt,
    this.maskedDestination,
  });

  final String challengeId;
  final DateTime expiresAt;
  final String? maskedDestination;

  factory ContactChallengeData.fromJson(Map<String, dynamic> json) =>
      ContactChallengeData(
        challengeId: json['challenge_id'] as String,
        expiresAt: DateTime.parse(json['expires_at'] as String),
        maskedDestination: json['masked_destination'] as String?,
      );
}

/// Data export request response.
class DataExportData {
  const DataExportData({
    required this.exportId,
    required this.status,
    required this.requestedAt,
    this.expiresAt,
  });

  final String exportId;
  final String status;
  final DateTime requestedAt;
  final DateTime? expiresAt;

  factory DataExportData.fromJson(Map<String, dynamic> json) => DataExportData(
        exportId: json['export_id'] as String,
        status: json['status'] as String,
        requestedAt: DateTime.parse(json['requested_at'] as String),
        expiresAt: json['expires_at'] != null
            ? DateTime.parse(json['expires_at'] as String)
            : null,
      );
}
