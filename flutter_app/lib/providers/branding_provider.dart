import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import '../services/api_service.dart';

import '../config/tenant_config.dart';

class BrandingProvider with ChangeNotifier {
  String _appName = TenantConfig.current.libraryName;
  String _appLogoUrl = '';
  String _appTagline = TenantConfig.current.libraryTagline;

  String get appName => _appName;
  String get appLogoUrl => _appLogoUrl;
  String get appTagline => _appTagline;

  String get _cachePrefix => 'branding_${TenantConfig.current.tenantCode.toLowerCase()}';

  BrandingProvider() {
    _loadCachedSettings();
    fetchAppSettings();
  }

  Future<void> _loadCachedSettings() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      _appName = prefs.getString('${_cachePrefix}_name') ?? TenantConfig.current.libraryName;
      _appLogoUrl = prefs.getString('${_cachePrefix}_logo_url') ?? '';
      _appTagline = prefs.getString('${_cachePrefix}_tagline') ?? TenantConfig.current.libraryTagline;
      notifyListeners();
    } catch (_) {}
  }

  Future<void> fetchAppSettings() async {
    try {
      final res = await ApiService.getAppSettings();
      if (res['success'] == true && res['settings'] != null) {
        final settings = res['settings'];
        final newName = (settings['app_name'] ?? '').toString().trim();
        final newLogo = (settings['app_logo_url'] ?? '').toString().trim();
        final newTagline = (settings['app_tagline'] ?? '').toString().trim();

        _appName = newName.isNotEmpty ? newName : TenantConfig.current.libraryName;
        _appLogoUrl = newLogo;
        _appTagline = newTagline.isNotEmpty ? newTagline : TenantConfig.current.libraryTagline;

        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('${_cachePrefix}_name', _appName);
        await prefs.setString('${_cachePrefix}_logo_url', _appLogoUrl);
        await prefs.setString('${_cachePrefix}_tagline', _appTagline);

        notifyListeners();
      }
    } catch (_) {}
  }

  void updateLocalBranding({required String name, required String logoUrl, required String tagline}) {
    if (name.isNotEmpty) _appName = name;
    _appLogoUrl = logoUrl;
    if (tagline.isNotEmpty) _appTagline = tagline;
    notifyListeners();
  }
}
