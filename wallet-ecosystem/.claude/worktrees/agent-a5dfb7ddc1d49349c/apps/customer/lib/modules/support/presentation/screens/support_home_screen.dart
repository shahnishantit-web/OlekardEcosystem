import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import '../../../../core/theme/design_tokens.dart';

class SupportHomeScreen extends StatelessWidget {
  const SupportHomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Support & Disputes')),
      body: ListView(
        padding: const EdgeInsets.all(AppSpacing.lg),
        children: [
          _SupportCard(
            icon: Icons.support_agent,
            title: 'Contact Support',
            subtitle: 'Submit a new inquiry or view your existing tickets',
            onTap: () => context.push('/support/tickets'),
          ),
          const SizedBox(height: AppSpacing.md),
          _SupportCard(
            icon: Icons.report_problem_outlined,
            title: 'Disputes',
            subtitle: 'Raise or track disputes on your transactions',
            onTap: () => context.push('/support/disputes'),
          ),
          const SizedBox(height: AppSpacing.xl),
          const Text('Frequently Asked Questions', style: AppTypography.headline),
          const SizedBox(height: AppSpacing.md),
          const _FaqTile(
            question: 'How long does a transfer take?',
            answer: 'Transfers are typically processed within seconds for wallet-to-wallet transactions.',
          ),
          const _FaqTile(
            question: 'How do I dispute a transaction?',
            answer: 'Tap "Disputes" above and select "Raise Dispute". Provide the transaction reference and reason.',
          ),
          const _FaqTile(
            question: 'How do I reset my PIN?',
            answer: 'Go to Security Settings → Change PIN. You will need your current PIN to set a new one.',
          ),
        ],
      ),
    );
  }
}

class _SupportCard extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  const _SupportCard({required this.icon, required this.title, required this.subtitle, required this.onTap});

  @override
  Widget build(BuildContext context) => Card(
        child: ListTile(
          leading: Icon(icon, color: AppColors.primary),
          title: Text(title, style: AppTypography.label),
          subtitle: Text(subtitle, style: AppTypography.caption),
          trailing: const Icon(Icons.chevron_right),
          onTap: onTap,
        ),
      );
}

class _FaqTile extends StatelessWidget {
  final String question;
  final String answer;
  const _FaqTile({required this.question, required this.answer});

  @override
  Widget build(BuildContext context) => ExpansionTile(
        title: Text(question, style: AppTypography.label),
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(AppSpacing.lg, 0, AppSpacing.lg, AppSpacing.md),
            child: Text(answer, style: AppTypography.body),
          ),
        ],
      );
}
