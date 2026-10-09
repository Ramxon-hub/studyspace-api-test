import 'package:flutter/material.dart';
import '../config/api_config.dart';
import '../services/api_service.dart';
import '../widgets/custom_app_bar.dart';

class AdminParentsScreen extends StatefulWidget {
  const AdminParentsScreen({Key? key}) : super(key: key);

  @override
  State<AdminParentsScreen> createState() => _AdminParentsScreenState();
}

class _AdminParentsScreenState extends State<AdminParentsScreen> {
  bool _isLoading = true;
  String _errorMessage = '';

  List<dynamic> _parents = [];
  List<dynamic> _filteredParents = [];
  List<dynamic> _allStudents = [];

  final TextEditingController _searchCtrl = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadData();
    _searchCtrl.addListener(_onSearchChanged);
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  void _onSearchChanged() {
    final query = _searchCtrl.text.toLowerCase().trim();
    setState(() {
      if (query.isEmpty) {
        _filteredParents = List.from(_parents);
      } else {
        _filteredParents = _parents.where((p) {
          final name = (p['name'] ?? '').toString().toLowerCase();
          final email = (p['email'] ?? '').toString().toLowerCase();
          final phone = (p['phone'] ?? '').toString().toLowerCase();
          return name.contains(query) || email.contains(query) || phone.contains(query);
        }).toList();
      }
    });
  }

  Future<void> _loadData() async {
    setState(() {
      _isLoading = true;
      _errorMessage = '';
    });

    final res = await ApiService.getAdminParents();
    if (mounted) {
      if (res['success'] == true) {
        setState(() {
          _parents = res['parents'] as List<dynamic>? ?? [];
          _allStudents = res['all_students'] as List<dynamic>? ?? [];
          _filteredParents = List.from(_parents);
          _isLoading = false;
        });
      } else {
        setState(() {
          _isLoading = false;
          _errorMessage = res['message'] ?? 'Failed to load parent accounts.';
        });
      }
    }
  }

