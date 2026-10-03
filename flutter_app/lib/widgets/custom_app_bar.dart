import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config/api_config.dart';
import '../providers/auth_provider.dart';
import '../providers/theme_provider.dart';
import '../providers/attendance_provider.dart';
import 'branding_logo_widget.dart';

class CustomAppBar extends StatelessWidget implements PreferredSizeWidget {
  final String title;
  final int unreadNotifications;
  final VoidCallback? onNotificationTap;
  final PreferredSizeWidget? bottom;

  const CustomAppBar({
    Key? key,
    required this.title,
    this.unreadNotifications = 0,
    this.onNotificationTap,
    this.bottom,
  }) : super(key: key);

  @override
  Size get preferredSize => Size.fromHeight(kToolbarHeight + (bottom?.preferredSize.height ?? 0.0));

  @override
  Widget build(BuildContext context) {
    final themeProvider = Provider.of<ThemeProvider>(context);
    final authProvider = Provider.of<AuthProvider>(context, listen: false);

    return AppBar(
      title: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          const BrandingLogoWidget(size: 26, fallbackColor: Colors.white24),
          const SizedBox(width: 8),
          Flexible(
            child: Text(
              title,
              style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: Colors.white),
              overflow: TextOverflow.ellipsis,
            ),
          ),
        ],
      ),
      backgroundColor: AppColors.primaryIndigo,
      iconTheme: const IconThemeData(color: Colors.white),
      elevation: 0,
      bottom: bottom,
      actions: [
        // Notification Bell Icon with Badge
        Stack(
          alignment: Alignment.center,
          children: [
            IconButton(
              icon: const Icon(Icons.notifications_none_rounded, color: Colors.white),
              onPressed: onNotificationTap ?? () => Navigator.of(context).pushNamed('/notification_hub'),
            ),
            if (unreadNotifications > 0)
              Positioned(
                right: 8,
                top: 8,
                child: Container(
                  padding: const EdgeInsets.all(4),
                  decoration: const BoxDecoration(
                    color: Colors.redAccent,
                    shape: BoxShape.circle,
                  ),
                  child: Text(
                    unreadNotifications > 9 ? '9+' : '$unreadNotifications',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 10,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ),
          ],
        ),
        // Theme Switcher
        IconButton(
          icon: Icon(
            themeProvider.isDarkMode ? Icons.light_mode_rounded : Icons.dark_mode_rounded,
            color: Colors.white,
          ),
          onPressed: () {
            themeProvider.toggleTheme(!themeProvider.isDarkMode);
          },
        ),
        // Logout Button
        IconButton(
          icon: const Icon(Icons.logout_rounded, color: Colors.white),
          onPressed: () {
            authProvider.logout();
            Provider.of<AttendanceProvider>(context, listen: false).reset();
            Navigator.of(context).pushReplacementNamed('/login');
          },
        ),
      ],
    );
  }
}
