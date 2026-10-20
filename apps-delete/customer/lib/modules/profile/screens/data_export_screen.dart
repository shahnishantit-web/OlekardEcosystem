import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../shared/widgets/button.dart';
import '../../../shared/widgets/loading.dart';
import '../state/profile_state.dart';
import '../state/profile_state_notifier.dart';

/// US-USER-015 — Personal data export request.
/// States: default, submitting, submitted (pending/processing), error.
/// EXPORT_IN_PROGRESS: shows status badge instead of duplicate request CTA.
class DataExportScreen extends ConsumerWidget {
  const DataExportScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final profileAsync = ref.watch(profileStateNotifierProvider);
    final loaded = profileAsync.valueOrNull is ProfileLoaded
        ? profileAsync.valueOrNull as ProfileLoaded
        : null;

    final isSubmitting = loaded?.isSubmitting ?? profileAsync.isLoading;
    final exportData = loaded?.dataExport;
    final errorCode = loaded?.errorCode;
    final errorMessage = loaded?.errorMessage;
    final successMessage = loaded?.successMessage;

    final exportInProgress = errorCode == 'EXPORT_IN_PROGRESS' ||
        (exportData != null &&
            (exportData.status == 'pending' ||
                exportData.status == 'processing'));

    return Scaffold(
      appBar: AppBar(title: const Text('Request Data Export')),
      body: SafeArea(
        child: isSubmitting
            ? const Center(child: LoadingWidget(message: 'Submitting request…'))
            : Padding(
                padding: const EdgeInsets.all(24),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Semantics(
                      header: true,
                      child: Text(
                        'Download your data',
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                    ),
                    const SizedBox(height: 12),
                    Text(
                      'You can request a copy of all personal data we hold for you. '
                      'We will prepare the export and notify you when it is ready.',
                      style: Theme.of(context).textTheme.bodyMedium,
                    ),
                    const SizedBox(height: 24),
                    // In-progress state
                    if (exportInProgress) ...[
                      _ExportStatusCard(
                        status: exportData?.status ?? 'pending',
                        requestedAt: exportData?.requestedAt,
                        expiresAt: exportData?.expiresAt,
                      ),
                      if (errorCode == 'EXPORT_IN_PROGRESS') ...[
                        const SizedBox(height: 16),
                        Semantics(
                          liveRegion: true,
                          child: _InfoBanner(
                            message:
                                'A data export is already in progress. '
                                'We will notify you when it is ready.',
                          ),
                        ),
                      ],
                    ],
                    // Success confirmation
                    if (successMessage != null && !exportInProgress) ...[
                      Semantics(
                        liveRegion: true,
                        child: _SuccessBanner(message: successMessage),
                      ),
                    ],
                    // Generic error
                    if (errorMessage != null &&
                        errorCode != 'EXPORT_IN_PROGRESS') ...[
                      Semantics(
                        liveRegion: true,
                        child: _ErrorBanner(message: errorMessage),
                      ),
                    ],
                    const Spacer(),
                    if (!exportInProgress)
                      AppButton(
                        label: 'Request data export',
                        onPressed: () => ref
                            .read(profileStateNotifierProvider.notifier)
                            .requestDataExport(),
                      ),
                  ],
                ),
              ),
      ),
    );
  }
}

// ---------------------------------------------------------------------------
// In-progress export card.
// ---------------------------------------------------------------------------

class _ExportStatusCard extends StatelessWidget {
  const _ExportStatusCard({
    required this.status,
    this.requestedAt,
    this.expiresAt,
  });

  final String status;
  final DateTime? requestedAt;
  final DateTime? expiresAt;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final label = switch (status) {
      'pending' => 'Pending — preparing your export',
      'processing' => 'Processing — export in progress',
      'ready' => 'Ready — export available for download',
      _ => status,
    };

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(Icons.file_download_outlined,
                    color: theme.colorScheme.primary),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(label, style: theme.textTheme.titleSmall),
                ),
              ],
            ),
            if (requestedAt != null) ...[
              const SizedBox(height: 8),
              Text(
                'Requested: ${_fmt(requestedAt!)}',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
            if (expiresAt != null) ...[
              const SizedBox(height: 4),
              Text(
                'Available until: ${_fmt(expiresAt!)}',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  String _fmt(DateTime dt) =>
      '${dt.year}-${dt.month.toString().padLeft(2, '0')}-${dt.day.toString().padLeft(2, '0')}';
}

// ---------------------------------------------------------------------------
// Shared banners.
// ---------------------------------------------------------------------------

class _InfoBanner extends StatelessWidget {
  const _InfoBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.secondaryContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        message,
        style: theme.textTheme.bodySmall
            ?.copyWith(color: theme.colorScheme.onSecondaryContainer),
      ),
    );
  }
}

class _SuccessBanner extends StatelessWidget {
  const _SuccessBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.tertiaryContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        message,
        style: theme.textTheme.bodySmall
            ?.copyWith(color: theme.colorScheme.onTertiaryContainer),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: theme.colorScheme.errorContainer,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Text(
        message,
        style: theme.textTheme.bodySmall
            ?.copyWith(color: theme.colorScheme.onErrorContainer),
      ),
    );
  }
}
