import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../config/tenant_config.dart';
import '../services/api_service.dart';

class TenantModel {
  final String code;
  final String name;
  final String status;
  final String tagline;
  final String logoUrl;
  final Color primaryColor;
  final String phone;
  final String address;
  final String planName;
  final String? validUntil;

  const TenantModel({
    required this.code,
    required this.name,
    required this.status,
    required this.tagline,
    required this.logoUrl,
    required this.primaryColor,
    required this.phone,
    required this.address,
    required this.planName,
    this.validUntil,
  });

  factory TenantModel.fromJson(Map<String, dynamic> json) {
    Color parsedColor = const Color(0xFF1D4ED8);
    final hexColor = json['primary_color'] ?? '#1D4ED8';
    try {
      final hex = hexColor.toString().replaceAll('#', '');
      if (hex.length == 6) {
        parsedColor = Color(int.parse('FF$hex', radix: 16));
      }
    } catch (_) {}

    return TenantModel(
      code: (json['code'] ?? json['library_code'] ?? 'LIB001').toString().toUpperCase().trim(),
      name: (json['name'] ?? 'StudySpace Library').toString(),
      status: (json['status'] ?? 'active').toString(),
      tagline: (json['tagline'] ?? 'Self Study Hall').toString(),
      logoUrl: (json['logo_url'] ?? '').toString(),
      primaryColor: parsedColor,
      phone: (json['phone'] ?? json['contact_phone'] ?? '').toString(),
      address: (json['address'] ?? '').toString(),
      planName: (json['plan_name'] ?? 'Standard').toString(),
      validUntil: json['valid_until']?.toString(),
    );
  }

  Map<String, dynamic> toJson() => {
    'code': code,
    'name': name,
    'status': status,
    'tagline': tagline,
    'logo_url': logoUrl,
    'primary_color': '#${primaryColor.value.toRadixString(16).substring(2).toUpperCase()}',
    'phone': phone,
    'address': address,
    'plan_name': planName,
    'valid_until': validUntil,
  };
}

class TenantProvider with ChangeNotifier {
  TenantModel? _currentTenant;
  bool _isLoading = false;
  String? _errorMessage;

  TenantModel? get currentTenant => _currentTenant;
  bool get isLibrarySelected => _currentTenant != null;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  String get activeLibraryCode => _currentTenant?.code ?? 'LIB001';
  String get activeLibraryName => _currentTenant?.name ?? 'StudySpace Library';
  String get activeTagline => _currentTenant?.tagline ?? 'Self Study Hall';
  Color get activePrimaryColor => _currentTenant?.primaryColor ?? const Color(0xFF1D4ED8);

  TenantProvider() {
    loadSavedTenant();
  }

  Future<void> loadSavedTenant() async {
    try {
      if (TenantConfig.current.isSingleTenant) {
        _currentTenant = TenantModel(
          code: TenantConfig.current.tenantCode,
          name: TenantConfig.current.libraryName,
          status: 'active',
          tagline: TenantConfig.current.libraryTagline,
          logoUrl: '',
          primaryColor: TenantConfig.current.primaryColor,
          phone: TenantConfig.current.libraryPhone,
          address: TenantConfig.current.libraryAddress,
          planName: 'Standard',
        );
        ApiService.activeLibraryCode = _currentTenant!.code;
        notifyListeners();
        return;
      }

      final prefs = await SharedPreferences.getInstance();
      final savedJsonStr = prefs.getString('saved_tenant_config');
      if (savedJsonStr != null && savedJsonStr.isNotEmpty) {
        final Map<String, dynamic> map = jsonDecode(savedJsonStr);
        _currentTenant = TenantModel.fromJson(map);
        ApiService.activeLibraryCode = _currentTenant!.code;
        notifyListeners();
      }
    } catch (e) {
      print("Error loading saved tenant: $e");
    }
  }

  Future<bool> selectAndValidateTenant(String inputCode) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    // Parse QR payload or raw library code input
    final cleanedCode = parseLibraryCodePayload(inputCode);
    if (cleanedCode.isEmpty) {
      _isLoading = false;
      _errorMessage = "Please enter a valid library code (e.g. LIB001)";
      notifyListeners();
      return false;
    }

    final res = await ApiService.getTenantInfo(cleanedCode);
    _isLoading = false;

    if (res['success'] == true && res['library'] != null) {
      final tenant = TenantModel.fromJson(res['library']);
      
      if (tenant.status == 'suspended') {
        _errorMessage = "Library '${tenant.name}' ($cleanedCode) is currently suspended. Please contact administrator.";
        notifyListeners();
        return false;
      }

      _currentTenant = tenant;
      ApiService.activeLibraryCode = tenant.code;

      // Persist only safe branding and library code
      final prefs = await SharedPreferences.getInstance();
      await prefs.setString('saved_tenant_config', jsonEncode(tenant.toJson()));
      await prefs.setString('selected_library_code', tenant.code);

      notifyListeners();
      return true;
    } else {
      _errorMessage = res['error'] ?? "Unregistered or invalid library code: $cleanedCode";
      notifyListeners();
      return false;
    }
  }

  static String parseLibraryCodePayload(String rawInput) {
    var trimmed = rawInput.trim();
    if (trimmed.startsWith('STUDYSPACE:')) {
      trimmed = trimmed.replaceFirst('STUDYSPACE:', '').trim();
    }
    if (trimmed.contains('code=')) {
      final uri = Uri.tryParse(trimmed);
      if (uri != null && uri.queryParameters.containsKey('code')) {
        trimmed = uri.queryParameters['code']!;
      }
    }
    return trimmed.toUpperCase();
  }

  Future<void> changeLibrary() async {
    _currentTenant = null;
    _errorMessage = null;
    ApiService.activeLibraryCode = 'LIB001';

    final prefs = await SharedPreferences.getInstance();
    await prefs.remove('saved_tenant_config');
    await prefs.remove('selected_library_code');
    await prefs.remove('library_app_user_session.json');

    notifyListeners();
  }
}
