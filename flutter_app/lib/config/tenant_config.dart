import 'package:flutter/material.dart';

enum LibraryFlavor {
  studyspace,
  libraryAbc,
  libraryXyz,
}

class TenantConfig {
  final LibraryFlavor flavor;
  final String tenantId;
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

  const TenantConfig({
    required this.flavor,
    required this.tenantId,
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
  });

  static TenantConfig _current = studyspaceConfig;

  static TenantConfig get current => _current;

  static void initialize(LibraryFlavor flavor) {
    switch (flavor) {
      case LibraryFlavor.studyspace:
        _current = studyspaceConfig;
        break;
      case LibraryFlavor.libraryAbc:
        _current = libraryAbcConfig;
        break;
      case LibraryFlavor.libraryXyz:
        _current = libraryXyzConfig;
        break;
    }
  }

  // Pre-configured Tenant Settings
  static const TenantConfig studyspaceConfig = TenantConfig(
    flavor: LibraryFlavor.studyspace,
    tenantId: 'studyspace_keshav',
    libraryName: 'Keshav Library & Study Center',
    libraryShortName: 'StudySpace',
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
    apiBaseUrl: 'https://studyspacetest.co4.in',
  );

  static const TenantConfig libraryAbcConfig = TenantConfig(
    flavor: LibraryFlavor.libraryAbc,
    tenantId: 'library_abc',
    libraryName: 'ABC Competition Study Library',
    libraryShortName: 'ABC Library',
    libraryTagline: 'PREMIER READING HALL',
    libraryPhone: '+91 98000 11111',
    libraryEmail: 'contact@abclibrary.com',
    libraryAddress: '123 Academic Block, Zone 1',
    libraryLat: 19.0760,
    libraryLng: 72.8770,
    geofenceRadiusMeters: 50.0,
    primaryColor: Color(0xFF059669),
    footerCredit: 'Ramxonwebwork',
    libraryIcon: Icons.local_library_rounded,
    apiBaseUrl: 'https://library-abc-service-placeholder.onrender.com',
  );

  static const TenantConfig libraryXyzConfig = TenantConfig(
    flavor: LibraryFlavor.libraryXyz,
    tenantId: 'library_xyz',
    libraryName: 'XYZ Exam Preparation Hall',
    libraryShortName: 'XYZ Library',
    libraryTagline: '24/7 STUDY CENTRE',
    libraryPhone: '+91 97000 22222',
    libraryEmail: 'help@xyzlibrary.com',
    libraryAddress: '456 Knowledge Hub, Sector 4',
    libraryLat: 28.6139,
    libraryLng: 77.2090,
    geofenceRadiusMeters: 50.0,
    primaryColor: Color(0xFF7C3AED),
    footerCredit: 'Ramxonwebwork',
    libraryIcon: Icons.school_rounded,
    apiBaseUrl: 'https://library-xyz-service-placeholder.onrender.com',
  );
}
