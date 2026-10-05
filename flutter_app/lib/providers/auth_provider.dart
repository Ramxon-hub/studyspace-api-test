import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import '../models/user_model.dart';
import '../services/api_service.dart';

import '../services/native_notification_service.dart';
import '../services/notification_service.dart';

class AuthProvider extends ChangeNotifier {
  UserModel? _currentUser;
  bool _isLoading = false;
  String? _errorMessage;

  UserModel? get currentUser => _currentUser;
  bool get isLoading => _isLoading;
  bool get isLoggedIn => _currentUser != null;
  String? get errorMessage => _errorMessage;

  AuthProvider() {
    tryAutoLogin();
  }

  File _getSessionFile() {
    final sysTemp = Directory.systemTemp;
    return File('${sysTemp.path}/library_app_user_session.json');
  }

  Future<bool> tryAutoLogin() async {
    try {
      final file = _getSessionFile();
      if (await file.exists()) {
        final saved = await file.readAsString();
        if (saved.isNotEmpty) {
          final Map<String, dynamic> userJson = jsonDecode(saved);
          _currentUser = UserModel.fromJson(userJson);
          if (_currentUser != null) {
            NativeNotificationService.startNativeService(_currentUser!.id);
          }
          notifyListeners();
          return true;
        }
      }
    } catch (e) {
      print("Auto login file read error: $e");
    }
    return false;
  }

  Future<bool> login(String email, String password) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    final result = await ApiService.login(email, password);

    _isLoading = false;
    if (result['success'] == true) {
      _currentUser = UserModel.fromJson(result['user']);
      _saveUserSession(result['user']);
      if (_currentUser != null) {
        NativeNotificationService.startNativeService(_currentUser!.id);
      }
      notifyListeners();
      return true;
    } else {
      _errorMessage = result['message'] ?? 'Login failed';
      notifyListeners();
      return false;
    }
  }

  String? _deviceId;

  Future<String> getDeviceId() async {
    if (_deviceId != null && _deviceId!.isNotEmpty) return _deviceId!;
    try {
      final sysTemp = Directory.systemTemp;
      final file = File('${sysTemp.path}/library_app_device_id.txt');
      if (await file.exists()) {
        final id = await file.readAsString();
        if (id.isNotEmpty) {
          _deviceId = id.trim();
          return _deviceId!;
        }
      }
      _deviceId = 'DEV-' + DateTime.now().millisecondsSinceEpoch.toString() + '-' + (1000 + (DateTime.now().microsecondsSinceEpoch % 8999)).toString();
      await file.writeAsString(_deviceId!);
      return _deviceId!;
    } catch (e) {
      _deviceId = 'DEV-DEFAULT-MOBILE';
      return _deviceId!;
    }
  }

  Future<Map<String, dynamic>> sendLoginOtp(String phoneOrEmail) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    final devId = await getDeviceId();
    final result = await ApiService.sendLoginOtp(phoneOrEmail, deviceId: devId);
    _isLoading = false;

    if (result['success'] == true && result['otp_code'] != null) {
      final String otpCode = result['otp_code'].toString();
      try {
        await NotificationService().showNotification(
          id: (DateTime.now().millisecondsSinceEpoch ~/ 1000) % 100000,
          title: 'Login OTP Code 🔑',
          body: 'Your Login OTP code is: $otpCode. Valid for 10 minutes.',
        );
      } catch (e) {
        print("Trigger instant OTP notification error: $e");
      }
    } else if (result['success'] != true) {
      _errorMessage = result['message'] ?? 'Failed to send OTP';
    }
    notifyListeners();
    return result;
  }

  Future<bool> verifyLoginOtp(String phoneOrEmail, String otpCode) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    final devId = await getDeviceId();
    final result = await ApiService.verifyLoginOtp(phoneOrEmail, otpCode, deviceId: devId);

    _isLoading = false;
    if (result['success'] == true) {
      _currentUser = UserModel.fromJson(result['user']);
      _saveUserSession(result['user']);
      if (_currentUser != null) {
        NativeNotificationService.startNativeService(_currentUser!.id);
      }
      notifyListeners();
      return true;
    } else {
      _errorMessage = result['message'] ?? 'Invalid OTP verification';
      notifyListeners();
      return false;
    }
  }

  Future<bool> register({
    required String name,
    required String email,
    required String phone,
    required String password,
    required int shiftId,
    String? emergencyContact,
    String? idProofType,
    String? idProofNo,
    String? preparationFor,
  }) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    final result = await ApiService.registerStudent(
      name: name,
      email: email,
      phone: phone,
      password: password,
      shiftId: shiftId,
      emergencyContact: emergencyContact,
      idProofType: idProofType,
      idProofNo: idProofNo,
      preparationFor: preparationFor,
    );

    _isLoading = false;
    if (result['success'] == true) {
      notifyListeners();
      return true;
    } else {
      _errorMessage = result['message'] ?? 'Registration failed';
      notifyListeners();
      return false;
    }
  }

  void updateCurrentUser(UserModel user) {
    _currentUser = user;
    _saveUserSession(user.toJson());
    notifyListeners();
  }

  bool _isSuperAdmin = false;
  String? _superAdminUsername;

  bool get isSuperAdmin => _isSuperAdmin;
  String? get superAdminUsername => _superAdminUsername;

  Future<bool> superAdminLogin(String username, String password) async {
    _isLoading = true;
    _errorMessage = null;
    notifyListeners();

    final result = await ApiService.superAdminLogin(username, password);

    _isLoading = false;
    if (result['success'] == true) {
      _isSuperAdmin = true;
      _superAdminUsername = result['username']?.toString() ?? username;
      _currentUser = UserModel(
        id: 0,
        name: 'Super Admin (${_superAdminUsername})',
        email: result['email']?.toString() ?? 'superadmin@studyspace.com',
        phone: '',
        role: 'super_admin',
        status: 'approved',
      );
      notifyListeners();
      return true;
    } else {
      _errorMessage = result['error']?.toString() ?? result['message']?.toString() ?? 'Super Admin login failed';
      notifyListeners();
      return false;
    }
  }

  Future<void> superAdminLogout() async {
    await ApiService.superAdminCall('logout');
    _isSuperAdmin = false;
    _superAdminUsername = null;
    ApiService.superAdminToken = null;
    await logout();
  }

  Future<void> logout() async {
    _currentUser = null;
    _isSuperAdmin = false;
    _superAdminUsername = null;
    ApiService.superAdminToken = null;
    NativeNotificationService.stopNativeService();
    try {
      final file = _getSessionFile();
      if (await file.exists()) {
        await file.delete();
      }
    } catch (e) {
      print("Logout session delete error: $e");
    }
    notifyListeners();
  }

  void _saveUserSession(Map<String, dynamic> userJson) async {
    try {
      final file = _getSessionFile();
      await file.writeAsString(jsonEncode(userJson));
    } catch (e) {
      print("Save session error: $e");
    }
  }
}
