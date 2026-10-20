import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../shared/widgets/button.dart';
import '../../../shared/widgets/loading.dart';
import '../models/agent_profile_models.dart';
import '../state/agent_profile_state.dart';
import '../providers/agent_profile_state_notifier.dart';

/// US-USER-002 (Agent) — edit personal information.
/// States: default, loading, success, validation error.
class AgentProfileEditScreen extends ConsumerStatefulWidget {
  const AgentProfileEditScreen({super.key});

  @override
  ConsumerState<AgentProfileEditScreen> createState() =>
      _AgentProfileEditScreenState();
}

class _AgentProfileEditScreenState
    extends ConsumerState<AgentProfileEditScreen> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _firstNameCtrl;
  late final TextEditingController _lastNameCtrl;
  late final TextEditingController _dobCtrl;
  String? _gender;
  bool _initialised = false;

  @override
  void dispose() {
    _firstNameCtrl.dispose();
    _lastNameCtrl.dispose();
    _dobCtrl.dispose();
    super.dispose();
  }

  void _initFromProfile(AgentProfileData profile) {
    if (_initialised) return;
    _firstNameCtrl = TextEditingController(text: profile.firstName ?? '');
    _lastNameCtrl = TextEditingController(text: profile.lastName ?? '');
    _dobCtrl = TextEditingController(text: profile.dateOfBirth ?? '');
    _gender = profile.gender;
    _initialised = true;
  }

  void _submit() {
    if (!_formKey.currentState!.validate()) return;
    final fields = <String, dynamic>{};
    if (_firstNameCtrl.text.trim().isNotEmpty) {
      fields['first_name'] = _firstNameCtrl.text.trim();
    }
    if (_lastNameCtrl.text.trim().isNotEmpty) {
      fields['last_name'] = _lastNameCtrl.text.trim();
    }
    if (_dobCtrl.text.trim().isNotEmpty) {
      fields['date_of_birth'] = _dobCtrl.text.trim();
    }
    if (_gender != null) fields['gender'] = _gender;
    ref
        .read(agentProfileStateNotifierProvider.notifier)
        .updatePersonalInfo(fields);
  }

  @override
  Widget build(BuildContext context) {
    final profileAsync = ref.watch(agentProfileStateNotifierProvider);
    final loaded = profileAsync.valueOrNull is AgentProfileLoaded
        ? profileAsync.valueOrNull as AgentProfileLoaded
        : null;

    if (loaded != null) _initFromProfile(loaded.profile);

    final isSubmitting = loaded?.isSubmitting ?? false;
    final errorMessage = loaded?.errorMessage;
    final successMessage = loaded?.successMessage;

    return Scaffold(
      appBar: AppBar(title: const Text('Edit Profile')),
      body: SafeArea(
        child: isSubmitting
            ? const Center(child: LoadingWidget(message: 'Saving…'))
            : _initialised
                ? _buildForm(context, errorMessage, successMessage)
                : const Center(child: LoadingWidget()),
      ),
    );
  }

  Widget _buildForm(
      BuildContext context, String? errorMessage, String? successMessage) {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Form(
        key: _formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Semantics(
              header: true,
              child: Text(
                'Personal information',
                style: Theme.of(context).textTheme.headlineSmall,
              ),
            ),
            const SizedBox(height: 24),
            Semantics(
              label: 'First name',
              child: TextFormField(
                controller: _firstNameCtrl,
                decoration: const InputDecoration(
                  labelText: 'First name',
                  border: OutlineInputBorder(),
                ),
                textCapitalization: TextCapitalization.words,
                validator: (v) => (v == null || v.trim().isEmpty)
                    ? 'First name is required'
                    : null,
              ),
            ),
            const SizedBox(height: 16),
            Semantics(
              label: 'Last name',
              child: TextFormField(
                controller: _lastNameCtrl,
                decoration: const InputDecoration(
                  labelText: 'Last name',
                  border: OutlineInputBorder(),
                ),
                textCapitalization: TextCapitalization.words,
                validator: (v) => (v == null || v.trim().isEmpty)
                    ? 'Last name is required'
                    : null,
              ),
            ),
            const SizedBox(height: 16),
            Semantics(
              label: 'Date of birth',
              child: TextFormField(
                controller: _dobCtrl,
                decoration: const InputDecoration(
                  labelText: 'Date of birth',
                  hintText: 'YYYY-MM-DD',
                  border: OutlineInputBorder(),
                  prefixIcon: Icon(Icons.calendar_today),
                ),
                keyboardType: TextInputType.datetime,
                validator: (v) {
                  if (v == null || v.trim().isEmpty) return null;
                  if (!RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(v.trim())) {
                    return 'Use format YYYY-MM-DD';
                  }
                  return null;
                },
              ),
            ),
            const SizedBox(height: 16),
            Semantics(
              label: 'Gender',
              child: DropdownButtonFormField<String>(
                value: _gender,
                decoration: const InputDecoration(
                  labelText: 'Gender',
                  border: OutlineInputBorder(),
                ),
                items: const [
                  DropdownMenuItem(value: 'male', child: Text('Male')),
                  DropdownMenuItem(value: 'female', child: Text('Female')),
                  DropdownMenuItem(
                      value: 'non_binary', child: Text('Non-binary')),
                  DropdownMenuItem(
                      value: 'prefer_not_to_say',
                      child: Text('Prefer not to say')),
                ],
                onChanged: (v) => setState(() => _gender = v),
              ),
            ),
            if (errorMessage != null) ...[
              const SizedBox(height: 16),
              Semantics(
                liveRegion: true,
                child: _ErrorBanner(message: errorMessage),
              ),
            ],
            if (successMessage != null) ...[
              const SizedBox(height: 16),
              Semantics(
                liveRegion: true,
                child: _SuccessBanner(message: successMessage),
              ),
            ],
            const SizedBox(height: 24),
            AppButton(label: 'Save changes', onPressed: _submit),
          ],
        ),
      ),
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Theme.of(context).colorScheme.errorContainer,
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          message,
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: Theme.of(context).colorScheme.onErrorContainer,
              ),
        ),
      );
}

class _SuccessBanner extends StatelessWidget {
  const _SuccessBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Colors.green.withOpacity(0.1),
          border: Border.all(color: Colors.green),
          borderRadius: BorderRadius.circular(8),
        ),
        child: Text(
          message,
          style: Theme.of(context)
              .textTheme
              .bodySmall
              ?.copyWith(color: Colors.green.shade800),
        ),
      );
}
