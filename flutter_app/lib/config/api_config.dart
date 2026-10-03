import 'package:flutter/material.dart';
import 'tenant_config.dart';

enum ApiEnvironment {
  deplexoTest,
  snapDeployTest,
  productionInfinityFree,
  cpanelStaging,
}

class ApiConfig {
  // Configurable API Environment (Default: Deplexo Test Host)
  static ApiEnvironment activeEnvironment = ApiEnvironment.deplexoTest;

  static const String deplexoTestUrl = 'https://studyspace-api-test.de.deplexo.com';
  static const String snapDeployTestUrl = 'https://studyspace-api-test-f4e0d.containers.snapdeploy.app';
  static const String productionInfinityFreeUrl = 'https://studyspace.kesug.com';
  static const String cpanelStagingUrl = 'https://studyspacetest.co4.in';

  // Centralized Base URL Switcher
  static String get baseUrl {
    switch (activeEnvironment) {
      case ApiEnvironment.deplexoTest:
        return deplexoTestUrl;
      case ApiEnvironment.snapDeployTest:
        return snapDeployTestUrl;
      case ApiEnvironment.productionInfinityFree:
        return productionInfinityFreeUrl;
      case ApiEnvironment.cpanelStaging:
        return cpanelStagingUrl;
    }
  }

  static String get jsonAuth => '$baseUrl/api/json_auth.php';
  static String get jsonStudent => '$baseUrl/api/json_student_actions.php';
  static String get jsonAdmin => '$baseUrl/api/json_admin_actions.php';
  static String get jsonParent => '$baseUrl/api/json_parent_actions.php';
  static String get jsonTenant => '$baseUrl/api/json_tenant.php';
  static String get jsonSuperAdmin => '$baseUrl/api/json_super_admin.php';
  static String get seatMatrix => '$baseUrl/api/seat_matrix.php';
  static String get health => '$baseUrl/api/health.php';
}

class AppColors {
  static const Color primaryIndigo = Color(0xFF1D4ED8); // Default Royal Blue Primary
  static Color get primaryTenant => TenantConfig.current.primaryColor; // Dynamic Tenant Primary Color
  static const Color primaryBlue = Color(0xFF1E40AF);   // Dark Royal Blue Header
  static const Color accentCyan = Color(0xFF06B6D4);    // Cyan Metric Accent
  static const Color accentViolet = Color(0xFF3B82F6);  // Bright Blue Accent
  
  // Status Colors (Concept B & Demo Chips)
  static const Color statusSuccess = Color(0xFF22C55E); // Mint Emerald Green
  static const Color statusSuccessBg = Color(0xFFDCFCE7);
  static const Color statusWarning = Color(0xFFF59E0B); // Amber Gold
  static const Color statusWarningBg = Color(0xFFFEF3C7);
  static const Color statusDanger = Color(0xFFEF4444);  // Soft Rose Red
  static const Color statusDangerBg = Color(0xFFFEE2E2);
  static const Color statusInfo = Color(0xFF0EA5E9);    // Sky Blue Info
  static const Color statusInfoBg = Color(0xFFE0F2FE);

  // Seat Matrix Specific
  static const Color seatAvailable = Color(0xFF22C55E); 
  static const Color seatOccupied = Color(0xFFEF4444);  
  static const Color seatMyBooked = Color(0xFF1D4ED8);  
  static const Color seatPending = Color(0xFFF59E0B);   

  // Light Mode Colors
  static const Color lightBg = Color(0xFFF8FAFC);
  static const Color lightCard = Color(0xFFFFFFFF);
  static const Color lightSurfaceElevated = Color(0xFFF1F5F9);
  static const Color lightText = Color(0xFF0F172A);
  static const Color lightSubtext = Color(0xFF64748B);
  static const Color lightBorder = Color(0xFFE2E8F0);

  // Dark Mode Colors
  static const Color darkBg = Color(0xFF0F172A);
  static const Color darkCard = Color(0xFF1E293B);
  static const Color darkText = Color(0xFFF8FAFC);
  static const Color darkSubtext = Color(0xFF94A3B8);
  static const Color darkBorder = Color(0xFF334155);
}
