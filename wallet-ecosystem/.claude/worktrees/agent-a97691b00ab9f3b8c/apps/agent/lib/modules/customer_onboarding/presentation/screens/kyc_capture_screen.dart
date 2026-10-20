import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import 'package:wallet_agent/core/security/screenshot_guard.dart';
import 'package:wallet_agent/core/theme/design_tokens.dart';
import 'package:wallet_agent/shared/widgets/offline_banner.dart';
import 'package:wallet_agent/shared/widgets/primary_button.dart';
import 'package:wallet_agent/modules/customer_onboarding/presentation/customer_onboarding_providers.dart';

class KycCaptureScreen extends ConsumerStatefulWidget {
  final String customerId;
  final String customerName;

  const KycCaptureScreen({
    super.key,
    required this.customerId,
    required this.customerName,
  });

  @override
  ConsumerState<KycCaptureScreen> createState() => _KycCaptureScreenState();
}

class _KycCaptureScreenState extends ConsumerState<KycCaptureScreen> {
  final _picker = ImagePicker();
  File? _frontImage;
  File? _backImage;
  bool _isSubmitting = false;
  String? _errorMessage;

  @override
  void initState() {
    super.initState();
    ScreenshotGuard.enable();
  }

  @override
  void dispose() {
    ScreenshotGuard.disable();
    super.dispose();
  }

  Future<void> _captureImage({required bool isFront}) async {
    setState(() => _errorMessage = null);
    try {
      final xFile = await _picker.pickImage(
        source: ImageSource.camera,
        imageQuality: 85,
        maxWidth: 1920,
      );
      if (xFile == null) return;
      final file = File(xFile.path);
      setState(() {
        if (isFront) {
          _frontImage = file;
        } else {
          _backImage = file;
        }
      });
    } catch (e) {
      setState(() {
        _errorMessage =
            'Camera is required to capture identity documents. Please grant camera permission in Settings.';
      });
    }
  }

  Future<void> _submit() async {
    if (_frontImage == null) {
      setState(() => _errorMessage = 'Front photo of ID document is required.');
      return;
    }
    setState(() {
      _isSubmitting = true;
      _errorMessage = null;
    });
    try {
      await ref.read(customerOnboardingRepositoryProvider).submitKycDocuments(
            customerId: widget.customerId,
            frontImage: _frontImage!,
            backImage: _backImage,
          );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('KYC documents submitted for review.'),
          backgroundColor: AppColors.success,
        ),
      );
      context.pop();
    } catch (_) {
      setState(() {
        _errorMessage =
            'Failed to submit documents. Check your connection and try again.';
      });
    } finally {
      if (mounted) setState(() => _isSubmitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('KYC — ${widget.customerName}')),
      body: Column(
        children: [
          const OfflineBanner(),
          Expanded(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(AppSpacing.md),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Capture Identity Document',
                      style: AppTypography.titleMedium),
                  const SizedBox(height: AppSpacing.xs),
                  Text(
                    'Take clear photos of the national ID card on a flat surface.',
                    style: AppTypography.bodyMedium
                        .copyWith(color: AppColors.onSurfaceVariant),
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  _DocumentCaptureTile(
                    label: 'Front of ID (required)',
                    image: _frontImage,
                    onCapture: () => _captureImage(isFront: true),
                  ),
                  const SizedBox(height: AppSpacing.md),
                  _DocumentCaptureTile(
                    label: 'Back of ID (optional)',
                    image: _backImage,
                    onCapture: () => _captureImage(isFront: false),
                    isRequired: false,
                  ),
                  const SizedBox(height: AppSpacing.lg),
                  if (_errorMessage != null) ...[
                    Text(_errorMessage!,
                        style: const TextStyle(color: AppColors.error)),
                    const SizedBox(height: AppSpacing.md),
                  ],
                  PrimaryButton(
                    label: 'Submit for Review',
                    isLoading: _isSubmitting,
                    onPressed: _frontImage != null ? _submit : null,
                    icon: Icons.upload_outlined,
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _DocumentCaptureTile extends StatelessWidget {
  final String label;
  final File? image;
  final VoidCallback onCapture;
  final bool isRequired;

  const _DocumentCaptureTile({
    required this.label,
    required this.image,
    required this.onCapture,
    this.isRequired = true,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(AppSpacing.md),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Text(label, style: AppTypography.labelLarge),
                if (isRequired) ...[
                  const SizedBox(width: 4),
                  const Text('*', style: TextStyle(color: AppColors.error)),
                ],
              ],
            ),
            const SizedBox(height: AppSpacing.sm),
            if (image != null)
              ClipRRect(
                borderRadius:
                    BorderRadius.circular(AppSpacing.borderRadius / 2),
                child: Image.file(
                  image!,
                  height: 140,
                  width: double.infinity,
                  fit: BoxFit.cover,
                ),
              )
            else
              Container(
                height: 140,
                width: double.infinity,
                decoration: BoxDecoration(
                  color: AppColors.surfaceVariant,
                  borderRadius:
                      BorderRadius.circular(AppSpacing.borderRadius / 2),
                  border: Border.all(color: AppColors.outline),
                ),
                child: const Icon(Icons.image_outlined,
                    size: 48, color: AppColors.onSurfaceVariant),
              ),
            const SizedBox(height: AppSpacing.sm),
            Semantics(
              label: image == null ? 'Capture $label' : 'Retake $label',
              button: true,
              child: OutlinedButton.icon(
                icon: Icon(image == null ? Icons.camera_alt : Icons.refresh),
                label: Text(image == null ? 'Capture Photo' : 'Retake'),
                onPressed: onCapture,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
