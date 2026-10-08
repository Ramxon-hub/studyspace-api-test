import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:http/http.dart' as http;
import '../config/api_config.dart';

class DbBackupScreen extends StatefulWidget {
  const DbBackupScreen({Key? key}) : super(key: key);

  @override
  State<DbBackupScreen> createState() => _DbBackupScreenState();
}

class _DbBackupScreenState extends State<DbBackupScreen> {
  bool _isLoading = true;
  String? _errorMessage;
  Map<String, dynamic>? _backupData;

  @override
  void initState() {
    super.initState();
    _fetchBackupInfo();
  }

  Future<void> _fetchBackupInfo() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.baseUrl}/api/json_admin_actions.php?action=get_database_backup_info'),
      ).timeout(const Duration(seconds: 10));

      if (response.statusCode == 200) {
        final bodyText = response.body.trim();
        if (bodyText.startsWith('{')) {
          final data = json.decode(bodyText);
          if (data['success'] == true) {
            setState(() {
              _backupData = data;
              _isLoading = false;
            });
          } else {
            setState(() {
              _errorMessage = data['message'] ?? 'Failed to load backup data.';
              _isLoading = false;
            });
          }
        } else {
          setState(() {
            _errorMessage = 'Server response error: ${bodyText.length > 80 ? bodyText.substring(0, 80) : bodyText}';
            _isLoading = false;
          });
        }
      } else {
        setState(() {
          _errorMessage = 'Server error: ${response.statusCode}';
          _isLoading = false;
        });
      }
    } catch (e) {
      setState(() {
        _errorMessage = 'Connection error: $e';
        _isLoading = false;
      });
    }
  }

  Future<void> _downloadFile(String downloadUrl, String label) async {
    try {
      const channel = MethodChannel('com.example.flutter_app/notifications');
      await channel.invokeMethod('launchUrl', {'url': downloadUrl});
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Starting $label download...'),
            backgroundColor: const Color(0xFF10B981),
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Could not trigger download: $e'),
            backgroundColor: Colors.redAccent,
            behavior: SnackBarBehavior.floating,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Scaffold(
      appBar: AppBar(
        title: const Text(
          'Database Backup & Health 💾',
          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh_rounded),
            onPressed: _fetchBackupInfo,
            tooltip: 'Refresh Backup Info',
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _errorMessage != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24.0),
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        const Icon(Icons.error_outline_rounded, color: Colors.redAccent, size: 48),
                        const SizedBox(height: 12),
                        Text(
                          _errorMessage!,
                          textAlign: TextAlign.center,
                          style: const TextStyle(color: Colors.redAccent, fontSize: 14),
                        ),
                        const SizedBox(height: 16),
                        ElevatedButton.icon(
                          onPressed: _fetchBackupInfo,
                          icon: const Icon(Icons.refresh_rounded),
                          label: const Text('Retry'),
                        ),
                      ],
                    ),
                  ),
                )
              : SingleChildScrollView(
                  padding: const EdgeInsets.all(16.0),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      // 1. Overview Card
                      Card(
                        elevation: 3,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
                        color: isDark ? AppColors.darkCard : Colors.white,
                        child: Padding(
                          padding: const EdgeInsets.all(18.0),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                children: [
                                  Container(
                                    padding: const EdgeInsets.all(10),
                                    decoration: BoxDecoration(
                                      color: const Color(0xFF10B981).withOpacity(0.15),
                                      borderRadius: BorderRadius.circular(12),
                                    ),
                                    child: const Icon(Icons.storage_rounded, color: Color(0xFF10B981), size: 24),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        const Text(
                                          'SQLite Database File',
                                          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                                        ),
                                        Text(
                                          _backupData?['filename'] ?? 'tenant_database.sqlite',
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
                                  Container(
                                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                                    decoration: BoxDecoration(
                                      color: const Color(0xFF3B82F6).withOpacity(0.15),
                                      borderRadius: BorderRadius.circular(20),
                                    ),
                                    child: Text(
                                      _backupData?['db_size_formatted'] ?? '0 KB',
                                      style: const TextStyle(
                                        color: Color(0xFF3B82F6),
                                        fontWeight: FontWeight.bold,
                                        fontSize: 12,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                              const SizedBox(height: 16),
                              const Divider(height: 1),
                              const SizedBox(height: 16),

                              // Quick Action Download Buttons
                              SizedBox(
                                width: double.infinity,
                                child: ElevatedButton.icon(
                                  onPressed: () {
                                    final backupUrl = _backupData?['backup_url'] ??
                                        '${ApiConfig.baseUrl}/api/admin_actions.php?action=backup_db';
                                    _downloadFile(backupUrl, 'Database Backup (.sqlite)');
                                  },
                                  icon: const Icon(Icons.download_rounded, color: Colors.white, size: 20),
                                  label: const Text(
                                    'Download Database Backup (.sqlite)',
                                    style: TextStyle(color: Colors.white, fontSize: 14, fontWeight: FontWeight.bold),
                                  ),
                                  style: ElevatedButton.styleFrom(
                                    backgroundColor: const Color(0xFF10B981),
                                    foregroundColor: Colors.white,
                                    padding: const EdgeInsets.symmetric(vertical: 14),
                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                    elevation: 3,
                                  ),
                                ),
                              ),
                              const SizedBox(height: 10),
                              SizedBox(
                                width: double.infinity,
                                child: OutlinedButton.icon(
                                  onPressed: () {
                                    final csvUrl = '${ApiConfig.baseUrl}/api/admin_actions.php?action=export_csv';
                                    _downloadFile(csvUrl, 'Excel (CSV) Student Ledger');
                                  },
                                  icon: const Icon(Icons.file_present_rounded, size: 18),
                                  label: const Text('Export Excel Master Ledger (.csv)', style: TextStyle(fontSize: 13, fontWeight: FontWeight.bold)),
                                  style: OutlinedButton.styleFrom(
                                    foregroundColor: const Color(0xFF0EA5E9),
                                    side: const BorderSide(color: Color(0xFF0EA5E9), width: 1.5),
                                    padding: const EdgeInsets.symmetric(vertical: 12),
                                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
                                  ),
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),

                      const SizedBox(height: 20),

                      // 2. Table Row Counts Breakdown
                      const Text(
                        'Database Records Breakdown',
                        style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                      const SizedBox(height: 12),

                      if (_backupData?['table_counts'] != null)
                        GridView.count(
                          crossAxisCount: 2,
                          crossAxisSpacing: 10,
                          mainAxisSpacing: 10,
                          childAspectRatio: 2.2,
                          shrinkWrap: true,
                          physics: const NeverScrollableScrollPhysics(),
                          children: [
                            _buildTableTile('Users / Students', _backupData!['table_counts']['users'] ?? 0, Icons.people_alt_rounded, const Color(0xFF3B82F6), isDark),
                            _buildTableTile('Seat Allocations', _backupData!['table_counts']['allocations'] ?? 0, Icons.chair_rounded, const Color(0xFF10B981), isDark),
                            _buildTableTile('Fee Collections', _backupData!['table_counts']['fee_payments'] ?? 0, Icons.payments_rounded, const Color(0xFFEF4444), isDark),
                            _buildTableTile('Attendance Logs', _backupData!['table_counts']['attendance'] ?? 0, Icons.fact_check_rounded, const Color(0xFF8B5CF6), isDark),
                            _buildTableTile('Complaints / Tickets', _backupData!['table_counts']['complaints'] ?? 0, Icons.support_agent_rounded, const Color(0xFFF59E0B), isDark),
                            _buildTableTile('Notifications', _backupData!['table_counts']['notifications'] ?? 0, Icons.notifications_rounded, const Color(0xFF0EA5E9), isDark),
                          ],
                        ),

                      const SizedBox(height: 20),

                      // 3. Information & Backup Integrity Card
                      Card(
                        elevation: 1,
                        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
                        color: isDark ? AppColors.darkCard : Colors.blue.shade50,
                        child: Padding(
                          padding: const EdgeInsets.all(16.0),
                          child: Row(
                            children: [
                              const Icon(Icons.shield_outlined, color: Color(0xFF0EA5E9), size: 28),
                              const SizedBox(width: 12),
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text(
                                      'Zero Data Loss Protection',
                                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13, color: Color(0xFF0EA5E9)),
                                    ),
                                    const SizedBox(height: 2),
                                    Text(
                                      'All student seat allotments, fee receipts, and attendance logs are saved in the active tenant database. Downloading a backup ensures you can restore your data anytime.',
                                      style: TextStyle(
                                        fontSize: 11,
                                        color: isDark ? const Color(0xFFCBD5E1) : Colors.black87,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ],
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
    );
  }

  Widget _buildTableTile(String label, int count, IconData icon, Color color, bool isDark) {
    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: isDark ? AppColors.darkCard : Colors.white,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: color.withOpacity(0.3)),
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(8),
            decoration: BoxDecoration(
              color: color.withOpacity(0.12),
              borderRadius: BorderRadius.circular(8),
            ),
            child: Icon(icon, color: color, size: 18),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(
                  '$count',
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                ),
                Text(
                  label,
                  style: TextStyle(
                    fontSize: 10,
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
    );
  }
}
