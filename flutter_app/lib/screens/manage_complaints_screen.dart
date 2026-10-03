import 'package:flutter/material.dart';
import '../config/api_config.dart';
import '../services/api_service.dart';
import '../widgets/custom_app_bar.dart';

class ManageComplaintsScreen extends StatefulWidget {
  const ManageComplaintsScreen({Key? key}) : super(key: key);

  @override
  _ManageComplaintsScreenState createState() => _ManageComplaintsScreenState();
}

class _ManageComplaintsScreenState extends State<ManageComplaintsScreen> {
  bool _isLoading = true;
  List<dynamic> _allTickets = [];
  String _filter = 'open'; // 'all', 'open', 'in_progress', 'resolved'

  @override
  void initState() {
    super.initState();
    _loadTickets();
  }

  void _loadTickets() async {
    final res = await ApiService.getAllComplaints();
    if (mounted) {
      setState(() {
        _isLoading = false;
        if (res['success'] == true) {
          _allTickets = res['complaints'] ?? [];
        }
      });
    }
  }

  void _updateStatus(int complaintId, String newStatus) async {
    final res = await ApiService.updateComplaintStatus(complaintId, newStatus);
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Ticket status updated.'),
          backgroundColor: newStatus == 'resolved' ? AppColors.statusSuccess : AppColors.primaryIndigo,
        ),
      );
      _loadTickets();
    }
  }

  List<dynamic> get _filteredTickets {
    if (_filter == 'all') return _allTickets;
    return _allTickets.where((t) => (t['status'] ?? 'open') == _filter).toList();
  }

  // Ticket Details Modal (Matching Screen 18 Ticket Details)
  void _showTicketDetailsModal(Map<String, dynamic> t) {
    final int id = t['id'] is int ? t['id'] : int.parse(t['id'].toString());
    final String name = t['student_name'] ?? 'Student';
    final String phone = t['student_phone'] ?? '';
    final String subj = t['subject'] ?? 'Support Request';
    final String desc = t['description'] ?? '';
    final String status = t['status'] ?? 'open';
    final String priority = t['priority'] ?? 'Medium';
    final String date = t['created_at'] ?? '2 hours ago';

    Color priorityColor = const Color(0xFFF59E0B);
    if (priority.toLowerCase() == 'high') priorityColor = AppColors.statusDanger;
    if (priority.toLowerCase() == 'low') priorityColor = AppColors.statusSuccess;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
            top: 20,
            left: 20,
            right: 20,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          'Ticket #TKT-${id.toString().padLeft(3, '0')}',
                          style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                        ),
                        Text('Submitted: $date', style: const TextStyle(fontSize: 12, color: Colors.grey)),
                      ],
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                    decoration: BoxDecoration(
                      color: priorityColor.withOpacity(0.15),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Text(
                      priority,
                      style: TextStyle(color: priorityColor, fontWeight: FontWeight.bold, fontSize: 12),
                    ),
                  ),
                ],
              ),
              const Divider(height: 20),

              Row(
                children: [
                  const Icon(Icons.person_rounded, size: 18, color: AppColors.primaryIndigo),
                  const SizedBox(width: 8),
                  Text('Student: $name ${phone.isNotEmpty ? "($phone)" : ""}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14)),
                ],
              ),
              const SizedBox(height: 12),

              const Text('Message / Request:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
              const SizedBox(height: 4),
              Container(
                padding: const EdgeInsets.all(12),
                width: double.infinity,
                decoration: BoxDecoration(
                  color: Colors.grey.shade100,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(
                  desc.isNotEmpty ? desc : subj,
                  style: const TextStyle(fontSize: 14),
                ),
              ),
              const SizedBox(height: 14),

              Row(
                children: [
                  const Text('Status: ', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                  Text(status.toUpperCase(), style: TextStyle(fontWeight: FontWeight.bold, color: status == 'resolved' ? AppColors.statusSuccess : Colors.amber.shade800)),
                ],
              ),
              const SizedBox(height: 20),

              if (status == 'open') ...[
                Row(
                  children: [
                    Expanded(
                      child: ElevatedButton.icon(
                        icon: const Icon(Icons.hourglass_top_rounded, color: Colors.white, size: 18),
                        label: const Text('In Progress', style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.white)),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xFFF59E0B),
                          padding: const EdgeInsets.symmetric(vertical: 12),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        onPressed: () {
                          Navigator.pop(ctx);
                          _updateStatus(id, 'in_progress');
                        },
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: ElevatedButton.icon(
                        icon: const Icon(Icons.check_circle_rounded, color: Colors.white, size: 18),
                        label: const Text('Resolve', style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold, color: Colors.white)),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xFF10B981),
                          padding: const EdgeInsets.symmetric(vertical: 12),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        onPressed: () {
                          Navigator.pop(ctx);
                          _updateStatus(id, 'resolved');
                        },
                      ),
                    ),
                  ],
                ),
              ] else if (status == 'in_progress') ...[
                SizedBox(
                  width: double.infinity,
                  height: 48,
                  child: ElevatedButton.icon(
                    icon: const Icon(Icons.check_circle_rounded, color: Colors.white),
                    label: const Text('Mark as Resolved', style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Colors.white)),
                    style: ElevatedButton.styleFrom(
                      backgroundColor: const Color(0xFF10B981),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                    ),
                    onPressed: () {
                      Navigator.pop(ctx);
                      _updateStatus(id, 'resolved');
                    },
                  ),
                ),
              ] else ...[
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.symmetric(vertical: 12),
                  decoration: BoxDecoration(
                    color: Colors.green.shade100,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: const Center(
                    child: Text('Ticket Marked as Resolved', style: TextStyle(color: Colors.green, fontWeight: FontWeight.bold, fontSize: 14)),
                  ),
                ),
              ],
            ],
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final int openCount = _allTickets.where((t) => t['status'] == 'open').length;
    final int inProgCount = _allTickets.where((t) => t['status'] == 'in_progress').length;
    final int resCount = _allTickets.where((t) => t['status'] == 'resolved').length;

    return Scaffold(
      appBar: const CustomAppBar(title: 'Support Tickets'),
      body: SafeArea(
        child: Column(
          children: [
            // Top Filter Chips Header (Matching Screen 17)
            Container(
              color: isDark ? AppColors.darkCard : Colors.white,
              padding: const EdgeInsets.all(12),
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    _buildFilterChip('Open ($openCount)', 'open'),
                    const SizedBox(width: 8),
                    _buildFilterChip('In Progress ($inProgCount)', 'in_progress'),
                    const SizedBox(width: 8),
                    _buildFilterChip('Resolved ($resCount)', 'resolved'),
                    const SizedBox(width: 8),
                    _buildFilterChip('All (${_allTickets.length})', 'all'),
                  ],
                ),
              ),
            ),

            // Tickets List (Matching Screen 17)
            Expanded(
              child: _isLoading
                  ? const Center(child: CircularProgressIndicator(color: AppColors.primaryIndigo))
                  : RefreshIndicator(
                      onRefresh: () async => _loadTickets(),
                      color: AppColors.primaryIndigo,
                      child: _filteredTickets.isEmpty
                          ? const Center(child: Text('No support tickets found for this filter.', style: TextStyle(color: Colors.grey)))
                          : ListView.builder(
                              padding: const EdgeInsets.all(14),
                              itemCount: _filteredTickets.length,
                              itemBuilder: (ctx, idx) {
                                final t = _filteredTickets[idx];
                                final int id = t['id'] is int ? t['id'] : int.parse(t['id'].toString());
                                final String name = t['student_name'] ?? 'Student';
                                final String subj = t['subject'] ?? 'Support Ticket';
                                // status unused
                                final String priority = t['priority'] ?? (id % 2 == 0 ? 'High' : 'Medium');
                                final String date = t['created_at'] ?? 'Recently';

                                Color pColor = const Color(0xFFF59E0B);
                                if (priority.toLowerCase() == 'high') pColor = AppColors.statusDanger;
                                if (priority.toLowerCase() == 'low') pColor = AppColors.statusSuccess;

                                return Card(
                                  margin: const EdgeInsets.only(bottom: 12),
                                  elevation: 2,
                                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                                  color: isDark ? AppColors.darkCard : Colors.white,
                                  child: InkWell(
                                    onTap: () => _showTicketDetailsModal(t),
                                    borderRadius: BorderRadius.circular(16),
                                    child: Padding(
                                      padding: const EdgeInsets.all(14.0),
                                      child: Row(
                                        children: [
                                          CircleAvatar(
                                            radius: 20,
                                            backgroundColor: pColor.withOpacity(0.15),
                                            child: Icon(Icons.headset_mic_rounded, color: pColor, size: 20),
                                          ),
                                          const SizedBox(width: 12),
                                          Expanded(
                                            child: Column(
                                              crossAxisAlignment: CrossAxisAlignment.start,
                                              children: [
                                                Row(
                                                  children: [
                                                    Expanded(
                                                      child: Text(
                                                        subj,
                                                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
                                                        maxLines: 1,
                                                        overflow: TextOverflow.ellipsis,
                                                      ),
                                                    ),
                                                    Container(
                                                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                                                      decoration: BoxDecoration(
                                                        color: pColor.withOpacity(0.12),
                                                        borderRadius: BorderRadius.circular(8),
                                                      ),
                                                      child: Text(
                                                        priority,
                                                        style: TextStyle(color: pColor, fontSize: 11, fontWeight: FontWeight.bold),
                                                      ),
                                                    ),
                                                  ],
                                                ),
                                                const SizedBox(height: 4),
                                                Text(
                                                  '#TKT-${id.toString().padLeft(3, '0')} • $name • $date',
                                                  style: const TextStyle(fontSize: 12, color: Colors.grey),
                                                ),
                                              ],
                                            ),
                                          ),
                                        ],
                                      ),
                                    ),
                                  ),
                                );
                              },
                            ),
                    ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildFilterChip(String label, String value) {
    final isSelected = _filter == value;
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
        if (val) setState(() => _filter = value);
      },
    );
  }
}
