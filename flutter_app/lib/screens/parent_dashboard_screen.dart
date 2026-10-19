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
  Map<String, dynamic>? _todayTimeline;
  Map<String, dynamic>? _monthlyAttendance;
  String _selectedMonth = '';
  int _unreadChatCount = 0;
  List<dynamic> _complaints = [];

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _selectedMonth = "${now.year}-${now.month.toString().padLeft(2, '0')}";
    _loadParentData();
  }

  Future<void> _loadParentData() async {
    setState(() {
      _isLoading = true;
      _errorMessage = '';
    });

    final parentId = widget.userData['id'] is int
        ? widget.userData['id'] as int
        : int.tryParse(widget.userData['id']?.toString() ?? '0');

    final res = await ApiService.getParentProfile(parentId: parentId);
    if (res['success'] == true) {
      final students = res['linked_students'] as List<dynamic>? ?? [];
      setState(() {
        _linkedStudents = students;
        if (_linkedStudents.isNotEmpty && _selectedStudent == null) {
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

    final parentId = widget.userData['id'] is int
        ? widget.userData['id'] as int
        : int.tryParse(widget.userData['id']?.toString() ?? '0');

    final summaryRes = await ApiService.getParentStudentFullSummary(studentId, parentId: parentId);
    final timelineRes = await ApiService.getParentTodayTimeline(studentId, parentId: parentId);
    final monthlyRes = await ApiService.getParentMonthlyAttendanceSummary(studentId, parentId: parentId, month: _selectedMonth);
    final unreadRes = await ApiService.getParentUnreadCounts(studentId: studentId, parentId: parentId);
    final compRes = await ApiService.getParentComplaints(studentId, parentId: parentId);

    if (summaryRes['success'] == true) {
      setState(() {
        _studentSummary = summaryRes;
        _todayTimeline = timelineRes['success'] == true
            ? (timelineRes['timeline'] as Map<String, dynamic>?)
            : (summaryRes['today_timeline'] as Map<String, dynamic>?);
        _monthlyAttendance = monthlyRes['success'] == true
            ? (monthlyRes['attendance_summary'] as Map<String, dynamic>?)
            : null;
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

  void _changeMonth(String month) {
    setState(() {
      _selectedMonth = month;
    });
    if (_selectedStudent != null) {
      _loadStudentSummary(_selectedStudent!['id']);
    }
  }

  void _openParentChat() {
    if (_selectedStudent == null) return;
    final parentId = widget.userData['id'] is int
        ? widget.userData['id'] as int
        : int.tryParse(widget.userData['id']?.toString() ?? '0');

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
        title: const Text('File Support Request / Query 🛡️', style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: subjectCtrl,
                decoration: const InputDecoration(labelText: 'Subject', hintText: 'e.g. Shift / Fee Query'),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: msgCtrl,
                maxLines: 3,
                decoration: const InputDecoration(labelText: 'Request Details', hintText: 'Enter details for Library Admin'),
              ),
            ],
          ),
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

  void _showDayTimelineDialog(int studentId, String date) async {
    final parentId = widget.userData['id'] is int
        ? widget.userData['id'] as int
        : int.tryParse(widget.userData['id']?.toString() ?? '0');

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) => FutureBuilder<Map<String, dynamic>>(
        future: ApiService.getParentTodayTimeline(studentId, parentId: parentId, date: date),
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Container(
              height: 250,
              alignment: Alignment.center,
              child: const CircularProgressIndicator(),
            );
          }

          final res = snapshot.data;
          final tl = res != null && res['success'] == true ? (res['timeline'] as Map<String, dynamic>?) : null;

          if (tl == null) {
            return Container(
              padding: const EdgeInsets.all(24),
              child: const Text("No timeline data found for this date.", textAlign: TextAlign.center),
            );
          }

          final events = tl['events'] as List<dynamic>? ?? [];

          return DraggableScrollableSheet(
            expand: false,
            initialChildSize: 0.65,
            minChildSize: 0.4,
            maxChildSize: 0.9,
            builder: (_, scrollController) => ListView(
              controller: scrollController,
              padding: const EdgeInsets.all(20),
              children: [
                Center(
                  child: Container(
                    width: 40,
                    height: 4,
                    decoration: BoxDecoration(color: Colors.grey[400], borderRadius: BorderRadius.circular(2)),
                  ),
                ),
                const SizedBox(height: 16),
                Row(
                  children: [
                    const Icon(Icons.history_rounded, color: AppColors.primaryIndigo),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        "Attendance Timeline: $date",
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 8,
                  runSpacing: 8,
                  children: [
                    _buildMiniBadge("Total Time: ${tl['total_inside_formatted'] ?? '0m'}", Colors.blue),
                    _buildMiniBadge("Status: ${tl['status_label'] ?? 'Unknown'}", tl['current_status'] == 'INSIDE_LIBRARY' ? Colors.green : Colors.orange),
                    _buildMiniBadge("Exits: ${tl['exit_count'] ?? 0}", Colors.purple),
                  ],
                ),
                const Divider(height: 24),
                if (events.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 20),
                    child: Text("No discrete check-in or checkout events recorded on this date.", style: TextStyle(color: Colors.grey)),
                  )
                else
                  ...events.map((ev) => _buildTimelineEventRow(ev)).toList(),
              ],
            ),
          );
        },
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      backgroundColor: isDark ? AppColors.darkBg : AppColors.lightBg,
      appBar: AppBar(
        title: const Text('Parent Portal 🛡️', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18)),
        backgroundColor: AppColors.primaryIndigo,
        foregroundColor: Colors.white,
        elevation: 2,
        actions: [
          Stack(
            children: [
              IconButton(
                icon: const Icon(Icons.chat_outlined),
                tooltip: 'Admin Chat Desk',
                onPressed: _openParentChat,
              ),
              if (_unreadChatCount > 0)
                Positioned(
                  right: 8,
                  top: 8,
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
                        padding: const EdgeInsets.symmetric(horizontal: 14.0, vertical: 12.0),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            // 1. Multi-Child Selector Chips
                            if (_linkedStudents.length > 1) ...[
                              const Text(
                                'Select Linked Child:',
                                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
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
                                        avatar: const Icon(Icons.person, size: 16),
                                        label: Text(st['name'] ?? 'Student'),
                                        selected: isSelected,
                                        selectedColor: AppColors.primaryIndigo,
                                        labelStyle: TextStyle(
                                          color: isSelected ? Colors.white : (isDark ? Colors.white : Colors.black),
                                          fontWeight: FontWeight.bold,
                                          fontSize: 12,
                                        ),
                                        onSelected: (selected) {
                                          if (selected) _onSelectStudent(st);
                                        },
                                      ),
                                    );
                                  }).toList(),
                                ),
                              ),
                              const SizedBox(height: 12),
                            ],

                            // 2. Student Profile Card
                            _buildStudentProfileCard(isDark),
                            const SizedBox(height: 14),

                            // 3. Full-Day Attendance In/Out Timeline Card
                            _buildTodayAttendanceTimelineCard(isDark),
                            const SizedBox(height: 14),

                            // 4. Monthly Attendance & History Card
                            _buildMonthlyAttendanceCard(isDark),
                            const SizedBox(height: 14),

                            // 5. Fee Payment Status & 12-Month Matrix
                            _buildFeeBillingCard(isDark),
                            const SizedBox(height: 14),

                            // 6. Direct Admin Chat Desk Card
                            _buildChatSupportCard(isDark),
                            const SizedBox(height: 14),

                            // 7. Support Requests & Queries
                            _buildComplaintsCard(isDark),
                            const SizedBox(height: 14),

                            // 8. Notices & Announcements
                            _buildNoticesCard(isDark),
                            const SizedBox(height: 20),
                          ],
                        ),
                      ),
                    ),
    );
  }

  // 1. STUDENT PROFILE CARD (Guaranteed No Overflow)
  Widget _buildStudentProfileCard(bool isDark) {
    final student = _studentSummary?['student'] ?? _selectedStudent;
    final seatNumber = _studentSummary?['seat_number'];
    final shift = _studentSummary?['shift'];

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(14.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 24,
                  backgroundColor: AppColors.primaryIndigo.withOpacity(0.12),
                  child: const Icon(Icons.school, size: 26, color: AppColors.primaryIndigo),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        student?['name'] ?? 'Student Name',
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'Mobile: ${student?['phone'] ?? 'N/A'}',
                        style: TextStyle(color: isDark ? Colors.grey[400] : Colors.grey[700], fontSize: 12),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                      Text(
                        'Prep: ${student?['preparation_for'] ?? 'General Studies'}',
                        style: TextStyle(color: isDark ? Colors.grey[400] : Colors.grey[700], fontSize: 12),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const Divider(height: 20),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _buildInfoBadge(
                  icon: Icons.event_seat_rounded,
                  label: seatNumber != null ? 'Desk $seatNumber' : 'Unassigned Desk',
                  color: AppColors.primaryIndigo,
                ),
                _buildInfoBadge(
                  icon: Icons.access_time_filled_rounded,
                  label: shift != null ? (shift['name'] ?? 'Assigned Shift') : 'Unassigned Shift',
                  color: Colors.amber[800]!,
                ),
                if (shift != null && shift['timing'] != null)
                  _buildInfoBadge(
                    icon: Icons.timelapse_rounded,
                    label: shift['timing'],
                    color: Colors.teal,
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  // 2. TODAY'S ATTENDANCE IN/OUT TIMELINE CARD
  Widget _buildTodayAttendanceTimelineCard(bool isDark) {
    final tl = _todayTimeline;
    final currentStatus = tl?['current_status'] ?? 'NOT_CHECKED_IN';
    final statusLabel = tl?['status_label'] ?? 'Not Checked In';
    final firstArrival = tl?['first_arrival_formatted'] ?? 'N/A';
    final finalExit = tl?['final_exit_formatted'] ?? 'In Session';
    final totalInside = tl?['total_inside_formatted'] ?? '0m';
    final exitCount = tl?['exit_count'] ?? 0;
    final reentryCount = tl?['reentry_count'] ?? 0;
    final incompletePairs = tl?['incomplete_pairs'] == true;
    final events = tl?['events'] as List<dynamic>? ?? [];

    Color statusColor;
    Color statusBg;
    if (currentStatus == 'INSIDE_LIBRARY') {
      statusColor = AppColors.statusSuccess;
      statusBg = AppColors.statusSuccessBg;
    } else if (currentStatus == 'OUTSIDE_LIBRARY') {
      statusColor = AppColors.statusWarning;
      statusBg = AppColors.statusWarningBg;
    } else {
      statusColor = Colors.grey[700]!;
      statusBg = isDark ? Colors.grey[800]! : Colors.grey[200]!;
    }

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(14.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Header Row (Safe from overflow)
            Row(
              children: [
                const Icon(Icons.timeline_rounded, color: AppColors.primaryIndigo, size: 20),
                const SizedBox(width: 8),
                const Expanded(
                  child: Text(
                    "Today's Attendance Timeline",
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusBg,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Container(
                        width: 8,
                        height: 8,
                        decoration: BoxDecoration(color: statusColor, shape: BoxShape.circle),
                      ),
                      const SizedBox(width: 5),
                      Text(
                        statusLabel,
                        style: TextStyle(color: statusColor, fontWeight: FontWeight.bold, fontSize: 11),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 14),

            // Responsive Metrics Grid (No Overflow)
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _buildTimelineStatBox("First Arrival", firstArrival, Icons.login_rounded, Colors.green, isDark),
                _buildTimelineStatBox("Final Exit", finalExit, Icons.logout_rounded, Colors.redAccent, isDark),
                _buildTimelineStatBox("Total Inside", totalInside, Icons.timer_rounded, Colors.blue, isDark),
                _buildTimelineStatBox("Activity Cycles", "$exitCount Exits ($reentryCount Re-entry)", Icons.sync_alt_rounded, Colors.purple, isDark),
              ],
            ),

            if (incompletePairs && currentStatus == 'INSIDE_LIBRARY') ...[
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: Colors.green.withOpacity(0.12),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.green.withOpacity(0.3)),
                ),
                child: const Row(
                  children: [
                    Icon(Icons.check_circle_outline, color: Colors.green, size: 16),
                    SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        "Student is currently present inside the library.",
                        style: TextStyle(color: Colors.green, fontSize: 11, fontWeight: FontWeight.w600),
                      ),
                    ),
                  ],
                ),
              ),
            ],

            const Divider(height: 20),

            // Timeline Events List
            const Text("Chronological In / Out Log:", style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: Colors.grey)),
            const SizedBox(height: 8),

            if (events.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 8),
                child: Text("No check-in or exit recorded yet for today.", style: TextStyle(color: Colors.grey, fontSize: 13)),
              )
            else
              ...events.map((ev) => _buildTimelineEventRow(ev)).toList(),
          ],
        ),
      ),
    );
  }

  Widget _buildTimelineStatBox(String label, String value, IconData icon, Color color, bool isDark) {
    return Container(
      width: 155,
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 8),
      decoration: BoxDecoration(
        color: isDark ? const Color(0xFF0F172A) : const Color(0xFFF1F5F9),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: isDark ? const Color(0xFF334155) : const Color(0xFFE2E8F0)),
      ),
      child: Row(
        children: [
          Icon(icon, size: 18, color: color),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: const TextStyle(fontSize: 10, color: Colors.grey)),
                Text(
                  value,
                  style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTimelineEventRow(Map<String, dynamic> ev) {
    final isCheckIn = (ev['event_type'] ?? '').toString().toUpperCase() == 'CHECK_IN';
    final label = ev['event_label'] ?? (isCheckIn ? 'Check-In' : 'Check-Out');
    final timeFormatted = ev['time_formatted'] ?? ev['event_time'] ?? '';
    final source = ev['source'] ?? 'SELF';

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4.0),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(6),
            decoration: BoxDecoration(
              color: isCheckIn ? Colors.green.withOpacity(0.15) : Colors.orange.withOpacity(0.15),
              shape: BoxShape.circle,
            ),
            child: Icon(
              isCheckIn ? Icons.login_rounded : Icons.logout_rounded,
              size: 14,
              color: isCheckIn ? Colors.green : Colors.orange,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              label,
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ),
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
            decoration: BoxDecoration(
              color: Colors.grey.withOpacity(0.15),
              borderRadius: BorderRadius.circular(6),
            ),
            child: Text(source, style: const TextStyle(fontSize: 9, color: Colors.grey, fontWeight: FontWeight.bold)),
          ),
          const SizedBox(width: 8),
          Text(
            timeFormatted,
            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
          ),
        ],
      ),
    );
  }

  // 3. MONTHLY ATTENDANCE & CALENDAR HISTORY CARD
  Widget _buildMonthlyAttendanceCard(bool isDark) {
    final summary = _monthlyAttendance;
    final totalActive = summary?['total_active_days'] ?? 0;
    final presentDays = summary?['present_days'] ?? 0;
    final partialDays = summary?['partial_days'] ?? 0;
    final days = summary?['days'] as List<dynamic>? ?? [];

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(14.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.calendar_month_rounded, color: AppColors.primaryIndigo, size: 20),
                const SizedBox(width: 8),
                const Expanded(
                  child: Text(
                    "Monthly Attendance History",
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                // Month selector button
                TextButton.icon(
                  style: TextButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4)),
                  icon: const Icon(Icons.date_range, size: 14),
                  label: Text(_selectedMonth, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold)),
                  onPressed: () async {
                    final picked = await showDatePicker(
                      context: context,
                      initialDate: DateTime.now(),
                      firstDate: DateTime(2025),
                      lastDate: DateTime(2028),
                    );
                    if (picked != null) {
                      final mStr = "${picked.year}-${picked.month.toString().padLeft(2, '0')}";
                      _changeMonth(mStr);
                    }
                  },
                ),
              ],
            ),
            const SizedBox(height: 10),

            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                _buildMiniBadge("Total Present: $presentDays Days", Colors.green),
                _buildMiniBadge("Partial / Short: $partialDays Days", Colors.amber[800]!),
                _buildMiniBadge("Total Recorded: $totalActive Days", Colors.blue),
              ],
            ),

            const Divider(height: 20),

            if (days.isEmpty)
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 8),
                child: Text("No attendance records found for this month.", style: TextStyle(color: Colors.grey, fontSize: 12)),
              )
            else ...[
              const Text("Tap any day to view full-day in/out timeline:", style: TextStyle(fontSize: 11, color: Colors.grey)),
              const SizedBox(height: 8),
              ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: days.length > 5 ? 5 : days.length,
                separatorBuilder: (_, __) => const Divider(height: 8),
                itemBuilder: (context, idx) {
                  final d = days[idx] as Map<String, dynamic>;
                  final date = d['date']?.toString() ?? '';
                  final isPresent = d['status'] == 'present';

                  return InkWell(
                    onTap: () {
                      if (_selectedStudent != null) {
                        _showDayTimelineDialog(_selectedStudent!['id'], date);
                      }
                    },
                    borderRadius: BorderRadius.circular(8),
                    child: Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 4),
                      child: Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                            decoration: BoxDecoration(
                              color: isDark ? const Color(0xFF0F172A) : const Color(0xFFF1F5F9),
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: Text(
                              "${d['day_number']} ${d['day_name']}",
                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 11),
                            ),
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              "${d['first_arrival'] ?? 'N/A'} - ${d['final_exit'] ?? 'Active'}",
                              style: const TextStyle(fontSize: 12),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                            decoration: BoxDecoration(
                              color: isPresent ? AppColors.statusSuccessBg : AppColors.statusWarningBg,
                              borderRadius: BorderRadius.circular(10),
                            ),
                            child: Text(
                              d['total_inside_formatted'] ?? '0m',
                              style: TextStyle(
                                color: isPresent ? AppColors.statusSuccess : AppColors.statusWarning,
                                fontWeight: FontWeight.bold,
                                fontSize: 10,
                              ),
                            ),
                          ),
                          const SizedBox(width: 4),
                          const Icon(Icons.chevron_right, size: 16, color: Colors.grey),
                        ],
                      ),
                    ),
                  );
                },
              ),
              if (days.length > 5) ...[
                const SizedBox(height: 8),
                Center(
                  child: TextButton(
                    onPressed: () {
                      if (_selectedStudent != null) {
                        _showAllDaysBottomSheet(days);
                      }
                    },
                    child: Text("View all ${days.length} days recorded"),
                  ),
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }

  void _showAllDaysBottomSheet(List<dynamic> days) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) => DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.75,
        minChildSize: 0.4,
        maxChildSize: 0.95,
        builder: (_, scrollController) => ListView.separated(
          controller: scrollController,
          padding: const EdgeInsets.all(20),
          itemCount: days.length,
          separatorBuilder: (_, __) => const Divider(height: 10),
          itemBuilder: (context, idx) {
            final d = days[idx] as Map<String, dynamic>;
            final date = d['date']?.toString() ?? '';
            final isPresent = d['status'] == 'present';

            return ListTile(
              contentPadding: EdgeInsets.zero,
              leading: CircleAvatar(
                backgroundColor: isPresent ? Colors.green.withOpacity(0.15) : Colors.orange.withOpacity(0.15),
                child: Text("${d['day_number']}", style: TextStyle(color: isPresent ? Colors.green : Colors.orange, fontWeight: FontWeight.bold, fontSize: 12)),
              ),
              title: Text("$date (${d['day_name']})", style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
              subtitle: Text("In: ${d['first_arrival'] ?? 'N/A'} | Out: ${d['final_exit'] ?? 'Active'}", style: const TextStyle(fontSize: 11)),
              trailing: ElevatedButton(
                style: ElevatedButton.styleFrom(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6)),
                onPressed: () {
                  Navigator.pop(ctx);
                  if (_selectedStudent != null) {
                    _showDayTimelineDialog(_selectedStudent!['id'], date);
                  }
                },
                child: Text(d['total_inside_formatted'] ?? 'Details', style: const TextStyle(fontSize: 11)),
              ),
            );
          },
        ),
      ),
    );
  }

  // 4. FEE BILLING CARD
  Widget _buildFeeBillingCard(bool isDark) {
    final feeStatus = _studentSummary?['fee_status'];
    final status = feeStatus?['status'] ?? 'pending';
    final label = feeStatus?['label'] ?? 'Pending';
    final dueDate = feeStatus?['due_date'] ?? 'N/A';
    final targetMonthLabel = feeStatus?['target_month_label'] ?? 'N/A';
    final matrix = _studentSummary?['fee_12_month_matrix'] as List<dynamic>? ?? [];

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
        padding: const EdgeInsets.all(14.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.monetization_on_rounded, color: AppColors.statusSuccess, size: 20),
                const SizedBox(width: 8),
                const Expanded(
                  child: Text(
                    "Fee Payment Status",
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusBg,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Text(
                    label,
                    style: TextStyle(color: statusColor, fontWeight: FontWeight.bold, fontSize: 11),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Text('Target Cycle: $targetMonthLabel', style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
            const SizedBox(height: 2),
            Text('Due Date: $dueDate', style: const TextStyle(fontSize: 12, color: Colors.grey)),
            const Divider(height: 20),
            const Text('12-Month Billing Matrix:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: Colors.grey)),
            const SizedBox(height: 8),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: matrix.map((m) {
                final isPaid = m['is_paid'] == true;
                final monthLabel = m['month_label'] ?? '';

                return Container(
                  width: 78,
                  padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 6),
                  decoration: BoxDecoration(
                    color: isPaid ? AppColors.statusSuccessBg : (isDark ? Colors.grey[850] : Colors.grey[100]),
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(
                      color: isPaid ? AppColors.statusSuccess : Colors.grey.withOpacity(0.3),
                    ),
                  ),
                  child: Column(
                    children: [
                      Text(monthLabel, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 10)),
                      const SizedBox(height: 2),
                      Icon(
                        isPaid ? Icons.check_circle : Icons.circle_outlined,
                        size: 13,
                        color: isPaid ? AppColors.statusSuccess : Colors.grey,
                      ),
                      const SizedBox(height: 2),
                      Text(
                        isPaid ? 'Paid' : 'Unpaid',
                        style: TextStyle(
                          fontSize: 9,
                          color: isPaid ? AppColors.statusSuccess : Colors.grey,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ],
                  ),
                );
              }).toList(),
            ),
          ],
        ),
      ),
    );
  }

  // 5. DIRECT ADMIN CHAT DESK CARD (Overflow Safe)
  Widget _buildChatSupportCard(bool isDark) {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(14.0),
        child: Row(
          children: [
            CircleAvatar(
              backgroundColor: AppColors.primaryIndigo.withOpacity(0.12),
              child: const Icon(Icons.mark_chat_unread_rounded, color: AppColors.primaryIndigo),
            ),
            const SizedBox(width: 12),
            const Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'Direct Admin Chat Desk 💬',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                  ),
                  SizedBox(height: 2),
                  Text(
                    'Direct message with Library Owner.',
                    style: TextStyle(fontSize: 11, color: Colors.grey),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 8),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primaryIndigo,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
              ),
              onPressed: _openParentChat,
              child: const Text('Open Chat', style: TextStyle(fontSize: 12)),
            ),
          ],
        ),
      ),
    );
  }

  // 6. SUPPORT REQUESTS & QUERIES CARD
  Widget _buildComplaintsCard(bool isDark) {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(14.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const Icon(Icons.report_problem_rounded, color: Colors.orange, size: 20),
                const SizedBox(width: 8),
                const Expanded(
                  child: Text(
                    "Support Requests",
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.amber[800],
                    foregroundColor: Colors.white,
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  ),
                  icon: const Icon(Icons.add, size: 14),
                  label: const Text('New Request', style: TextStyle(fontSize: 11)),
                  onPressed: _showFileComplaintDialog,
                ),
              ],
            ),
            const SizedBox(height: 10),
            if (_complaints.isEmpty)
              const Text('No complaints or requests filed.', style: TextStyle(color: Colors.grey, fontSize: 12))
            else
              ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: _complaints.length > 3 ? 3 : _complaints.length,
                separatorBuilder: (_, __) => const Divider(height: 8),
                itemBuilder: (context, index) {
                  final c = _complaints[index];
                  final status = c['status'] ?? 'open';
                  final isResolved = status == 'resolved' || status == 'closed';

                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 4),
                    child: Row(
                      children: [
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(c['subject'] ?? 'Query', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12), maxLines: 1, overflow: TextOverflow.ellipsis),
                              Text(c['description'] ?? '', style: const TextStyle(fontSize: 11, color: Colors.grey), maxLines: 1, overflow: TextOverflow.ellipsis),
                            ],
                          ),
                        ),
                        const SizedBox(width: 8),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                          decoration: BoxDecoration(
                            color: isResolved ? AppColors.statusSuccessBg : AppColors.statusWarningBg,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Text(
                            status.toUpperCase(),
                            style: TextStyle(
                              color: isResolved ? AppColors.statusSuccess : AppColors.statusWarning,
                              fontWeight: FontWeight.bold,
                              fontSize: 9,
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                },
              ),
          ],
        ),
      ),
    );
  }

  // 7. NOTICES & ANNOUNCEMENTS CARD
  Widget _buildNoticesCard(bool isDark) {
    final notifs = _studentSummary?['notifications'] as List<dynamic>? ?? [];

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(14.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.notifications_active_rounded, color: AppColors.primaryIndigo, size: 20),
                SizedBox(width: 8),
                Expanded(
                  child: Text(
                    "Library Announcements",
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            if (notifs.isEmpty)
              const Text('No recent announcements.', style: TextStyle(color: Colors.grey, fontSize: 12))
            else
              ListView.separated(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                itemCount: notifs.length > 3 ? 3 : notifs.length,
                separatorBuilder: (_, __) => const Divider(height: 10),
                itemBuilder: (context, index) {
                  final n = notifs[index];
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                            child: Text(
                              n['title'] ?? '',
                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          Text(
                            n['created_at'] != null ? n['created_at'].toString().split(' ')[0] : '',
                            style: const TextStyle(fontSize: 10, color: Colors.grey),
                          ),
                        ],
                      ),
                      const SizedBox(height: 2),
                      Text(n['message'] ?? '', style: const TextStyle(fontSize: 11, color: Colors.grey)),
                    ],
                  );
                },
              ),
          ],
        ),
      ),
    );
  }

  Widget _buildInfoBadge({required IconData icon, required String label, required Color color}) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: color.withOpacity(0.12),
        borderRadius: BorderRadius.circular(8),
        border: Border.all(color: color.withOpacity(0.25)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 14, color: color),
          const SizedBox(width: 4),
          Text(
            label,
            style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 11),
          ),
        ],
      ),
    );
  }

  Widget _buildMiniBadge(String text, Color color) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
      decoration: BoxDecoration(
        color: color.withOpacity(0.12),
        borderRadius: BorderRadius.circular(6),
      ),
      child: Text(
        text,
        style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 11),
      ),
    );
  }
}
