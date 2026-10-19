// This is a basic Flutter widget test.
//
// To perform an interaction with a widget in your test, use the WidgetTester
// utility in the flutter_test package. For example, you can send tap and scroll
// gestures. You can also use WidgetTester to find child widgets in the widget
// tree, read text, and verify that the values of widget properties are correct.

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';

import 'package:flutter_app/main.dart';
import 'package:flutter_app/providers/theme_provider.dart';
import 'package:flutter_app/providers/auth_provider.dart';
import 'package:flutter_app/providers/seat_provider.dart';
import 'package:flutter_app/providers/attendance_provider.dart';
import 'package:flutter_app/config/tenant_config.dart';
import 'package:flutter_app/providers/branding_provider.dart';
import 'package:flutter_app/providers/tenant_provider.dart';

void main() {
  testWidgets('App initializes properly smoke test', (WidgetTester tester) async {
    TenantConfig.initialize();

    // Build our app and trigger a frame.
    await tester.pumpWidget(
      MultiProvider(
        providers: [
          ChangeNotifierProvider(create: (_) => ThemeProvider()),
          ChangeNotifierProvider(create: (_) => TenantProvider()),
          ChangeNotifierProvider(create: (_) => BrandingProvider()),
          ChangeNotifierProvider(create: (_) => AuthProvider()),
          ChangeNotifierProvider(create: (_) => SeatProvider()),
          ChangeNotifierProvider(create: (_) => AttendanceProvider()),
        ],
        child: const StudyLibraryApp(),
      ),
    );

    // Verify MaterialApp widget is found
    expect(find.byType(MaterialApp), findsOneWidget);
  });
}