  void _showCreateParentModal() {
    final nameCtrl = TextEditingController();
    final emailCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final passCtrl = TextEditingController();
    final confirmPassCtrl = TextEditingController();

    String selectedRelationship = 'Father';
    int? selectedStudentId = _allStudents.isNotEmpty ? _allStudents[0]['id'] as int : null;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setModalState) {
          return AlertDialog(
            title: const Row(
              children: [
                Icon(Icons.person_add_rounded, color: AppColors.primaryIndigo),
                SizedBox(width: 8),
                Text('Create Parent Account', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  TextField(
                    controller: nameCtrl,
                    decoration: const InputDecoration(labelText: 'Parent Full Name *', prefixIcon: Icon(Icons.person)),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: emailCtrl,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(labelText: 'Email Address *', prefixIcon: Icon(Icons.email)),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: phoneCtrl,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(labelText: 'Phone Number', prefixIcon: Icon(Icons.phone)),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: passCtrl,
                    obscureText: true,
                    decoration: const InputDecoration(labelText: 'Initial Password *', prefixIcon: Icon(Icons.lock)),
                  ),
                  const SizedBox(height: 10),
                  TextField(
                    controller: confirmPassCtrl,
                    obscureText: true,
                    decoration: const InputDecoration(labelText: 'Confirm Password *', prefixIcon: Icon(Icons.lock_clock)),
                  ),
                  const SizedBox(height: 14),
                  const Text('Link Initial Student:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                  const SizedBox(height: 6),
                  if (_allStudents.isEmpty)
                    const Text('No students registered yet.', style: TextStyle(color: Colors.grey))
                  else
                    DropdownButtonFormField<int>(
                      value: selectedStudentId,
                      items: _allStudents.map<DropdownMenuItem<int>>((st) {
                        return DropdownMenuItem<int>(
                          value: st['id'] as int,
                          child: Text('${st['name']} (${st['email']})'),
                        );
                      }).toList(),
                      onChanged: (val) => setModalState(() => selectedStudentId = val),
                      decoration: const InputDecoration(contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 8), border: OutlineInputBorder()),
                    ),
                  const SizedBox(height: 12),
                  const Text('Relationship:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                  const SizedBox(height: 6),
                  DropdownButtonFormField<String>(
                    value: selectedRelationship,
                    items: ['Father', 'Mother', 'Guardian', 'Other'].map((rel) {
                      return DropdownMenuItem<String>(value: rel, child: Text(rel));
                    }).toList(),
                    onChanged: (val) => setModalState(() => selectedRelationship = val ?? 'Father'),
                    decoration: const InputDecoration(contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 8), border: OutlineInputBorder()),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: AppColors.primaryIndigo),
                onPressed: () async {
                  final name = nameCtrl.text.trim();
                  final email = emailCtrl.text.trim();
                  final phone = phoneCtrl.text.trim();
                  final pass = passCtrl.text.trim();
                  final confirm = confirmPassCtrl.text.trim();

                  if (name.isEmpty || email.isEmpty || pass.isEmpty) {
                    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Please fill all required fields.')));
                    return;
                  }
                  if (pass != confirm) {
                    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Passwords do not match.')));
                    return;
                  }

                  Navigator.pop(ctx);
                  final res = await ApiService.createAdminParent(
                    name: name,
                    email: email,
                    phone: phone,
                    password: pass,
                    relationship: selectedRelationship,
                    studentIds: selectedStudentId != null ? [selectedStudentId!] : [],
                  );

                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(res['message'] ?? 'Parent created.')),
                    );
                    _loadData();
                  }
                },
                child: const Text('Create Account', style: TextStyle(color: Colors.white)),
              ),
            ],
          );
        },
      ),
    );
  }

  void _showLinkStudentModal(Map<String, dynamic> parent) {
    int? selectedStudentId = _allStudents.isNotEmpty ? _allStudents[0]['id'] as int : null;
    String selectedRelationship = 'Father';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setModalState) {
          return AlertDialog(
            title: Text('Link Student to ${parent['name'] ?? 'Parent'}'),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                DropdownButtonFormField<int>(
                  value: selectedStudentId,
                  items: _allStudents.map<DropdownMenuItem<int>>((st) {
                    return DropdownMenuItem<int>(
                      value: st['id'] as int,
                      child: Text('${st['name']} (${st['email']})'),
                    );
                  }).toList(),
                  onChanged: (val) => setModalState(() => selectedStudentId = val),
                  decoration: const InputDecoration(labelText: 'Select Student'),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  value: selectedRelationship,
                  items: ['Father', 'Mother', 'Guardian', 'Other'].map((rel) {
                    return DropdownMenuItem<String>(value: rel, child: Text(rel));
                  }).toList(),
                  onChanged: (val) => setModalState(() => selectedRelationship = val ?? 'Father'),
                  decoration: const InputDecoration(labelText: 'Relationship'),
                ),
              ],
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: AppColors.primaryIndigo),
                onPressed: () async {
                  if (selectedStudentId == null) return;
                  Navigator.pop(ctx);
                  final res = await ApiService.linkParentStudent(
                    parentId: parent['id'],
                    studentId: selectedStudentId!,
                    relationship: selectedRelationship,
                  );
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(res['message'] ?? 'Linked.')));
                    _loadData();
                  }
                },
                child: const Text('Link Student', style: TextStyle(color: Colors.white)),
              ),
            ],
          );
        },
      ),
    );
  }

  void _showEditParentModal(Map<String, dynamic> parent) {
    final nameCtrl = TextEditingController(text: parent['name'] ?? '');
    final emailCtrl = TextEditingController(text: parent['email'] ?? '');
    final phoneCtrl = TextEditingController(text: parent['phone'] ?? '');
    String status = parent['status'] ?? 'approved';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setModalState) {
          return AlertDialog(
            title: Text('Edit ${parent['name'] ?? 'Parent'}'),
            content: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(controller: nameCtrl, decoration: const InputDecoration(labelText: 'Full Name')),
                const SizedBox(height: 10),
                TextField(controller: emailCtrl, decoration: const InputDecoration(labelText: 'Email Address')),
                const SizedBox(height: 10),
                TextField(controller: phoneCtrl, decoration: const InputDecoration(labelText: 'Phone Number')),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  value: status,
                  items: const [
                    DropdownMenuItem(value: 'approved', child: Text('Active / Approved')),
                    DropdownMenuItem(value: 'disabled', child: Text('Disabled / Inactive')),
                  ],
                  onChanged: (val) => setModalState(() => status = val ?? 'approved'),
                  decoration: const InputDecoration(labelText: 'Account Status'),
                ),
              ],
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
              ElevatedButton(
                style: ElevatedButton.styleFrom(backgroundColor: AppColors.primaryIndigo),
                onPressed: () async {
                  Navigator.pop(ctx);
                  final res = await ApiService.editAdminParent(
                    parentId: parent['id'],
                    name: nameCtrl.text.trim(),
                    email: emailCtrl.text.trim(),
                    phone: phoneCtrl.text.trim(),
                    status: status,
                  );
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(res['message'] ?? 'Updated.')));
                    _loadData();
                  }
                },
                child: const Text('Save Changes', style: TextStyle(color: Colors.white)),
              ),
            ],
          );
        },
      ),
    );
  }

  void _showResetPasswordModal(Map<String, dynamic> parent) {
    final passCtrl = TextEditingController();

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text('Reset Password for ${parent['name']}'),
        content: TextField(
          controller: passCtrl,
          obscureText: true,
          decoration: const InputDecoration(labelText: 'New Password', hintText: 'Enter new password'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.orange),
            onPressed: () async {
              final newPass = passCtrl.text.trim();
              if (newPass.isEmpty) return;
              Navigator.pop(ctx);
              final res = await ApiService.resetParentPassword(parent['id'], newPass);
              if (mounted) {
                ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(res['message'] ?? 'Password reset.')));
              }
            },
            child: const Text('Reset Password', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
  }

  void _showActivityBottomSheet(Map<String, dynamic> parent) async {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) => Container(
        height: MediaQuery.of(context).size.height * 0.75,
        decoration: BoxDecoration(
          color: Theme.of(context).scaffoldBackgroundColor,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
        ),
        child: FutureBuilder<Map<String, dynamic>>(
          future: ApiService.getParentActivity(parent['id']),
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }
            final data = snapshot.data;
            if (data == null || data['success'] != true) {
              return Center(child: Text(data?['message'] ?? 'Failed to load activity details.'));
            }

            final linked = data['linked_students'] as List<dynamic>? ?? [];
            final attendance = data['attendance'] as List<dynamic>? ?? [];
            final fees = data['fees'] as List<dynamic>? ?? [];
            final complaints = data['complaints'] as List<dynamic>? ?? [];
            final chat = data['chat'] as List<dynamic>? ?? [];

            return Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text('${parent['name']} Activity Overview', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                      IconButton(icon: const Icon(Icons.close), onPressed: () => Navigator.pop(ctx)),
                    ],
                  ),
                  const Divider(),
                  Expanded(
                    child: ListView(
                      children: [
                        Text('Linked Children (${linked.length}):', style: const TextStyle(fontWeight: FontWeight.bold)),
                        const SizedBox(height: 6),
                        Wrap(
                          spacing: 8,
                          children: linked.map((st) => Chip(avatar: const Icon(Icons.school, size: 16), label: Text('${st['name']} (${st['relationship'] ?? 'Child'})'))).toList(),
                        ),
                        const SizedBox(height: 16),
                        Text('Recent Attendance (${attendance.length} records):', style: const TextStyle(fontWeight: FontWeight.bold)),
                        ...attendance.take(5).map((a) => ListTile(dense: true, title: Text('Date: ${a['date']}'), subtitle: Text('In: ${a['check_in_time'] ?? 'N/A'} - Out: ${a['check_out_time'] ?? 'N/A'}'))),
                        const SizedBox(height: 16),
                        Text('Fee History (${fees.length} records):', style: const TextStyle(fontWeight: FontWeight.bold)),
                        ...fees.take(5).map((f) => ListTile(dense: true, title: Text('Month: ${f['month_year'] ?? 'N/A'} - ₹${f['amount'] ?? 0}'), subtitle: Text('Status: ${f['status']} | Receipt: ${f['receipt_number'] ?? 'N/A'}'))),
                        const SizedBox(height: 16),
                        Text('Support Queries (${complaints.length}):', style: const TextStyle(fontWeight: FontWeight.bold)),
                        ...complaints.take(5).map((c) => ListTile(dense: true, title: Text(c['subject'] ?? 'Query'), subtitle: Text('Status: ${c['status']}'))),
                      ],
                    ),
                  ),
                ],
              ),
            );
          },
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      appBar: const CustomAppBar(title: 'Parent Management 🛡️'),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _showCreateParentModal,
        backgroundColor: AppColors.primaryIndigo,
        icon: const Icon(Icons.person_add_rounded, color: Colors.white),
        label: const Text('Create Parent', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
      ),
      body: Column(
        children: [
          // Top Search & Metrics Header
          Container(
            padding: const EdgeInsets.all(14),
            color: isDark ? AppColors.darkCard : Colors.white,
            child: Column(
              children: [
                TextField(
                  controller: _searchCtrl,
                  decoration: InputDecoration(
                    hintText: 'Search parents by name, email, phone...',
                    prefixIcon: const Icon(Icons.search),
                    suffixIcon: _searchCtrl.text.isNotEmpty
                        ? IconButton(icon: const Icon(Icons.clear), onPressed: () => _searchCtrl.clear())
                        : null,
                    contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                  ),
                ),
                const SizedBox(height: 10),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('Total Accounts: ${_parents.length}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                    Text('Showing: ${_filteredParents.length}', style: const TextStyle(color: Colors.grey, fontSize: 13)),
                  ],
                ),
              ],
            ),
          ),
          const Divider(height: 1),

          // Body Content
          Expanded(
            child: _isLoading
                ? const Center(child: CircularProgressIndicator())
                : _errorMessage.isNotEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Icon(Icons.error_outline, size: 48, color: Colors.red),
                            const SizedBox(height: 12),
                            Text(_errorMessage),
                            ElevatedButton(onPressed: _loadData, child: const Text('Retry')),
                          ],
                        ),
                      )
                    : _filteredParents.isEmpty
                        ? const Center(
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Icon(Icons.family_restroom, size: 64, color: Colors.grey),
                                SizedBox(height: 12),
                                Text('No Parent Accounts Found', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                                SizedBox(height: 4),
                                Text('Click "Create Parent" to add an account.', style: TextStyle(color: Colors.grey)),
                              ],
                            ),
                          )
                        : RefreshIndicator(
                            onRefresh: _loadData,
                            child: ListView.builder(
                              padding: const EdgeInsets.all(12),
                              itemCount: _filteredParents.length,
                              itemBuilder: (ctx, idx) {
                                final parent = _filteredParents[idx] as Map<String, dynamic>;
                                final linkedStudents = parent['linked_students'] as List<dynamic>? ?? [];
                                final status = (parent['status'] ?? 'approved').toString().toLowerCase();
                                final isApproved = status == 'approved' || status == 'active';

                                return Card(
                                  margin: const EdgeInsets.only(bottom: 12),
                                  elevation: 2,
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                  child: Padding(
                                    padding: const EdgeInsets.all(14.0),
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Row(
                                          children: [
                                            CircleAvatar(
                                              backgroundColor: isApproved ? Colors.green.withOpacity(0.15) : Colors.red.withOpacity(0.15),
                                              child: Icon(
                                                Icons.family_restroom_rounded,
                                                color: isApproved ? Colors.green : Colors.red,
                                              ),
                                            ),
                                            const SizedBox(width: 12),
                                            Expanded(
                                              child: Column(
                                                crossAxisAlignment: CrossAxisAlignment.start,
                                                children: [
                                                  Text(
                                                    parent['name'] ?? 'Parent Account',
                                                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                                                  ),
                                                  Text(
                                                    parent['email'] ?? 'No email',
                                                    style: const TextStyle(fontSize: 13, color: Colors.grey),
                                                  ),
                                                  if ((parent['phone'] ?? '').toString().isNotEmpty)
                                                    Text(
                                                      'Phone: ${parent['phone']}',
                                                      style: const TextStyle(fontSize: 12, color: Colors.grey),
                                                    ),
                                                ],
                                              ),
                                            ),
                                            Container(
                                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                              decoration: BoxDecoration(
                                                color: isApproved ? Colors.green.withOpacity(0.1) : Colors.red.withOpacity(0.1),
                                                borderRadius: BorderRadius.circular(6),
                                              ),
                                              child: Text(
                                                isApproved ? 'Active' : 'Disabled',
                                                style: TextStyle(
                                                  color: isApproved ? Colors.green : Colors.red,
                                                  fontWeight: FontWeight.bold,
                                                  fontSize: 11,
                                                ),
                                              ),
                                            ),
                                            PopupMenuButton<String>(
                                              onSelected: (val) async {
                                                if (val == 'link') {
                                                  _showLinkStudentModal(parent);
                                                } else if (val == 'edit') {
                                                  _showEditParentModal(parent);
                                                } else if (val == 'reset_pass') {
                                                  _showResetPasswordModal(parent);
                                                } else if (val == 'toggle_status') {
                                                  final res = await ApiService.toggleParentStatus(parent['id']);
                                                  if (mounted) {
                                                    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(res['message'] ?? 'Status updated.')));
                                                    _loadData();
                                                  }
                                                } else if (val == 'activity') {
                                                  _showActivityBottomSheet(parent);
                                                }
                                              },
                                              itemBuilder: (ctx) => [
                                                const PopupMenuItem(value: 'link', child: Row(children: [Icon(Icons.link, size: 18), SizedBox(width: 8), Text('Link Student')])),
                                                const PopupMenuItem(value: 'edit', child: Row(children: [Icon(Icons.edit, size: 18), SizedBox(width: 8), Text('Edit Details')])),
                                                const PopupMenuItem(value: 'reset_pass', child: Row(children: [Icon(Icons.key, size: 18), SizedBox(width: 8), Text('Reset Password')])),
                                                PopupMenuItem(value: 'toggle_status', child: Row(children: [Icon(isApproved ? Icons.block : Icons.check_circle, size: 18), const SizedBox(width: 8), Text(isApproved ? 'Deactivate Account' : 'Activate Account')])),
                                                const PopupMenuItem(value: 'activity', child: Row(children: [Icon(Icons.analytics, size: 18), SizedBox(width: 8), Text('View Activity')])),
                                              ],
                                            ),
                                          ],
                                        ),
                                        const SizedBox(height: 10),
                                        const Text('Linked Students:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                                        const SizedBox(height: 6),
                                        linkedStudents.isEmpty
                                            ? const Text('No students linked yet.', style: TextStyle(color: Colors.grey, fontSize: 12))
                                            : Wrap(
                                                spacing: 6,
                                                runSpacing: 6,
                                                children: linkedStudents.map((st) {
                                                  final rel = st['relationship'] ?? 'Child';
                                                  return Chip(
                                                    avatar: const Icon(Icons.school, size: 14),
                                                    label: Text('${st['name']} ($rel)', style: const TextStyle(fontSize: 12)),
                                                    deleteIcon: const Icon(Icons.cancel, size: 16),
                                                    onDeleted: () async {
                                                      final confirm = await showDialog<bool>(
                                                        context: context,
                                                        builder: (c) => AlertDialog(
                                                          title: const Text('Unlink Student?'),
                                                          content: Text('Unlink ${st['name']} from ${parent['name']}?'),
                                                          actions: [
                                                            TextButton(onPressed: () => Navigator.pop(c, false), child: const Text('Cancel')),
                                                            ElevatedButton(style: ElevatedButton.styleFrom(backgroundColor: Colors.red), onPressed: () => Navigator.pop(c, true), child: const Text('Unlink', style: TextStyle(color: Colors.white))),
                                                          ],
                                                        ),
                                                      );
                                                      if (confirm == true) {
                                                        final res = await ApiService.unlinkParentStudent(parentId: parent['id'], studentId: st['id']);
                                                        if (mounted) {
                                                          ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(res['message'] ?? 'Unlinked.')));
                                                          _loadData();
                                                        }
                                                      }
                                                    },
                                                  );
                                                }).toList(),
                                              ),
                                      ],
                                    ),
                                  ),
                                );
                              },
                            ),
                          ),
          ),
        ],
      ),
    );
  }
}
