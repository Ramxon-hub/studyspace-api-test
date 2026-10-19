import 'package:flutter/widgets.dart';
import 'config/tenant_config.dart';
import 'main.dart' as app;

void main() async {
  WidgetsFlutterBinding.ensureInitialized();
  TenantConfig.initialize(LibraryFlavor.lib001);
  app.main();
}
