import 'package:flutter/material.dart';
import '../config/api_config.dart';
import '../services/api_service.dart';
import 'parent_chat_screen.dart';

class ParentDashboardScreen extends StatefulWidget {
  final Map<String, dynamic> userData;

  const ParentDashboardScreen({Key? key, required this.userData}) : super(key: key);

  @override
  State<ParentDashboardScreen> createState() => _ParentDashboardScreenState();
}

class _ParentDashboardScreenState extends State<ParentDashboardScreen> {
  bool _isLoading = true;
  String _errorMessage = '';

  List<dynamic> _linkedStudents = [];
  Map<String, dynamic>? _selectedStudent;
  Map<String, dynamic>? _studentSummary;
  int _unreadChatCount = 0;
  List<dynamic> _complaints = [];

  @override
  void initState() {
    super.initState();
    _loadParentData();
  }

  Future<void> _loadParentData() async {
    setState(() {
      _isLoading = true;
      _errorMessage = '';
    });

    final parentId = widget.userData['id'] is int ? widget.userData['id'] as int : int.tryParse(widget.userData['id']?.toString() ?? '0');
    final res = await ApiService.getParentProfile(parentId: parentId);
    if (res['success'] == true) {
      final students = res['linked_students'] as List<dynamic>? ?? [];
      setState(() {
        _linkedStudents = students;
        if (_linkedStudents.isNotEmpty) {
          _selectedStudent = _linkedStudents[0] as Map<String, dynamic>;
        }
      });

      if (_selectedStudent != null) {
        await _loadStudentSummary(_selectedStudent!['id']);
      } else {
        setState(() {
          _isLoading = false;
        });
      }
    } else {
      setState(() {
        _isLoading = false;
        _errorMessage = res['message'] ?? 'Failed to load parent profile.';
      });
    }
  }

  Future<void> _loadStudentSummary(int studentId) async {
    setState(() {
      _isLoading = true;
    });

    final parentId = widget.userData['id'] is int ? widget.userData['id'] as int : int.tryParse(widget.userData['id']?.toString() ?? '0');
    final summaryRes = await ApiService.getParentStudentFullSummary(studentId, parentId: parentId);
    final unreadRes = await ApiService.getParentUnreadCounts(studentId: studentId, parentId: parentId);
    final compRes = await ApiService.getParentComplaints(studentId, parentId: parentId);

    if (summaryRes['success'] == true) {
      setState(() {
        _studentSummary = summaryRes;
        _unreadChatCount = unreadRes['unread_chat_count'] ?? 0;
        _complaints = compRes['complaints'] as List<dynamic>? ?? [];
        _isLoading = false;
      });
    } else {
      setState(() {
        _isLoading = false;
        _errorMessage = summaryRes['message'] ?? 'Failed to load student details.';
      });
    }
  }

  void _onSelectStudent(Map<String, dynamic> student) {
    setState(() {
      _selectedStudent = student;
    });
    _loadStudentSummary(student['id']);
  }

