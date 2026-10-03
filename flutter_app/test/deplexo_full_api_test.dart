import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:flutter_app/config/api_config.dart';

void main() {
  group('StudySpace API Production Smoke & Connectivity Test Suite', () {
    const String localUrl = 'http://127.0.0.1:8000';
    const String deplexoUrl = ApiConfig.productionUrl;

    test('1. Local Health Endpoint Test (/api/health.php)', () async {
      final Uri uri = Uri.parse('$localUrl/api/health.php');
      print('Testing Health API: $uri');

      final http.Response response = await http.get(uri).timeout(
        const Duration(seconds: 15),
      );

      print('Health Status: ${response.statusCode}');
      print('Health Content-Type: ${response.headers['content-type']}');
      print('Health Body: ${response.body}');

      expect(response.statusCode, equals(200));
      expect(response.body.contains('/aes.js'), isFalse);
      expect(response.body.toLowerCase().contains('<html'), isFalse);

      final Map<String, dynamic> data = jsonDecode(response.body) as Map<String, dynamic>;
      expect(data['success'], isTrue);
      expect(data['environment'], equals('production'));
      expect(data['database'], equals('connected'));
    });

    test('2. Tenant Resolution Test (/api/json_tenant.php?code=LIB001)', () async {
      final Uri uri = Uri.parse('$localUrl/api/json_tenant.php?code=LIB001');
      print('Testing Tenant API: $uri');

      final http.Response response = await http.get(uri).timeout(
        const Duration(seconds: 15),
      );

      print('Tenant Status: ${response.statusCode}');
      print('Tenant Content-Type: ${response.headers['content-type']}');
      print('Tenant Body: ${response.body}');

      expect(response.statusCode, equals(200));
      expect(response.body.contains('/aes.js'), isFalse);

      final Map<String, dynamic> data = jsonDecode(response.body) as Map<String, dynamic>;
      expect(data['success'], isTrue);
      expect(data['library'], isNotNull);
      expect(data['library']['code'], equals('LIB001'));
      expect(data['library']['name'], equals('Demo Library 1'));
    });

    test('3. Deplexo Host Root Test (/)', () async {
      final Uri uri = Uri.parse('$deplexoUrl/');
      print('Testing Deplexo Root: $uri');

      final http.Response response = await http.get(uri).timeout(
        const Duration(seconds: 15),
      );

      print('Deplexo Root Status: ${response.statusCode}');
      print('Deplexo Root Body: ${response.body}');

      expect(response.statusCode, equals(200));
      expect(response.body.contains('/aes.js'), isFalse);
      expect(response.body.toLowerCase().contains('<html'), isFalse);

      final Map<String, dynamic> data = jsonDecode(response.body) as Map<String, dynamic>;
      expect(data['success'], isTrue);
    });
  });
}
