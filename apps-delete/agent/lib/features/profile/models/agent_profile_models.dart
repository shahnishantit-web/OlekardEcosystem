/// Data models for E02 Profile API responses (Agent Flutter).
/// Agent subset: no email change, no closure, no data export.
/// profile_photo_key MUST NOT appear here — only the signed URL is stored.

class AgentAddressData {
  const AgentAddressData({
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

  factory AgentAddressData.fromJson(Map<String, dynamic> json) =>
      AgentAddressData(
        street: json['street'] as String?,
        city: json['city'] as String?,
        region: json['region'] as String?,
        postalCode: json['postal_code'] as String?,
        country: json['country'] as String?,
      );
}

/// Immutable profile DTO.  profile_photo_url is a server-issued signed URL.
class AgentProfileData {
  const AgentProfileData({
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
  final AgentAddressData? address;
  final String? preferredLanguage;

  factory AgentProfileData.fromJson(Map<String, dynamic> json) =>
      AgentProfileData(
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
            ? AgentAddressData.fromJson(
                json['address'] as Map<String, dynamic>)
            : null,
        preferredLanguage: json['preferred_language'] as String?,
      );

  String get displayName {
    final parts = [firstName, lastName].whereType<String>().join(' ');
    return parts.isNotEmpty ? parts : 'Profile';
  }
}

/// Single account status history row.
class AgentStatusHistoryEntry {
  const AgentStatusHistoryEntry({
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

  factory AgentStatusHistoryEntry.fromJson(Map<String, dynamic> json) =>
      AgentStatusHistoryEntry(
        fromStatus: json['from_status'] as String,
        toStatus: json['to_status'] as String,
        reason: json['reason'] as String?,
        actorType: json['actor_type'] as String,
        createdAt: DateTime.parse(json['created_at'] as String),
      );
}

/// Account status response carrying current status and history.
class AgentAccountStatusData {
  const AgentAccountStatusData({
    required this.status,
    required this.history,
    this.closureObligations,
  });

  final String status;
  final List<AgentStatusHistoryEntry> history;
  final List<String>? closureObligations;

  factory AgentAccountStatusData.fromJson(Map<String, dynamic> json) =>
      AgentAccountStatusData(
        status: json['status'] as String,
        history: (json['history'] as List<dynamic>)
            .map((e) =>
                AgentStatusHistoryEntry.fromJson(e as Map<String, dynamic>))
            .toList(),
        closureObligations: (json['closure_obligations'] as List<dynamic>?)
            ?.map((e) => e as String)
            .toList(),
      );
}

/// OTP challenge issued by the server for mobile-number change.
class AgentContactChallengeData {
  const AgentContactChallengeData({
    required this.challengeId,
    required this.expiresAt,
    this.maskedDestination,
  });

  final String challengeId;
  final DateTime expiresAt;
  final String? maskedDestination;

  factory AgentContactChallengeData.fromJson(Map<String, dynamic> json) =>
      AgentContactChallengeData(
        challengeId: json['challenge_id'] as String,
        expiresAt: DateTime.parse(json['expires_at'] as String),
        maskedDestination: json['masked_destination'] as String?,
      );
}
