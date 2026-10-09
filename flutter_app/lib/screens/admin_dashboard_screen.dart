import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config/api_config.dart';
import '../providers/auth_provider.dart';
import '../services/api_service.dart';
import '../widgets/custom_app_bar.dart';

class AdminDashboardScreen extends StatefulWidget {
  const AdminDashboardScreen({Key? key}) : super(key: key);

  @override
  _AdminDashboardScreenState createState() => _AdminDashboardScreenState();
}

class _AdminDashboardScreenState extends State<AdminDashboardScreen> {
  bool _isLoading = true;
  Map<String, dynamic>? _stats;

  @override
  void initState() {
    super.initState();
    _loadStats();
  }

  void _loadStats() async {
    try {
      final res = await ApiService.getAdminStats();
      if (mounted) {
        setState(() {
          _stats = res['stats'];
          _isLoading = false;
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

  @override
  Widget build(BuildContext context) {
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final authProvider = Provider.of<AuthProvider>(context, listen: false);
    final adminUser = authProvider.currentUser;

    return Scaffold(
      appBar: const CustomAppBar(
        title: 'Admin Dashboard',
      ),
      body: _isLoading
          ? const Center(
              child: CircularProgressIndicator(color: AppColors.primaryIndigo),
            )
          : RefreshIndicator(
              onRefresh: () async => _loadStats(),
              color: AppColors.primaryIndigo,
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    // 1. Header Welcome Bar (Matching Screen 3)
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: isDark ? AppColors.darkCard : Colors.white,
                        borderRadius: BorderRadius.circular(16),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withOpacity(0.04),
                            blurRadius: 10,
                            offset: const Offset(0, 4),
                          ),
                        ],
                      ),
                      child: Row(
                        children: [
                          CircleAvatar(
                            radius: 22,
                            backgroundColor: AppColors.primaryIndigo.withOpacity(0.12),
                            child: const Icon(
                              Icons.person_rounded,
                              color: AppColors.primaryIndigo,
                              size: 26,
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                const Text(
                                  'Welcome Back,',
                                  style: TextStyle(
                                    fontSize: 12,
                                    color: Colors.grey,
                                    fontWeight: FontWeight.w500,
                                  ),
                                ),
                                Text(
                                  adminUser?.name ?? 'Admin',
                                  style: const TextStyle(
                                    fontSize: 18,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          Stack(
                            children: [
                              IconButton(
                                icon: const Icon(Icons.notifications_none_rounded, color: AppColors.primaryIndigo),
                                onPressed: () => Navigator.of(context).pushNamed('/notification_hub'),
                              ),
                              if ((_stats?['unread_chats_count'] ?? 0) > 0)
                                Positioned(
                                  right: 6,
                                  top: 6,
                                  child: Container(
                                    padding: const EdgeInsets.all(4),
                                    decoration: const BoxDecoration(
                                      color: Colors.redAccent,
                                      shape: BoxShape.circle,
                                    ),
                                    child: Text(
                                      '${_stats!['unread_chats_count']}',
                                      style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold),
                                    ),
                                  ),
                                ),
                            ],
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),

                    // 2. Stats 2x2 Grid (Matching Screen 3 colors & layout)
                    GridView.count(
                      crossAxisCount: 2,
                      crossAxisSpacing: 12,
                      mainAxisSpacing: 12,
                      childAspectRatio: 1.85,
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      children: [
                        _buildStatCard(
                          title: 'Active Students',
                          value: '${_stats?['total_students'] ?? 0}',
                          icon: Icons.person_outline_rounded,
                          iconBg: const Color(0xFF22C55E),
                        ),
                        _buildStatCard(
                          title: 'Pending Requests',
                          value: '${_stats?['pending_students'] ?? 0}',
                          icon: Icons.assignment_outlined,
                          iconBg: const Color(0xFFF59E0B),
                        ),
                        _buildStatCard(
                          title: 'Total Desks',
                          value: '${_stats?['total_seats'] ?? 0}',
                          icon: Icons.chair_outlined,
                          iconBg: const Color(0xFF0EA5E9),
                        ),
                        _buildStatCard(
                          title: 'Present Inside',
                          value: '${_stats?['currently_inside'] ?? 0}',
                          icon: Icons.check_circle_outline_rounded,
                          iconBg: AppColors.primaryIndigo,
                        ),
                      ],
                    ),
                    const SizedBox(height: 20),

                    // 3. Quick Actions Header (Matching Screen 3)
                    const Text(
                      'Quick Actions',
                      style: TextStyle(fontSize: 17, fontWeight: FontWeight.bold),
                    ),
                    const SizedBox(height: 12),

                    // 6 Quick Action Buttons (Matching Screen 3 icons & layout)
                    GridView.count(
                      crossAxisCount: 3,
                      crossAxisSpacing: 10,
                      mainAxisSpacing: 12,
                      childAspectRatio: 1.05,
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      children: [
                        _buildQuickActionTile(
                          label: 'Seat Matrix',
                          icon: Icons.grid_on_rounded,
                          color: const Color(0xFF3B82F6),
                          onTap: () => Navigator.of(context).pushNamed('/seat_matrix'),
                        ),
                        _buildQuickActionTile(
                          label: 'Pending Allotments',
                          icon: Icons.assignment_ind_rounded,
                          color: const Color(0xFFF59E0B),
                          badgeCount: _stats?['pending_students'] ?? 0,
                          onTap: () => Navigator.of(context).pushNamed('/pending_students'),
                        ),
                        _buildQuickActionTile(
                          label: 'Live Attendance',
                          icon: Icons.fact_check_rounded,
                          color: const Color(0xFF10B981),
                          onTap: () => Navigator.of(context).pushNamed('/live_attendance'),
                        ),
                        _buildQuickActionTile(
                          label: 'Fee Management',
                          icon: Icons.payments_rounded,
                          color: const Color(0xFFEF4444),
                          onTap: () => Navigator.of(context).pushNamed('/manage_fees'),
                        ),
                        _buildQuickActionTile(
                          label: 'Students',
                          icon: Icons.people_alt_rounded,
                          color: const Color(0xFF0EA5E9),
                          onTap: () => Navigator.of(context).pushNamed('/manage_students'),
                        ),
                        _buildQuickActionTile(
                          label: 'Notifications',
                          icon: Icons.notifications_rounded,
                          color: AppColors.primaryIndigo,
                          badgeCount: _stats?['unread_chats_count'] ?? 0,
                          onTap: () => Navigator.of(context).pushNamed('/notification_hub'),
                        ),
                      ],
                    ),
                    const SizedBox(height: 20),

                    // 4. Extended Operations Section
                    const Text(
                      'Management Modules',
                      style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                    ),
                    const SizedBox(height: 10),

                    _buildAdminMenuCard(
                      title: 'Manage Study Desks & 1-Click Range',
                      subtitle: 'Generate ranges (e.g. E-01 to E-10) or delete desks',
                      icon: Icons.auto_awesome_rounded,
                      color: AppColors.primaryIndigo,
                      onTap: () => Navigator.of(context).pushNamed('/manage_seats'),
                    ),
                    const SizedBox(height: 10),

                    _buildAdminMenuCard(
                      title: 'Direct Student Support Chats 💬',
                      subtitle: 'Chat 1-on-1 directly with registered students',
                      icon: Icons.chat_bubble_rounded,
                      color: const Color(0xFF0EA5E9),
                      badgeCount: _stats?['unread_chats_count'] ?? 0,
                      onTap: () async {
                        await Navigator.of(context).pushNamed('/admin_chat_threads');
                        _loadStats();
                      },
                    ),
                    const SizedBox(height: 10),

                    _buildAdminMenuCard(
                      title: 'Manage Shift Timings & Fees ⏰',
                      subtitle: 'Create shifts, set timings (Morning/Evening) & monthly rates',
                      icon: Icons.schedule_rounded,
                      color: const Color(0xFF8B5CF6),
                      onTap: () => Navigator.of(context).pushNamed('/manage_shifts'),
                    ),
                    const SizedBox(height: 10),

                    _buildAdminMenuCard(
                      title: 'Support Tickets Desk 🎧',
                      subtitle: 'Review & resolve student support requests (30-day auto purge)',
                      icon: Icons.support_agent_rounded,
                      color: const Color(0xFFF59E0B),
                      onTap: () => Navigator.of(context).pushNamed('/manage_complaints'),
                    ),
                    const SizedBox(height: 10),

                    _buildAdminMenuCard(
                      title: 'Manage Parents & Student Links 🛡️',
                      subtitle: 'Create parent accounts, link students, reset passwords & view activity',
                      icon: Icons.family_restroom_rounded,
                      color: const Color(0xFF6366F1),
                      onTap: () => Navigator.of(context).pushNamed('/manage_parents'),
                    ),
                    const SizedBox(height: 10),

                    _buildAdminMenuCard(
                      title: 'Database Backup & System Health 💾',
                      subtitle: 'Download SQLite database backup file (.sqlite) directly to mobile',
                      icon: Icons.storage_rounded,
                      color: const Color(0xFF10B981),
                      onTap: () => Navigator.of(context).pushNamed('/db_backup'),
                    ),
                    const SizedBox(height: 10),

                    _buildAdminMenuCard(
                      title: 'App Branding & Name Settings ⚙️',
                      subtitle: 'Customize mobile app name, tagline & custom logo image',
                      icon: Icons.app_settings_alt_rounded,
                      color: const Color(0xFFEC4899),
                      onTap: () => Navigator.of(context).pushNamed('/app_settings'),
                    ),
                    const SizedBox(height: 24),

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

  Widget _buildStatCard({
    required String title,
    required String value,
    required IconData icon,
    required Color iconBg,
  }) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: isDark ? AppColors.darkCard : Colors.white,
        borderRadius: BorderRadius.circular(14),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withOpacity(0.04),
            blurRadius: 8,
            offset: const Offset(0, 2),
          ),
        ],
      ),
      child: Row(
        children: [
          Container(
            padding: const EdgeInsets.all(10),
            decoration: BoxDecoration(
              color: iconBg,
              borderRadius: BorderRadius.circular(10),
            ),
            child: Icon(icon, color: Colors.white, size: 20),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text(
                  value,
                  style: const TextStyle(
                    fontSize: 20,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                Text(
                  title,
                  style: TextStyle(
                    fontSize: 11,
                    color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B),
                    fontWeight: FontWeight.w500,
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

  Widget _buildQuickActionTile({
    required String label,
    required IconData icon,
    required Color color,
    int badgeCount = 0,
    required VoidCallback onTap,
  }) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: Container(
        padding: const EdgeInsets.all(10),
        decoration: BoxDecoration(
          color: isDark ? AppColors.darkCard : Colors.white,
          borderRadius: BorderRadius.circular(16),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withOpacity(0.03),
              blurRadius: 6,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Stack(
              children: [
                Container(
                  padding: const EdgeInsets.all(10),
                  decoration: BoxDecoration(
                    color: color.withOpacity(0.12),
                    shape: BoxShape.circle,
                  ),
                  child: Icon(icon, color: color, size: 24),
                ),
                if (badgeCount > 0)
                  Positioned(
                    right: 0,
                    top: 0,
                    child: Container(
                      padding: const EdgeInsets.all(4),
                      decoration: const BoxDecoration(
                        color: Colors.redAccent,
                        shape: BoxShape.circle,
                      ),
                      child: Text(
                        '$badgeCount',
                        style: const TextStyle(color: Colors.white, fontSize: 9, fontWeight: FontWeight.bold),
                      ),
                    ),
                  ),
              ],
            ),
            const SizedBox(height: 6),
            Text(
              label,
              textAlign: TextAlign.center,
              style: const TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.w600,
              ),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildAdminMenuCard({
    required String title,
    required String subtitle,
    required IconData icon,
    required Color color,
    int badgeCount = 0,
    required VoidCallback onTap,
  }) {
    final isDark = Theme.of(context).brightness == Brightness.dark;

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(14)),
      color: isDark ? AppColors.darkCard : Colors.white,
      child: ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
        leading: Container(
          padding: const EdgeInsets.all(10),
          decoration: BoxDecoration(
            color: color.withOpacity(0.12),
            borderRadius: BorderRadius.circular(12),
          ),
          child: Icon(icon, color: color, size: 24),
        ),
        title: Row(
          children: [
            Expanded(
              child: Text(
                title,
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
              ),
            ),
            if (badgeCount > 0)
              Container(
                margin: const EdgeInsets.only(left: 6),
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: Colors.redAccent,
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(
                  '$badgeCount Unread',
                  style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                ),
              ),
          ],
        ),
        subtitle: Text(
          subtitle,
          style: TextStyle(
            fontSize: 11,
            color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B),
          ),
        ),
        trailing: const Icon(Icons.arrow_forward_ios_rounded, size: 14, color: Colors.grey),
        onTap: onTap,
      ),
    );
  }
}
