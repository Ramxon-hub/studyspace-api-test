import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:flutter_app/config/api_config.dart';

void main() {
  group('Deplexo Full PHP API Test Suite', () {
    final String baseUrl = ApiConfig.deplexoTestUrl;

    test('1. Health Endpoint Test (/api/health.php or /)', () async {
      final Uri uri = Uri.parse('$baseUrl/api/health.php');
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
    });

    test('2. Root Health Endpoint Test (/)', () async {
      final Uri uri = Uri.parse('$baseUrl/');
      print('Testing Root Endpoint: $uri');

      final http.Response response = await http.get(uri).timeout(
        const Duration(seconds: 15),
      );

      print('Root Status: ${response.statusCode}');
      print('Root Content-Type: ${response.headers['content-type']}');
      print('Root Body: ${response.body}');

      expect(response.statusCode, equals(200));
      expect(response.body.contains('/aes.js'), isFalse);
      expect(response.body.toLowerCase().contains('<html'), isFalse);

      final Map<String, dynamic> data = jsonDecode(response.body) as Map<String, dynamic>;
      expect(data['success'], isTrue);
    });

    test('3. Tenant Resolution Test (/api/json_tenant.php?code=LIBTEST)', () async {
      final Uri uri = Uri.parse('$baseUrl/api/json_tenant.php?code=LIBTEST');
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
    });
  });
}
