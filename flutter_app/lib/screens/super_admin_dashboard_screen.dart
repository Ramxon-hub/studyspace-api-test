import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config/api_config.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import 'library_selector_screen.dart';

class SuperAdminDashboardScreen extends StatefulWidget {
  const SuperAdminDashboardScreen({Key? key}) : super(key: key);

  @override
  State<SuperAdminDashboardScreen> createState() => _SuperAdminDashboardScreenState();
}

class _SuperAdminDashboardScreenState extends State<SuperAdminDashboardScreen> {
  int _activeNavIndex = 0;
  bool _isLoading = true;
  String _searchQuery = '';

  // Data Stores
  Map<String, dynamic> _stats = {};
  List<dynamic> _libraries = [];
  List<dynamic> _tenantAdmins = [];
  List<dynamic> _plans = [];
  List<dynamic> _invoices = [];
  List<dynamic> _payments = [];
  List<dynamic> _renewalRequests = [];
  List<dynamic> _backups = [];
  List<dynamic> _auditLogs = [];
  Map<String, dynamic> _supportSettings = {};

  final List<Map<String, dynamic>> _navItems = const [
    {'title': 'Overview', 'icon': Icons.dashboard_rounded},
    {'title': 'Library Directory', 'icon': Icons.apartment_rounded},
    {'title': 'Tenant Admins', 'icon': Icons.people_alt_rounded},
    {'title': 'Subscriptions & Plans', 'icon': Icons.card_membership_rounded},
    {'title': 'Invoices & Billing', 'icon': Icons.receipt_long_rounded},
    {'title': 'Manual Payments', 'icon': Icons.payments_rounded},
    {'title': 'Dynamic Branding', 'icon': Icons.brush_rounded},
    {'title': 'Tenant Databases', 'icon': Icons.storage_rounded},
    {'title': 'Backup & Restore', 'icon': Icons.backup_rounded},
    {'title': 'Audit Logs', 'icon': Icons.security_rounded},
    {'title': 'Platform Settings', 'icon': Icons.settings_rounded},
  ];

  @override
  void initState() {
    super.initState();
    _loadAllData();
  }

  Future<void> _loadAllData() async {
    if (!mounted) return;
    setState(() {
      _isLoading = true;
    });

    try {
      final statsRes = await ApiService.superAdminCall('dashboard_stats');
      final libsRes = await ApiService.superAdminCall('list_libraries');
      final adminsRes = await ApiService.superAdminCall('list_tenant_admins');
      final plansRes = await ApiService.superAdminCall('list_plans');
      final invRes = await ApiService.superAdminCall('list_invoices');
      final payRes = await ApiService.superAdminCall('list_manual_payments');
      final renewRes = await ApiService.superAdminCall('list_renewal_requests');
      final backupRes = await ApiService.superAdminCall('list_backups');
      final auditRes = await ApiService.superAdminCall('list_audit_logs');
      final suppRes = await ApiService.superAdminCall('get_support_settings');

      if (mounted) {
        setState(() {
          _isLoading = false;
          if (statsRes['success'] == true) _stats = statsRes['stats'] ?? {};
          if (libsRes['success'] == true) _libraries = libsRes['libraries'] ?? [];
          if (adminsRes['success'] == true) _tenantAdmins = adminsRes['admins'] ?? [];
          if (plansRes['success'] == true) _plans = plansRes['plans'] ?? [];
          if (invRes['success'] == true) _invoices = invRes['invoices'] ?? [];
          if (payRes['success'] == true) _payments = payRes['payments'] ?? [];
          if (renewRes['success'] == true) _renewalRequests = renewRes['requests'] ?? [];
          if (backupRes['success'] == true) _backups = backupRes['backups'] ?? [];
          if (auditRes['success'] == true) _auditLogs = auditRes['logs'] ?? [];
          if (suppRes['success'] == true) _supportSettings = suppRes['settings'] ?? {};
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _isLoading = false;
        });
      }
    }
  }

  // --- ACTIONS ---

