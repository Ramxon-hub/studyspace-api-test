import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'config/api_config.dart';
import 'config/tenant_config.dart';
import 'providers/auth_provider.dart';
import 'providers/seat_provider.dart';
import 'providers/attendance_provider.dart';
import 'providers/theme_provider.dart';
import 'providers/branding_provider.dart';

import 'providers/tenant_provider.dart';
import 'screens/library_selector_screen.dart';

import 'screens/login_screen.dart';
import 'screens/register_screen.dart';
import 'screens/student_dashboard_screen.dart';
import 'screens/admin_dashboard_screen.dart';
import 'screens/parent_dashboard_screen.dart';
import 'screens/seat_matrix_screen.dart';
import 'screens/pending_students_screen.dart';
import 'screens/live_attendance_screen.dart';
import 'screens/notification_hub_screen.dart';
import 'screens/manage_seats_screen.dart';

import 'screens/support_tickets_screen.dart';
import 'screens/manage_complaints_screen.dart';
import 'screens/manage_fees_screen.dart';
import 'screens/student_fee_screen.dart';
import 'screens/student_chat_screen.dart';
import 'screens/admin_chat_threads_screen.dart';
import 'screens/manage_students_screen.dart';
import 'screens/manage_shifts_screen.dart';
import 'screens/db_backup_screen.dart';
import 'screens/app_settings_screen.dart';

import 'package:workmanager/workmanager.dart';
import 'services/notification_service.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  // Ensure tenant config is initialized
  TenantConfig.initialize(TenantConfig.current.flavor);
  await NotificationService().init();

  try {
    await Workmanager().initialize(callbackDispatcher, isInDebugMode: false);
    await Workmanager().registerPeriodicTask(
      "libraryNotifCheck",
      "fetchLibraryNotificationsTask",
      frequency: const Duration(minutes: 15),
      existingWorkPolicy: ExistingWorkPolicy.replace,
      constraints: Constraints(
        networkType: NetworkType.connected,
      ),
    );
  } catch (e) {
    print("Workmanager init error: $e");
  }

  runApp(
    MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => ThemeProvider()),
        ChangeNotifierProvider(create: (_) => TenantProvider()),
        ChangeNotifierProvider(create: (_) => AuthProvider()),
        ChangeNotifierProvider(create: (_) => SeatProvider()),
        ChangeNotifierProvider(create: (_) => AttendanceProvider()),
        ChangeNotifierProvider(create: (_) => BrandingProvider()),
      ],
      child: const StudyLibraryApp(),
    ),
  );
}

