import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../config/api_config.dart';
import '../services/api_service.dart';
import '../widgets/custom_app_bar.dart';

class ManageFeesScreen extends StatefulWidget {
  const ManageFeesScreen({Key? key}) : super(key: key);

  @override
  _ManageFeesScreenState createState() => _ManageFeesScreenState();
}

class _ManageFeesScreenState extends State<ManageFeesScreen> {
  bool _isLoading = true;
  Map<String, dynamic>? _stats;
  List<dynamic> _allPayments = [];
  String _filter = 'all'; // all, paid, pending, overdue

  @override
  void initState() {
    super.initState();
    _loadFeeData();
  }

  void _loadFeeData() async {
    setState(() => _isLoading = true);
    final res = await ApiService.getAdminFeePayments();
    if (mounted) {
      if (res['success'] == true) {
        setState(() {
          _stats = res['stats'];
          _allPayments = res['payments'] ?? [];
          _isLoading = false;
        });
      } else {
        setState(() => _isLoading = false);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(res['message'] ?? 'Failed to load fee data')),
        );
      }
    }
  }

  Future<void> _openPdfUrl(String url) async {
    try {
      const channel = MethodChannel('com.example.flutter_app/notifications');
      await channel.invokeMethod('launchUrl', {'url': url});
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not open PDF: $url')),
        );
      }
    }
  }

  List<dynamic> get _filteredPayments {
    if (_filter == 'all') return _allPayments;
    return _allPayments.where((p) => (p['payment_status'] ?? '') == _filter).toList();
  }

  // Record Fee Payment Modal
  void _openRecordPaymentModal(Map<String, dynamic> item, {bool isAdvance = false}) {
    final double defaultAmount = (item['fee_amount'] as num?)?.toDouble() ?? 600.0;
    final TextEditingController amountController = TextEditingController(text: defaultAmount.toStringAsFixed(0));
    String selectedMode = 'UPI';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return StatefulBuilder(
          builder: (BuildContext context, StateSetter setModalState) {
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
                      Text(
                        isAdvance ? 'Record Advance Fee Collection ⚡' : 'Collect Fee Payment 💳',
                        style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
                      ),
                      IconButton(
                        icon: const Icon(Icons.close_rounded),
                        onPressed: () => Navigator.pop(ctx),
                      ),
                    ],
                  ),
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: AppColors.primaryIndigo.withOpacity(0.06),
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Row(
                      children: [
                        const Icon(Icons.person_rounded, size: 18, color: AppColors.primaryIndigo),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'Student: ${item['student_name']} • Desk ${item['seat_number']} (${item['shift_name'] ?? ''})',
                            style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),

                  TextField(
                    controller: amountController,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    decoration: const InputDecoration(
                      labelText: 'Amount Paid (₹)',
                      prefixIcon: Icon(Icons.currency_rupee_rounded),
                      border: OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 14),

                  const Text('Select Payment Mode:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
                  const SizedBox(height: 8),
                  Row(
                    children: ['UPI', 'Cash', 'PhonePe / GPay'].map((mode) {
                      final bool isSel = selectedMode == mode;
                      return Padding(
                        padding: const EdgeInsets.only(right: 8.0),
                        child: ChoiceChip(
                          label: Text(mode),
                          selected: isSel,
                          selectedColor: AppColors.primaryIndigo,
                          labelStyle: TextStyle(
                            color: isSel ? Colors.white : Colors.black87,
                            fontWeight: isSel ? FontWeight.bold : FontWeight.normal,
                          ),
                          onSelected: (val) {
                            if (val) setModalState(() => selectedMode = mode);
                          },
                        ),
                      );
                    }).toList(),
                  ),
                  const SizedBox(height: 20),

                  SizedBox(
                    width: double.infinity,
                    height: 48,
                    child: ElevatedButton.icon(
                      icon: const Icon(Icons.receipt_long_rounded, color: Colors.white),
                      label: Text(
                        isAdvance ? 'Record Advance Payment' : 'Collect Fee & Send Receipt',
                        style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold, color: Colors.white),
                      ),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: isAdvance ? AppColors.primaryIndigo : AppColors.statusSuccess,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                      ),
                      onPressed: () async {
                        final double amount = double.tryParse(amountController.text.trim()) ?? 0;
                        if (amount <= 0) return;

                        Navigator.pop(ctx);
                        setState(() => _isLoading = true);

                        final res = await ApiService.recordFeePayment(
                          allocationId: item['allocation_id'],
                          userId: item['user_id'],
                          amount: amount,
                          paymentMode: selectedMode,
                          monthYear: item['month_year'],
                        );

                        if (mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(
                              content: Text(res['message'] ?? 'Payment recorded!'),
                              backgroundColor: res['success'] == true ? AppColors.statusSuccess : Colors.red,
                            ),
                          );
                          _loadFeeData();
                        }
                      },
                    ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  // Fee Details Modal (For full 12-month statement history view)
  void _openStudentHistoryModal(Map<String, dynamic> item) {
    final String statementPdfUrl = '${ApiConfig.baseUrl}/receipt_statement.php?user_id=${item['user_id']}';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return FutureBuilder<Map<String, dynamic>>(
          future: ApiService.getStudentFeeHistory(item['user_id']),
          builder: (ctx, snapshot) {
            final List history = snapshot.data?['fee_history'] ?? [];

            return Container(
              padding: const EdgeInsets.all(20.0),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
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
                              item['student_name'] ?? 'Student Name',
                              style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
                            ),
                            Text(
                              'Desk ${item['seat_number']} • ${item['shift_name'] ?? "Shift"}',
                              style: const TextStyle(color: Colors.grey, fontSize: 13),
                            ),
                          ],
                        ),
                      ),
                      IconButton(
                        icon: const Icon(Icons.close_rounded),
                        onPressed: () => Navigator.pop(ctx),
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),

                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton.icon(
                      icon: const Icon(Icons.picture_as_pdf_rounded, size: 16, color: Colors.white),
                      label: const Text(
                        'Download 12-Month Statement (PDF)',
                        style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Colors.white),
                      ),
                      style: ElevatedButton.styleFrom(
                        backgroundColor: Colors.red.shade700,
                        padding: const EdgeInsets.symmetric(vertical: 10),
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                      ),
                      onPressed: () => _openPdfUrl(statementPdfUrl),
                    ),
                  ),
                  const Divider(height: 20),

                  const Text(
                    'Receipt History (Last 12 Months)',
                    style: TextStyle(fontSize: 14, fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 10),

                  snapshot.connectionState == ConnectionState.waiting
                      ? const SizedBox(
                          height: 100,
                          child: Center(child: CircularProgressIndicator(color: AppColors.primaryIndigo)),
                        )
                      : history.isEmpty
                          ? const Padding(
                              padding: EdgeInsets.all(16),
                              child: Text('No past receipts recorded.', style: TextStyle(color: Colors.grey)),
                            )
                          : Flexible(
                              child: ListView.builder(
                                shrinkWrap: true,
                                itemCount: history.length,
                                itemBuilder: (c, i) {
                                  final h = history[i];
                                  final bool hPaid = h['payment_status'] == 'paid';
                                  final String rNo = h['receipt_no'] ?? '';
                                  final String rPdf = '${ApiConfig.baseUrl}/receipt.php?receipt_no=${Uri.encodeComponent(rNo)}';

                                  return Container(
                                    margin: const EdgeInsets.only(bottom: 8),
                                    padding: const EdgeInsets.all(12),
                                    decoration: BoxDecoration(
                                      color: Colors.grey.shade50,
                                      borderRadius: BorderRadius.circular(12),
                                    ),
                                    child: Row(
                                      children: [
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            children: [
                                              Text(
                                                '${h['month_year']} • ₹${(h['amount'] as num).toStringAsFixed(2)}',
                                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                              ),
                                              Text(
                                                hPaid ? 'Paid on: ${h['paid_date']} (${h['payment_mode'] ?? 'UPI'})' : 'Due: ${h['due_date']}',
                                                style: const TextStyle(fontSize: 11, color: Colors.grey),
                                              ),
                                            ],
                                          ),
                                        ),
                                        if (hPaid && rNo.isNotEmpty)
                                          OutlinedButton.icon(
                                            icon: const Icon(Icons.download_rounded, size: 14),
                                            label: const Text('Receipt'),
                                            style: OutlinedButton.styleFrom(
                                              foregroundColor: AppColors.statusSuccess,
                                              side: const BorderSide(color: AppColors.statusSuccess),
                                              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                                              minimumSize: Size.zero,
                                              textStyle: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold),
                                            ),
                                            onPressed: () => _openPdfUrl(rPdf),
                                          ),
                                      ],
                                    ),
                                  );
                                },
                              ),
                            ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  void _open12MonthMasterLedgerModal() {
    final csvUrl = '${ApiConfig.baseUrl}/master_12month_fee_report.php?format=csv';
    final webReportUrl = '${ApiConfig.baseUrl}/master_12month_fee_report.php';

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (ctx) {
        return FutureBuilder<Map<String, dynamic>>(
          future: ApiService.get12MonthMasterFeeReport(),
          builder: (context, snapshot) {
            final isDark = Theme.of(context).brightness == Brightness.dark;
            final data = snapshot.data ?? {};
            final summary = data['summary'] ?? {};
            final List students = data['students'] ?? [];

            return Container(
              height: MediaQuery.of(context).size.height * 0.88,
              decoration: BoxDecoration(
                color: isDark ? AppColors.darkCard : Colors.white,
                borderRadius: const BorderRadius.vertical(top: Radius.circular(24)),
              ),
              padding: const EdgeInsets.all(20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Modal Header
                  Row(
                    children: [
                      Container(
                        padding: const EdgeInsets.all(8),
                        decoration: BoxDecoration(
                          color: AppColors.primaryIndigo.withOpacity(0.1),
                          borderRadius: BorderRadius.circular(10),
                        ),
                        child: const Icon(Icons.bar_chart_rounded, color: AppColors.primaryIndigo, size: 24),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: const [
                            Text(
                              '12-Month Master Fee Ledger',
                              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                            ),
                            Text(
                              'All active & past student fee structures',
                              style: TextStyle(fontSize: 12, color: Colors.grey),
                            ),
                          ],
                        ),
                      ),
                      IconButton(
                        icon: const Icon(Icons.close_rounded),
                        onPressed: () => Navigator.pop(ctx),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),

                  // Quick Action Export Buttons (Excel & PDF)
                  Row(
                    children: [
                      Expanded(
                        child: ElevatedButton.icon(
                          icon: const Icon(Icons.table_chart_rounded, size: 16, color: Colors.white),
                          label: const Text('Export Excel / CSV', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: Colors.white)),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF10B981),
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                          ),
                          onPressed: () => _openPdfUrl(csvUrl),
                        ),
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: ElevatedButton.icon(
                          icon: const Icon(Icons.picture_as_pdf_rounded, size: 16, color: Colors.white),
                          label: const Text('All 12M PDF Page', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12, color: Colors.white)),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppColors.primaryIndigo,
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                          ),
                          onPressed: () => _openPdfUrl(webReportUrl),
                        ),
                      ),
                    ],
                  ),
                  const Divider(height: 24),

                  // Summary Badges
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceAround,
                    children: [
                      Column(
                        children: [
                          Text('${summary['total_students'] ?? 0}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: AppColors.primaryIndigo)),
                          const Text('Enrolled Students', style: TextStyle(fontSize: 11, color: Colors.grey)),
                        ],
                      ),
                      Column(
                        children: [
                          Text('₹${(summary['total_collected'] as num?)?.toStringAsFixed(2) ?? "0.00"}', style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: AppColors.statusSuccess)),
                          const Text('Total Fee Collected', style: TextStyle(fontSize: 11, color: Colors.grey)),
                        ],
                      ),
                    ],
                  ),
                  const SizedBox(height: 14),

                  // Student List Breakdown
                  Expanded(
                    child: snapshot.connectionState == ConnectionState.waiting
                        ? const Center(child: CircularProgressIndicator(color: AppColors.primaryIndigo))
                        : students.isEmpty
                            ? const Center(child: Text('No student records found.', style: TextStyle(color: Colors.grey)))
                            : ListView.builder(
                                itemCount: students.length,
                                itemBuilder: (c, i) {
                                  final s = students[i];
                                  final bool isLeft = s['status'] == 'Left / Deleted';

                                  return Container(
                                    margin: const EdgeInsets.only(bottom: 10),
                                    padding: const EdgeInsets.all(12),
                                    decoration: BoxDecoration(
                                      color: isDark ? const Color(0xFF1E293B) : const Color(0xFFF8FAFC),
                                      borderRadius: BorderRadius.circular(12),
                                      border: Border.all(color: isDark ? const Color(0xFF334155) : const Color(0xFFE2E8F0)),
                                    ),
                                    child: Row(
                                      children: [
                                        Expanded(
                                          child: Column(
                                            crossAxisAlignment: CrossAxisAlignment.start,
                                            children: [
                                              Row(
                                                children: [
                                                  Text(
                                                    s['name'] ?? 'Student',
                                                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                                  ),
                                                  const SizedBox(width: 8),
                                                  Container(
                                                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                                    decoration: BoxDecoration(
                                                      color: isLeft ? AppColors.statusDangerBg : AppColors.statusSuccessBg,
                                                      borderRadius: BorderRadius.circular(6),
                                                    ),
                                                    child: Text(
                                                      s['status'] ?? 'Active',
                                                      style: TextStyle(
                                                        fontSize: 10,
                                                        fontWeight: FontWeight.bold,
                                                        color: isLeft ? AppColors.statusDanger : AppColors.statusSuccess,
                                                      ),
                                                    ),
                                                  ),
                                                ],
                                              ),
                                              const SizedBox(height: 2),
                                              Text(
                                                'Desk ${s['seat_number']} • ${s['shift_name']} • ${s['phone']}',
                                                style: const TextStyle(fontSize: 11, color: Colors.grey),
                                              ),
                                              Text(
                                                'Paid Cycles: ${s['paid_cycles_count']} Months',
                                                style: const TextStyle(fontSize: 11, color: AppColors.primaryIndigo, fontWeight: FontWeight.w600),
                                              ),
                                            ],
                                          ),
                                        ),
                                        Column(
                                          crossAxisAlignment: CrossAxisAlignment.end,
                                          children: [
                                            Text(
                                              '₹${(s['total_paid'] as num?)?.toStringAsFixed(2) ?? "0.00"}',
                                              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14, color: AppColors.statusSuccess),
                                            ),
                                            const Text('Total Paid', style: TextStyle(fontSize: 10, color: Colors.grey)),
                                          ],
                                        ),
                                      ],
                                    ),
                                  );
                                },
                              ),
                  ),
                ],
              ),
            );
          },
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    // isDark unused
    final double totalCollected = (_stats?['total_collected'] as num?)?.toDouble() ?? 1900.0;
    final int pendingCount = _stats?['pending_count'] ?? 0;
    final int overdueCount = _stats?['overdue_count'] ?? 2;

    return Scaffold(
      appBar: const CustomAppBar(title: 'Fee Management'),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator(color: AppColors.primaryIndigo))
          : RefreshIndicator(
              onRefresh: () async => _loadFeeData(),
              color: AppColors.primaryIndigo,
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(16.0),
                physics: const AlwaysScrollableScrollPhysics(),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    // Top Summary Card (Exact match to media_1789107953922.jpg!)
                    Container(
                      padding: const EdgeInsets.all(18),
                      decoration: BoxDecoration(
                        color: AppColors.primaryIndigo,
                        borderRadius: BorderRadius.circular(20),
                        boxShadow: [
                          BoxShadow(
                            color: AppColors.primaryIndigo.withOpacity(0.3),
                            blurRadius: 10,
                            offset: const Offset(0, 5),
                          ),
                        ],
                      ),
                      child: Column(
                        children: [
                          const Text(
                            'Monthly Fee Collection Summary',
                            style: TextStyle(color: Color(0xFFE0E7FF), fontSize: 13, fontWeight: FontWeight.w500),
                          ),
                          const SizedBox(height: 6),
                          Text(
                            '₹${totalCollected.toStringAsFixed(2)}',
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 28,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                          const SizedBox(height: 16),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceAround,
                            children: [
                              _buildStatSubItem(
                                label: 'Pending',
                                value: '$pendingCount',
                                color: const Color(0xFFFDE68A),
                              ),
                              Container(width: 1, height: 25, color: Colors.white.withOpacity(0.3)),
                              _buildStatSubItem(
                                label: 'Overdue',
                                value: '$overdueCount',
                                color: const Color(0xFFFECDD3),
                              ),
                              Container(width: 1, height: 25, color: Colors.white.withOpacity(0.3)),
                              _buildStatSubItem(
                                label: 'Total Active',
                                value: '${_stats?['total_active'] ?? 5}',
                                color: Colors.white,
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 14),

                    // 12-Month Master Fee Report & Excel/CSV Export Button
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton.icon(
                        icon: const Icon(Icons.bar_chart_rounded, size: 18, color: Colors.white),
                        label: const Text(
                          '📊 12-Month Master Ledger & Export (Excel/PDF)',
                          style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold, color: Colors.white),
                        ),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xFF10B981),
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(vertical: 12),
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                        ),
                        onPressed: _open12MonthMasterLedgerModal,
                      ),
                    ),
                    const SizedBox(height: 18),

                    // Filter Pills Bar
                    SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: Row(
                        children: [
                          _buildFilterChip('All Students', 'all'),
                          const SizedBox(width: 8),
                          _buildFilterChip('Paid 🟢', 'paid'),
                          const SizedBox(width: 8),
                          _buildFilterChip('Pending 🟡', 'pending'),
                          const SizedBox(width: 8),
                          _buildFilterChip('Overdue 🔴', 'overdue'),
                        ],
                      ),
                    ),
                    const SizedBox(height: 18),

                    // Fee List Header
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          'Fee Records (${_filteredPayments.length})',
                          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                        ),
                        IconButton(
                          icon: const Icon(Icons.refresh_rounded, size: 22, color: AppColors.primaryIndigo),
                          onPressed: _loadFeeData,
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),

                    // Rich Fee Cards (Exact match to media_1789107953922.jpg design!)
                    _filteredPayments.isEmpty
                        ? const Padding(
                            padding: EdgeInsets.symmetric(vertical: 40),
                            child: Center(
                              child: Text(
                                'No fee records found for selected filter.',
                                style: TextStyle(color: Colors.grey),
                              ),
                            ),
                          )
                        : ListView.builder(
                            shrinkWrap: true,
                            physics: const NeverScrollableScrollPhysics(),
                            itemCount: _filteredPayments.length,
                            itemBuilder: (ctx, idx) {
                              final item = _filteredPayments[idx];
                              return _buildRichFeeCard(item);
                            },
                          ),

                    const SizedBox(height: 20),
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

  Widget _buildStatSubItem({required String label, required String value, required Color color}) {
    return Column(
      children: [
        Text(value, style: TextStyle(color: color, fontWeight: FontWeight.bold, fontSize: 17)),
        const SizedBox(height: 2),
        Text(label, style: const TextStyle(color: Color(0xFFE0E7FF), fontSize: 11)),
      ],
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

  // Rich Fee Card matching media_1789107953922.jpg layout exactly!
  Widget _buildRichFeeCard(Map<String, dynamic> item) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final status = item['payment_status'] ?? 'pending';
    final bool isPaid = status == 'paid';
    final bool isOverdue = status == 'overdue';

    final String studentName = item['student_name'] ?? 'Student';
    final String seatNumber = item['seat_number'] ?? 'N/A';
    final String shiftName = item['shift_name'] ?? 'Morning Shift';
    final double amount = (item['fee_amount'] as num?)?.toDouble() ?? 600.0;
    final String dueDate = item['due_date'] ?? '2026-10-04';
    final String cycle = item['month_year'] ?? 'October 2026';

    // Status Badge Text & Colors (Matching media_1789107953922.jpg!)
    String statusBadgeText = 'Paid (Valid till $dueDate)';
    Color statusBg = AppColors.statusSuccessBg;
    Color statusTextColor = AppColors.statusSuccess;

    if (isOverdue) {
      int days = 0;
      try {
        final dt = DateTime.parse(dueDate);
        final diff = DateTime.now().difference(dt).inDays;
        if (diff > 0) days = diff;
      } catch (_) {}
      statusBadgeText = 'Overdue${days > 0 ? " ($days days)" : ""}';
      statusBg = AppColors.statusDangerBg;
      statusTextColor = AppColors.statusDanger;
    } else if (!isPaid) {
      statusBadgeText = 'Pending';
      statusBg = AppColors.statusWarningBg;
      statusTextColor = const Color(0xFFB45309);
    }

    return Card(
      elevation: 2,
      margin: const EdgeInsets.only(bottom: 14),
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            // Row 1: Student Name & Status Badge (Matching screenshot)
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    studentName,
                    style: const TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusBg,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: statusTextColor.withOpacity(0.5)),
                  ),
                  child: Text(
                    statusBadgeText,
                    style: TextStyle(
                      color: statusTextColor,
                      fontWeight: FontWeight.bold,
                      fontSize: 11,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),

            // Row 2: Desk & Shift (Matching screenshot icon styling)
            Row(
              children: [
                const Icon(Icons.event_seat_rounded, size: 16, color: AppColors.primaryIndigo),
                const SizedBox(width: 4),
                Text(
                  'Desk $seatNumber',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                ),
                const SizedBox(width: 12),
                Icon(Icons.schedule_rounded, size: 16, color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B)),
                const SizedBox(width: 4),
                Expanded(
                  child: Text(
                    shiftName,
                    style: TextStyle(fontSize: 12, color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B)),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ],
            ),
            const Divider(height: 20),

            // Row 3: Monthly Fee, Next Due, Cycle & Direct Action Buttons (Matching screenshot!)
            Row(
              crossAxisAlignment: CrossAxisAlignment.end,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Monthly Fee: ₹${amount.toStringAsFixed(2)}',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        'Next Due: $dueDate',
                        style: TextStyle(
                          fontSize: 11,
                          color: isOverdue ? AppColors.statusDanger : Colors.grey,
                          fontWeight: isOverdue ? FontWeight.bold : FontWeight.normal,
                        ),
                      ),
                      Text(
                        'Cycle: $cycle ${isPaid ? "(Advance)" : ""}',
                        style: const TextStyle(fontSize: 11, color: AppColors.primaryIndigo, fontWeight: FontWeight.w600),
                      ),
                    ],
                  ),
                ),
                Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    // History Button
                    OutlinedButton.icon(
                      icon: const Icon(Icons.history_rounded, size: 14),
                      label: const Text('History'),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: AppColors.primaryIndigo,
                        side: const BorderSide(color: AppColors.primaryIndigo),
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                        minimumSize: Size.zero,
                        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        textStyle: const TextStyle(fontSize: 11, fontWeight: FontWeight.bold),
                      ),
                      onPressed: () => _openStudentHistoryModal(item),
                    ),
                    const SizedBox(width: 8),

                    // Context Button: Collect Fee (Green) if unpaid / Advance Pay (Blue) if paid!
                    if (!isPaid)
                      ElevatedButton.icon(
                        icon: const Icon(Icons.payments_rounded, size: 14, color: Colors.white),
                        label: const Text(
                          'Collect Fee',
                          style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.white),
                        ),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.statusSuccess,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          minimumSize: Size.zero,
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        ),
                        onPressed: () => _openRecordPaymentModal(item, isAdvance: false),
                      )
                    else
                      ElevatedButton.icon(
                        icon: const Icon(Icons.flash_on_rounded, size: 14, color: Colors.white),
                        label: const Text(
                          'Advance Pay',
                          style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.white),
                        ),
                        style: ElevatedButton.styleFrom(
                          backgroundColor: AppColors.primaryIndigo,
                          foregroundColor: Colors.white,
                          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                          minimumSize: Size.zero,
                          tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
                        ),
                        onPressed: () => _openRecordPaymentModal(item, isAdvance: true),
                      ),
                  ],
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