  Future<void> _toggleLibraryStatus(String code, String currentStatus) async {
    final action = currentStatus == 'active' ? 'suspend_library' : 'activate_library';
    final actionLabel = currentStatus == 'active' ? 'Suspend' : 'Activate';

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1E293B),
        title: Text("$actionLabel Library $code?", style: const TextStyle(color: Colors.white)),
        content: Text(
          currentStatus == 'active'
              ? "Suspending $code will block client access to $code while keeping other libraries fully operational."
              : "Activating $code will restore access to $code.",
          style: const TextStyle(color: Color(0xFF94A3B8)),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text("Cancel")),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: currentStatus == 'active' ? Colors.red : Colors.green),
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(actionLabel),
          ),
        ],
      ),
    );

    if (confirm != true) return;

    final res = await ApiService.superAdminCall(action, {'library_code': code});
    if (res['success'] == true && mounted) {
      _showToast(res['message'] ?? "Library $code status updated.", isError: false);
      _loadAllData();
    } else if (mounted) {
      _showToast(res['error'] ?? "Action failed.", isError: true);
    }
  }

  Future<void> _provisionDatabase(String code) async {
    _showToast("Provisioning database for $code...", isError: false);
    final res = await ApiService.superAdminCall('provision_database', {'library_code': code});
    if (res['success'] == true && mounted) {
      _showToast(res['message'] ?? "Database provisioned for $code.", isError: false);
      _loadAllData();
    } else if (mounted) {
      _showToast(res['error'] ?? "Provisioning failed.", isError: true);
    }
  }

  Future<void> _checkDbStatus(String code) async {
    _showToast("Checking database connection for $code...", isError: false);
    final res = await ApiService.superAdminCall('check_db_status', {'library_code': code});
    if (res['success'] == true && mounted) {
      _showToast("DB Status for $code: ${res['connection_status']} (${res['table_count']} tables)", isError: false);
      _loadAllData();
    } else if (mounted) {
      _showToast(res['error'] ?? "Connection check failed.", isError: true);
    }
  }

  void _showToast(String message, {bool isError = false}) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(message),
        backgroundColor: isError ? Colors.red.shade700 : const Color(0xFF10B981),
        behavior: SnackBarBehavior.floating,
      ),
    );
  }

  // --- DIALOGS ---

  void _showCreateLibraryDialog() {
    final codeCtrl = TextEditingController();
    final nameCtrl = TextEditingController();
    final personCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final emailCtrl = TextEditingController();
    final addressCtrl = TextEditingController();
    final adminUserCtrl = TextEditingController();
    final adminPassCtrl = TextEditingController();

    String selectedPlan = 'MONTHLY';
    String maxStudents = '100';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          backgroundColor: const Color(0xFF1E293B),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: const Row(
            children: [
              Icon(Icons.add_business_rounded, color: Color(0xFF8B5CF6)),
              SizedBox(width: 8),
              Text("Create New Library Tenant", style: TextStyle(color: Colors.white, fontSize: 18)),
            ],
          ),
          content: SizedBox(
            width: 480,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  _dialogTextField(codeCtrl, "LIBRARY CODE (e.g. LIB004)", hint: "LIB004"),
                  const SizedBox(height: 12),
                  _dialogTextField(nameCtrl, "LIBRARY NAME", hint: "Apex Study Library"),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(child: _dialogTextField(personCtrl, "CONTACT PERSON")),
                      const SizedBox(width: 12),
                      Expanded(child: _dialogTextField(phoneCtrl, "PHONE NUMBER")),
                    ],
                  ),
                  const SizedBox(height: 12),
                  _dialogTextField(emailCtrl, "CONTACT EMAIL"),
                  const SizedBox(height: 12),
                  _dialogTextField(addressCtrl, "ADDRESS"),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            const Text("SUBSCRIPTION PLAN", style: TextStyle(color: Color(0xFF94A3B8), fontSize: 10, fontWeight: FontWeight.bold)),
                            const SizedBox(height: 4),
                            DropdownButtonFormField<String>(
                              value: selectedPlan,
                              dropdownColor: const Color(0xFF0F172A),
                              style: const TextStyle(color: Colors.white, fontSize: 14),
                              decoration: _inputDecoration(),
                              items: ['TRIAL', 'MONTHLY', 'YEARLY'].map((p) => DropdownMenuItem(value: p, child: Text(p))).toList(),
                              onChanged: (v) => setDlgState(() => selectedPlan = v!),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(child: _dialogTextField(TextEditingController(text: maxStudents), "MAX CAPACITY", hint: "100")),
                    ],
                  ),
                  const SizedBox(height: 16),
                  const Text("INITIAL TENANT ADMIN CREDENTIALS", style: TextStyle(color: Color(0xFFA78BFA), fontSize: 11, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(child: _dialogTextField(adminUserCtrl, "ADMIN EMAIL", hint: "admin@lib004.com")),
                      const SizedBox(width: 12),
                      Expanded(child: _dialogTextField(adminPassCtrl, "ADMIN PASSWORD", hint: "AdminPass123!", obscure: true)),
                    ],
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text("Cancel")),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF8B5CF6)),
              onPressed: () async {
                final code = codeCtrl.text.trim().toUpperCase();
                final name = nameCtrl.text.trim();
                if (code.isEmpty || name.isEmpty) return;

                Navigator.pop(ctx);
                _showToast("Creating & provisioning library $code...", isError: false);

                final res = await ApiService.superAdminCall('create_library', {
                  'library_code': code,
                  'name': name,
                  'contact_person': personCtrl.text.trim(),
                  'phone': phoneCtrl.text.trim(),
                  'email': emailCtrl.text.trim(),
                  'address': addressCtrl.text.trim(),
                  'plan_name': selectedPlan,
                  'max_students': maxStudents,
                  'admin_username': adminUserCtrl.text.trim(),
                  'admin_password': adminPassCtrl.text.trim(),
                });

                if (res['success'] == true && mounted) {
                  _showToast(res['message'] ?? "Library $code created cleanly.", isError: false);
                  _loadAllData();
                } else if (mounted) {
                  _showToast(res['error'] ?? "Creation failed.", isError: true);
                }
              },
              child: const Text("Create & Provision"),
            ),
          ],
        ),
      ),
    );
  }

  void _showCreateAdminDialog([String? defaultLibCode]) {
    final libCodeCtrl = TextEditingController(text: defaultLibCode ?? '');
    final nameCtrl = TextEditingController();
    final emailCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final passCtrl = TextEditingController();
    final confirmPassCtrl = TextEditingController();

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1E293B),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Row(
          children: [
            Icon(Icons.person_add_rounded, color: Color(0xFF8B5CF6)),
            SizedBox(width: 8),
            Text("Create Tenant Admin Account", style: TextStyle(color: Colors.white, fontSize: 18)),
          ],
        ),
        content: SizedBox(
          width: 440,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                _dialogTextField(libCodeCtrl, "TARGET LIBRARY CODE", hint: "LIB001"),
                const SizedBox(height: 10),
                _dialogTextField(nameCtrl, "ADMIN FULL NAME", hint: "Library Administrator"),
                const SizedBox(height: 10),
                _dialogTextField(emailCtrl, "ADMIN EMAIL / USERNAME", hint: "admin@lib001.com"),
                const SizedBox(height: 10),
                _dialogTextField(phoneCtrl, "PHONE NUMBER", hint: "+91 98765 00000"),
                const SizedBox(height: 10),
                _dialogTextField(passCtrl, "PASSWORD", hint: "••••••••", obscure: true),
                const SizedBox(height: 10),
                _dialogTextField(confirmPassCtrl, "CONFIRM PASSWORD", hint: "••••••••", obscure: true),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text("Cancel")),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF8B5CF6)),
            onPressed: () async {
              final code = libCodeCtrl.text.trim().toUpperCase();
              final email = emailCtrl.text.trim();
              final pass = passCtrl.text.trim();
              final confirmPass = confirmPassCtrl.text.trim();

              if (code.isEmpty || email.isEmpty || pass.isEmpty) {
                _showToast("Library code, email and password are required.", isError: true);
                return;
              }
              if (pass != confirmPass) {
                _showToast("Passwords do not match.", isError: true);
                return;
              }

              Navigator.pop(ctx);
              final res = await ApiService.superAdminCall('create_library_admin', {
                'library_code': code,
                'name': nameCtrl.text.trim().isNotEmpty ? nameCtrl.text.trim() : "Admin - $code",
                'email': email,
                'phone': phoneCtrl.text.trim(),
                'password': pass,
              });

              if (res['success'] == true && mounted) {
                _showToast(res['message'] ?? "Admin created for $code.", isError: false);
                _loadAllData();
              } else if (mounted) {
                _showToast(res['error'] ?? "Failed to create admin.", isError: true);
              }
            },
            child: const Text("Create Admin"),
          ),
        ],
      ),
    );
  }

  void _showResetAdminPasswordDialog(String libCode, String adminEmail) {
    final newPassCtrl = TextEditingController();
    final confirmPassCtrl = TextEditingController();

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1E293B),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Text("Reset Password for $adminEmail ($libCode)", style: const TextStyle(color: Colors.white, fontSize: 16)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text("Enter new password for this tenant admin. Plaintext passwords are never logged or stored.", style: TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
            const SizedBox(height: 14),
            _dialogTextField(newPassCtrl, "NEW PASSWORD", hint: "••••••••", obscure: true),
            const SizedBox(height: 10),
            _dialogTextField(confirmPassCtrl, "CONFIRM NEW PASSWORD", hint: "••••••••", obscure: true),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text("Cancel")),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFEF4444)),
            onPressed: () async {
              final newPass = newPassCtrl.text.trim();
              final confirmPass = confirmPassCtrl.text.trim();

              if (newPass.isEmpty || newPass.length < 6) {
                _showToast("Password must be at least 6 characters.", isError: true);
                return;
              }
              if (newPass != confirmPass) {
                _showToast("Passwords do not match.", isError: true);
                return;
              }

              Navigator.pop(ctx);
              final res = await ApiService.superAdminCall('reset_admin_password', {
                'library_code': libCode,
                'email': adminEmail,
                'new_password': newPass,
              });

              if (res['success'] == true && mounted) {
                _showToast(res['message'] ?? "Password reset successfully.", isError: false);
                _loadAllData();
              } else if (mounted) {
                _showToast(res['error'] ?? "Password reset failed.", isError: true);
              }
            },
            child: const Text("Reset Password"),
          ),
        ],
      ),
    );
  }

  void _showEditBrandingDialog(Map<String, dynamic> lib) {
    final code = lib['library_code'].toString();
    final nameCtrl = TextEditingController(text: lib['name']?.toString() ?? '');
    final taglineCtrl = TextEditingController(text: lib['tagline']?.toString() ?? '');
    final phoneCtrl = TextEditingController(text: lib['phone']?.toString() ?? '');
    final colorCtrl = TextEditingController(text: lib['primary_color']?.toString() ?? '#1D4ED8');
    final logoCtrl = TextEditingController(text: lib['logo_url']?.toString() ?? '');
    final appIconCtrl = TextEditingController(text: lib['app_icon_url']?.toString() ?? '');
    bool isUploadingIcon = false;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          backgroundColor: const Color(0xFF1E293B),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Text("Edit Branding & App Icon: $code", style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold)),
          content: SizedBox(
            width: double.maxFinite,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // App Icon Preview & Sync Section
                  Container(
                    padding: const EdgeInsets.all(12),
                    margin: const EdgeInsets.only(bottom: 14),
                    decoration: BoxDecoration(
                      color: const Color(0xFF0F172A),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFF334155)),
                    ),
                    child: Row(
                      children: [
                        Container(
                          width: 50,
                          height: 50,
                          decoration: BoxDecoration(
                            color: const Color(0xFF1E293B),
                            borderRadius: BorderRadius.circular(10),
                            border: Border.all(color: const Color(0xFF475569)),
                          ),
                          clipBehavior: Clip.antiAlias,
                          child: appIconCtrl.text.isNotEmpty
                              ? Image.network(
                                  appIconCtrl.text.startsWith('http')
                                      ? appIconCtrl.text
                                      : '${ApiConfig.baseUrl}/${appIconCtrl.text}',
                                  fit: BoxFit.cover,
                                  errorBuilder: (_, __, ___) => const Icon(Icons.broken_image_rounded, color: Colors.orange, size: 26),
                                )
                              : const Icon(Icons.apps_rounded, color: Color(0xFF38BDF8), size: 28),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              const Text(
                                "Launcher App Icon",
                                style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13),
                              ),
                              const SizedBox(height: 2),
                              Text(
                                appIconCtrl.text.isNotEmpty ? "Custom Icon URL Set" : "Default Flavor Icon",
                                style: TextStyle(
                                  color: appIconCtrl.text.isNotEmpty ? const Color(0xFF34D399) : const Color(0xFF94A3B8),
                                  fontSize: 11,
                                ),
                              ),
                            ],
                          ),
                        ),
                        if (isUploadingIcon)
                          const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                        else
                          ElevatedButton(
                            style: ElevatedButton.styleFrom(
                              backgroundColor: const Color(0xFF7C3AED),
                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                              textStyle: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold),
                            ),
                            onPressed: () async {
                              setDialogState(() => isUploadingIcon = true);
                              final syncRes = await ApiService.superAdminCall('sync_apk_launcher_icons');
                              setDialogState(() => isUploadingIcon = false);
                              if (syncRes['success'] == true) {
                                _showToast("Launcher icons synchronized across flavors!", isError: false);
                              } else {
                                _showToast(syncRes['error'] ?? "Icon sync failed", isError: true);
                              }
                            },
                            child: const Text("Sync Icons"),
                          ),
                      ],
                    ),
                  ),

                  _dialogTextField(nameCtrl, "LIBRARY NAME"),
                  const SizedBox(height: 10),
                  _dialogTextField(taglineCtrl, "TAGLINE / SUBTITLE"),
                  const SizedBox(height: 10),
                  _dialogTextField(phoneCtrl, "CONTACT PHONE"),
                  const SizedBox(height: 10),
                  _dialogTextField(colorCtrl, "PRIMARY COLOR HEX (#1D4ED8)"),
                  const SizedBox(height: 10),
                  _dialogTextField(logoCtrl, "LOGO URL"),
                  const SizedBox(height: 10),
                  _dialogTextField(appIconCtrl, "APP ICON URL / PATH", hint: "uploads/icons/lib001_icon.png"),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text("Cancel")),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF3B82F6)),
              onPressed: () async {
                Navigator.pop(ctx);
                final res = await ApiService.superAdminCall('update_library', {
                  'library_code': code,
                  'name': nameCtrl.text.trim(),
                  'phone': phoneCtrl.text.trim(),
                  'tagline': taglineCtrl.text.trim(),
                  'primary_color': colorCtrl.text.trim(),
                  'logo_url': logoCtrl.text.trim(),
                  'app_icon_url': appIconCtrl.text.trim(),
                });
                if (res['success'] == true && mounted) {
                  _showToast("Branding & icon updated for $code!", isError: false);
                  _loadAllData();
                } else if (mounted) {
                  _showToast(res['error'] ?? "Failed to update branding.", isError: true);
                }
              },
              child: const Text("Save Branding"),
            ),
          ],
        ),
      ),
    );
  }

  void _showRenewSubscriptionDialog([String? defaultCode]) {
    final codeCtrl = TextEditingController(text: defaultCode ?? '');
    final monthsCtrl = TextEditingController(text: '12');
    final paymentRefCtrl = TextEditingController(text: 'REF-${DateTime.now().millisecondsSinceEpoch}');
    String selectedPlan = 'YEARLY';

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1E293B),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: const Text("Renew Subscription", style: TextStyle(color: Colors.white)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            _dialogTextField(codeCtrl, "LIBRARY CODE", hint: "LIB001"),
            const SizedBox(height: 10),
            _dialogTextField(monthsCtrl, "DURATION (MONTHS)", hint: "12"),
            const SizedBox(height: 10),
            _dialogTextField(paymentRefCtrl, "PAYMENT REFERENCE"),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text("Cancel")),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF10B981)),
            onPressed: () async {
              final code = codeCtrl.text.trim().toUpperCase();
              final months = int.tryParse(monthsCtrl.text.trim()) ?? 12;
              if (code.isEmpty) return;

              Navigator.pop(ctx);
              final res = await ApiService.superAdminCall('renew_subscription', {
                'library_code': code,
                'months': months.toString(),
                'plan_name': selectedPlan,
                'payment_reference': paymentRefCtrl.text.trim(),
              });

              if (res['success'] == true && mounted) {
                _showToast(res['message'] ?? "Subscription renewed.", isError: false);
                _loadAllData();
              } else if (mounted) {
                _showToast(res['error'] ?? "Renewal failed.", isError: true);
              }
            },
            child: const Text("Renew & Extend"),
          ),
        ],
      ),
    );
  }

  void _showCreateBackupDialog() {
    String selectedCode = 'MASTER';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          backgroundColor: const Color(0xFF1E293B),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: const Text("Create Database Backup", style: TextStyle(color: Colors.white)),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text("Select target database to snapshot:", style: TextStyle(color: Color(0xFF94A3B8), fontSize: 13)),
              const SizedBox(height: 12),
              DropdownButtonFormField<String>(
                value: selectedCode,
                dropdownColor: const Color(0xFF0F172A),
                style: const TextStyle(color: Colors.white, fontSize: 14),
                decoration: _inputDecoration(),
                items: [
                  const DropdownMenuItem(value: 'MASTER', child: Text("Master Control DB (studyspace_master.sqlite)")),
                  ..._libraries.map((l) => DropdownMenuItem(
                    value: l['library_code'].toString(),
                    child: Text("Library ${l['library_code']} (${l['name']})"),
                  )),
                ],
                onChanged: (v) => setDlgState(() => selectedCode = v!),
              ),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text("Cancel")),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF8B5CF6)),
              onPressed: () async {
                Navigator.pop(ctx);
                _showToast("Creating backup for $selectedCode...", isError: false);

                final res = await ApiService.superAdminCall('create_backup', {'library_code': selectedCode});
                if (res['success'] == true && mounted) {
                  _showToast("Backup created: ${res['backup']['file_name']}", isError: false);
                  _loadAllData();
                } else if (mounted) {
                  _showToast(res['error'] ?? "Backup creation failed.", isError: true);
                }
              },
              child: const Text("Create Snapshot"),
            ),
          ],
        ),
      ),
    );
  }

  void _showRestoreBackupDialog(Map<String, dynamic> backup) {
    final backupId = backup['backup_id'].toString();
    final targetCode = backup['library_code']?.toString() ?? 'MASTER';

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1E293B),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        title: Row(
          children: [
            const Icon(Icons.warning_amber_rounded, color: Colors.amber),
            const SizedBox(width: 8),
            Text("Restore Database $targetCode?", style: const TextStyle(color: Colors.white, fontSize: 16)),
          ],
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text("Backup ID: $backupId", style: const TextStyle(color: Color(0xFFA78BFA), fontWeight: FontWeight.bold, fontSize: 12)),
            const SizedBox(height: 8),
            const Text("Restoring will create an automatic pre-restore safety snapshot, verify SQLite integrity, and overwrite the target database file.", style: TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
            const SizedBox(height: 8),
            const Text("Cross-tenant restorations are strictly blocked.", style: TextStyle(color: Colors.amber, fontSize: 11, fontWeight: FontWeight.bold)),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text("Cancel")),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () async {
              Navigator.pop(ctx);
              _showToast("Restoring database from backup $backupId...", isError: false);

              final res = await ApiService.superAdminCall('restore_backup', {
                'library_code': targetCode,
                'backup_id': backupId,
              });

              if (res['success'] == true && mounted) {
                _showToast(res['message'] ?? "Database restored cleanly.", isError: false);
                _loadAllData();
              } else if (mounted) {
                _showToast(res['error'] ?? "Restoration failed.", isError: true);
              }
            },
            child: const Text("Execute Restore"),
          ),
        ],
      ),
    );
  }

  void _createTenantBackup(String code) async {
    _showToast("Creating backup snapshot for $code...", isError: false);
    final res = await ApiService.superAdminCall('create_backup', {'library_code': code});
    if (res['success'] == true && mounted) {
      _showToast("Backup created: ${res['backup']['file_name']}", isError: false);
      _loadAllData();
    } else if (mounted) {
      _showToast(res['error'] ?? "Backup failed.", isError: true);
    }
  }

  void _showLibraryDetailsSheet(String code) async {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xFF0F172A),
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) {
        return FutureBuilder<Map<String, dynamic>>(
          future: ApiService.superAdminCall('get_library', {'library_code': code}),
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return Container(
                height: 350,
                alignment: Alignment.center,
                child: const Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    CircularProgressIndicator(color: Color(0xFF8B5CF6)),
                    SizedBox(height: 14),
                    Text("Loading 12-dimension operational data...", style: TextStyle(color: Color(0xFF94A3B8), fontSize: 13)),
                  ],
                ),
              );
            }

            final data = snapshot.data;
            if (data == null || data['success'] != true) {
              return Container(
                padding: const EdgeInsets.all(24),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Icon(Icons.error_outline_rounded, color: Colors.red, size: 40),
                    const SizedBox(height: 12),
                    Text(data?['error'] ?? "Failed to load library details.", style: const TextStyle(color: Colors.white, fontSize: 14), textAlign: TextAlign.center),
                    const SizedBox(height: 16),
                    ElevatedButton(onPressed: () => Navigator.pop(ctx), child: const Text("Close")),
                  ],
                ),
              );
            }

            final lib = data['library'] as Map<String, dynamic>;
            final m = (lib['metrics'] as Map<String, dynamic>?) ?? {};
            final isAvailable = m['available'] == true;

            return DraggableScrollableSheet(
              expand: false,
              initialChildSize: 0.85,
              minChildSize: 0.5,
              maxChildSize: 0.95,
              builder: (_, scrollCtrl) {
                return ListView(
                  controller: scrollCtrl,
                  padding: const EdgeInsets.all(16),
                  children: [
                    Center(child: Container(width: 40, height: 4, decoration: BoxDecoration(color: const Color(0xFF334155), borderRadius: BorderRadius.circular(2)))),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                          decoration: BoxDecoration(color: const Color(0xFF3B82F6).withOpacity(0.2), borderRadius: BorderRadius.circular(6)),
                          child: Text(code, style: const TextStyle(color: Color(0xFF60A5FA), fontWeight: FontWeight.bold, fontSize: 14)),
                        ),
                        const SizedBox(width: 10),
                        Expanded(child: Text(lib['name']?.toString() ?? '', style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold))),
                        Chip(
                          label: Text((lib['status']?.toString() ?? 'ACTIVE').toUpperCase(), style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold)),
                          backgroundColor: lib['status'] == 'active' ? Colors.green : Colors.red,
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Text("Plan: ${lib['plan_name'] ?? 'Standard'} | Valid Until: ${lib['valid_until'] ?? 'N/A'}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
                    Text("Contact: ${lib['contact_person'] ?? 'N/A'} (${lib['phone'] ?? 'N/A'})", style: const TextStyle(color: Color(0xFF64748B), fontSize: 12)),
                    const Divider(color: Color(0xFF334155), height: 24),
                    if (!isAvailable) ...[
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(color: Colors.red.withOpacity(0.15), borderRadius: BorderRadius.circular(8)),
                        child: Text(m['error']?.toString() ?? "Tenant database is currently unavailable.", style: const TextStyle(color: Colors.redAccent, fontSize: 12)),
                      ),
                    ] else ...[
                      // Database Health Header
                      Container(
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(color: const Color(0xFF1E293B), borderRadius: BorderRadius.circular(10), border: Border.all(color: const Color(0xFF334155))),
                        child: Row(
                          children: [
                            const Icon(Icons.storage_rounded, color: Color(0xFFA78BFA), size: 24),
                            const SizedBox(width: 10),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text("SQLite DB: ${m['database']?['size_formatted'] ?? 'N/A'} (Integrity: ${m['database']?['integrity'] ?? 'N/A'})", style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 12)),
                                  Text("Path: ${m['database']?['file_path'] ?? 'N/A'}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 10), maxLines: 1, overflow: TextOverflow.ellipsis),
                                ],
                              ),
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                              decoration: BoxDecoration(color: (m['database']?['status'] == 'HEALTHY' ? Colors.green : Colors.red).withOpacity(0.2), borderRadius: BorderRadius.circular(6)),
                              child: Text(m['database']?['status'] ?? 'OK', style: TextStyle(color: m['database']?['status'] == 'HEALTHY' ? Colors.green : Colors.red, fontWeight: FontWeight.bold, fontSize: 10)),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 12),
                      // 12-Dimension Grid
                      GridView.count(
                        crossAxisCount: 2,
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        crossAxisSpacing: 10,
                        mainAxisSpacing: 10,
                        childAspectRatio: 1.6,
                        children: [
                          _detailMetricCard("Students", "${m['students']?['active'] ?? 0} Active", "Total: ${m['students']?['total'] ?? 0} | Pending: ${m['students']?['pending'] ?? 0}", Icons.school_rounded, const Color(0xFF3B82F6)),
                          _detailMetricCard("Parents", "${m['parents']?['linked_parents'] ?? 0} Linked", "Total: ${m['parents']?['total'] ?? 0} | Links: ${m['parents']?['total_links'] ?? 0}", Icons.family_restroom_rounded, const Color(0xFF10B981)),
                          _detailMetricCard("Admins", "${m['admins']?['total'] ?? 0} Active", "Library Owners & Staff", Icons.admin_panel_settings_rounded, const Color(0xFFF59E0B)),
                          _detailMetricCard("Seats & Desks", "${m['seats']?['allocated'] ?? 0} Allocated", "Total: ${m['seats']?['total'] ?? 0} | Free: ${(m['seats']?['total'] ?? 0) - (m['seats']?['allocated'] ?? 0)}", Icons.event_seat_rounded, const Color(0xFF8B5CF6)),
                          _detailMetricCard("Shifts", "${m['shifts']?['active'] ?? 0} Active", "Configured Shifts: ${m['shifts']?['total'] ?? 0}", Icons.schedule_rounded, const Color(0xFF06B6D4)),
                          _detailMetricCard("Attendance", "${m['attendance']?['today_records'] ?? 0} Today", "Total records: ${m['attendance']?['total_records'] ?? 0}", Icons.fact_check_rounded, const Color(0xFFEC4899)),
                          _detailMetricCard("Fee Collections", "${m['fees']?['total_collected_formatted'] ?? '₹0'}", "Pending Dues: ${m['fees']?['pending_dues_formatted'] ?? '₹0'}", Icons.account_balance_wallet_rounded, const Color(0xFF10B981)),
                          _detailMetricCard("Complaints", "${m['complaints']?['open'] ?? 0} Open", "Total tickets: ${m['complaints']?['total'] ?? 0}", Icons.support_agent_rounded, const Color(0xFFEF4444)),
                          _detailMetricCard("Communications", "${(m['communications']?['notifications'] ?? 0) + (m['communications']?['chat_messages'] ?? 0)} Msgs", "Notices: ${m['communications']?['notifications']} | Chat: ${m['communications']?['chat_messages']}", Icons.chat_bubble_rounded, const Color(0xFF6366F1)),
                          _detailMetricCard("Latest Backup", m['latest_backup'] != null ? "${m['latest_backup']['file_size_formatted']}" : "No Backup", m['latest_backup'] != null ? "${m['latest_backup']['created_at']}" : "Create one now", Icons.backup_rounded, const Color(0xFFA78BFA)),
                        ],
                      ),
                      const SizedBox(height: 16),
                      Row(
                        children: [
                          Expanded(
                            child: OutlinedButton.icon(
                              style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Color(0xFF334155)), padding: const EdgeInsets.symmetric(vertical: 12)),
                              onPressed: () => Navigator.pop(ctx),
                              icon: const Icon(Icons.close_rounded, size: 16),
                              label: const Text("Close"),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: ElevatedButton.icon(
                              style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF8B5CF6), padding: const EdgeInsets.symmetric(vertical: 12)),
                              onPressed: () {
                                Navigator.pop(ctx);
                                _createTenantBackup(code);
                              },
                              icon: const Icon(Icons.backup_rounded, size: 16),
                              label: const Text("Create Backup"),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ],
                );
              },
            );
          },
        );
      },
    );
  }

  Widget _detailMetricCard(String title, String val, String sub, IconData icon, Color color) {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: const Color(0xFF1E293B),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: const Color(0xFF334155)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Row(
            children: [
              Icon(icon, size: 14, color: color),
              const SizedBox(width: 6),
              Expanded(child: Text(title, style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 11, fontWeight: FontWeight.bold), maxLines: 1, overflow: TextOverflow.ellipsis)),
            ],
          ),
          const SizedBox(height: 4),
          Text(val, style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13), maxLines: 1, overflow: TextOverflow.ellipsis),
          Text(sub, style: const TextStyle(color: Color(0xFF64748B), fontSize: 9), maxLines: 1, overflow: TextOverflow.ellipsis),
        ],
      ),
    );
  }

  void _showRejectPaymentDialog(String paymentId) {
    final reasonCtrl = TextEditingController();

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1E293B),
        title: const Text("Reject Manual Payment", style: TextStyle(color: Colors.white)),
        content: _dialogTextField(reasonCtrl, "REJECTION REASON", hint: "Invalid UTR or payment not received in bank"),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text("Cancel")),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () async {
              final reason = reasonCtrl.text.trim();
              if (reason.isEmpty) return;

              Navigator.pop(ctx);
              final res = await ApiService.superAdminCall('reject_manual_payment', {
                'payment_id': paymentId,
                'rejection_reason': reason,
              });

              if (res['success'] == true && mounted) {
                _showToast("Payment rejected.", isError: false);
                _loadAllData();
              } else if (mounted) {
                _showToast(res['error'] ?? "Action failed.", isError: true);
              }
            },
            child: const Text("Reject Payment"),
          ),
        ],
      ),
    );
  }

  // Helper Inputs
  Widget _dialogTextField(TextEditingController controller, String label, {String? hint, bool obscure = false}) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 10, fontWeight: FontWeight.bold)),
        const SizedBox(height: 4),
        TextField(
          controller: controller,
          obscureText: obscure,
          style: const TextStyle(color: Colors.white, fontSize: 14),
          decoration: _inputDecoration(hint: hint),
        ),
      ],
    );
  }

  InputDecoration _inputDecoration({String? hint}) {
    return InputDecoration(
      hintText: hint,
      hintStyle: const TextStyle(color: Color(0xFF64748B), fontSize: 13),
      filled: true,
      fillColor: const Color(0xFF0F172A),
      contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: BorderSide.none),
    );
  }

  // --- BUILD METHOD & NAVIGATION LAYOUT ---

  @override
  Widget build(BuildContext context) {
    final authProvider = Provider.of<AuthProvider>(context);
    final activeItem = _navItems[_activeNavIndex];

    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        backgroundColor: const Color(0xFF1E293B),
        elevation: 0,
        title: Row(
          children: [
            Icon(activeItem['icon'] as IconData, color: const Color(0xFFA78BFA), size: 20),
            const SizedBox(width: 8),
            Text(
              activeItem['title'] as String,
              style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold),
            ),
          ],
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded, color: Colors.white),
            tooltip: "Refresh Data",
            onPressed: _loadAllData,
          ),
          IconButton(
            icon: const Icon(Icons.logout_rounded, color: Color(0xFFFCA5A5)),
            tooltip: "Logout Super Admin",
            onPressed: () async {
              await authProvider.superAdminLogout();
              if (mounted) {
                Navigator.of(context).pushReplacement(
                  MaterialPageRoute(builder: (_) => const LibrarySelectorScreen()),
                );
              }
            },
          ),
        ],
      ),
      drawer: Drawer(
        backgroundColor: const Color(0xFF0F172A),
        child: Column(
          children: [
            Container(
              padding: const EdgeInsets.fromLTRB(16, 48, 16, 20),
              color: const Color(0xFF1E293B),
              child: const Row(
                children: [
                  CircleAvatar(
                    backgroundColor: Color(0xFF7C3AED),
                    child: Icon(Icons.stars_rounded, color: Colors.white),
                  ),
                  SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text("StudySpace SaaS", style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16)),
                        Text("Super Admin Portal", style: TextStyle(color: Color(0xFFA78BFA), fontSize: 12)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
            Expanded(
              child: ListView.builder(
                itemCount: _navItems.length,
                itemBuilder: (context, idx) {
                  final item = _navItems[idx];
                  final isSelected = idx == _activeNavIndex;
                  return ListTile(
                    selected: isSelected,
                    selectedTileColor: const Color(0xFF7C3AED).withOpacity(0.15),
                    leading: Icon(
                      item['icon'] as IconData,
                      color: isSelected ? const Color(0xFFA78BFA) : const Color(0xFF94A3B8),
                    ),
                    title: Text(
                      item['title'] as String,
                      style: TextStyle(
                        color: isSelected ? Colors.white : const Color(0xFFCBD5E1),
                        fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                        fontSize: 14,
                      ),
                    ),
                    onTap: () {
                      setState(() {
                        _activeNavIndex = idx;
                      });
                      Navigator.pop(context);
                    },
                  );
                },
              ),
            ),
          ],
        ),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: Color(0xFFA78BFA)))
          : RefreshIndicator(
              onRefresh: _loadAllData,
              color: const Color(0xFFA78BFA),
              child: _buildActiveView(),
            ),
      floatingActionButton: _activeNavIndex == 1
          ? FloatingActionButton.extended(
              backgroundColor: const Color(0xFF7C3AED),
              onPressed: _showCreateLibraryDialog,
              icon: const Icon(Icons.add_rounded),
              label: const Text("New Library"),
            )
          : (_activeNavIndex == 2
              ? FloatingActionButton.extended(
                  backgroundColor: const Color(0xFF7C3AED),
                  onPressed: () => _showCreateAdminDialog(),
                  icon: const Icon(Icons.person_add_rounded),
                  label: const Text("New Admin"),
                )
              : null),
    );
  }

  Widget _buildActiveView() {
    switch (_activeNavIndex) {
      case 0:
        return _buildOverviewView();
      case 1:
        return _buildLibraryDirectoryView();
      case 2:
        return _buildTenantAdminsView();
      case 3:
        return _buildSubscriptionsView();
      case 4:
        return _buildInvoicesView();
      case 5:
        return _buildManualPaymentsView();
      case 6:
        return _buildDynamicBrandingView();
      case 7:
        return _buildTenantDatabasesView();
      case 8:
        return _buildBackupRestoreView();
      case 9:
        return _buildAuditLogsView();
      case 10:
        return _buildPlatformSettingsView();
      default:
        return _buildOverviewView();
    }
  }

  // 1. OVERVIEW VIEW
  Widget _buildOverviewView() {
    return SingleChildScrollView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text("Platform Overview Metrics", style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
          const SizedBox(height: 14),
          LayoutBuilder(
            builder: (context, constraints) {
              final crossCount = constraints.maxWidth > 700 ? 4 : 2;
              return GridView.count(
                crossAxisCount: crossCount,
                crossAxisSpacing: 12,
                mainAxisSpacing: 12,
                childAspectRatio: 1.5,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                children: [
                  _statCard("Total Libraries", "${_stats['total_libraries'] ?? 0}", Icons.apartment_rounded, Colors.blue),
                  _statCard("Active Libraries", "${_stats['active_libraries'] ?? 0}", Icons.check_circle_rounded, Colors.green),
                  _statCard("Suspended", "${_stats['suspended_libraries'] ?? 0}", Icons.pause_circle_rounded, Colors.orange),
                  _statCard("Trial / Expired", "${_stats['trial_libraries'] ?? 0} / ${_stats['expired_libraries'] ?? 0}", Icons.hourglass_top_rounded, Colors.purple),
                  _statCard("Tenant Admins", "${_stats['total_tenant_admins'] ?? 0}", Icons.people_alt_rounded, const Color(0xFFA78BFA)),
                  _statCard("Pending Payments", "${_stats['pending_manual_payments'] ?? 0}", Icons.pending_actions_rounded, Colors.amber),
                  _statCard("Verified Payments", "${_stats['verified_manual_payments'] ?? 0}", Icons.price_check_rounded, const Color(0xFF10B981)),
                  _statCard("Total Collections", "₹${_stats['total_manual_collections'] ?? 0}", Icons.account_balance_wallet_rounded, Colors.indigo),
                ],
              );
            },
          ),
          const SizedBox(height: 24),
          const Text("Recent Registrations", style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 10),
          ...(_stats['recent_registrations'] as List? ?? []).map((lib) => Card(
            color: const Color(0xFF1E293B),
            margin: const EdgeInsets.only(bottom: 8),
            child: ListTile(
              title: Text("${lib['name']} (${lib['library_code']})", style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
              subtitle: Text("Created: ${lib['created_at'] ?? 'N/A'}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
              trailing: Chip(
                label: Text(lib['status'] ?? 'active', style: const TextStyle(color: Colors.white, fontSize: 11)),
                backgroundColor: lib['status'] == 'active' ? Colors.green : Colors.orange,
              ),
            ),
          )),
        ],
      ),
    );
  }

  Widget _statCard(String title, String value, IconData icon, Color color) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: const Color(0xFF1E293B),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFF334155)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Row(
            children: [
              Icon(icon, color: color, size: 20),
              const SizedBox(width: 6),
              Expanded(child: Text(title, style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 11), overflow: TextOverflow.ellipsis)),
            ],
          ),
          const SizedBox(height: 6),
          Text(value, style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }

  // 2. LIBRARY DIRECTORY VIEW
  Widget _buildLibraryDirectoryView() {
    final filtered = _libraries.where((lib) {
      final code = lib['library_code'].toString().toLowerCase();
      final name = lib['name'].toString().toLowerCase();
      return _searchQuery.isEmpty || code.contains(_searchQuery.toLowerCase()) || name.contains(_searchQuery.toLowerCase());
    }).toList();

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(12),
          child: TextField(
            style: const TextStyle(color: Colors.white, fontSize: 14),
            decoration: _inputDecoration(hint: "Search libraries by code or name...").copyWith(
              prefixIcon: const Icon(Icons.search_rounded, color: Color(0xFF94A3B8)),
            ),
            onChanged: (val) => setState(() => _searchQuery = val),
          ),
        ),
        Expanded(
          child: filtered.isEmpty
              ? const Center(child: Text("No libraries found matching criteria.", style: TextStyle(color: Color(0xFF94A3B8))))
              : ListView.builder(
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  itemCount: filtered.length,
                  itemBuilder: (context, idx) {
                    final lib = filtered[idx] as Map<String, dynamic>;
                    final code = lib['library_code'].toString();
                    final status = lib['status'].toString();
                    final isSuspended = status == 'suspended';

                    return Card(
                      color: const Color(0xFF1E293B),
                      margin: const EdgeInsets.only(bottom: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12), side: BorderSide(color: isSuspended ? Colors.red.withOpacity(0.5) : const Color(0xFF334155))),
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                Container(
                                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                  decoration: BoxDecoration(color: const Color(0xFF3B82F6).withOpacity(0.2), borderRadius: BorderRadius.circular(6)),
                                  child: Text(code, style: const TextStyle(color: Color(0xFF60A5FA), fontWeight: FontWeight.bold, fontSize: 13)),
                                ),
                                const SizedBox(width: 8),
                                Expanded(child: Text(lib['name']?.toString() ?? '', style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold))),
                                Chip(
                                  label: Text(status.toUpperCase(), style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold)),
                                  backgroundColor: isSuspended ? Colors.red : Colors.green,
                                ),
                              ],
                            ),
                            const SizedBox(height: 8),
                            Text("Plan: ${lib['plan_name'] ?? 'Standard'} | Expires: ${lib['valid_until'] ?? 'N/A'}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
                            Text("DB Path: ${lib['db_file'] ?? 'data/tenant_' + code.toLowerCase() + '.sqlite'}", style: const TextStyle(color: Color(0xFF64748B), fontSize: 11)),
                            const Divider(color: Color(0xFF334155), height: 20),
                            Wrap(
                              spacing: 8,
                              runSpacing: 8,
                              children: [
                                ElevatedButton.icon(
                                  style: ElevatedButton.styleFrom(backgroundColor: isSuspended ? Colors.green : Colors.red.shade700, padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6)),
                                  onPressed: () => _toggleLibraryStatus(code, status),
                                  icon: Icon(isSuspended ? Icons.play_arrow_rounded : Icons.block_rounded, size: 14),
                                  label: Text(isSuspended ? "Activate" : "Suspend", style: const TextStyle(fontSize: 11)),
                                ),
                                OutlinedButton.icon(
                                  style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Color(0xFF3B82F6))),
                                  onPressed: () => _showLibraryDetailsSheet(code),
                                  icon: const Icon(Icons.analytics_rounded, size: 14, color: Color(0xFF60A5FA)),
                                  label: const Text("View Data", style: TextStyle(fontSize: 11)),
                                ),
                                OutlinedButton.icon(
                                  style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Color(0xFF10B981))),
                                  onPressed: () => _createTenantBackup(code),
                                  icon: const Icon(Icons.backup_rounded, size: 14, color: Color(0xFF34D399)),
                                  label: const Text("Backup", style: TextStyle(fontSize: 11)),
                                ),
                                OutlinedButton.icon(
                                  style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Color(0xFF334155))),
                                  onPressed: () => _provisionDatabase(code),
                                  icon: const Icon(Icons.storage_rounded, size: 14, color: Color(0xFFA78BFA)),
                                  label: const Text("Provision DB", style: TextStyle(fontSize: 11)),
                                ),
                                OutlinedButton.icon(
                                  style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Color(0xFF334155))),
                                  onPressed: () => _showEditBrandingDialog(lib),
                                  icon: const Icon(Icons.brush_rounded, size: 14, color: Color(0xFF38BDF8)),
                                  label: const Text("Branding", style: TextStyle(fontSize: 11)),
                                ),
                                OutlinedButton.icon(
                                  style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Color(0xFF334155))),
                                  onPressed: () => _showCreateAdminDialog(code),
                                  icon: const Icon(Icons.person_add_rounded, size: 14, color: Color(0xFF10B981)),
                                  label: const Text("Add Admin", style: TextStyle(fontSize: 11)),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
        ),
      ],
    );
  }

  // 3. TENANT ADAMS VIEW
  Widget _buildTenantAdminsView() {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  style: const TextStyle(color: Colors.white, fontSize: 14),
                  decoration: _inputDecoration(hint: "Search admins by name, email or library...").copyWith(
                    prefixIcon: const Icon(Icons.search_rounded, color: Color(0xFF94A3B8)),
                  ),
                  onChanged: (val) => setState(() => _searchQuery = val),
                ),
              ),
              const SizedBox(width: 8),
              ElevatedButton.icon(
                style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF7C3AED)),
                onPressed: () => _showCreateAdminDialog(),
                icon: const Icon(Icons.add_rounded, size: 16),
                label: const Text("New Admin"),
              ),
            ],
          ),
        ),
        Expanded(
          child: _tenantAdmins.isEmpty
              ? const Center(child: Text("No tenant admins registered.", style: TextStyle(color: Color(0xFF94A3B8))))
              : ListView.builder(
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  itemCount: _tenantAdmins.length,
                  itemBuilder: (context, idx) {
                    final adm = _tenantAdmins[idx] as Map<String, dynamic>;
                    final lcode = adm['library_code'].toString();
                    final email = adm['email'].toString();
                    final name = adm['name']?.toString() ?? 'Admin';
                    final status = adm['status']?.toString() ?? 'approved';

                    if (_searchQuery.isNotEmpty) {
                      final sq = _searchQuery.toLowerCase();
                      if (!name.toLowerCase().contains(sq) && !email.toLowerCase().contains(sq) && !lcode.toLowerCase().contains(sq)) {
                        return const SizedBox.shrink();
                      }
                    }

                    return Card(
                      color: const Color(0xFF1E293B),
                      margin: const EdgeInsets.only(bottom: 8),
                      child: ListTile(
                        leading: CircleAvatar(
                          backgroundColor: const Color(0xFF3B82F6).withOpacity(0.2),
                          child: Text(lcode.substring(0, lcode.length > 3 ? 3 : lcode.length), style: const TextStyle(color: Color(0xFF60A5FA), fontSize: 10, fontWeight: FontWeight.bold)),
                        ),
                        title: Text("$name ($email)", style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14)),
                        subtitle: Text("Library: $lcode | Status: $status", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
                        trailing: ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFDC2626), padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6)),
                          onPressed: () => _showResetAdminPasswordDialog(lcode, email),
                          icon: const Icon(Icons.lock_reset_rounded, size: 14),
                          label: const Text("Reset Password", style: TextStyle(fontSize: 11)),
                        ),
                      ),
                    );
                  },
                ),
        ),
      ],
    );
  }

  // 4. SUBSCRIPTIONS VIEW
  Widget _buildSubscriptionsView() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        const Text("Available SaaS Plans", style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
        const SizedBox(height: 10),
        ..._plans.map((p) => Card(
          color: const Color(0xFF1E293B),
          margin: const EdgeInsets.only(bottom: 8),
          child: ListTile(
            title: Text(p['name'] ?? '', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            subtitle: Text("Price: ₹${p['price']} | Duration: ${p['duration_days']} days | Max Students: ${p['max_students']}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
            trailing: Chip(label: Text(p['plan_id'] ?? ''), backgroundColor: const Color(0xFF3B82F6)),
          ),
        )),
        const SizedBox(height: 20),
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            const Text("Renewal Requests", style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF10B981)),
              onPressed: () => _showRenewSubscriptionDialog(),
              icon: const Icon(Icons.autorenew_rounded, size: 16),
              label: const Text("Manual Renewal"),
            ),
          ],
        ),
        const SizedBox(height: 10),
        if (_renewalRequests.isEmpty)
          const Text("No pending subscription renewal requests.", style: TextStyle(color: Color(0xFF94A3B8))),
        ..._renewalRequests.map((r) => Card(
          color: const Color(0xFF1E293B),
          margin: const EdgeInsets.only(bottom: 8),
          child: ListTile(
            title: Text("${r['library_name']} (${r['library_code']})", style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            subtitle: Text("Requested: ${r['requested_plan']} | Status: ${r['status']}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
            trailing: ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF3B82F6)),
              onPressed: () => _showRenewSubscriptionDialog(r['library_code'].toString()),
              child: const Text("Process"),
            ),
          ),
        )),
      ],
    );
  }

  // 5. INVOICES VIEW
  Widget _buildInvoicesView() {
    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _invoices.length,
      itemBuilder: (context, idx) {
        final inv = _invoices[idx] as Map<String, dynamic>;
        final status = inv['status']?.toString() ?? 'DRAFT';

        return Card(
          color: const Color(0xFF1E293B),
          margin: const EdgeInsets.only(bottom: 10),
          child: ListTile(
            title: Text("${inv['invoice_number']} — ₹${inv['amount']}", style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            subtitle: Text("Library: ${inv['library_code']} | Due: ${inv['due_date']} | Ref: ${inv['payment_reference'] ?? 'N/A'}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
            trailing: Chip(
              label: Text(status),
              backgroundColor: status == 'PAID' ? Colors.green : (status == 'ISSUED' ? Colors.blue : Colors.orange),
            ),
          ),
        );
      },
    );
  }

  // 6. MANUAL PAYMENTS VIEW
  Widget _buildManualPaymentsView() {
    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _payments.length,
      itemBuilder: (context, idx) {
        final p = _payments[idx] as Map<String, dynamic>;
        final status = p['status']?.toString() ?? 'PENDING';
        final isPending = status == 'PENDING';

        return Card(
          color: const Color(0xFF1E293B),
          margin: const EdgeInsets.only(bottom: 12),
          child: Padding(
            padding: const EdgeInsets.all(12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text("${p['library_name']} (${p['library_code']})", style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 15)),
                    Text("₹${p['amount']}", style: const TextStyle(color: Color(0xFF10B981), fontWeight: FontWeight.bold, fontSize: 16)),
                  ],
                ),
                const SizedBox(height: 6),
                Text("UTR/Ref: ${p['payment_reference'] ?? 'N/A'} | Date: ${p['payment_date']} | Method: ${p['payment_method']}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
                const SizedBox(height: 10),
                Row(
                  children: [
                    Chip(label: Text(status), backgroundColor: status == 'VERIFIED' ? Colors.green : (status == 'PENDING' ? Colors.amber : Colors.red)),
                    const Spacer(),
                    if (isPending) ...[
                      OutlinedButton(
                        style: OutlinedButton.styleFrom(foregroundColor: Colors.red, side: const BorderSide(color: Colors.red)),
                        onPressed: () => _showRejectPaymentDialog(p['payment_id'].toString()),
                        child: const Text("Reject"),
                      ),
                      const SizedBox(width: 8),
                      ElevatedButton(
                        style: ElevatedButton.styleFrom(backgroundColor: Colors.green),
                        onPressed: () async {
                          final res = await ApiService.superAdminCall('verify_manual_payment', {'payment_id': p['payment_id'].toString()});
                          if (res['success'] == true && mounted) {
                            _showToast("Payment verified & subscription updated!", isError: false);
                            _loadAllData();
                          }
                        },
                        child: const Text("Verify Payment"),
                      ),
                    ],
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  // 7. DYNAMIC BRANDING VIEW
  Widget _buildDynamicBrandingView() {
    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _libraries.length,
      itemBuilder: (context, idx) {
        final lib = _libraries[idx] as Map<String, dynamic>;
        final code = lib['library_code'].toString();
        final appIconUrl = lib['app_icon_url']?.toString();

        return Card(
          color: const Color(0xFF1E293B),
          margin: const EdgeInsets.only(bottom: 12),
          child: ListTile(
            leading: (appIconUrl != null && appIconUrl.isNotEmpty)
                ? ClipRRect(
                    borderRadius: BorderRadius.circular(8),
                    child: Image.network(
                      appIconUrl.startsWith('http') ? appIconUrl : '${ApiConfig.baseUrl}/$appIconUrl',
                      width: 42,
                      height: 42,
                      fit: BoxFit.cover,
                      errorBuilder: (_, __, ___) => CircleAvatar(
                        backgroundColor: const Color(0xFF7C3AED),
                        child: Text(code.substring(0, code.length > 3 ? 3 : code.length), style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                      ),
                    ),
                  )
                : CircleAvatar(
                    backgroundColor: const Color(0xFF7C3AED),
                    child: Text(code.substring(0, code.length > 3 ? 3 : code.length), style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                  ),
            title: Text(lib['name']?.toString() ?? '', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            subtitle: Text("Tagline: ${lib['tagline'] ?? 'Self Study Hall'} | Color: ${lib['primary_color'] ?? '#1D4ED8'}\nApp Icon: ${appIconUrl != null && appIconUrl.isNotEmpty ? appIconUrl : 'Default APK Icon'}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
            trailing: ElevatedButton.icon(
              style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF3B82F6)),
              onPressed: () => _showEditBrandingDialog(lib),
              icon: const Icon(Icons.brush_rounded, size: 14),
              label: const Text("Edit Branding"),
            ),
          ),
        );
      },
    );
  }

  // 8. TENANT DATABASES VIEW
  Widget _buildTenantDatabasesView() {
    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _libraries.length,
      itemBuilder: (context, idx) {
        final lib = _libraries[idx] as Map<String, dynamic>;
        final code = lib['library_code'].toString();
        final dbStatus = lib['db_connection_status']?.toString() ?? 'CONNECTED';

        return Card(
          color: const Color(0xFF1E293B),
          margin: const EdgeInsets.only(bottom: 12),
          child: ListTile(
            leading: const Icon(Icons.storage_rounded, color: Color(0xFFA78BFA)),
            title: Text("Database: $code (${lib['name']})", style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            subtitle: Text("File: ${lib['db_file'] ?? 'data/tenant_' + code.toLowerCase() + '.sqlite'}\nStatus: $dbStatus", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
            trailing: Wrap(
              spacing: 6,
              children: [
                OutlinedButton(
                  style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Color(0xFF334155))),
                  onPressed: () => _checkDbStatus(code),
                  child: const Text("Check"),
                ),
                ElevatedButton(
                  style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF7C3AED)),
                  onPressed: () => _provisionDatabase(code),
                  child: const Text("Provision"),
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  // 9. BACKUP & RESTORE VIEW
  Widget _buildBackupRestoreView() {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text("Database Snapshots & History", style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
              ElevatedButton.icon(
                style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF7C3AED)),
                onPressed: _showCreateBackupDialog,
                icon: const Icon(Icons.add_a_photo_rounded, size: 16),
                label: const Text("Create Snapshot"),
              ),
            ],
          ),
        ),
        Expanded(
          child: _backups.isEmpty
              ? const Center(child: Text("No backup snapshots registered.", style: TextStyle(color: Color(0xFF94A3B8))))
              : ListView.builder(
                  padding: const EdgeInsets.symmetric(horizontal: 12),
                  itemCount: _backups.length,
                  itemBuilder: (context, idx) {
                    final bk = _backups[idx] as Map<String, dynamic>;
                    final bkId = bk['backup_id']?.toString() ?? '';
                    final target = bk['library_code']?.toString() ?? bk['scope']?.toString() ?? 'MASTER';

                    return Card(
                      color: const Color(0xFF1E293B),
                      margin: const EdgeInsets.only(bottom: 10),
                      child: ListTile(
                        title: Text("$bkId ($target)", style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 13)),
                        subtitle: Text("File: ${bk['file_name']}\nChecksum: ${(bk['checksum']?.toString() ?? '').substring(0, 16)}...\nDate: ${bk['created_at']}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 11)),
                        trailing: ElevatedButton.icon(
                          style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFFDC2626), padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6)),
                          onPressed: () => _showRestoreBackupDialog(bk),
                          icon: const Icon(Icons.restore_rounded, size: 14),
                          label: const Text("Restore"),
                        ),
                      ),
                    );
                  },
                ),
        ),
      ],
    );
  }

  // 10. AUDIT LOGS VIEW
  Widget _buildAuditLogsView() {
    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _auditLogs.length,
      itemBuilder: (context, idx) {
        final log = _auditLogs[idx] as Map<String, dynamic>;
        return Card(
          color: const Color(0xFF1E293B),
          margin: const EdgeInsets.only(bottom: 8),
          child: ListTile(
            leading: const Icon(Icons.security_rounded, color: Color(0xFFA78BFA)),
            title: Text(log['action']?.toString() ?? '', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 14)),
            subtitle: Text("By: ${log['super_admin']} | Target: ${log['library_code'] ?? 'GLOBAL'} | Date: ${log['created_at']}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
            trailing: Chip(
              label: Text(log['result_status'] ?? 'SUCCESS', style: const TextStyle(color: Colors.white, fontSize: 10)),
              backgroundColor: log['result_status'] == 'SUCCESS' ? Colors.green : Colors.red,
            ),
          ),
        );
      },
    );
  }

  // 11. PLATFORM SETTINGS VIEW
  Widget _buildPlatformSettingsView() {
    final nameCtrl = TextEditingController(text: _supportSettings['support_name'] ?? '');
    final emailCtrl = TextEditingController(text: _supportSettings['support_email'] ?? '');
    final phoneCtrl = TextEditingController(text: _supportSettings['support_phone'] ?? '');

    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text("Global Platform Contact Settings", style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
          const SizedBox(height: 16),
          _dialogTextField(nameCtrl, "SUPPORT TEAM NAME"),
          const SizedBox(height: 12),
          _dialogTextField(emailCtrl, "SUPPORT EMAIL"),
          const SizedBox(height: 12),
          _dialogTextField(phoneCtrl, "SUPPORT PHONE"),
          const SizedBox(height: 24),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: const Color(0xFF7C3AED), padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 14)),
            onPressed: () async {
              final res = await ApiService.superAdminCall('update_support_settings', {
                'support_name': nameCtrl.text.trim(),
                'support_email': emailCtrl.text.trim(),
                'support_phone': phoneCtrl.text.trim(),
              });
              if (res['success'] == true && mounted) {
                _showToast("Platform support contact settings saved!", isError: false);
              }
            },
            child: const Text("Save Platform Settings"),
          ),
        ],
      ),
    );
  }
}
