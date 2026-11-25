import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../../core/theme/design_tokens.dart';

class EventRegistrationConfirmationScreen extends StatelessWidget {
  const EventRegistrationConfirmationScreen(
      {super.key, this.details = const {}});
  final Map<String, String> details;

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('Registration confirmed')),
        body: SafeArea(
            child: Center(
                child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 480),
                    child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            Container(
                                width: 76,
                                height: 76,
                                alignment: Alignment.center,
                                decoration: const BoxDecoration(
                                    color: AppColors.successBg,
                                    shape: BoxShape.circle),
                                child: const Icon(Icons.check_rounded,
                                    color: AppColors.success, size: 42)),
                            const SizedBox(height: 24),
                            Text('You’re registered',
                                textAlign: TextAlign.center,
                                style:
                                    Theme.of(context).textTheme.headlineSmall),
                            const SizedBox(height: 8),
                            Text(
                                details['event_title']?.isNotEmpty == true
                                    ? 'Your place at ${details['event_title']} is confirmed.'
                                    : 'Your event registration is confirmed.',
                                textAlign: TextAlign.center,
                                style: Theme.of(context)
                                    .textTheme
                                    .bodyLarge
                                    ?.copyWith(color: AppColors.textSecondary)),
                            if (details['category']?.isNotEmpty == true) ...[
                              const SizedBox(height: 20),
                              Container(
                                  padding: const EdgeInsets.all(16),
                                  decoration: BoxDecoration(
                                      color: AppColors.surface,
                                      borderRadius: BorderRadius.circular(16),
                                      border:
                                          Border.all(color: AppColors.outline)),
                                  child: Row(children: [
                                    const Icon(
                                        Icons.confirmation_number_outlined,
                                        color: AppColors.primary),
                                    const SizedBox(width: 12),
                                    Expanded(
                                        child: Column(
                                            crossAxisAlignment:
                                                CrossAxisAlignment.start,
                                            children: [
                                          Text(details['category']!,
                                              style: Theme.of(context)
                                                  .textTheme
                                                  .titleSmall),
                                          const SizedBox(height: 3),
                                          const Text(
                                              'Your ticket is available in My tickets.',
                                              style: TextStyle(
                                                  color:
                                                      AppColors.textSecondary))
                                        ]))
                                  ])),
                            ],
                            const SizedBox(height: 28),
                            FilledButton.icon(
                                onPressed: () => context.go('/events/tickets'),
                                icon: const Icon(
                                    Icons.confirmation_number_outlined),
                                label: const Text('View my tickets')),
                            const SizedBox(height: 8),
                            TextButton(
                                onPressed: () =>
                                    context.go('/events/catalogue'),
                                child: const Text('Back to events')),
                          ]),
                    )))),
      );
}