class StudyLibraryApp extends StatelessWidget {
  const StudyLibraryApp({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    final themeProvider = Provider.of<ThemeProvider>(context);
    final tenantProvider = Provider.of<TenantProvider>(context);
    final authProvider = Provider.of<AuthProvider>(context);

    Widget homeWidget;
    if (!tenantProvider.isLibrarySelected) {
      homeWidget = const LibrarySelectorScreen();
    } else if (authProvider.isLoggedIn) {
      if (authProvider.currentUser?.role == 'admin') {
        homeWidget = const AdminDashboardScreen();
      } else if (authProvider.currentUser?.role == 'parent') {
        homeWidget = ParentDashboardScreen(userData: authProvider.currentUser?.toJson() ?? {});
      } else {
        homeWidget = const StudentDashboardScreen();
      }
    } else {
      homeWidget = const LoginScreen();
    }

    return MaterialApp(
      title: 'Self-Study Library Management System',
      debugShowCheckedModeBanner: false,
      themeMode: themeProvider.themeMode,

      // Light Theme
      theme: ThemeData(
        brightness: Brightness.light,
        primaryColor: AppColors.primaryBlue,
        scaffoldBackgroundColor: AppColors.lightBg,
        cardColor: AppColors.lightCard,
        dialogBackgroundColor: AppColors.lightCard,
        colorScheme: const ColorScheme.light(
          primary: AppColors.primaryIndigo,
          secondary: AppColors.accentCyan,
          surface: AppColors.lightCard,
          onSurface: AppColors.lightText,
        ),
        appBarTheme: const AppBarTheme(
          backgroundColor: AppColors.primaryBlue,
          foregroundColor: Colors.white,
          elevation: 2,
        ),
        cardTheme: CardTheme(
          color: AppColors.lightCard,
          elevation: 3,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
        inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: Colors.white,
          labelStyle: const TextStyle(color: Color(0xFF334155), fontWeight: FontWeight.w600),
          hintStyle: const TextStyle(color: Color(0xFF64748B)),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: AppColors.lightBorder),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: AppColors.lightBorder),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: AppColors.primaryIndigo, width: 2),
          ),
        ),
        dialogTheme: DialogTheme(
          backgroundColor: Colors.white,
          titleTextStyle: const TextStyle(color: Color(0xFF0F172A), fontSize: 18, fontWeight: FontWeight.bold),
          contentTextStyle: const TextStyle(color: Color(0xFF334155), fontSize: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
        bottomSheetTheme: const BottomSheetThemeData(
          backgroundColor: Colors.white,
          modalBackgroundColor: Colors.white,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
        ),
        textTheme: const TextTheme(
          bodyLarge: TextStyle(color: Color(0xFF0F172A)),
          bodyMedium: TextStyle(color: Color(0xFF334155)),
          bodySmall: TextStyle(color: Color(0xFF64748B)),
          titleMedium: TextStyle(color: Color(0xFF0F172A), fontWeight: FontWeight.bold),
        ),
      ),

      // Dark Theme
      darkTheme: ThemeData(
        brightness: Brightness.dark,
        primaryColor: AppColors.primaryIndigo,
        scaffoldBackgroundColor: AppColors.darkBg,
        cardColor: AppColors.darkCard,
        dialogBackgroundColor: AppColors.darkCard,
        colorScheme: const ColorScheme.dark(
          primary: AppColors.primaryIndigo,
          secondary: AppColors.accentCyan,
          surface: AppColors.darkCard,
          onSurface: AppColors.darkText,
        ),
        appBarTheme: const AppBarTheme(
          backgroundColor: AppColors.darkCard,
          foregroundColor: Colors.white,
          elevation: 2,
        ),
        cardTheme: CardTheme(
          color: AppColors.darkCard,
          elevation: 3,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        ),
        inputDecorationTheme: InputDecorationTheme(
          filled: true,
          fillColor: const Color(0xFF1E293B),
          labelStyle: const TextStyle(color: Color(0xFFCBD5E1), fontWeight: FontWeight.w600),
          hintStyle: const TextStyle(color: Color(0xFF94A3B8)),
          border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: AppColors.darkBorder),
          ),
          enabledBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: AppColors.darkBorder),
          ),
          focusedBorder: OutlineInputBorder(
            borderRadius: BorderRadius.circular(10),
            borderSide: const BorderSide(color: AppColors.accentCyan, width: 2),
          ),
        ),
        dialogTheme: DialogTheme(
          backgroundColor: AppColors.darkCard,
          titleTextStyle: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
          contentTextStyle: const TextStyle(color: Color(0xFFCBD5E1), fontSize: 14),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
        ),
        bottomSheetTheme: const BottomSheetThemeData(
          backgroundColor: AppColors.darkCard,
          modalBackgroundColor: AppColors.darkCard,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
        ),
        textTheme: const TextTheme(
          bodyLarge: TextStyle(color: Colors.white),
          bodyMedium: TextStyle(color: Color(0xFFE2E8F0)),
          bodySmall: TextStyle(color: Color(0xFF94A3B8)),
          titleMedium: TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
        ),
      ),

      home: homeWidget,
      routes: {
        '/login': (context) => const LoginScreen(),
        '/register': (context) => const RegisterScreen(),
        '/student_dashboard': (context) => const StudentDashboardScreen(),
        '/admin_dashboard': (context) => const AdminDashboardScreen(),
        '/parent_dashboard': (context) => ParentDashboardScreen(userData: Provider.of<AuthProvider>(context, listen: false).currentUser?.toJson() ?? {}),
        '/seat_matrix': (context) => const SeatMatrixScreen(),
        '/pending_students': (context) => const PendingStudentsScreen(),
        '/live_attendance': (context) => const LiveAttendanceScreen(),
        '/notification_hub': (context) => const NotificationHubScreen(),
        '/notifications': (context) => const NotificationHubScreen(),
        '/manage_seats': (context) => const ManageSeatsScreen(),
        '/support_tickets': (context) => const SupportTicketsScreen(),
        '/manage_complaints': (context) => const ManageComplaintsScreen(),
        '/manage_fees': (context) => const ManageFeesScreen(),
        '/student_fee': (context) => const StudentFeeScreen(),
        '/student_chat': (context) => const StudentChatScreen(),
        '/admin_chat_threads': (context) => const AdminChatThreadsScreen(),
        '/manage_students': (context) => const ManageStudentsScreen(),
        '/manage_shifts': (context) => const ManageShiftsScreen(),
        '/db_backup': (context) => const DbBackupScreen(),
        '/app_settings': (context) => const AppSettingsScreen(),
      },
    );
  }
}
