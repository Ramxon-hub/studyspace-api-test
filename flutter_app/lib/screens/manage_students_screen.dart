import 'package:flutter/material.dart';
import '../config/api_config.dart';
import '../services/api_service.dart';
import '../widgets/custom_app_bar.dart';

class ManageStudentsScreen extends StatefulWidget {
  const ManageStudentsScreen({Key? key}) : super(key: key);

  @override
  _ManageStudentsScreenState createState() => _ManageStudentsScreenState();
}

class _ManageStudentsScreenState extends State<ManageStudentsScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  bool _isLoadingActive = true;
  bool _isLoadingRecycleBin = true;

  List<dynamic> _allStudents = [];
  List<dynamic> _filteredStudents = [];
  List<dynamic> _recycleBinStudents = [];

  String _searchQuery = '';
  String _selectedStatusFilter = 'all';

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 2, vsync: this);
    _fetchActiveStudents();
    _fetchRecycleBinStudents();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _fetchActiveStudents() async {
    setState(() => _isLoadingActive = true);
    final res = await ApiService.getAllStudents();
    if (mounted) {
      if (res['success'] == true) {
        setState(() {
          _allStudents = res['students'] ?? [];
          _isLoadingActive = false;
        });
        _applyFilters();
      } else {
        setState(() => _isLoadingActive = false);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res['message'] ?? 'Failed to load students directory.')),
        );
      }
    }
  }

  Future<void> _fetchRecycleBinStudents() async {
    setState(() => _isLoadingRecycleBin = true);
    final res = await ApiService.getRecycleBinStudents();
    if (mounted) {
      if (res['success'] == true) {
        setState(() {
          _recycleBinStudents = res['students'] ?? [];
          _isLoadingRecycleBin = false;
        });
      } else {
        setState(() => _isLoadingRecycleBin = false);
      }
    }
  }

  void _applyFilters() {
    final query = _searchQuery.toLowerCase().trim();
    setState(() {
      _filteredStudents = _allStudents.where((s) {
        final name = (s['name'] ?? '').toString().toLowerCase();
        final phone = (s['phone'] ?? '').toString().toLowerCase();
        final email = (s['email'] ?? '').toString().toLowerCase();
        final desk = (s['seat_number'] ?? '').toString().toLowerCase();
        final status = (s['status'] ?? '').toString().toLowerCase();

        final matchesSearch = query.isEmpty ||
            name.contains(query) ||
            phone.contains(query) ||
            email.contains(query) ||
            desk.contains(query);

        final matchesStatus = _selectedStatusFilter == 'all' ||
            (_selectedStatusFilter == 'approved' && (status == 'approved' || status == 'active')) ||
            (_selectedStatusFilter == 'pending' && status == 'pending') ||
            (_selectedStatusFilter == 'hold' && status == 'hold') ||
            (_selectedStatusFilter == 'cancelled' && status == 'cancelled');

        return matchesSearch && matchesStatus;
      }).toList();
    });
  }

  void _confirmSoftDeleteStudent(Map<String, dynamic> student) {
    final int studentId = student['id'] is int ? student['id'] : int.tryParse(student['id'].toString()) ?? 0;
    final String studentName = student['name'] ?? 'Student';

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Row(
          children: [
            Icon(Icons.delete_sweep_rounded, color: Colors.orangeAccent),
            SizedBox(width: 8),
            Text('Move to Recycle Bin', style: TextStyle(color: Colors.orangeAccent, fontSize: 16)),
          ],
        ),
        content: Text(
          'Move "$studentName" to Recycle Bin?\n\n'
          'The student record will be safely stored in the Recycle Bin for 60 DAYS before permanent deletion. You can restore it anytime.',
          style: const TextStyle(fontSize: 14),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('CANCEL'),
          ),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.orangeAccent),
            onPressed: () async {
              Navigator.of(ctx).pop();
              _performSoftDeleteStudent(studentId, studentName);
            },
            icon: const Icon(Icons.delete_rounded, color: Colors.white),
            label: const Text('MOVE TO RECYCLE BIN', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
  }

  void _performSoftDeleteStudent(int studentId, String studentName) async {
    final res = await ApiService.deleteStudent(studentId);
    if (mounted) {
      if (res['success'] == true) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'Student "$studentName" moved to Recycle Bin (Kept for 60 days).'),
            backgroundColor: Colors.orangeAccent,
          ),
        );
        _fetchActiveStudents();
        _fetchRecycleBinStudents();
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'Failed to move student to Recycle Bin.'),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }

  void _restoreStudent(Map<String, dynamic> student) async {
    final int studentId = student['id'] is int ? student['id'] : int.tryParse(student['id'].toString()) ?? 0;
    final String studentName = student['name'] ?? 'Student';

    final res = await ApiService.restoreStudent(studentId);
    if (mounted) {
      if (res['success'] == true) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(res['message'] ?? 'Student "$studentName" restored to active list.'),
            backgroundColor: AppColors.statusSuccess,
          ),
        );
        _fetchActiveStudents();
        _fetchRecycleBinStudents();
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res['message'] ?? 'Failed to restore student.')),
        );
      }
    }
  }

  void _confirmPermanentDeleteStudent(Map<String, dynamic> student) {
    final int studentId = student['id'] is int ? student['id'] : int.tryParse(student['id'].toString()) ?? 0;
    final String studentName = student['name'] ?? 'Student';

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Row(
          children: [
            Icon(Icons.warning_amber_rounded, color: Colors.redAccent),
            SizedBox(width: 8),
            Text('Permanently Delete', style: TextStyle(color: Colors.redAccent, fontSize: 16)),
          ],
        ),
        content: Text(
          '⚠️ PERMANENT PURGE WARNING:\n\n'
          'Are you sure you want to PERMANENTLY ERASE "$studentName" from the database?\n\n'
          'This will permanently delete profile, fee records, attendance logs, and seat assignments. This action CANNOT be undone.',
          style: const TextStyle(fontSize: 14),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('CANCEL'),
          ),
          ElevatedButton.icon(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () async {
              Navigator.of(ctx).pop();
              final res = await ApiService.permanentDeleteStudent(studentId);
              if (mounted) {
                if (res['success'] == true) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(
                      content: Text(res['message'] ?? 'Student "$studentName" permanently deleted.'),
                      backgroundColor: Colors.red,
                    ),
                  );
                  _fetchRecycleBinStudents();
                } else {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text(res['message'] ?? 'Failed to erase student permanently.')),
                  );
                }
              }
            },
            icon: const Icon(Icons.delete_forever_rounded, color: Colors.white),
            label: const Text('ERASE PERMANENTLY', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
  }

  void _showStudentDetailsModal(Map<String, dynamic> s) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return SingleChildScrollView(
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
                    child: Text(
                      'Student Profile: ${s['name']}',
                      style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close_rounded),
                    onPressed: () => Navigator.pop(ctx),
                  ),
                ],
              ),
              const Divider(height: 20),

              _buildDetailRow(Icons.person_rounded, 'Full Name', s['name'] ?? 'N/A'),
              _buildDetailRow(Icons.family_restroom_rounded, 'Father Name', s['father_name'] ?? 'N/A'),
              _buildDetailRow(Icons.school_rounded, 'Preparation For', (s['preparation_for'] != null && s['preparation_for'].toString().trim().isNotEmpty) ? s['preparation_for'] : 'Not specified'),
              _buildDetailRow(Icons.phone_android_rounded, 'Phone Number', s['phone'] ?? 'N/A'),
              _buildDetailRow(Icons.email_rounded, 'Email Address', s['email'] ?? 'N/A'),
              _buildDetailRow(Icons.event_seat_rounded, 'Assigned Desk', s['seat_number'] != null ? 'Desk ${s['seat_number']}' : 'Unassigned'),
              _buildDetailRow(Icons.schedule_rounded, 'Assigned Shift', '${s['shift_name'] ?? 'N/A'} (${s['start_time'] ?? ''} - ${s['end_time'] ?? ''})'),
              _buildDetailRow(Icons.location_on_rounded, 'Home Address', s['address'] ?? 'N/A'),
              _buildDetailRow(Icons.numbers_rounded, 'Aadhaar / ID', s['aadhaar_number'] ?? 'N/A'),
              _buildDetailRow(Icons.phone_in_talk_rounded, 'Emergency Contact', s['emergency_contact'] ?? 'N/A'),
              _buildDetailRow(Icons.phone_iphone_rounded, 'Hardware Device ID', s['device_mac'] ?? 'Not Bound'),
              _buildDetailRow(Icons.calendar_today_rounded, 'Registration Date', s['created_at'] ?? 'N/A'),
              _buildDetailRow(Icons.verified_rounded, 'Account Status', (s['status'] ?? 'pending').toString().toUpperCase()),

              const SizedBox(height: 20),
              Row(
                children: [
                  Expanded(
                    child: ElevatedButton.icon(
                      icon: const Icon(Icons.event_seat_rounded, color: Colors.white),
                      label: const Text('Edit Seat', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppColors.primaryIndigo,
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      ),
                      onPressed: () {
                        Navigator.pop(ctx);
                        _showChangeSeatModal(s);
                      },
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: OutlinedButton.icon(
                      icon: const Icon(Icons.edit_rounded, color: AppColors.primaryIndigo),
                      label: const Text('Edit Profile', style: TextStyle(color: AppColors.primaryIndigo)),
                      style: OutlinedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        side: const BorderSide(color: AppColors.primaryIndigo),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      ),
                      onPressed: () {
                        Navigator.pop(ctx);
                        _showEditStudentModal(s);
                      },
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: ElevatedButton.icon(
                      icon: const Icon(Icons.delete_sweep_rounded, color: Colors.white),
                      label: const Text('Bin', style: TextStyle(color: Colors.white)),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.orangeAccent,
                        padding: const EdgeInsets.symmetric(vertical: 12),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      ),
                      onPressed: () {
                        Navigator.pop(ctx);
                        _confirmSoftDeleteStudent(s);
                      },
                    ),
                  ),
                ],
              ),
            ],
          ),
        );
      },
    );
  }

  void _showChangeSeatModal(Map<String, dynamic> s) {
    final int studentId = s['id'] is int ? s['id'] : int.tryParse(s['id'].toString()) ?? 0;
    final String studentName = s['name'] ?? 'Student';
    final String currentSeatNo = s['seat_number'] != null ? s['seat_number'].toString() : 'Unassigned';
    final int currentShiftId = s['shift_id'] is int ? s['shift_id'] : int.tryParse((s['shift_id'] ?? '1').toString()) ?? 1;

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        List<dynamic> shifts = [];
        List<dynamic> seatOptions = [];
        bool isLoadingSeats = true;
        bool isSubmitting = false;
        int selectedShiftId = currentShiftId;
        int? selectedSeatId;

        return StatefulBuilder(
          builder: (context, setModalState) {
            if (shifts.isEmpty && isLoadingSeats) {
              ApiService.getShifts().then((fetchedShifts) {
                if (ctx.mounted) {
                  setModalState(() {
                    shifts = fetchedShifts;
                    if (shifts.isNotEmpty && !shifts.any((sh) => sh['id'] == selectedShiftId)) {
                      selectedShiftId = shifts.first['id'];
                    }
                  });
                  ApiService.getSeatsWithStatus(shiftId: selectedShiftId, studentId: studentId).then((seatRes) {
                    if (ctx.mounted) {
                      setModalState(() {
                        seatOptions = seatRes['seats'] ?? [];
                        isLoadingSeats = false;
                        final currentSeat = seatOptions.firstWhere((st) => st['status'] == 'CURRENT', orElse: () => null);
                        if (currentSeat != null) {
                          selectedSeatId = currentSeat['id'];
                        }
                      });
                    }
                  });
                }
              });
            }

            void fetchSeatsForShift(int newShiftId) {
              setModalState(() {
                selectedShiftId = newShiftId;
                isLoadingSeats = true;
                selectedSeatId = null;
              });
              ApiService.getSeatsWithStatus(shiftId: newShiftId, studentId: studentId).then((seatRes) {
                if (ctx.mounted) {
                  setModalState(() {
                    seatOptions = seatRes['seats'] ?? [];
                    isLoadingSeats = false;
                    final currentSeat = seatOptions.firstWhere((st) => st['status'] == 'CURRENT', orElse: () => null);
                    selectedSeatId = currentSeat != null ? currentSeat['id'] : (seatOptions.isNotEmpty ? seatOptions.first['id'] : null);
                  });
                }
              });
            }

            Map<String, dynamic>? selectedSeatObj;
            if (selectedSeatId != null) {
              selectedSeatObj = seatOptions.firstWhere((st) => st['id'] == selectedSeatId, orElse: () => null);
            }
            final newSeatNo = selectedSeatObj != null ? selectedSeatObj['seat_number'].toString() : null;

            return Padding(
              padding: EdgeInsets.only(
                top: 20,
                left: 20,
                right: 20,
                bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
              ),
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Row(
                          children: [
                            const Icon(Icons.event_seat_rounded, color: AppColors.primaryIndigo),
                            const SizedBox(width: 8),
                            Text('Edit Seat: $studentName', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                          ],
                        ),
                        IconButton(icon: const Icon(Icons.close), onPressed: () => Navigator.pop(ctx)),
                      ],
                    ),
                    const Divider(),
                    const SizedBox(height: 8),

                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: AppColors.primaryIndigo.withOpacity(0.08),
                        borderRadius: BorderRadius.circular(10),
                        border: Border.all(color: AppColors.primaryIndigo.withOpacity(0.2)),
                      ),
                      child: Row(
                        children: [
                          const Icon(Icons.info_outline_rounded, color: AppColors.primaryIndigo, size: 20),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              'Current Seat Desk: Desk $currentSeatNo',
                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppColors.primaryIndigo),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),

                    const Text('Select Shift', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                    const SizedBox(height: 6),
                    shifts.isEmpty
                        ? const SizedBox(height: 36, child: Center(child: CircularProgressIndicator(strokeWidth: 2)))
                        : DropdownButtonFormField<int>(
                            value: selectedShiftId,
                            decoration: const InputDecoration(
                              border: OutlineInputBorder(),
                              contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                              prefixIcon: Icon(Icons.schedule_rounded),
                            ),
                            items: shifts.map<DropdownMenuItem<int>>((sh) {
                              return DropdownMenuItem<int>(
                                value: sh['id'],
                                child: Text('${sh['name']} (${sh['start_time']} - ${sh['end_time']})'),
                              );
                            }).toList(),
                            onChanged: isSubmitting
                                ? null
                                : (val) {
                                    if (val != null && val != selectedShiftId) {
                                      fetchSeatsForShift(val);
                                    }
                                  },
                          ),
                    const SizedBox(height: 16),

                    const Text('Select Available Seat Desk', style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
                    const SizedBox(height: 6),
                    isLoadingSeats
                        ? const Padding(padding: EdgeInsets.all(16), child: Center(child: CircularProgressIndicator(color: AppColors.primaryIndigo)))
                        : DropdownButtonFormField<int>(
                            value: selectedSeatId,
                            decoration: const InputDecoration(
                              border: OutlineInputBorder(),
                              contentPadding: EdgeInsets.symmetric(horizontal: 12, vertical: 10),
                              prefixIcon: Icon(Icons.event_seat_rounded),
                            ),
                            items: seatOptions.map<DropdownMenuItem<int>>((st) {
                              final isCurrent = st['status'] == 'CURRENT';
                              final isOccupied = st['status'] == 'OCCUPIED';
                              final label = isCurrent
                                  ? 'Desk ${st['seat_number']} — CURRENT'
                                  : (isOccupied ? 'Desk ${st['seat_number']} — OCCUPIED (${st['occupant_name'] ?? 'Student'})' : 'Desk ${st['seat_number']} — AVAILABLE');
                              return DropdownMenuItem<int>(
                                value: st['id'],
                                enabled: !isOccupied,
                                child: Text(
                                  label,
                                  style: TextStyle(
                                    color: isOccupied ? Colors.grey : (isCurrent ? AppColors.primaryIndigo : Colors.black87),
                                    fontWeight: isCurrent ? FontWeight.bold : FontWeight.normal,
                                  ),
                                ),
                              );
                            }).toList(),
                            onChanged: isSubmitting
                                ? null
                                : (val) {
                                    if (val != null) {
                                      setModalState(() => selectedSeatId = val);
                                    }
                                  },
                          ),
                    const SizedBox(height: 20),

                    ElevatedButton.icon(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppColors.primaryIndigo,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      ),
                      onPressed: isSubmitting || selectedSeatId == null
                          ? null
                          : () async {
                              if (newSeatNo == null || (newSeatNo == currentSeatNo && selectedShiftId == currentShiftId)) {
                                ScaffoldMessenger.of(context).showSnackBar(
                                  const SnackBar(content: Text('Please select a different available seat desk to reassign.')),
                                );
                                return;
                              }

                              final confirm = await showDialog<bool>(
                                context: ctx,
                                builder: (dialogCtx) => AlertDialog(
                                  title: const Row(
                                    children: [
                                      Icon(Icons.help_outline_rounded, color: AppColors.primaryIndigo),
                                      SizedBox(width: 8),
                                      Text('Confirm Seat Change'),
                                    ],
                                  ),
                                  content: Text('Change seat from $currentSeatNo to $newSeatNo?'),
                                  actions: [
                                    TextButton(onPressed: () => Navigator.pop(dialogCtx, false), child: const Text('CANCEL')),
                                    ElevatedButton(
                                      style: ElevatedButton.styleFrom(backgroundColor: AppColors.primaryIndigo),
                                      onPressed: () => Navigator.pop(dialogCtx, true),
                                      child: const Text('CONFIRM', style: TextStyle(color: Colors.white)),
                                    ),
                                  ],
                                ),
                              );

                              if (confirm != true) return;

                              setModalState(() => isSubmitting = true);

                              final res = await ApiService.allotSeat(studentId, selectedSeatId!, selectedShiftId);

                              if (ctx.mounted) Navigator.pop(ctx);

                              if (mounted) {
                                if (res['success'] == true) {
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    SnackBar(
                                      content: Text(res['message'] ?? 'Seat changed successfully!'),
                                      backgroundColor: Colors.green,
                                    ),
                                  );
                                  _fetchActiveStudents();
                                } else {
                                  ScaffoldMessenger.of(context).showSnackBar(
                                    SnackBar(
                                      content: Text(res['message'] ?? 'Failed to change seat.'),
                                      backgroundColor: Colors.redAccent,
                                    ),
                                  );
                                }
                              }
                            },
                      icon: isSubmitting
                          ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2))
                          : const Icon(Icons.check_circle_rounded, color: Colors.white),
                      label: Text(
                        isSubmitting ? 'SAVING...' : 'CONFIRM & CHANGE SEAT',
                        style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  void _showEditStudentModal(Map<String, dynamic> s) async {
    final int studentId = s['id'] is int ? s['id'] : int.tryParse(s['id'].toString()) ?? 0;
    final String initialStudentName = s['name'] ?? 'Student';
    final nameController = TextEditingController(text: s['name'] ?? '');
    final phoneController = TextEditingController(text: s['phone'] ?? '');
    final emailController = TextEditingController(text: s['email'] ?? '');
    final fatherController = TextEditingController(text: s['father_name'] ?? '');
    final addressController = TextEditingController(text: s['address'] ?? '');
    final emergencyController = TextEditingController(text: s['emergency_contact'] ?? '');

    final existingPrep = (s['preparation_for'] ?? '').toString().trim();
    final List<String> standardOptions = ['RAS', 'REET', 'SSC', 'UPSC', 'CET', 'Banking', 'Railway', 'NEET', 'JEE'];
    
    String selectedPrepOpt;
    final customPrepController = TextEditingController();

    if (existingPrep.isEmpty) {
      selectedPrepOpt = 'RAS';
    } else if (standardOptions.contains(existingPrep)) {
      selectedPrepOpt = existingPrep;
    } else {
      selectedPrepOpt = 'Other';
      customPrepController.text = existingPrep;
    }

    final initialSeatNo = s['seat_number'] != null ? s['seat_number'].toString() : 'Unassigned';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        List<dynamic> shifts = [];
        List<dynamic> seatOptions = [];
        bool isLoadingSeats = true;
        int selectedShiftId = 1;
        int? selectedSeatId;

        return StatefulBuilder(
          builder: (context, setModalState) {
            // Load shifts and seats on first render of modal
            if (shifts.isEmpty && isLoadingSeats) {
              ApiService.getShifts().then((fetchedShifts) {
                if (ctx.mounted) {
                  setModalState(() {
                    shifts = fetchedShifts;
                    if (shifts.isNotEmpty) {
                      selectedShiftId = shifts.first['id'];
                    }
                  });
                  // Load seats for current student & selected shift
                  ApiService.getSeatsWithStatus(shiftId: selectedShiftId, studentId: studentId).then((seatRes) {
                    if (ctx.mounted) {
                      setModalState(() {
                        seatOptions = seatRes['seats'] ?? [];
                        isLoadingSeats = false;
                        // Pre-select student's current seat
                        final currentSeat = seatOptions.firstWhere((st) => st['status'] == 'CURRENT', orElse: () => null);
                        if (currentSeat != null) {
                          selectedSeatId = currentSeat['id'];
                        }
                      });
                    }
                  });
                }
              });
            }

            void fetchSeatsForSelectedShift(int newShiftId) {
              setModalState(() {
                selectedShiftId = newShiftId;
                isLoadingSeats = true;
              });
              ApiService.getSeatsWithStatus(shiftId: newShiftId, studentId: studentId).then((seatRes) {
                if (ctx.mounted) {
                  setModalState(() {
                    seatOptions = seatRes['seats'] ?? [];
                    isLoadingSeats = false;
                    final currentSeat = seatOptions.firstWhere((st) => st['status'] == 'CURRENT', orElse: () => null);
                    selectedSeatId = currentSeat != null ? currentSeat['id'] : (seatOptions.isNotEmpty ? seatOptions.first['id'] : null);
                  });
                }
              });
            }

            return Padding(
              padding: EdgeInsets.only(
                top: 20,
                left: 20,
                right: 20,
                bottom: MediaQuery.of(ctx).viewInsets.bottom + 20,
              ),
              child: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text('Edit Student: $initialStudentName', style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
                        IconButton(icon: const Icon(Icons.close), onPressed: () => Navigator.pop(ctx)),
                      ],
                    ),
                    const Divider(),
                    TextField(controller: nameController, decoration: const InputDecoration(labelText: 'Full Name *', border: OutlineInputBorder())),
                    const SizedBox(height: 10),
                    TextField(controller: phoneController, decoration: const InputDecoration(labelText: 'Phone Number *', border: OutlineInputBorder())),
                    const SizedBox(height: 10),
                    TextField(controller: emailController, decoration: const InputDecoration(labelText: 'Email Address *', border: OutlineInputBorder())),
                    const SizedBox(height: 10),
                    TextField(controller: fatherController, decoration: const InputDecoration(labelText: 'Father Name', border: OutlineInputBorder())),
                    const SizedBox(height: 10),
                    TextField(controller: addressController, decoration: const InputDecoration(labelText: 'Home Address', border: OutlineInputBorder())),
                    const SizedBox(height: 10),
                    TextField(controller: emergencyController, decoration: const InputDecoration(labelText: 'Emergency Contact', border: OutlineInputBorder())),
                    const SizedBox(height: 10),
                    DropdownButtonFormField<String>(
                      value: selectedPrepOpt,
                      decoration: const InputDecoration(labelText: 'What are you preparing for?', border: OutlineInputBorder()),
                      items: [...standardOptions, 'Other'].map((opt) => DropdownMenuItem(value: opt, child: Text(opt))).toList(),
                      onChanged: (val) {
                        if (val != null) setModalState(() => selectedPrepOpt = val);
                      },
                    ),
                    if (selectedPrepOpt == 'Other') ...[
                      const SizedBox(height: 10),
                      TextField(
                        controller: customPrepController,
                        decoration: const InputDecoration(labelText: 'Please specify course/exam', border: OutlineInputBorder()),
                      ),
                    ],
                    const SizedBox(height: 14),
                    const Text('Assigned Seat & Shift Settings', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppColors.primaryIndigo)),
                    const SizedBox(height: 8),

                    // Shift Dropdown
                    shifts.isEmpty
                        ? const SizedBox(height: 20, child: Center(child: CircularProgressIndicator()))
                        : DropdownButtonFormField<int>(
                            value: selectedShiftId,
                            decoration: const InputDecoration(labelText: 'Assigned Shift', border: OutlineInputBorder(), prefixIcon: Icon(Icons.schedule_rounded)),
                            items: shifts.map<DropdownMenuItem<int>>((sh) {
                              return DropdownMenuItem<int>(
                                value: sh['id'],
                                child: Text('${sh['name']} (${sh['start_time']} - ${sh['end_time']})'),
                              );
                            }).toList(),
                            onChanged: (val) {
                              if (val != null && val != selectedShiftId) {
                                fetchSeatsForSelectedShift(val);
                              }
                            },
                          ),
                    const SizedBox(height: 10),

                    // Seat Dropdown
                    isLoadingSeats
                        ? const Padding(padding: EdgeInsets.all(12), child: Center(child: CircularProgressIndicator()))
                        : DropdownButtonFormField<int>(
                            value: selectedSeatId,
                            decoration: const InputDecoration(labelText: 'Assigned Seat Desk', border: OutlineInputBorder(), prefixIcon: Icon(Icons.event_seat_rounded)),
                            items: seatOptions.map<DropdownMenuItem<int>>((st) {
                              final isCurrent = st['status'] == 'CURRENT';
                              final isOccupied = st['status'] == 'OCCUPIED';
                              final label = isCurrent
                                  ? 'Desk ${st['seat_number']} — CURRENT'
                                  : (isOccupied ? 'Desk ${st['seat_number']} — OCCUPIED (${st['occupant_name'] ?? 'Student'})' : 'Desk ${st['seat_number']} — AVAILABLE');
                              return DropdownMenuItem<int>(
                                value: st['id'],
                                enabled: !isOccupied,
                                child: Text(
                                  label,
                                  style: TextStyle(
                                    color: isOccupied ? Colors.grey : (isCurrent ? AppColors.primaryIndigo : Colors.black87),
                                    fontWeight: isCurrent ? FontWeight.bold : FontWeight.normal,
                                  ),
                                ),
                              );
                            }).toList(),
                            onChanged: (val) {
                              if (val != null) {
                                setModalState(() => selectedSeatId = val);
                              }
                            },
                          ),
                    const SizedBox(height: 18),

                    ElevatedButton(
                      style: ElevatedButton.styleFrom(
                        backgroundColor: AppColors.primaryIndigo,
                        padding: const EdgeInsets.symmetric(vertical: 14),
                      ),
                      onPressed: () async {
                        final finalPrep = selectedPrepOpt == 'Other' ? customPrepController.text.trim() : selectedPrepOpt;

                        // Check if seat desk is changing
                        Map<String, dynamic>? selectedSeatObj;
                        if (selectedSeatId != null) {
                          selectedSeatObj = seatOptions.firstWhere((st) => st['id'] == selectedSeatId, orElse: () => null);
                        }
                        final newSeatNo = selectedSeatObj != null ? selectedSeatObj['seat_number'].toString() : null;

                        bool shouldConfirm = false;
                        if (newSeatNo != null && newSeatNo != initialSeatNo && initialSeatNo != 'Unassigned') {
                          shouldConfirm = true;
                        }

                        if (shouldConfirm) {
                          final confirm = await showDialog<bool>(
                            context: ctx,
                            builder: (dialogCtx) => AlertDialog(
                              title: const Row(
                                children: [
                                  Icon(Icons.warning_amber_rounded, color: Colors.orangeAccent),
                                  SizedBox(width: 8),
                                  Text('Confirm Seat Change'),
                                ],
                              ),
                              content: Text('Reassign seat for "$initialStudentName" from Desk $initialSeatNo to Desk $newSeatNo?'),
                              actions: [
                                TextButton(onPressed: () => Navigator.pop(dialogCtx, false), child: const Text('CANCEL')),
                                ElevatedButton(
                                  style: ElevatedButton.styleFrom(backgroundColor: AppColors.primaryIndigo),
                                  onPressed: () => Navigator.pop(dialogCtx, true),
                                  child: const Text('CONFIRM', style: TextStyle(color: Colors.white)),
                                ),
                              ],
                            ),
                          );
                          if (confirm != true) return;
                        }

                        final res = await ApiService.updateStudent(
                          studentId: studentId,
                          name: nameController.text.trim(),
                          phone: phoneController.text.trim(),
                          email: emailController.text.trim(),
                          fatherName: fatherController.text.trim(),
                          address: addressController.text.trim(),
                          emergencyContact: emergencyController.text.trim(),
                          preparationFor: finalPrep,
                          shiftId: selectedShiftId,
                          seatId: selectedSeatId,
                        );

                        if (ctx.mounted) Navigator.pop(ctx);
                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(
                              content: Text(res['message'] ?? 'Updated student profile'),
                              backgroundColor: res['success'] == true ? Colors.green : Colors.redAccent,
                            ),
                          );
                          _fetchActiveStudents();
                        }
                      },
                      child: const Text('SAVE CHANGES', style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold)),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  Widget _buildDetailRow(IconData icon, String title, String value) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6.0),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 18, color: AppColors.primaryIndigo),
          const SizedBox(width: 10),
          SizedBox(
            width: 130,
            child: Text(
              title,
              style: TextStyle(
                fontWeight: FontWeight.w600,
                color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF475569),
                fontSize: 13,
              ),
            ),
          ),
          Expanded(
            child: Text(
              value,
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 13,
                color: isDark ? Colors.white : const Color(0xFF0F172A),
              ),
            ),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: CustomAppBar(
        title: 'Student Directory & Recycle Bin',
        bottom: TabBar(
          controller: _tabController,
          labelColor: Colors.white,
          unselectedLabelColor: Colors.white70,
          indicatorColor: Colors.white,
          indicatorWeight: 3,
          tabs: [
            const Tab(text: 'Active Directory 👥'),
            Tab(
              child: Row(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  const Text('Recycle Bin 🗑️'),
                  if (_recycleBinStudents.isNotEmpty) ...[
                    const SizedBox(width: 6),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                      decoration: BoxDecoration(
                        color: Colors.redAccent,
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        '${_recycleBinStudents.length}',
                        style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ],
                ],
              ),
            ),
          ],
        ),
      ),
      body: TabBarView(
        controller: _tabController,
        children: [
          // TAB 1: ACTIVE STUDENTS DIRECTORY
          _buildActiveStudentsTab(),

          // TAB 2: RECYCLE BIN DELETED STUDENTS
          _buildRecycleBinTab(),
        ],
      ),
    );
  }

  Widget _buildActiveStudentsTab() {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Column(
      children: [
        // Search & Filter Header Container
        Container(
          color: isDark ? AppColors.darkCard : Colors.white,
          padding: const EdgeInsets.all(14.0),
          child: Column(
            children: [
              TextField(
                onChanged: (val) {
                  _searchQuery = val;
                  _applyFilters();
                },
                decoration: InputDecoration(
                  hintText: 'Search student name, phone, desk...',
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
                  contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
                  border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
                ),
              ),
              const SizedBox(height: 10),

              SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: [
                    _buildFilterChip('All Students', 'all'),
                    const SizedBox(width: 8),
                    _buildFilterChip('Approved 🟢', 'approved'),
                    const SizedBox(width: 8),
                    _buildFilterChip('Pending 🟡', 'pending'),
                    const SizedBox(width: 8),
                    _buildFilterChip('On Hold 🟠', 'hold'),
                  ],
                ),
              ),
            ],
          ),
        ),

        // Active Student Cards List
        Expanded(
          child: _isLoadingActive
              ? const Center(child: CircularProgressIndicator(color: AppColors.primaryIndigo))
              : _filteredStudents.isEmpty
                  ? Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: const [
                          Icon(Icons.people_outline_rounded, size: 64, color: Colors.grey),
                          SizedBox(height: 12),
                          Text('No active student records found.', style: TextStyle(color: Colors.grey)),
                        ],
                      ),
                    )
                  : RefreshIndicator(
                      onRefresh: _fetchActiveStudents,
                      color: AppColors.primaryIndigo,
                      child: ListView.builder(
                        padding: const EdgeInsets.all(14),
                        itemCount: _filteredStudents.length,
                        itemBuilder: (ctx, idx) {
                          final s = _filteredStudents[idx];
                          final String status = s['status'] ?? 'pending';

                          Color statusBg = AppColors.statusWarningBg;
                          Color statusTextColor = const Color(0xFFB45309);
                          String statusText = 'Pending';

                          if (status == 'approved' || status == 'active') {
                            statusBg = AppColors.statusSuccessBg;
                            statusTextColor = AppColors.statusSuccess;
                            statusText = 'Approved';
                          } else if (status == 'hold') {
                            statusBg = Colors.orange.shade50;
                            statusTextColor = Colors.orange.shade800;
                            statusText = 'On Hold';
                          }

                          return Card(
                            margin: const EdgeInsets.only(bottom: 12),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                            color: isDark ? AppColors.darkCard : Colors.white,
                            child: InkWell(
                              onTap: () => _showStudentDetailsModal(s),
                              borderRadius: BorderRadius.circular(16),
                              child: Padding(
                                padding: const EdgeInsets.all(14.0),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        CircleAvatar(
                                          radius: 22,
                                          backgroundColor: AppColors.primaryIndigo.withOpacity(0.15),
                                          child: Text(
                                            (s['name'] ?? 'S').substring(0, 1).toUpperCase(),
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
                                              Row(
                                                children: [
                                                  Expanded(
                                                    child: Text(
                                                      s['name'] ?? 'No Name',
                                                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                                                      maxLines: 1,
                                                      overflow: TextOverflow.ellipsis,
                                                    ),
                                                  ),
                                                  Container(
                                                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                                                    decoration: BoxDecoration(
                                                      color: statusBg,
                                                      borderRadius: BorderRadius.circular(10),
                                                    ),
                                                    child: Text(
                                                      statusText,
                                                      style: TextStyle(
                                                        color: statusTextColor,
                                                        fontSize: 11,
                                                        fontWeight: FontWeight.bold,
                                                      ),
                                                    ),
                                                  ),
                                                ],
                                              ),
                                              const SizedBox(height: 4),
                                              Text(
                                                '📞 ${s['phone'] ?? 'N/A'} • ${s['email'] ?? ''}',
                                                style: TextStyle(
                                                  fontSize: 12,
                                                  color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B),
                                                ),
                                                maxLines: 1,
                                                overflow: TextOverflow.ellipsis,
                                              ),
                                            ],
                                          ),
                                        ),
                                      ],
                                    ),
                                    const Divider(height: 18),
                                    Row(
                                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                                      children: [
                                        Expanded(
                                          child: Text(
                                            '🪑 Desk: ${s['seat_number'] != null ? 'Desk ' + s['seat_number'].toString() : 'Unassigned'} • ${s['shift_name'] ?? 'Shift N/A'}',
                                            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
                                            maxLines: 1,
                                            overflow: TextOverflow.ellipsis,
                                          ),
                                        ),
                                        Row(
                                          mainAxisSize: MainAxisSize.min,
                                          children: [
                                            IconButton(
                                              constraints: const BoxConstraints(),
                                              padding: const EdgeInsets.symmetric(horizontal: 6),
                                              icon: const Icon(Icons.edit_outlined, color: AppColors.primaryIndigo, size: 20),
                                              onPressed: () => _showEditStudentModal(s),
                                              tooltip: 'Edit Student',
                                            ),
                                            IconButton(
                                              constraints: const BoxConstraints(),
                                              padding: const EdgeInsets.symmetric(horizontal: 6),
                                              icon: const Icon(Icons.info_outline_rounded, color: AppColors.primaryIndigo, size: 20),
                                              onPressed: () => _showStudentDetailsModal(s),
                                              tooltip: 'View Profile',
                                            ),
                                            IconButton(
                                              constraints: const BoxConstraints(),
                                              padding: const EdgeInsets.symmetric(horizontal: 6),
                                              icon: const Icon(Icons.delete_outline_rounded, color: Colors.orangeAccent, size: 20),
                                              onPressed: () => _confirmSoftDeleteStudent(s),
                                              tooltip: 'Move to Recycle Bin',
                                            ),
                                          ],
                                        ),
                                      ],
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
    );
  }

  Widget _buildFilterChip(String label, String value) {
    final isSelected = _selectedStatusFilter == value;
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
          setState(() => _selectedStatusFilter = value);
          _applyFilters();
        }
      },
    );
  }

  Widget _buildRecycleBinTab() {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return _isLoadingRecycleBin
        ? const Center(child: CircularProgressIndicator(color: AppColors.primaryIndigo))
        : _recycleBinStudents.isEmpty
            ? Center(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: const [
                    Icon(Icons.delete_sweep_outlined, size: 64, color: Colors.grey),
                    SizedBox(height: 12),
                    Text('Recycle Bin is currently empty.', style: TextStyle(color: Colors.grey, fontSize: 16)),
                    SizedBox(height: 6),
                    Text('Deleted students will remain here for 60 days.', style: TextStyle(color: Colors.grey, fontSize: 12)),
                  ],
                ),
              )
            : RefreshIndicator(
                onRefresh: _fetchRecycleBinStudents,
                color: AppColors.primaryIndigo,
                child: ListView.builder(
                  padding: const EdgeInsets.all(14),
                  itemCount: _recycleBinStudents.length,
                  itemBuilder: (ctx, idx) {
                    final s = _recycleBinStudents[idx];
                    return Card(
                      margin: const EdgeInsets.only(bottom: 12),
                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                      color: isDark ? AppColors.darkCard : Colors.white,
                      child: Padding(
                        padding: const EdgeInsets.all(14.0),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(
                              children: [
                                CircleAvatar(
                                  backgroundColor: Colors.red.withOpacity(0.15),
                                  child: Text(
                                    (s['name'] ?? 'S').substring(0, 1).toUpperCase(),
                                    style: const TextStyle(color: Colors.redAccent, fontWeight: FontWeight.bold),
                                  ),
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        s['name'] ?? 'No Name',
                                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                                      ),
                                      const SizedBox(height: 2),
                                      Text(
                                        'Deleted: ${s['deleted_at'] ?? 'Recently'} • ${s['phone'] ?? ''}',
                                        style: const TextStyle(fontSize: 12, color: Colors.grey),
                                      ),
                                    ],
                                  ),
                                ),
                              ],
                            ),
                            const Divider(height: 18),
                            Row(
                              children: [
                                Expanded(
                                  child: ElevatedButton.icon(
                                    icon: const Icon(Icons.restore_from_trash_rounded, color: Colors.white, size: 16),
                                    label: const Text('Restore', style: TextStyle(color: Colors.white, fontSize: 12)),
                                    style: ElevatedButton.styleFrom(
                                      backgroundColor: AppColors.statusSuccess,
                                      padding: const EdgeInsets.symmetric(vertical: 8),
                                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                    ),
                                    onPressed: () => _restoreStudent(s),
                                  ),
                                ),
                                const SizedBox(width: 10),
                                Expanded(
                                  child: ElevatedButton.icon(
                                    icon: const Icon(Icons.delete_forever_rounded, color: Colors.white, size: 16),
                                    label: const Text('Erase', style: TextStyle(color: Colors.white, fontSize: 12)),
                                    style: ElevatedButton.styleFrom(
                                      backgroundColor: Colors.red,
                                      padding: const EdgeInsets.symmetric(vertical: 8),
                                      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                                    ),
                                    onPressed: () => _confirmPermanentDeleteStudent(s),
                                  ),
                                ),
                              ],
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
              );
  }
}
