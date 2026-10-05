import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import 'library_selector_screen.dart';

class SuperAdminDashboardScreen extends StatefulWidget {
  const SuperAdminDashboardScreen({Key? key}) : super(key: key);

  @override
  State<SuperAdminDashboardScreen> createState() => _SuperAdminDashboardScreenState();
}

class _SuperAdminDashboardScreenState extends State<SuperAdminDashboardScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  bool _isLoading = true;
  // Stats Data
  Map<String, dynamic> _stats = {};
  List<dynamic> _libraries = [];
  List<dynamic> _plans = [];
  List<dynamic> _payments = [];
  List<dynamic> _auditLogs = [];
  Map<String, dynamic> _supportSettings = {};

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 6, vsync: this);
    _loadAllData();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _loadAllData() async {
    setState(() {
      _isLoading = true;
    });

    try {
      final statsRes = await ApiService.superAdminCall('dashboard_stats');
      final libsRes = await ApiService.superAdminCall('list_libraries');
      final plansRes = await ApiService.superAdminCall('list_plans');
      final payRes = await ApiService.superAdminCall('list_manual_payments');
      final auditRes = await ApiService.superAdminCall('list_audit_logs');
      final suppRes = await ApiService.superAdminCall('get_support_settings');

      if (mounted) {
        setState(() {
          _isLoading = false;
          if (statsRes['success'] == true) _stats = statsRes['stats'] ?? {};
          if (libsRes['success'] == true) _libraries = libsRes['libraries'] ?? [];
          if (plansRes['success'] == true) _plans = plansRes['plans'] ?? [];
          if (payRes['success'] == true) _payments = payRes['payments'] ?? [];
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
              ? "Suspending $code will block client access to $code while keeping other libraries active."
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
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res['message'] ?? "Library $code updated successfully.")),
      );
      _loadAllData();
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res['error'] ?? "Action failed."), backgroundColor: Colors.red),
      );
    }
  }

  Future<void> _provisionDatabase(String code) async {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text("Provisioning database for $code...")),
    );
    final res = await ApiService.superAdminCall('provision_database', {'library_code': code});
    if (res['success'] == true && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res['message'] ?? "Database provisioned for $code."), backgroundColor: Colors.green),
      );
      _loadAllData();
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res['error'] ?? "Provisioning failed."), backgroundColor: Colors.red),
      );
    }
  }

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
    int maxStudents = 100;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          backgroundColor: const Color(0xFF1E293B),
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
                  _dialogTextField(codeCtrl, "LIBRARY CODE (e.g. LIB003)", hint: "LIB003"),
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
                  const Text("INITIAL TENANT ADMIN CREDENTIALS", style: TextStyle(color: Color(0xFFA78BFA), fontSize: 12, fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      Expanded(child: _dialogTextField(adminUserCtrl, "ADMIN USERNAME/EMAIL", hint: "admin@lib003.com")),
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
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(content: Text("Creating & provisioning library $code...")),
                );

                final res = await ApiService.superAdminCall('create_library', {
                  'library_code': code,
                  'name': name,
                  'contact_person': personCtrl.text.trim(),
                  'phone': phoneCtrl.text.trim(),
                  'email': emailCtrl.text.trim(),
                  'address': addressCtrl.text.trim(),
                  'plan_name': selectedPlan,
                  'max_students': maxStudents.toString(),
                  'admin_username': adminUserCtrl.text.trim(),
                  'admin_password': adminPassCtrl.text.trim(),
                });

                if (res['success'] == true && mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text(res['message'] ?? "Library $code created cleanly."), backgroundColor: Colors.green),
                  );
                  _loadAllData();
                } else if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text(res['error'] ?? "Creation failed."), backgroundColor: Colors.red),
                  );
                }
              },
              child: const Text("Create & Provision"),
            ),
          ],
        ),
      ),
    );
  }

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
          decoration: InputDecoration(
            hintText: hint,
            hintStyle: const TextStyle(color: Color(0xFF64748B), fontSize: 13),
            filled: true,
            fillColor: const Color(0xFF0F172A),
            contentPadding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
            border: OutlineInputBorder(borderRadius: BorderRadius.circular(8), borderSide: BorderSide.none),
          ),
        ),
      ],
    );
  }

  void _showEditBrandingDialog(Map<String, dynamic> lib) {
    final code = lib['library_code'].toString();
    final nameCtrl = TextEditingController(text: lib['name']?.toString() ?? '');
    final phoneCtrl = TextEditingController(text: lib['phone']?.toString() ?? '');
    final taglineCtrl = TextEditingController(text: lib['tagline']?.toString() ?? '');
    final colorCtrl = TextEditingController(text: lib['primary_color']?.toString() ?? '#1D4ED8');
    final logoCtrl = TextEditingController(text: lib['logo_url']?.toString() ?? '');

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        backgroundColor: const Color(0xFF1E293B),
        title: Text("Edit Branding for $code", style: const TextStyle(color: Colors.white)),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            _dialogTextField(nameCtrl, "LIBRARY NAME"),
            const SizedBox(height: 10),
            _dialogTextField(taglineCtrl, "TAGLINE / SUBTITLE"),
            const SizedBox(height: 10),
            _dialogTextField(phoneCtrl, "CONTACT PHONE"),
            const SizedBox(height: 10),
            _dialogTextField(colorCtrl, "PRIMARY COLOR HEX (e.g. #1D4ED8)"),
            const SizedBox(height: 10),
            _dialogTextField(logoCtrl, "LOGO URL"),
          ],
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
              });
              if (res['success'] == true && mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(content: Text("Branding updated for $code! Only $code experience updated.")),
                );
                _loadAllData();
              }
            },
            child: const Text("Save Branding"),
          ),
        ],
      ),
    );
  }

  Future<void> _verifyPayment(String paymentId) async {
    final res = await ApiService.superAdminCall('verify_manual_payment', {'payment_id': paymentId});
    if (res['success'] == true && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res['message'] ?? "Payment verified."), backgroundColor: Colors.green),
      );
      _loadAllData();
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(res['error'] ?? "Verification failed."), backgroundColor: Colors.red),
      );
    }
  }

  // --- BUILD UI ---

  @override
  Widget build(BuildContext context) {
    final authProvider = Provider.of<AuthProvider>(context);

    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        backgroundColor: const Color(0xFF1E293B),
        elevation: 0,
        title: const Row(
          children: [
            Icon(Icons.stars_rounded, color: Color(0xFFA78BFA)),
            SizedBox(width: 8),
            Text("StudySpace Master Platform", style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
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
        bottom: TabBar(
          controller: _tabController,
          isScrollable: true,
          indicatorColor: const Color(0xFFA78BFA),
          labelColor: const Color(0xFFA78BFA),
          unselectedLabelColor: const Color(0xFF94A3B8),
          tabs: const [
            Tab(icon: Icon(Icons.dashboard_rounded), text: "Overview"),
            Tab(icon: Icon(Icons.apartment_rounded), text: "Libraries"),
            Tab(icon: Icon(Icons.card_membership_rounded), text: "Subscriptions"),
            Tab(icon: Icon(Icons.payments_rounded), text: "Manual Payments"),
            Tab(icon: Icon(Icons.headset_mic_rounded), text: "Support Settings"),
            Tab(icon: Icon(Icons.history_rounded), text: "Audit Logs"),
          ],
        ),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: Color(0xFFA78BFA)))
          : TabBarView(
              controller: _tabController,
              children: [
                _buildOverviewTab(),
                _buildLibrariesTab(),
                _buildSubscriptionsTab(),
                _buildPaymentsTab(),
                _buildSupportSettingsTab(),
                _buildAuditLogsTab(),
              ],
            ),
      floatingActionButton: FloatingActionButton.extended(
        backgroundColor: const Color(0xFF7C3AED),
        onPressed: _showCreateLibraryDialog,
        icon: const Icon(Icons.add_rounded),
        label: const Text("New Library"),
      ),
    );
  }

  // TAB 1: OVERVIEW
  Widget _buildOverviewTab() {
    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text("Platform Overview Metrics", style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
          const SizedBox(height: 16),
          LayoutBuilder(
            builder: (context, constraints) {
              final crossCount = constraints.maxWidth > 800 ? 4 : (constraints.maxWidth > 500 ? 2 : 2);
              return GridView.count(
                crossAxisCount: crossCount,
                crossAxisSpacing: 14,
                mainAxisSpacing: 14,
                childAspectRatio: 1.6,
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                children: [
                  _statCard("Total Libraries", "${_stats['total_libraries'] ?? 0}", Icons.apartment_rounded, Colors.blue),
                  _statCard("Active Libraries", "${_stats['active_libraries'] ?? 0}", Icons.check_circle_rounded, Colors.green),
                  _statCard("Suspended", "${_stats['suspended_libraries'] ?? 0}", Icons.pause_circle_rounded, Colors.orange),
                  _statCard("Trial Libraries", "${_stats['trial_libraries'] ?? 0}", Icons.hourglass_top_rounded, Colors.purple),
                  _statCard("Active Subscriptions", "${_stats['active_subscriptions'] ?? 0}", Icons.verified_rounded, Colors.teal),
                  _statCard("Pending Payments", "${_stats['pending_manual_payments'] ?? 0}", Icons.pending_actions_rounded, Colors.amber),
                  _statCard("Verified Payments", "${_stats['verified_manual_payments'] ?? 0}", Icons.price_check_rounded, const Color(0xFF10B981)),
                  _statCard("Total Collections", "₹${_stats['total_manual_collections'] ?? 0}", Icons.account_balance_wallet_rounded, Colors.indigo),
                ],
              );
            },
          ),
          const SizedBox(height: 28),
          const Text("Recent System Registrations", style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
          const SizedBox(height: 12),
          ...(_stats['recent_registrations'] as List? ?? []).map((lib) => Card(
            color: const Color(0xFF1E293B),
            margin: const EdgeInsets.only(bottom: 8),
            child: ListTile(
              title: Text("${lib['name']} (${lib['library_code']})", style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
              subtitle: Text("Registered: ${lib['created_at'] ?? 'N/A'}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
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
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFF1E293B),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFF334155)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Row(
            children: [
              Icon(icon, color: color, size: 22),
              const SizedBox(width: 8),
              Expanded(child: Text(title, style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12), overflow: TextOverflow.ellipsis)),
            ],
          ),
          const SizedBox(height: 8),
          Text(value, style: TextStyle(color: Colors.white, fontSize: 22, fontWeight: FontWeight.bold)),
        ],
      ),
    );
  }

  // TAB 2: LIBRARIES
  Widget _buildLibrariesTab() {
    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _libraries.length,
      itemBuilder: (context, idx) {
        final lib = _libraries[idx] as Map<String, dynamic>;
        final code = lib['library_code'].toString();
        final status = lib['status'].toString();
        final isSuspended = status == 'suspended';

        return Card(
          color: const Color(0xFF1E293B),
          margin: const EdgeInsets.only(bottom: 12),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14), side: BorderSide(color: isSuspended ? Colors.red.withOpacity(0.5) : const Color(0xFF334155))),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(color: const Color(0xFF3B82F6).withOpacity(0.2), borderRadius: BorderRadius.circular(8)),
                      child: Text(code, style: const TextStyle(color: Color(0xFF60A5FA), fontWeight: FontWeight.bold)),
                    ),
                    const SizedBox(width: 10),
                    Expanded(child: Text(lib['name']?.toString() ?? '', style: const TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.bold))),
                    Chip(
                      label: Text(status.toUpperCase(), style: const TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                      backgroundColor: isSuspended ? Colors.red : Colors.green,
                    ),
                  ],
                ),
                const SizedBox(height: 10),
                Text("Plan: ${lib['plan_name'] ?? 'Standard'}  |  Expires: ${lib['valid_until'] ?? 'N/A'}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 13)),
                Text("DB File: ${lib['db_file'] ?? 'data/tenant_' + code.toLowerCase() + '.sqlite'}", style: const TextStyle(color: Color(0xFF64748B), fontSize: 12)),
                const Divider(color: Color(0xFF334155), height: 24),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(backgroundColor: isSuspended ? Colors.green : Colors.red.shade700, padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8)),
                      onPressed: () => _toggleLibraryStatus(code, status),
                      icon: Icon(isSuspended ? Icons.play_arrow_rounded : Icons.block_rounded, size: 16),
                      label: Text(isSuspended ? "Restore / Activate" : "Suspend Library", style: const TextStyle(fontSize: 12)),
                    ),
                    OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Color(0xFF334155))),
                      onPressed: () => _provisionDatabase(code),
                      icon: const Icon(Icons.storage_rounded, size: 16, color: Color(0xFFA78BFA)),
                      label: const Text("Provision / Verify DB", style: TextStyle(fontSize: 12)),
                    ),
                    OutlinedButton.icon(
                      style: OutlinedButton.styleFrom(foregroundColor: Colors.white, side: const BorderSide(color: Color(0xFF334155))),
                      onPressed: () => _showEditBrandingDialog(lib),
                      icon: const Icon(Icons.brush_rounded, size: 16, color: Color(0xFF38BDF8)),
                      label: const Text("Edit Branding", style: TextStyle(fontSize: 12)),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  // TAB 3: SUBSCRIPTIONS
  Widget _buildSubscriptionsTab() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        const Text("Available SaaS Plans", style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold)),
        const SizedBox(height: 12),
        ..._plans.map((p) => Card(
          color: const Color(0xFF1E293B),
          child: ListTile(
            title: Text(p['name'] ?? '', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            subtitle: Text("Price: ₹${p['price']} | Duration: ${p['duration_days']} days | Max Students: ${p['max_students']}", style: const TextStyle(color: Color(0xFF94A3B8))),
            trailing: Chip(label: Text(p['plan_id'] ?? ''), backgroundColor: const Color(0xFF3B82F6)),
          ),
        )),
      ],
    );
  }

  // TAB 4: PAYMENTS
  Widget _buildPaymentsTab() {
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
          child: ListTile(
            title: Text("${p['library_name']} (${p['library_code']}) — ₹${p['amount']}", style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            subtitle: Text("Ref: ${p['payment_reference']} | Date: ${p['payment_date']} | Method: ${p['payment_method']}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
            trailing: isPending
                ? ElevatedButton(
                    style: ElevatedButton.styleFrom(backgroundColor: Colors.green),
                    onPressed: () => _verifyPayment(p['payment_id'].toString()),
                    child: const Text("Verify Payment"),
                  )
                : Chip(label: Text(status), backgroundColor: status == 'VERIFIED' ? Colors.green : Colors.red),
          ),
        );
      },
    );
  }

  // TAB 5: SUPPORT SETTINGS
  Widget _buildSupportSettingsTab() {
    final nameCtrl = TextEditingController(text: _supportSettings['support_name'] ?? '');
    final emailCtrl = TextEditingController(text: _supportSettings['support_email'] ?? '');
    final phoneCtrl = TextEditingController(text: _supportSettings['support_phone'] ?? '');

    return SingleChildScrollView(
      padding: const EdgeInsets.all(20),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text("Global Platform Support Contact Settings", style: TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold)),
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
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text("Support settings saved!")));
              }
            },
            child: const Text("Save Support Settings"),
          ),
        ],
      ),
    );
  }

  // TAB 6: AUDIT LOGS
  Widget _buildAuditLogsTab() {
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
            title: Text(log['action']?.toString() ?? '', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
            subtitle: Text("By: ${log['super_admin']} | Target: ${log['library_code'] ?? 'GLOBAL'} | ${log['created_at']}", style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12)),
            trailing: Chip(
              label: Text(log['result_status'] ?? 'SUCCESS', style: const TextStyle(color: Colors.white, fontSize: 10)),
              backgroundColor: log['result_status'] == 'SUCCESS' ? Colors.green : Colors.red,
            ),
          ),
        );
      },
    );
  }
}
