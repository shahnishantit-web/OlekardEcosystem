import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:image_picker/image_picker.dart';
import '../../../shared/widgets/button.dart';
import '../../../shared/widgets/loading.dart';
import '../state/profile_state.dart';
import '../state/profile_state_notifier.dart';

/// US-USER-003 — Profile photo upload/change.
/// States: default, selecting, uploading, success, size/type/dimension error.
class ProfilePhotoScreen extends ConsumerStatefulWidget {
  const ProfilePhotoScreen({super.key});

  @override
  ConsumerState<ProfilePhotoScreen> createState() => _ProfilePhotoScreenState();
}

class _ProfilePhotoScreenState extends ConsumerState<ProfilePhotoScreen> {
  File? _selected;

  static const int _maxBytes = 5 * 1024 * 1024; // 5 MB — client-side UX guard; backend enforces policy
  static const List<String> _allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

  final _picker = ImagePicker();

  Future<void> _pickPhoto() async {
    try {
      final xFile = await _picker.pickImage(source: ImageSource.gallery);
      if (xFile == null) return; // user cancelled

      setState(() => _selected = File(xFile.path));
    } on PlatformException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Semantics(
            liveRegion: true,
            child: Text(
              e.code == 'photo_access_denied'
                  ? 'Photo access denied. Please allow access in Settings.'
                  : 'Could not open photo library. Please try again.',
            ),
          ),
          backgroundColor: Theme.of(context).colorScheme.error,
        ),
      );
    }
  }

  bool _validateFile(File file) {
    final ext = file.path.split('.').last.toLowerCase();
    if (!_allowedExtensions.contains(ext)) {
      _showError('Unsupported file type. Use JPG, PNG, or WebP.');
      return false;
    }
    if (file.lengthSync() > _maxBytes) {
      _showError('Photo must be smaller than 5 MB.');
      return false;
    }
    return true;
  }

  void _showError(String message) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Semantics(liveRegion: true, child: Text(message)),
        backgroundColor: Theme.of(context).colorScheme.error,
      ),
    );
  }

  void _upload() {
    if (_selected == null) return;
    if (!_validateFile(_selected!)) return;
    ref.read(profileStateNotifierProvider.notifier).uploadPhoto(_selected!);
  }

  @override
  Widget build(BuildContext context) {
    final profileAsync = ref.watch(profileStateNotifierProvider);
    final loaded = profileAsync.valueOrNull is ProfileLoaded
        ? profileAsync.valueOrNull as ProfileLoaded
        : null;

    final isUploading = loaded?.isSubmitting ?? false;
    final errorMessage = loaded?.errorMessage;
    final successMessage = loaded?.successMessage;
    final currentPhotoUrl = loaded?.profile.profilePhotoUrl;

    return Scaffold(
      appBar: AppBar(title: const Text('Profile Photo')),
      body: SafeArea(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: isUploading
              ? const Center(child: LoadingWidget(message: 'Uploading photo…'))
              : Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // Preview
                    Center(
                      child: Semantics(
                        label: _selected != null
                            ? 'Selected photo preview'
                            : 'Current profile photo',
                        child: CircleAvatar(
                          radius: 64,
                          backgroundImage: _selected != null
                              ? FileImage(_selected!) as ImageProvider
                              : (currentPhotoUrl != null
                                  ? NetworkImage(currentPhotoUrl)
                                  : null),
                          child: _selected == null && currentPhotoUrl == null
                              ? const Icon(Icons.person, size: 64)
                              : null,
                        ),
                      ),
                    ),
                    const SizedBox(height: 24),
                    if (errorMessage != null) ...[
                      Semantics(
                        liveRegion: true,
                        child: _AlertBanner(
                          message: errorMessage,
                          isError: true,
                        ),
                      ),
                      const SizedBox(height: 16),
                    ],
                    if (successMessage != null) ...[
                      Semantics(
                        liveRegion: true,
                        child: _AlertBanner(message: successMessage),
                      ),
                      const SizedBox(height: 16),
                    ],
                    AppButton(
                      label: 'Choose photo',
                      variant: ButtonVariant.secondary,
                      onPressed: () => _pickPhoto(),
                    ),
                    const SizedBox(height: 12),
                    AppButton(
                      label: 'Upload',
                      onPressed: _selected != null ? _upload : null,
                    ),
                    const SizedBox(height: 16),
                    Semantics(
                      label: 'Photo requirements: JPG, PNG, or WebP, maximum 5 MB',
                      child: Text(
                        'JPG, PNG, or WebP · Max 5 MB · Min 200 × 200 px',
                        style: Theme.of(context).textTheme.bodySmall?.copyWith(
                              color:
                                  Theme.of(context).colorScheme.onSurfaceVariant,
                            ),
                        textAlign: TextAlign.center,
                      ),
                    ),
                  ],
                ),
        ),
      ),
    );
  }
}

class _AlertBanner extends StatelessWidget {
  const _AlertBanner({required this.message, this.isError = false});

  final String message;
  final bool isError;

  @override
  Widget build(BuildContext context) {
    final color = isError ? Theme.of(context).colorScheme.error : Colors.green;
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: color.withOpacity(0.1),
        border: Border.all(color: color),
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          Icon(isError ? Icons.error_outline : Icons.check_circle_outline,
              color: color, size: 20),
          const SizedBox(width: 8),
          Expanded(
            child: Text(message,
                style: Theme.of(context)
                    .textTheme
                    .bodySmall
                    ?.copyWith(color: color)),
          ),
        ],
      ),
    );
  }
}
