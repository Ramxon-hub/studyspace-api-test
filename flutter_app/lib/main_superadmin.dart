import 'config/tenant_config.dart';
import 'main.dart' as app;

void main() async {
  TenantConfig.initialize(LibraryFlavor.superadmin);
  app.main();
}