  void _openParentChat() {
    if (_selectedStudent == null) return;
    final parentId = widget.userData['id'] is int ? widget.userData['id'] as int : int.tryParse(widget.userData['id']?.toString() ?? '0');
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ParentChatScreen(
          studentId: _selectedStudent!['id'],
          studentName: _selectedStudent!['name'] ?? 'Child',
          parentId: parentId,
        ),
      ),
    ).then((_) {
      if (_selectedStudent != null) {
        _loadStudentSummary(_selectedStudent!['id']);
      }
    });
  }

  void _showFileComplaintDialog() {
    final subjectCtrl = TextEditingController(text: 'Parent Query / Request');
    final msgCtrl = TextEditingController();

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('File Support Request / Query 🛡️'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: subjectCtrl,
              decoration: const InputDecoration(labelText: 'Subject', hintText: 'e.g. Shift Change / Fee Query'),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: msgCtrl,
              maxLines: 3,
              decoration: const InputDecoration(labelText: 'Request Details', hintText: 'Enter details for Library Admin'),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Cancel')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: AppColors.primaryIndigo),
            onPressed: () async {
              final msg = msgCtrl.text.trim();
              if (msg.isEmpty || _selectedStudent == null) return;
              Navigator.pop(ctx);
              final res = await ApiService.createParentComplaint(
                _selectedStudent!['id'],
                subject: subjectCtrl.text.trim(),
                message: msg,
              );
              if (mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(content: Text(res['message'] ?? 'Submitted.')),
                );
                _loadStudentSummary(_selectedStudent!['id']);
              }
            },
            child: const Text('Submit', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      backgroundColor: isDark ? AppColors.darkBg : AppColors.lightBg,
      appBar: AppBar(
        title: const Text('Parent Portal 🛡️', style: TextStyle(fontWeight: FontWeight.bold)),
        backgroundColor: AppColors.primaryIndigo,
        foregroundColor: Colors.white,
        elevation: 2,
        actions: [
          Stack(
            children: [
              IconButton(
                icon: const Icon(Icons.chat),
                tooltip: 'Admin Chat Desk',
                onPressed: _openParentChat,
              ),
              if (_unreadChatCount > 0)
                Positioned(
                  right: 6,
                  top: 6,
                  child: Container(
                    padding: const EdgeInsets.all(4),
                    decoration: const BoxDecoration(
                      color: Colors.red,
                      shape: BoxShape.circle,
                    ),
                    constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
                    child: Text(
                      '$_unreadChatCount',
                      style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                      textAlign: TextAlign.center,
                    ),
                  ),
                ),
            ],
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh Data',
            onPressed: () {
              if (_selectedStudent != null) {
                _loadStudentSummary(_selectedStudent!['id']);
              } else {
                _loadParentData();
              }
            },
          ),
          IconButton(
            icon: const Icon(Icons.logout),
            tooltip: 'Log Out',
            onPressed: () {
              Navigator.of(context).pushReplacementNamed('/login');
            },
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _errorMessage.isNotEmpty
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24.0),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Icon(Icons.error_outline, size: 64, color: AppColors.statusDanger),
                        const SizedBox(height: 16),
                        Text(_errorMessage, textAlign: TextAlign.center, style: const TextStyle(fontSize: 16)),
                        const SizedBox(height: 16),
                        ElevatedButton.icon(
                          onPressed: _loadParentData,
                          icon: const Icon(Icons.refresh),
                          label: const Text('Retry'),
                        ),
                      ],
                    ),
                  ),
                )
              : _linkedStudents.isEmpty
                  ? Center(
                      child: Padding(
                        padding: const EdgeInsets.all(24.0),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            const Icon(Icons.school, size: 64, color: Colors.grey),
                            const SizedBox(height: 16),
                            const Text(
                              'No Student Accounts Linked',
                              style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(height: 8),
                            const Text(
                              'Please contact Library Admin to link your child to your parent account.',
                              textAlign: TextAlign.center,
                              style: TextStyle(color: Colors.grey),
                            ),
                          ],
                        ),
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: () async {
                        if (_selectedStudent != null) {
                          await _loadStudentSummary(_selectedStudent!['id']);
                        }
                      },
                      child: SingleChildScrollView(
                        physics: const AlwaysScrollableScrollPhysics(),
                        padding: const EdgeInsets.all(16.0),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            // Multi-Child Selector Chips
                            if (_linkedStudents.length > 1) ...[
                              const Text(
                                'Select Linked Child:',
                                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                              ),
                              const SizedBox(height: 8),
                              SingleChildScrollView(
                                scrollDirection: Axis.horizontal,
                                child: Row(
                                  children: _linkedStudents.map((st) {
                                    final isSelected = _selectedStudent != null && _selectedStudent!['id'] == st['id'];
                                    return Padding(
                                      padding: const EdgeInsets.only(right: 8.0),
                                      child: ChoiceChip(
                                        avatar: const Icon(Icons.person, size: 18),
                                        label: Text(st['name'] ?? 'Student'),
                                        selected: isSelected,
                                        selectedColor: AppColors.primaryIndigo,
                                        labelStyle: TextStyle(
                                          color: isSelected ? Colors.white : (isDark ? Colors.white : Colors.black),
                                          fontWeight: FontWeight.bold,
                                        ),
                                        onSelected: (selected) {
                                          if (selected) _onSelectStudent(st);
                                        },
                                      ),
                                    );
                                  }).toList(),
                                ),
                              ),
                              const SizedBox(height: 16),
                            ],

                            // Student Overview Hero Card
                            _buildStudentHeroCard(isDark),
                            const SizedBox(height: 16),

                            // Today's Attendance Metric Card
                            _buildTodayAttendanceCard(isDark),
                            const SizedBox(height: 16),

                            // Fee Status & 12-Month Matrix
                            _buildFeeStatusCard(isDark),
                            const SizedBox(height: 16),

                            // 12-Month Matrix List Tile
                            _build12MonthMatrixTile(isDark),
                            const SizedBox(height: 16),

                             // Chat & Support Banner Card
                            _buildChatSupportCard(isDark),
                            const SizedBox(height: 16),

                            // Complaints & Support Requests Card
                            _buildComplaintsCard(isDark),
                            const SizedBox(height: 16),

                            // Notifications List
                            _buildNotificationsCard(isDark),
                          ],
                        ),
                      ),
                    ),
    );
  }

  Widget _buildStudentHeroCard(bool isDark) {
    final student = _studentSummary?['student'] ?? _selectedStudent;
    final seatNumber = _studentSummary?['seat_number'];
    final shift = _studentSummary?['shift'];

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 28,
                  backgroundColor: AppColors.primaryIndigo.withOpacity(0.15),
                  child: const Icon(Icons.school, size: 30, color: AppColors.primaryIndigo),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        student?['name'] ?? 'Student Name',
                        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        'Mobile: ${student?['phone'] ?? 'N/A'}',
                        style: TextStyle(color: isDark ? Colors.grey[400] : Colors.grey[700], fontSize: 13),
                      ),
                      Text(
                        'Prep: ${student?['preparation_for'] ?? 'General Study'}',
                        style: TextStyle(color: isDark ? Colors.grey[400] : Colors.grey[700], fontSize: 13),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const Divider(height: 24),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceAround,
              children: [
                _buildInfoChip(
                  icon: Icons.event_seat,
                  label: 'Desk Seat',
                  value: seatNumber != null ? 'Desk $seatNumber' : 'Unassigned',
                  color: AppColors.primaryIndigo,
                ),
                _buildInfoChip(
                  icon: Icons.access_time,
                  label: 'Shift',
                  value: shift != null ? shift['name'] ?? 'Assigned' : 'Unassigned',
                  color: Colors.amber[800]!,
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildInfoChip({required IconData icon, required String label, required String value, required Color color}) {
    return Column(
      children: [
        Row(
          children: [
            Icon(icon, size: 16, color: color),
            const SizedBox(width: 4),
            Text(label, style: const TextStyle(fontSize: 12, color: Colors.grey)),
          ],
        ),
        const SizedBox(height: 2),
        Text(value, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
      ],
    );
  }

  Widget _buildTodayAttendanceCard(bool isDark) {
    final todayAtt = _studentSummary?['today_attendance'];
    final isPresent = todayAtt != null && todayAtt['status'] == 'present';
    final checkIn = todayAtt?['check_in_time'];
    final checkOut = todayAtt?['check_out_time'];
    final monthSummary = _studentSummary?['month_attendance_summary'];

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Row(
                  children: [
                    Icon(Icons.today, color: AppColors.primaryIndigo),
                    SizedBox(width: 8),
                    Text("Today's Attendance", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                  ],
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: isPresent ? AppColors.statusSuccessBg : AppColors.statusWarningBg,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    isPresent ? 'Present Inside' : 'Not Checked In',
                    style: TextStyle(
                      color: isPresent ? AppColors.statusSuccess : AppColors.statusWarning,
                      fontWeight: FontWeight.bold,
                      fontSize: 12,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              checkIn != null
                  ? 'Check-In: $checkIn ${checkOut != null ? ' | Check-Out: $checkOut' : ''}'
                  : 'No check-in record for today',
              style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w600),
            ),
            if (monthSummary != null) ...[
              const SizedBox(height: 8),
              Text(
                'Total Present This Month: ${monthSummary['present_count'] ?? 0} Days',
                style: const TextStyle(fontSize: 12, color: Colors.grey),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildFeeStatusCard(bool isDark) {
    final feeStatus = _studentSummary?['fee_status'];
    final status = feeStatus?['status'] ?? 'pending';
    final label = feeStatus?['label'] ?? 'Pending';
    final dueDate = feeStatus?['due_date'] ?? 'N/A';
    final targetMonthLabel = feeStatus?['target_month_label'] ?? 'N/A';

    Color statusColor = AppColors.statusWarning;
    Color statusBg = AppColors.statusWarningBg;
    if (status == 'paid') {
      statusColor = AppColors.statusSuccess;
      statusBg = AppColors.statusSuccessBg;
    } else if (status == 'overdue') {
      statusColor = AppColors.statusDanger;
      statusBg = AppColors.statusDangerBg;
    }

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Row(
                  children: [
                    Icon(Icons.monetization_on, color: AppColors.statusSuccess),
                    SizedBox(width: 8),
                    Text("Fee Payment Status", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                  ],
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusBg,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    label,
                    style: TextStyle(color: statusColor, fontWeight: FontWeight.bold, fontSize: 12),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Text('Target Cycle: $targetMonthLabel', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14)),
            const SizedBox(height: 4),
            Text('Due Date: $dueDate', style: const TextStyle(fontSize: 12, color: Colors.grey)),
          ],
        ),
      ),
    );
  }

  Widget _build12MonthMatrixTile(bool isDark) {
    final matrix = _studentSummary?['fee_12_month_matrix'] as List<dynamic>? ?? [];

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: ExpansionTile(
        leading: const Icon(Icons.calendar_view_month, color: AppColors.primaryIndigo),
        title: const Text('12-Month Billing Status Matrix', style: TextStyle(fontWeight: FontWeight.bold)),
        subtitle: const Text('Tap to view monthly payment history', style: TextStyle(fontSize: 12)),
        children: [
          Padding(
            padding: const EdgeInsets.all(12.0),
            child: Wrap(
              spacing: 8,
              runSpacing: 8,
              children: matrix.map((m) {
                final isPaid = m['is_paid'] == true;
                final monthLabel = m['month_label'] ?? '';

                return Container(
                  width: 100,
                  padding: const EdgeInsets.all(8),
                  decoration: BoxDecoration(
                    color: isPaid ? AppColors.statusSuccessBg : (isDark ? Colors.grey[800] : Colors.grey[100]),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(
                      color: isPaid ? AppColors.statusSuccess : Colors.grey.withOpacity(0.3),
                    ),
                  ),
                  child: Column(
                    children: [
                      Text(monthLabel, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12)),
                      const SizedBox(height: 4),
                      Icon(
                        isPaid ? Icons.check_circle : Icons.circle_outlined,
                        size: 16,
                        color: isPaid ? AppColors.statusSuccess : Colors.grey,
                      ),
                      const SizedBox(height: 2),
                      Text(
                        isPaid ? 'Paid' : 'Unpaid',
                        style: TextStyle(
                          fontSize: 11,
                          color: isPaid ? AppColors.statusSuccess : Colors.grey,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ],
                  ),
                );
              }).toList(),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildNotificationsCard(bool isDark) {
    final notifs = _studentSummary?['notifications'] as List<dynamic>? ?? [];

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.notifications_active, color: AppColors.primaryIndigo),
                SizedBox(width: 8),
                Text("Notices & Announcements", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              ],
            ),
            const SizedBox(height: 12),
            if (notifs.isEmpty)
              const Text('No recent notices.', style: TextStyle(color: Colors.grey))
            else
              ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: notifs.length,
                separatorBuilder: (context, index) => const Divider(height: 16),
                itemBuilder: (context, index) {
                  final n = notifs[index];
                  return ListTile(
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    title: Text(n['title'] ?? '', style: const TextStyle(fontWeight: FontWeight.bold)),
                    subtitle: Text(n['message'] ?? ''),
                    trailing: Text(
                      n['created_at'] != null ? n['created_at'].toString().split(' ')[0] : '',
                      style: const TextStyle(fontSize: 10, color: Colors.grey),
                    ),
                  );
                },
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildChatSupportCard(bool isDark) {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
        leading: CircleAvatar(
          backgroundColor: AppColors.primaryIndigo.withOpacity(0.15),
          child: const Icon(Icons.mark_chat_unread, color: AppColors.primaryIndigo),
        ),
        title: const Text('Direct Admin Chat Desk 💬', style: TextStyle(fontWeight: FontWeight.bold)),
        subtitle: const Text('Chat 1-on-1 with Library Owner for seat, fee & shift queries.'),
        trailing: ElevatedButton(
          style: ElevatedButton.styleFrom(
            backgroundColor: AppColors.primaryIndigo,
            foregroundColor: Colors.white,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
          ),
          onPressed: _openParentChat,
          child: const Text('Open Chat'),
        ),
      ),
    );
  }

  Widget _buildComplaintsCard(bool isDark) {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Row(
                  children: [
                    Icon(Icons.report_problem, color: Colors.orange),
                    SizedBox(width: 8),
                    Text("Support Requests & Queries", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                  ],
                ),
                ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.amber[800],
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  ),
                  icon: const Icon(Icons.add, size: 16),
                  label: const Text('New Request', style: TextStyle(fontSize: 12)),
                  onPressed: _showFileComplaintDialog,
                ),
              ],
            ),
            const SizedBox(height: 12),
            if (_complaints.isEmpty)
              const Text('No complaints or requests filed.', style: TextStyle(color: Colors.grey))
            else
              ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: _complaints.length,
                separatorBuilder: (context, index) => const Divider(height: 12),
                itemBuilder: (context, index) {
                  final c = _complaints[index];
                  final status = c['status'] ?? 'open';
                  final isResolved = status == 'resolved' || status == 'closed';

                  return ListTile(
                    contentPadding: EdgeInsets.zero,
                    dense: true,
                    title: Text(c['subject'] ?? 'Query', style: const TextStyle(fontWeight: FontWeight.bold)),
                    subtitle: Text(c['description'] ?? ''),
                    trailing: Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                      decoration: BoxDecoration(
                        color: isResolved ? AppColors.statusSuccessBg : AppColors.statusWarningBg,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        status.toUpperCase(),
                        style: TextStyle(
                          color: isResolved ? AppColors.statusSuccess : AppColors.statusWarning,
                          fontWeight: FontWeight.bold,
                          fontSize: 10,
                        ),
                      ),
                    ),
                  );
                },
              ),
          ],
        ),
      ),
    );
  }
}
