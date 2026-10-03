import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:flutter_app/config/api_config.dart';

void main() {
  test('Deplexo API Connectivity Test', () async {
    const String targetUrl = ApiConfig.productionUrl;
    print('Testing Deplexo API URL: $targetUrl');

    int httpStatus = 0;
    String contentType = '';
    String responseBody = '';
    bool jsonDecodeSuccess = false;
    bool apiSuccess = false;
    String message = '';
    String phpVersion = '';
    String server = '';
    bool formatExceptionOccurred = false;
    bool htmlChallengeDetected = false;
    bool aesJsDetected = false;

    try {
      final Uri uri = Uri.parse(targetUrl);
      final http.Response response = await http.get(uri).timeout(
        const Duration(seconds: 15),
      );

      httpStatus = response.statusCode;
      contentType = response.headers['content-type'] ?? '';
      responseBody = response.body;

      print('HTTP Status: $httpStatus');
      print('Content-Type: $contentType');
      print('Response snippet: ${responseBody.length > 200 ? responseBody.substring(0, 200) : responseBody}');

      // HTML and Anti-bot inspection
      if (responseBody.toLowerCase().contains('<html') || responseBody.toLowerCase().contains('<doctype')) {
        htmlChallengeDetected = true;
        print('DETECTED: HTML Response Body');
      }

      if (responseBody.contains('/aes.js') || responseBody.contains('aes.js')) {
        aesJsDetected = true;
        print('DETECTED: AES.js JavaScript Challenge');
      }

      // JSON parsing attempt
      try {
        final Map<String, dynamic> jsonData = jsonDecode(responseBody) as Map<String, dynamic>;
        jsonDecodeSuccess = true;
        apiSuccess = jsonData['success'] == true;
        message = jsonData['message']?.toString() ?? '';
        phpVersion = jsonData['php_version']?.toString() ?? '';
        server = jsonData['server']?.toString() ?? '';

        print('JSON Decode: SUCCESS');
        print('API success: $apiSuccess');
        print('Message: $message');
        print('PHP Version: $phpVersion');
        print('Server: $server');
      } on FormatException catch (e) {
        formatExceptionOccurred = true;
        print('FormatException caught during jsonDecode: $e');
      }
    } catch (e) {
      print('Network or Exception during test: $e');
    }

    // Assertions for flutter test runner
    expect(httpStatus, equals(200), reason: 'Expected HTTP status 200');
    expect(contentType.contains('json'), isTrue, reason: 'Expected JSON Content-Type');
    expect(htmlChallengeDetected, isFalse, reason: 'Must not return HTML page');
    expect(aesJsDetected, isFalse, reason: 'Must not contain /aes.js script');
    expect(formatExceptionOccurred, isFalse, reason: 'Must not throw FormatException');
    expect(jsonDecodeSuccess, isTrue, reason: 'JSON decode must succeed');
    expect(apiSuccess, isTrue, reason: 'API response success must be true');
  });
}
