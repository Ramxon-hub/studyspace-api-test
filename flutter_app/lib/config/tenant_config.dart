import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

enum LibraryFlavor {
  studyspace,
  lib001,
  lib002,
  superadmin,
}

class TenantConfig {
  final LibraryFlavor flavor;
  final String tenantId;
  final String tenantCode;
  final String libraryName;
  final String libraryShortName;
  final String libraryTagline;
  final String libraryPhone;
  final String libraryEmail;
  final String libraryAddress;
  final double libraryLat;
  final double libraryLng;
  final double geofenceRadiusMeters;
  final Color primaryColor;
  final String footerCredit;
  final IconData libraryIcon;
  final String apiBaseUrl;
  final bool isSingleTenant;
  final bool isSuperAdmin;

  const TenantConfig({
    required this.flavor,
    required this.tenantId,
    required this.tenantCode,
    required this.libraryName,
    required this.libraryShortName,
    required this.libraryTagline,
    required this.libraryPhone,
    required this.libraryEmail,
    required this.libraryAddress,
    required this.libraryLat,
    required this.libraryLng,
    required this.geofenceRadiusMeters,
    required this.primaryColor,
    required this.footerCredit,
    required this.libraryIcon,
    required this.apiBaseUrl,
    this.isSingleTenant = true,
    this.isSuperAdmin = false,
  });

  static TenantConfig _current = studyspaceConfig;

  static TenantConfig get current => _current;

  static void initialize([LibraryFlavor? flavor]) {
    final String? buildFlavor = appFlavor;
    if (flavor == null && buildFlavor != null) {
      if (buildFlavor == 'lib002') {
        flavor = LibraryFlavor.lib002;
      } else if (buildFlavor == 'superadmin') {
        flavor = LibraryFlavor.superadmin;
      } else if (buildFlavor == 'lib001' || buildFlavor == 'studyspace') {
        flavor = LibraryFlavor.lib001;
      }
    }
    
    final targetFlavor = flavor ?? LibraryFlavor.lib001;
    switch (targetFlavor) {
      case LibraryFlavor.studyspace:
      case LibraryFlavor.lib001:
        _current = studyspaceConfig;
        break;
      case LibraryFlavor.lib002:
        _current = lib002Config;
        break;
      case LibraryFlavor.superadmin:
        _current = superadminConfig;
        break;
    }
  }

  // Pre-configured Tenant Settings
  static const TenantConfig studyspaceConfig = TenantConfig(
    flavor: LibraryFlavor.lib001,
    tenantId: 'lib001',
    tenantCode: 'LIB001',
    libraryName: 'Keshav Library & Study Center',
    libraryShortName: 'StudySpace LIB001',
    libraryTagline: 'SELF STUDY HALL',
    libraryPhone: '+91 98765 43210',
    libraryEmail: 'support@studyspace.com',
    libraryAddress: 'Near City Park, Main Road',
    libraryLat: 28.0087395,
    libraryLng: 73.2924508,
    geofenceRadiusMeters: 50.0,
    primaryColor: Color(0xFF1D4ED8),
    footerCredit: 'Ramxonwebwork',
    libraryIcon: Icons.menu_book_rounded,
    apiBaseUrl: 'https://studyspace-api-test.de.deplexo.com',
    isSingleTenant: true,
  );

  static const TenantConfig lib002Config = TenantConfig(
    flavor: LibraryFlavor.lib002,
    tenantId: 'lib002',
    tenantCode: 'LIB002',
    libraryName: 'Demo Library 2',
    libraryShortName: 'StudySpace LIB002',
    libraryTagline: 'PREMIER READING HALL',
    libraryPhone: '+91 98765 00002',
    libraryEmail: 'contact@lib002.com',
    libraryAddress: '456 Knowledge Park, Block B',
    libraryLat: 19.0760,
    libraryLng: 72.8770,
    geofenceRadiusMeters: 50.0,
    primaryColor: Color(0xFF10B981),
    footerCredit: 'Ramxonwebwork',
    libraryIcon: Icons.local_library_rounded,
    apiBaseUrl: 'https://studyspace-api-test.de.deplexo.com',
    isSingleTenant: true,
  );

  static const TenantConfig superadminConfig = TenantConfig(
    flavor: LibraryFlavor.superadmin,
    tenantId: 'superadmin',
    tenantCode: 'SUPERADMIN',
    libraryName: 'StudySpace Platform Control',
    libraryShortName: 'Super Admin',
    libraryTagline: 'SAAS MASTER CONTROL PANEL',
    libraryPhone: '+91 98765 00000',
    libraryEmail: 'superadmin@studyspace.com',
    libraryAddress: 'HQ Control Center',
    libraryLat: 28.6139,
    libraryLng: 77.2090,
    geofenceRadiusMeters: 500.0,
    primaryColor: Color(0xFF7C3AED),
    footerCredit: 'Ramxonwebwork',
    libraryIcon: Icons.admin_panel_settings_rounded,
    apiBaseUrl: 'https://studyspace-api-test.de.deplexo.com',
    isSingleTenant: true,
    isSuperAdmin: true,
  );
}
