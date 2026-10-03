import 'package:flutter/material.dart';
import '../config/api_config.dart';
import '../services/api_service.dart';
import '../widgets/custom_app_bar.dart';

class ManageShiftsScreen extends StatefulWidget {
  const ManageShiftsScreen({Key? key}) : super(key: key);

  @override
  _ManageShiftsScreenState createState() => _ManageShiftsScreenState();
}

class _ManageShiftsScreenState extends State<ManageShiftsScreen> {
  bool _isLoading = true;
  List<dynamic> _shifts = [];

  @override
  void initState() {
    super.initState();
    _loadShifts();
  }

  Future<void> _loadShifts() async {
    try {
      final res = await ApiService.getAllShiftsAdmin();
      if (mounted) {
        if (res['success'] == true) {
          setState(() {
            _shifts = res['shifts'] ?? [];
            _isLoading = false;
          });
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

  void _openAddEditShiftDialog({Map<String, dynamic>? shift}) {
    final bool isEdit = shift != null;
    final nameController = TextEditingController(text: isEdit ? shift['name'] : '');
    final startTimeController = TextEditingController(text: isEdit ? shift['start_time'] : '08:00');
    final endTimeController = TextEditingController(text: isEdit ? shift['end_time'] : '14:00');
    final feeController = TextEditingController(text: isEdit ? (shift['fee_amount']?.toString() ?? '600') : '600');

    showDialog(
      context: context,
      builder: (ctx) {
        // isDark unused

        return AlertDialog(
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          title: Row(
            children: [
              const Icon(Icons.schedule_rounded, color: AppColors.primaryIndigo),
              const SizedBox(width: 8),
              Text(
                isEdit ? 'Edit Shift Details' : 'Add New Library Shift',
                style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
              ),
            ],
          ),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: nameController,
                  decoration: const InputDecoration(
                    labelText: 'Shift Name *',
                    hintText: 'e.g. Morning Shift, Night Shift',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: TextField(
                        controller: startTimeController,
                        decoration: const InputDecoration(
                          labelText: 'Start Time (HH:MM) *',
                          hintText: '08:00',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: TextField(
                        controller: endTimeController,
                        decoration: const InputDecoration(
                          labelText: 'End Time (HH:MM) *',
                          hintText: '14:00',
                          border: OutlineInputBorder(),
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: feeController,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'Monthly Fee Rate (₹) *',
                    hintText: '600.00',
                    prefixText: '₹ ',
                    border: OutlineInputBorder(),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Cancel', style: TextStyle(color: Colors.grey)),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primaryIndigo,
                foregroundColor: Colors.white,
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
              onPressed: () async {
                final name = nameController.text.trim();
                final start = startTimeController.text.trim();
                final end = endTimeController.text.trim();
                final fee = double.tryParse(feeController.text.trim()) ?? 0.0;

                if (name.isEmpty || start.isEmpty || end.isEmpty || fee <= 0) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Please fill all valid shift fields.')),
                  );
                  return;
                }

                Navigator.pop(ctx);
                Map<String, dynamic> res;

                if (isEdit) {
                  final shiftId = shift['id'] is int ? shift['id'] : int.parse(shift['id'].toString());
                  res = await ApiService.editShift(
                    shiftId: shiftId,
                    name: name,
                    startTime: start,
                    endTime: end,
                    feeAmount: fee,
                  );
                } else {
                  res = await ApiService.addShift(
                    name: name,
                    startTime: start,
                    endTime: end,
                    feeAmount: fee,
                  );
                }

                if (mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text(res['message'] ?? 'Shift updated.'),
                      backgroundColor: res['success'] == true ? AppColors.statusSuccess : AppColors.statusDanger,
                    ),
                  );
                  _loadShifts();
                }
              },
              child: Text(isEdit ? 'SAVE CHANGES' : 'CREATE SHIFT'),
            ),
          ],
        );
      },
    );
  }

  void _toggleShiftStatus(int shiftId, bool currentStatus) async {
    final res = await ApiService.toggleShift(shiftId, !currentStatus);
    if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(res['message'] ?? 'Status updated.'),
          backgroundColor: res['success'] == true ? AppColors.statusSuccess : AppColors.statusDanger,
        ),
      );
      _loadShifts();
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      appBar: const CustomAppBar(title: 'Shift Management'),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _openAddEditShiftDialog(),
        backgroundColor: AppColors.primaryIndigo,
        icon: const Icon(Icons.add_rounded, color: Colors.white),
        label: const Text('Add Shift', style: TextStyle(fontWeight: FontWeight.bold, color: Colors.white)),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: AppColors.primaryIndigo))
          : RefreshIndicator(
              onRefresh: _loadShifts,
              color: AppColors.primaryIndigo,
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(16.0),
                physics: const AlwaysScrollableScrollPhysics(),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Top Summary Card
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: AppColors.primaryIndigo,
                        borderRadius: BorderRadius.circular(16),
                      ),
                      child: Row(
                        children: [
                          Container(
                            padding: const EdgeInsets.all(12),
                            decoration: BoxDecoration(
                              color: Colors.white.withOpacity(0.2),
                              borderRadius: BorderRadius.circular(12),
                            ),
                            child: const Icon(Icons.schedule_rounded, color: Colors.white, size: 28),
                          ),
                          const SizedBox(width: 14),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  'Library Shift Timings & Fee Rates',
                                  style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold, fontSize: 16),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  'Total Shifts: ${_shifts.length} configured',
                                  style: const TextStyle(color: Color(0xFFE0E7FF), fontSize: 12),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 18),

                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          'Configured Shifts (${_shifts.length})',
                          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                        ),
                        TextButton.icon(
                          onPressed: () => _openAddEditShiftDialog(),
                          icon: const Icon(Icons.add_circle_outline_rounded, size: 18),
                          label: const Text('New Shift', style: TextStyle(fontWeight: FontWeight.bold)),
                        ),
                      ],
                    ),
                    const SizedBox(height: 10),

                    // Shifts Cards List
                    _shifts.isEmpty
                        ? const Padding(
                            padding: EdgeInsets.all(30.0),
                            child: Center(
                              child: Text('No shifts configured yet. Tap + Add Shift to create one.', style: TextStyle(color: Colors.grey)),
                            ),
                          )
                        : ListView.builder(
                            shrinkWrap: true,
                            physics: const NeverScrollableScrollPhysics(),
                            itemCount: _shifts.length,
                            itemBuilder: (ctx, idx) {
                              final item = _shifts[idx];
                              final bool isActive = (item['is_active'] == 1 || item['is_active'] == '1');
                              final int shiftId = item['id'] is int ? item['id'] : int.parse(item['id'].toString());
                              final double fee = (item['fee_amount'] as num?)?.toDouble() ?? 600.0;
                              final int studentCount = (item['active_students_count'] as num?)?.toInt() ?? 0;

                              return Card(
                                margin: const EdgeInsets.only(bottom: 12),
                                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                                color: isDark ? AppColors.darkCard : Colors.white,
                                child: Padding(
                                  padding: const EdgeInsets.all(16.0),
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Row(
                                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                        children: [
                                          Expanded(
                                            child: Text(
                                              item['name'] ?? 'Shift Name',
                                              style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                                            ),
                                          ),
                                          Switch(
                                            value: isActive,
                                            activeColor: AppColors.statusSuccess,
                                            onChanged: (val) => _toggleShiftStatus(shiftId, isActive),
                                          ),
                                        ],
                                      ),
                                      const SizedBox(height: 6),
                                      Row(
                                        children: [
                                          const Icon(Icons.access_time_rounded, size: 16, color: AppColors.primaryIndigo),
                                          const SizedBox(width: 6),
                                          Text(
                                            'Timing: ${item['start_time']} - ${item['end_time']}',
                                            style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                                          ),
                                        ],
                                      ),
                                      const SizedBox(height: 4),
                                      Row(
                                        children: [
                                          const Icon(Icons.payments_rounded, size: 16, color: AppColors.statusSuccess),
                                          const SizedBox(width: 6),
                                          Text(
                                            'Monthly Fee: ₹${fee.toStringAsFixed(2)} / month',
                                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: AppColors.statusSuccess),
                                          ),
                                        ],
                                      ),
                                      const Divider(height: 20),

                                      Row(
                                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                        children: [
                                          Container(
                                            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                            decoration: BoxDecoration(
                                              color: AppColors.primaryIndigo.withOpacity(0.1),
                                              borderRadius: BorderRadius.circular(10),
                                            ),
                                            child: Text(
                                              '$studentCount Students Enrolled',
                                              style: const TextStyle(
                                                color: AppColors.primaryIndigo,
                                                fontWeight: FontWeight.bold,
                                                fontSize: 12,
                                              ),
                                            ),
                                          ),
                                          OutlinedButton.icon(
                                            icon: const Icon(Icons.edit_rounded, size: 14),
                                            label: const Text('Edit Shift'),
                                            style: OutlinedButton.styleFrom(
                                              foregroundColor: AppColors.primaryIndigo,
                                              side: const BorderSide(color: AppColors.primaryIndigo),
                                              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                              minimumSize: Size.zero,
                                              tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                                              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                            ),
                                            onPressed: () => _openAddEditShiftDialog(shift: item),
                                          ),
                                        ],
                                      ),
                                    ],
                                  ),
                                ),
                              );
                            },
                          ),

                    const SizedBox(height: 40),
                    const Center(
                      child: Text(
                        'Powered by Ramxonwebwork',
                        style: TextStyle(
                          fontSize: 12,
                          fontWeight: FontWeight.bold,
                          color: AppColors.primaryIndigo,
                        ),
                      ),
                    ),
                    const SizedBox(height: 10),
                  ],
                ),
              ),
            ),
    );
  }
}
