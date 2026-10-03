import 'package:flutter/material.dart';
import '../config/api_config.dart';
import '../services/api_service.dart';
import '../widgets/custom_app_bar.dart';

class LiveAttendanceScreen extends StatefulWidget {
  const LiveAttendanceScreen({Key? key}) : super(key: key);

  @override
  _LiveAttendanceScreenState createState() => _LiveAttendanceScreenState();
}

class _LiveAttendanceScreenState extends State<LiveAttendanceScreen> {
  bool _isLoading = true;
  List<dynamic> _allAttendance = [];
  List<dynamic> _filteredAttendance = [];
  int _totalStudents = 5;
  int _currentlyInside = 0;
  int _absentOutside = 5;
  String _searchQuery = '';
  String _statusFilter = 'all'; // all, inside, outside

  @override
  void initState() {
    super.initState();
    _loadLiveAttendance();
  }

  Future<void> _loadLiveAttendance() async {
    try {
      final res = await ApiService.getLiveAttendance();
      if (mounted) {
        if (res['success'] == true) {
          setState(() {
            _allAttendance = res['attendance_list'] ?? [];
            _totalStudents = res['total_students'] is int
                ? res['total_students']
                : int.tryParse(res['total_students']?.toString() ?? '0') ?? _allAttendance.length;
            _currentlyInside = res['currently_inside'] is int
                ? res['currently_inside']
                : int.tryParse(res['currently_inside']?.toString() ?? '0') ?? 0;
            _absentOutside = res['absent_outside'] is int
                ? res['absent_outside']
                : int.tryParse(res['absent_outside']?.toString() ?? '0') ?? (_totalStudents - _currentlyInside);
            _isLoading = false;
          });
          _applyFilters();
        } else {
          setState(() => _isLoading = false);
        }
      }
    } catch (e) {
      if (mounted) {
        setState(() => _isLoading = false);
      }
    }
  }

  void _applyFilters() {
    final query = _searchQuery.toLowerCase().trim();
    setState(() {
      _filteredAttendance = _allAttendance.where((item) {
        final name = (item['student_name'] ?? '').toString().toLowerCase();
        final phone = (item['phone'] ?? '').toString().toLowerCase();
        final desk = (item['seat_number'] ?? '').toString().toLowerCase();
        final shift = (item['shift_name'] ?? '').toString().toLowerCase();

        final bool isPresent = item['check_in_time'] != null &&
            (item['check_out_time'] == null || item['check_out_time'].toString().isEmpty);

        final matchesSearch = query.isEmpty ||
            name.contains(query) ||
            phone.contains(query) ||
            desk.contains(query) ||
            shift.contains(query);

        final matchesStatus = _statusFilter == 'all' ||
            (_statusFilter == 'inside' && isPresent) ||
            (_statusFilter == 'outside' && !isPresent);

        return matchesSearch && matchesStatus;
      }).toList();
    });
  }

