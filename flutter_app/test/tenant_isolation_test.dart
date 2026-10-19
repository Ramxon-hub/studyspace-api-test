import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_app/config/tenant_config.dart';
import 'package:flutter_app/services/api_service.dart';

void main() {
  test('TenantConfig and ApiService isolation test for lib001 and lib002', () {
    // 1. Initialize as LIB002
    TenantConfig.initialize(LibraryFlavor.lib002);
    expect(TenantConfig.current.tenantCode, equals('LIB002'));
    expect(TenantConfig.current.flavor, equals(LibraryFlavor.lib002));
    expect(ApiService.activeLibraryCode, equals('LIB002'));
    expect(ApiService.defaultHeaders['X-Library-Code'], equals('LIB002'));

    // 2. Simulate what main.dart does: calling TenantConfig.initialize() without parameters
    TenantConfig.initialize();
    // It MUST NOT reset back to LIB001!
    expect(TenantConfig.current.tenantCode, equals('LIB002'));
    expect(ApiService.activeLibraryCode, equals('LIB002'));
    expect(ApiService.defaultHeaders['X-Library-Code'], equals('LIB002'));

    // 3. Switch explicitly to LIB001
    TenantConfig.initialize(LibraryFlavor.lib001);
    expect(TenantConfig.current.tenantCode, equals('LIB001'));
    expect(TenantConfig.current.flavor, equals(LibraryFlavor.lib001));
    expect(ApiService.activeLibraryCode, equals('LIB001'));
    expect(ApiService.defaultHeaders['X-Library-Code'], equals('LIB001'));

    // 4. Switch explicitly to superadmin
    TenantConfig.initialize(LibraryFlavor.superadmin);
    expect(TenantConfig.current.tenantCode, equals('SUPERADMIN'));
    expect(TenantConfig.current.flavor, equals(LibraryFlavor.superadmin));
    expect(ApiService.activeLibraryCode, equals('SUPERADMIN'));
  });
}