  void _toggleAttendance(int studentId, String type) async {
    final res = await ApiService.adminAttendanceToggle(studentId, type);
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Attendance status updated.'),
          backgroundColor: type == 'checkin' ? AppColors.statusSuccess : AppColors.statusDanger,
        ),
      );
      _loadLiveAttendance();
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      appBar: const CustomAppBar(title: 'Live Attendance'),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: AppColors.primaryIndigo))
          : RefreshIndicator(
              onRefresh: _loadLiveAttendance,
              color: AppColors.primaryIndigo,
              child: Column(
                children: [
                  // Top Summary Metrics Bar (Fixing Point 3 Alignment & Red Color for Outside!)
                  Container(
                    color: isDark ? AppColors.darkCard : Colors.white,
                    padding: const EdgeInsets.all(14),
                    child: Column(
                      children: [
                        Row(
                          children: [
                            Expanded(
                              child: _buildMetricCard(
                                value: '$_totalStudents',
                                label: 'Total Allotted',
                                color: AppColors.primaryIndigo,
                                bg: AppColors.primaryIndigo.withOpacity(0.08),
                              ),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: _buildMetricCard(
                                value: '$_currentlyInside',
                                label: 'Inside Hall',
                                color: AppColors.statusSuccess,
                                bg: AppColors.statusSuccessBg,
                              ),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: _buildMetricCard(
                                value: '$_absentOutside',
                                label: 'Outside / Exited',
                                color: AppColors.statusDanger,
                                bg: AppColors.statusDangerBg,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 12),

                        // Search Student Bar
                        TextField(
                          onChanged: (val) {
                            _searchQuery = val;
                            _applyFilters();
                          },
                          decoration: InputDecoration(
                            hintText: 'Search student name, phone...',
                            prefixIcon: const Icon(Icons.search_rounded, color: AppColors.primaryIndigo),
                            suffixIcon: _searchQuery.isNotEmpty
                                ? IconButton(
                                    icon: const Icon(Icons.clear_rounded),
                                    onPressed: () {
                                      setState(() => _searchQuery = '');
                                      _applyFilters();
                                    },
                                  )
                                : null,
                            contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                            border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                          ),
                        ),
                        const SizedBox(height: 10),

                        // Status Filter Chips
                        SingleChildScrollView(
                          scrollDirection: Axis.horizontal,
                          child: Row(
                            children: [
                              _buildFilterChip('All ($_totalStudents)', 'all'),
                              const SizedBox(width: 8),
                              _buildFilterChip('Inside ($_currentlyInside)', 'inside'),
                              const SizedBox(width: 8),
                              _buildFilterChip('Outside ($_absentOutside)', 'outside'),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),

                  // Student Attendance Cards List
                  Expanded(
                    child: _filteredAttendance.isEmpty
                        ? const Center(
                            child: Text('No student records match filter criteria.', style: TextStyle(color: Colors.grey)),
                          )
                        : ListView.builder(
                            padding: const EdgeInsets.all(14),
                            itemCount: _filteredAttendance.length,
                            itemBuilder: (ctx, index) {
                              final item = _filteredAttendance[index];
                              final bool isPresent = item['check_in_time'] != null &&
                                  (item['check_out_time'] == null || item['check_out_time'].toString().isEmpty);

                              final int studentId = item['user_id'] is int
                                  ? item['user_id']
                                  : int.tryParse(item['user_id']?.toString() ?? '0') ?? 0;

                              return Card(
                                margin: const EdgeInsets.only(bottom: 12),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                                color: isDark ? AppColors.darkCard : Colors.white,
                                child: Padding(
                                  padding: const EdgeInsets.all(14.0),
                                  child: Row(
                                    children: [
                                      CircleAvatar(
                                        radius: 22,
                                        backgroundColor: AppColors.primaryIndigo.withOpacity(0.12),
                                        child: Text(
                                          (item['student_name'] ?? 'S').substring(0, 1).toUpperCase(),
                                          style: const TextStyle(
                                            color: AppColors.primaryIndigo,
                                            fontWeight: FontWeight.bold,
                                            fontSize: 18,
                                          ),
                                        ),
                                      ),
                                      const SizedBox(width: 12),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              item['student_name'] ?? 'Student',
                                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                                            ),
                                            const SizedBox(height: 2),
                                            Text(
                                              item['phone'] ?? '9812345678',
                                              style: const TextStyle(fontSize: 12, color: Colors.grey),
                                            ),
                                            Text(
                                              'Desk ${item['seat_number'] ?? 'A-01'} (${item['shift_name'] ?? 'Morning'})',
                                              style: TextStyle(
                                                fontSize: 12,
                                                color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B),
                                              ),
                                            ),
                                             const SizedBox(height: 4),
                                             // 1-Day Arrival / Departure History Timings Log
                                             Container(
                                               padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                               decoration: BoxDecoration(
                                                 color: isDark ? const Color(0xFF1E293B) : const Color(0xFFF1F5F9),
                                                 borderRadius: BorderRadius.circular(8),
                                               ),
                                               child: Column(
                                                 crossAxisAlignment: CrossAxisAlignment.start,
                                                 children: [
                                                   Row(
                                                     children: [
                                                       const Icon(Icons.login_rounded, size: 12, color: AppColors.statusSuccess),
                                                       const SizedBox(width: 4),
                                                       Text(
                                                         'In: ${item['check_in_formatted'] ?? (item['check_in_time'] != null ? item['check_in_time'].toString() : "Not Arrived")}',
                                                         style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600),
                                                       ),
                                                     ],
                                                   ),
                                                   const SizedBox(height: 2),
                                                   Row(
                                                     children: [
                                                       Icon(Icons.logout_rounded, size: 12, color: isPresent ? AppColors.statusSuccess : AppColors.statusDanger),
                                                       const SizedBox(width: 4),
                                                       Text(
                                                         'Out: ${item['check_out_formatted'] ?? (item['check_out_time'] != null ? item['check_out_time'].toString() : "N/A")}',
                                                         style: TextStyle(
                                                           fontSize: 11,
                                                           fontWeight: FontWeight.w600,
                                                           color: isPresent ? AppColors.statusSuccess : (item['check_out_time'] != null ? AppColors.statusDanger : Colors.grey),
                                                         ),
                                                       ),
                                                     ],
                                                   ),
                                                   if (item['duration_today'] != null && item['duration_today'] != '--') ...[
                                                     const SizedBox(height: 2),
                                                     Text(
                                                       'Spent Today: ${item['duration_today']}',
                                                       style: const TextStyle(fontSize: 10, color: AppColors.primaryIndigo, fontWeight: FontWeight.bold),
                                                     ),
                                                   ],
                                                 ],
                                               ),
                                             ),
                                          ],
                                        ),
                                      ),
                                      Column(
                                        crossAxisAlignment: CrossAxisAlignment.end,
                                        children: [
                                          Text(
                                            isPresent ? 'Inside' : 'Outside',
                                            style: TextStyle(
                                              color: isPresent ? AppColors.statusSuccess : AppColors.statusDanger,
                                              fontSize: 12,
                                              fontWeight: FontWeight.bold,
                                            ),
                                          ),
                                          const SizedBox(height: 6),
                                          isPresent
                                              ? ElevatedButton.icon(
                                                  icon: const Icon(Icons.logout_rounded, size: 14, color: Colors.white),
                                                  label: const Text('Check Out', style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold)),
                                                  style: ElevatedButton.styleFrom(
                                                    backgroundColor: AppColors.statusDanger,
                                                    foregroundColor: Colors.white,
                                                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                                  ),
                                                  onPressed: () => _toggleAttendance(studentId, 'checkout'),
                                                )
                                              : ElevatedButton.icon(
                                                  icon: const Icon(Icons.login_rounded, size: 14, color: Colors.white),
                                                  label: const Text('Check In', style: TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.bold)),
                                                  style: ElevatedButton.styleFrom(
                                                    backgroundColor: AppColors.statusSuccess,
                                                    foregroundColor: Colors.white,
                                                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                                  ),
                                                  onPressed: () => _toggleAttendance(studentId, 'checkin'),
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
              ),
            ),
    );
  }

  Widget _buildMetricCard({
    required String value,
    required String label,
    required Color color,
    required Color bg,
  }) {
    return Container(
      height: 72,
      padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 6),
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(14),
      ),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Text(
            value,
            style: TextStyle(fontSize: 22, fontWeight: FontWeight.bold, color: color),
          ),
          const SizedBox(height: 2),
          Text(
            label,
            style: TextStyle(fontSize: 10, fontWeight: FontWeight.w600, color: color),
            textAlign: TextAlign.center,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
          ),
        ],
      ),
    );
  }

  Widget _buildFilterChip(String label, String value) {
    final isSelected = _statusFilter == value;
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return ChoiceChip(
      label: Text(label),
      selected: isSelected,
      selectedColor: AppColors.primaryIndigo,
      backgroundColor: isDark ? AppColors.darkCard : Colors.grey.shade200,
      labelStyle: TextStyle(
        color: isSelected ? Colors.white : (isDark ? Colors.grey.shade300 : Colors.black87),
        fontWeight: isSelected ? FontWeight.bold : FontWeight.w500,
        fontSize: 13,
      ),
      onSelected: (val) {
        if (val) {
          setState(() => _statusFilter = value);
          _applyFilters();
        }
      },
    );
  }
}
