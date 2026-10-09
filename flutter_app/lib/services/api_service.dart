import 'dart:convert';
import 'package:http/http.dart' as http;
import '../config/api_config.dart';

class ApiService {
  static const Duration _timeout = Duration(seconds: 15);
  static String activeLibraryCode = 'LIB001';

  static Map<String, String> get defaultHeaders => {
    'X-Library-Code': activeLibraryCode,
  };

  static Map<String, dynamic> _safeDecodeResponse(http.Response response, {String defaultMessage = 'Server response error.'}) {
    if (response.statusCode != 200) {
      return {
        'success': false,
        'message': 'API service returned HTTP status ${response.statusCode}. Please verify server deployment.',
        'error': 'API service returned HTTP status ${response.statusCode}. Please verify server deployment.'
      };
    }
    final body = response.body.trim();
    if (body.toLowerCase().startsWith('<html') ||
        body.toLowerCase().startsWith('<!doctype') ||
        body.contains('/aes.js') ||
        body.contains('aes.js')) {
      return {
        'success': false,
        'message': 'Server returned non-JSON HTML response. Please verify API endpoint configuration.',
        'error': 'Server returned non-JSON HTML response. Please verify API endpoint configuration.'
      };
    }
    try {
      final decoded = jsonDecode(body);
      if (decoded is Map<String, dynamic>) {
        return decoded;
      }
      return {'success': false, 'message': defaultMessage, 'error': defaultMessage};
    } on FormatException {
      return {'success': false, 'message': 'Invalid JSON response format from server.', 'error': 'Invalid JSON response format from server.'};
    } catch (_) {
      return {'success': false, 'message': defaultMessage, 'error': defaultMessage};
    }
  }

  // Fetch Public Tenant Branding & Information
  static Future<Map<String, dynamic>> getTenantInfo(String libraryCode) async {
    try {
      final code = libraryCode.trim().toUpperCase();
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonTenant}?code=$code'),
        headers: {'X-Library-Code': code},
      ).timeout(_timeout);
      return _safeDecodeResponse(response, defaultMessage: 'Failed to retrieve tenant info.');
    } catch (e) {
      return {'success': false, 'error': 'Connection error or network timeout: $e'};
    }
  }

  // Login
  static Future<Map<String, dynamic>> login(String email, String password) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAuth),
        headers: defaultHeaders,
        body: {'action': 'login', 'email': email, 'password': password, 'library_code': activeLibraryCode},
      ).timeout(_timeout);
      return _safeDecodeResponse(response);
    } catch (e) {
      return {'success': false, 'message': 'Network timeout or connection busy: $e'};
    }
  }

  // Send Login OTP
  static Future<Map<String, dynamic>> sendLoginOtp(String phoneOrEmail, {String? deviceId}) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAuth),
        body: {
          'action': 'send_login_otp',
          'phone_or_email': phoneOrEmail,
          'device_id': deviceId ?? '',
        },
      ).timeout(_timeout);
      return _safeDecodeResponse(response);
    } catch (e) {
      return {'success': false, 'message': 'Network timeout or connection busy: $e'};
    }
  }

  // Verify Login OTP
  static Future<Map<String, dynamic>> verifyLoginOtp(String phoneOrEmail, String otpCode, {String? deviceId}) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAuth),
        body: {
          'action': 'verify_login_otp',
          'phone_or_email': phoneOrEmail,
          'otp_code': otpCode,
          'device_id': deviceId ?? '',
        },
      ).timeout(_timeout);
      return _safeDecodeResponse(response);
    } catch (e) {
      return {'success': false, 'message': 'Network timeout or connection busy: $e'};
    }
  }

  // Student Register
  static Future<Map<String, dynamic>> registerStudent({
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
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAuth),
        body: {
          'action': 'register',
          'name': name,
          'email': email,
          'phone': phone,
          'password': password,
          'shift_id': shiftId.toString(),
          'emergency_contact': emergencyContact ?? '',
          'id_proof_type': idProofType ?? 'Aadhaar Card',
          'id_proof_no': idProofNo ?? '',
          'preparation_for': preparationFor ?? '',
        },
      );
      return _safeDecodeResponse(response);
    } catch (e) {
      return {'success': false, 'message': 'Connection error: $e'};
    }
  }

  // Get Active Shifts
  static Future<List<dynamic>> getShifts() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAuth}?action=get_shifts'),
      );
      final data = _safeDecodeResponse(response);
      if (data['success'] == true && data['shifts'] is List) {
        return data['shifts'];
      }
    } catch (e) {
      print("Error fetching shifts: $e");
    }
    return [];
  }

  // Fetch Seat Matrix Grid
  static Future<Map<String, dynamic>> getSeatMatrix(int shiftId, int currentUserId) async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.seatMatrix}?shift_id=$shiftId&user_id=$currentUserId'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Error loading seat map: $e'};
    }
  }

  // Student Dashboard Data
  static Future<Map<String, dynamic>> getStudentDashboard(int userId) async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonStudent}?action=get_dashboard&user_id=$userId'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Error loading dashboard: $e'};
    }
  }

  // Student Check-In
  static Future<Map<String, dynamic>> studentCheckIn(int userId, String deviceTime, {double? lat, double? lng}) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonStudent),
        body: {
          'action': 'checkin',
          'user_id': userId.toString(),
          'device_time': deviceTime,
          'latitude': lat?.toString() ?? '0.0',
          'longitude': lng?.toString() ?? '0.0',
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Check-in failed: $e'};
    }
  }

  // Student Check-Out
  static Future<Map<String, dynamic>> studentCheckOut(int userId, String deviceTime, {double? lat, double? lng, bool autoCheckout = false}) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonStudent),
        body: {
          'action': 'checkout',
          'user_id': userId.toString(),
          'device_time': deviceTime,
          'latitude': lat?.toString() ?? '0.0',
          'longitude': lng?.toString() ?? '0.0',
          'auto_checkout': autoCheckout ? '1' : '0',
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Check-out failed: $e'};
    }
  }

  // Submit Complaint
  static Future<Map<String, dynamic>> submitComplaint(int userId, String category, String subject, String description) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonStudent),
        body: {
          'action': 'submit_complaint',
          'user_id': userId.toString(),
          'category': category,
          'subject': subject,
          'description': description,
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to submit complaint: $e'};
    }
  }

  // Get Student Complaints / Support Tickets List
  static Future<Map<String, dynamic>> getStudentComplaints(int userId) async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonStudent}?action=get_complaints&user_id=$userId'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load tickets: $e'};
    }
  }

  // Admin: Get All Complaints / Support Tickets List
  static Future<Map<String, dynamic>> getAllComplaints() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_all_complaints'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load complaints: $e'};
    }
  }

  // Admin: Update Complaint Ticket Status
  static Future<Map<String, dynamic>> updateComplaintStatus(int complaintId, String status) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'update_complaint_status',
          'complaint_id': complaintId.toString(),
          'status': status,
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to update ticket status: $e'};
    }
  }

  // Admin Dashboard Stats
  static Future<Map<String, dynamic>> getAdminStats() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_dashboard_stats'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Error loading stats: $e'};
    }
  }

  // Admin Pending Students Request List
  static Future<Map<String, dynamic>> getPendingStudents() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_pending_students'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Error loading pending list: $e'};
    }
  }

  // Admin Seat Allotment
  static Future<Map<String, dynamic>> allotSeat(int studentId, int seatId, int shiftId) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'allot_seat',
          'student_id': studentId.toString(),
          'seat_id': seatId.toString(),
          'shift_id': shiftId.toString(),
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Seat allotment failed: $e'};
    }
  }

  // Admin Live Attendance
  static Future<Map<String, dynamic>> getLiveAttendance() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_live_attendance'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Error loading attendance: $e'};
    }
  }

  // Admin Attendance Toggle (Check-In or Check-Out for Student)
  static Future<Map<String, dynamic>> adminAttendanceToggle(int studentId, String toggleType) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'admin_attendance_toggle',
          'student_id': studentId.toString(),
          'toggle_type': toggleType,
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Action failed: $e'};
    }
  }

  // Send Notification / Broadcast
  static Future<Map<String, dynamic>> sendNotification(String title, String content, {int? targetUserId}) async {
    try {
      final body = {
        'action': 'send_notification',
        'title': title,
        'content': content,
      };
      if (targetUserId != null) {
        body['target_user_id'] = targetUserId.toString();
      }
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: body,
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to send notification: $e'};
    }
  }

  // Mark notification read
  static Future<Map<String, dynamic>> markNotificationRead(int userId, {int? notifId}) async {
    try {
      final body = {
        'action': 'mark_notification_read',
        'user_id': userId.toString(),
      };
      if (notifId != null) {
        body['notif_id'] = notifId.toString();
      }
      final response = await http.post(
        Uri.parse(ApiConfig.jsonStudent),
        body: body,
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to mark notification read: $e'};
    }
  }

  // Bulk Create Seats Range (e.g. Row E, 1 to 10 -> E-01 to E-10)
  static Future<Map<String, dynamic>> bulkCreateSeats(String rowLabel, int startNum, int endNum, {int formatDigits = 2}) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'bulk_create_seats',
          'row_label': rowLabel,
          'start_num': startNum.toString(),
          'end_num': endNum.toString(),
          'format_digits': formatDigits.toString(),
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Bulk creation failed: $e'};
    }
  }

  // Delete Seat Desk
  static Future<Map<String, dynamic>> deleteSeat(int seatId) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'delete_seat',
          'seat_id': seatId.toString(),
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Delete seat failed: $e'};
    }
  }

  // Admin Fee Management: Get All Payments
  static Future<Map<String, dynamic>> getAdminFeePayments() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_fee_payments'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load fee payments: $e'};
    }
  }

  // Admin Fee Management: Record Fee Payment
  static Future<Map<String, dynamic>> recordFeePayment({
    required int allocationId,
    required int userId,
    required double amount,
    required String paymentMode,
    String? monthYear,
  }) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'record_fee_payment',
          'allocation_id': allocationId.toString(),
          'user_id': userId.toString(),
          'amount': amount.toString(),
          'payment_mode': paymentMode,
          'month_year': monthYear ?? '',
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to record payment: $e'};
    }
  }

  // Student Fee History
  static Future<Map<String, dynamic>> getStudentFeeHistory(int userId) async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonStudent}?action=get_fee_history&user_id=$userId'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load fee history: $e'};
    }
  }

  // Student Chat API
  static Future<Map<String, dynamic>> getStudentChatMessages(int userId) async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonStudent}?action=get_chat_messages&user_id=$userId'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load chat: $e'};
    }
  }

  static Future<Map<String, dynamic>> sendStudentChatMessage(int userId, String message) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonStudent),
        body: {
          'action': 'send_chat_message',
          'user_id': userId.toString(),
          'message': message,
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to send message: $e'};
    }
  }

  // Admin Chat API
  static Future<Map<String, dynamic>> getAdminChatThreads() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_admin_chat_threads'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load chat threads: $e'};
    }
  }

  static Future<Map<String, dynamic>> getAdminChatMessages(int studentId) async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_admin_chat_messages&student_id=$studentId'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load conversation: $e'};
    }
  }

  static Future<Map<String, dynamic>> sendAdminChatMessage(int studentId, String message) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'send_admin_chat_message',
          'student_id': studentId.toString(),
          'message': message,
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to send reply: $e'};
    }
  }

  // Manage Students API
  static Future<Map<String, dynamic>> getAllStudents() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_all_students'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to fetch student directory: $e'};
    }
  }

  static Future<Map<String, dynamic>> deleteStudent(int studentId) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'delete_student',
          'student_id': studentId.toString(),
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to delete student record: $e'};
    }
  }

  // Recycle Bin API
  static Future<Map<String, dynamic>> getRecycleBinStudents() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_recycle_bin_students'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to fetch recycle bin: $e'};
    }
  }

  static Future<Map<String, dynamic>> restoreStudent(int studentId) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'restore_student',
          'student_id': studentId.toString(),
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to restore student: $e'};
    }
  }

  static Future<Map<String, dynamic>> permanentDeleteStudent(int studentId) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'permanent_delete_student',
          'student_id': studentId.toString(),
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to erase student record: $e'};
    }
  }

  static Future<Map<String, dynamic>> get12MonthMasterFeeReport() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_12month_master_fee_report'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to fetch 12-month master report: $e'};
    }
  }

  // Shift Management Methods
  static Future<Map<String, dynamic>> getAllShiftsAdmin() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_shifts'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to fetch shifts: $e'};
    }
  }

  static Future<Map<String, dynamic>> addShift({
    required String name,
    required String startTime,
    required String endTime,
    required double feeAmount,
  }) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'add_shift',
          'name': name,
          'start_time': startTime,
          'end_time': endTime,
          'fee_amount': feeAmount.toString(),
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to add new shift: $e'};
    }
  }

  static Future<Map<String, dynamic>> editShift({
    required int shiftId,
    required String name,
    required String startTime,
    required String endTime,
    required double feeAmount,
  }) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'edit_shift',
          'shift_id': shiftId.toString(),
          'name': name,
          'start_time': startTime,
          'end_time': endTime,
          'fee_amount': feeAmount.toString(),
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to update shift: $e'};
    }
  }

  static Future<Map<String, dynamic>> toggleShift(int shiftId, bool isActive) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: {
          'action': 'toggle_shift',
          'shift_id': shiftId.toString(),
          'is_active': isActive ? '1' : '0',
        },
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to toggle shift status: $e'};
    }
  }

  static Future<Map<String, dynamic>> getAdminNotifications(int userId) async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_admin_notifications&user_id=$userId'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load notifications: $e'};
    }
  }

  static Future<Map<String, dynamic>> getAppSettings() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_app_settings'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to fetch app settings: $e'};
    }
  }

  static Future<Map<String, dynamic>> updateAppSettings({
    required String appName,
    required String appLogoUrl,
    required String appTagline,
    String? logoFilePath,
  }) async {
    try {
      if (logoFilePath != null && logoFilePath.isNotEmpty) {
        final request = http.MultipartRequest(
          'POST',
          Uri.parse(ApiConfig.jsonAdmin),
        );
        request.fields['action'] = 'update_app_settings';
        request.fields['app_name'] = appName;
        request.fields['app_logo_url'] = appLogoUrl;
        request.fields['app_tagline'] = appTagline;
        request.files.add(await http.MultipartFile.fromPath('logo_file', logoFilePath));

        final streamedResponse = await request.send();
        final response = await http.Response.fromStream(streamedResponse);
        return jsonDecode(response.body);
      } else {
        final response = await http.post(
          Uri.parse(ApiConfig.jsonAdmin),
          body: {
            'action': 'update_app_settings',
            'app_name': appName,
            'app_logo_url': appLogoUrl,
            'app_tagline': appTagline,
          },
        );
        return jsonDecode(response.body);
      }
    } catch (e) {
      return {'success': false, 'message': 'Failed to update app settings: $e'};
    }
  }

  static Future<Map<String, dynamic>> getSeatsWithStatus({required int shiftId, required int studentId}) async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_seats_with_status&shift_id=$shiftId&student_id=$studentId'),
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load seats for shift: $e'};
    }
  }

  static Future<Map<String, dynamic>> updateStudent({
    required int studentId,
    required String name,
    required String phone,
    required String email,
    String? fatherName,
    String? address,
    String? emergencyContact,
    String? preparationFor,
    int? shiftId,
    int? seatId,
  }) async {
    try {
      final body = {
        'action': 'update_student',
        'student_id': studentId.toString(),
        'name': name,
        'phone': phone,
        'email': email,
        'father_name': fatherName ?? '',
        'address': address ?? '',
        'emergency_contact': emergencyContact ?? '',
        'preparation_for': preparationFor ?? '',
      };
      if (shiftId != null && shiftId > 0) body['shift_id'] = shiftId.toString();
      if (seatId != null && seatId > 0) body['seat_id'] = seatId.toString();

      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        body: body,
      );
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to update student profile: $e'};
    }
  }

  // ================= PARENT PORTAL API METHODS ================= //

  // Get Parent Profile & Linked Students
  static Future<Map<String, dynamic>> getParentProfile({int? parentId}) async {
    try {
      final pParam = parentId != null && parentId > 0 ? '&parent_id=$parentId' : '';
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonParent}?action=parent_profile$pParam'),
        headers: parentId != null ? {'X-Parent-Id': parentId.toString(), ...defaultHeaders} : defaultHeaders,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load parent profile: $e'};
    }
  }

  // Get Linked Students for Parent
  static Future<Map<String, dynamic>> getParentLinkedStudents({int? parentId}) async {
    try {
      final pParam = parentId != null && parentId > 0 ? '&parent_id=$parentId' : '';
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonParent}?action=linked_students$pParam'),
        headers: parentId != null ? {'X-Parent-Id': parentId.toString(), ...defaultHeaders} : defaultHeaders,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load linked students: $e'};
    }
  }

  // Get Full Student Summary for Parent Portal Dashboard
  static Future<Map<String, dynamic>> getParentStudentFullSummary(int studentId, {int? parentId}) async {
    try {
      final pParam = parentId != null && parentId > 0 ? '&parent_id=$parentId' : '';
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonParent}?action=student_full_summary&student_id=$studentId$pParam'),
        headers: parentId != null ? {'X-Parent-Id': parentId.toString(), ...defaultHeaders} : defaultHeaders,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load student summary: $e'};
    }
  }

  // Get Attendance History for Linked Student
  static Future<Map<String, dynamic>> getParentStudentAttendance(int studentId, {int? parentId, String? month}) async {
    try {
      final monthParam = month != null ? '&month=$month' : '';
      final pParam = parentId != null && parentId > 0 ? '&parent_id=$parentId' : '';
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonParent}?action=attendance_history&student_id=$studentId$monthParam$pParam'),
        headers: parentId != null ? {'X-Parent-Id': parentId.toString(), ...defaultHeaders} : defaultHeaders,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load attendance history: $e'};
    }
  }

  // Get 12-Month Fee Billing Matrix for Linked Student
  static Future<Map<String, dynamic>> getParentStudent12MonthFees(int studentId, {int? parentId}) async {
    try {
      final pParam = parentId != null && parentId > 0 ? '&parent_id=$parentId' : '';
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonParent}?action=fee_12_month&student_id=$studentId$pParam'),
        headers: parentId != null ? {'X-Parent-Id': parentId.toString(), ...defaultHeaders} : defaultHeaders,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load 12-month fee matrix: $e'};
    }
  }

  // Get Parent Chat Messages for Linked Student
  static Future<Map<String, dynamic>> getParentChatMessages(int studentId, {int? parentId}) async {
    try {
      final pParam = parentId != null && parentId > 0 ? '&parent_id=$parentId' : '';
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonParent}?action=get_chat_messages&student_id=$studentId$pParam'),
        headers: parentId != null ? {'X-Parent-Id': parentId.toString(), ...defaultHeaders} : defaultHeaders,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load chat messages: $e'};
    }
  }

  // Send Parent Chat Message to Admin
  static Future<Map<String, dynamic>> sendParentChatMessage(int studentId, String message, {int? parentId}) async {
    try {
      final body = {
        'action': 'send_chat_message',
        'student_id': studentId.toString(),
        'message': message,
      };
      if (parentId != null && parentId > 0) {
        body['parent_id'] = parentId.toString();
      }
      final response = await http.post(
        Uri.parse(ApiConfig.jsonParent),
        headers: parentId != null ? {'X-Parent-Id': parentId.toString(), ...defaultHeaders} : defaultHeaders,
        body: body,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to send chat message: $e'};
    }
  }

  // Get Parent Unread Badges Count
  static Future<Map<String, dynamic>> getParentUnreadCounts({int? studentId, int? parentId}) async {
    try {
      final sidParam = studentId != null && studentId > 0 ? '&student_id=$studentId' : '';
      final pParam = parentId != null && parentId > 0 ? '&parent_id=$parentId' : '';
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonParent}?action=get_unread_counts$sidParam$pParam'),
        headers: parentId != null ? {'X-Parent-Id': parentId.toString(), ...defaultHeaders} : defaultHeaders,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to get unread counts: $e'};
    }
  }

  // Get Complaints for Linked Student
  static Future<Map<String, dynamic>> getParentComplaints(int studentId, {int? parentId}) async {
    try {
      final pParam = parentId != null && parentId > 0 ? '&parent_id=$parentId' : '';
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonParent}?action=complaints&student_id=$studentId$pParam'),
        headers: parentId != null ? {'X-Parent-Id': parentId.toString(), ...defaultHeaders} : defaultHeaders,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load complaints: $e'};
    }
  }

  // Create Complaint / Request for Linked Student
  static Future<Map<String, dynamic>> createParentComplaint(int studentId, {String? subject, required String message, int? parentId}) async {
    try {
      final body = {
        'action': 'create_complaint',
        'student_id': studentId.toString(),
        'subject': subject ?? 'Parent Query / Request',
        'description': message,
      };
      if (parentId != null && parentId > 0) {
        body['parent_id'] = parentId.toString();
      }
      final response = await http.post(
        Uri.parse(ApiConfig.jsonParent),
        headers: parentId != null ? {'X-Parent-Id': parentId.toString(), ...defaultHeaders} : defaultHeaders,
        body: body,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to submit complaint: $e'};
    }
  }

  // ================= ADMIN PARENT MANAGEMENT API METHODS ================= //
  static Future<Map<String, dynamic>> getAdminParents() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_parents'),
        headers: defaultHeaders,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load parents: $e'};
    }
  }

  static Future<Map<String, dynamic>> createAdminParent({
    required String name,
    required String email,
    required String phone,
    required String password,
    String relationship = 'Father',
    String status = 'approved',
    List<int> studentIds = const [],
  }) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        headers: defaultHeaders,
        body: {
          'action': 'create_parent',
          'name': name,
          'email': email,
          'phone': phone,
          'password': password,
          'relationship': relationship,
          'status': status,
          'student_ids': jsonEncode(studentIds),
        },
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to create parent: $e'};
    }
  }

  static Future<Map<String, dynamic>> editAdminParent({
    required int parentId,
    required String name,
    required String email,
    required String phone,
    required String status,
  }) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        headers: defaultHeaders,
        body: {
          'action': 'edit_parent',
          'parent_id': parentId.toString(),
          'name': name,
          'email': email,
          'phone': phone,
          'status': status,
        },
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to update parent details: $e'};
    }
  }

  static Future<Map<String, dynamic>> linkParentStudent({
    required int parentId,
    required int studentId,
    String relationship = 'Father',
  }) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        headers: defaultHeaders,
        body: {
          'action': 'link_parent_student',
          'parent_id': parentId.toString(),
          'student_id': studentId.toString(),
          'relationship': relationship,
        },
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to link student: $e'};
    }
  }

  static Future<Map<String, dynamic>> unlinkParentStudent({
    required int parentId,
    required int studentId,
  }) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        headers: defaultHeaders,
        body: {
          'action': 'unlink_parent_student',
          'parent_id': parentId.toString(),
          'student_id': studentId.toString(),
        },
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to unlink student: $e'};
    }
  }

  static Future<Map<String, dynamic>> toggleParentStatus(int parentId) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        headers: defaultHeaders,
        body: {
          'action': 'toggle_parent_status',
          'parent_id': parentId.toString(),
        },
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to toggle status: $e'};
    }
  }

  static Future<Map<String, dynamic>> resetParentPassword(int parentId, String newPassword) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonAdmin),
        headers: defaultHeaders,
        body: {
          'action': 'reset_parent_password',
          'parent_id': parentId.toString(),
          'new_password': newPassword,
        },
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to reset password: $e'};
    }
  }

  static Future<Map<String, dynamic>> getParentActivity(int parentId) async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonAdmin}?action=get_parent_activity&parent_id=$parentId'),
        headers: defaultHeaders,
      ).timeout(_timeout);
      return jsonDecode(response.body);
    } catch (e) {
      return {'success': false, 'message': 'Failed to load parent activity: $e'};
    }
  }

  // ==========================================
  // SUPER ADMIN PLATFORM API SERVICES
  // ==========================================
  static String? superAdminToken;

  static Map<String, String> get superAdminHeaders => {
    if (superAdminToken != null && superAdminToken!.isNotEmpty)
      'Authorization': 'Bearer $superAdminToken',
    'X-Super-Admin-Key': 'superadmin_secret_key_2026',
  };

  static Future<Map<String, dynamic>> superAdminLogin(String username, String password) async {
    try {
      final response = await http.post(
        Uri.parse(ApiConfig.jsonSuperAdmin),
        body: {'action': 'login', 'username': username, 'password': password},
      ).timeout(_timeout);
      final res = _safeDecodeResponse(response);
      if (res['success'] == true && res['token'] != null) {
        superAdminToken = res['token'].toString();
      }
      return res;
    } catch (e) {
      return {'success': false, 'error': 'Connection error: $e'};
    }
  }

  static Future<Map<String, dynamic>> superAdminCall(String action, [Map<String, String>? bodyParams]) async {
    try {
      final map = <String, String>{'action': action};
      if (bodyParams != null) {
        map.addAll(bodyParams);
      }
      final response = await http.post(
        Uri.parse(ApiConfig.jsonSuperAdmin),
        headers: superAdminHeaders,
        body: map,
      ).timeout(_timeout);
      return _safeDecodeResponse(response);
    } catch (e) {
      return {'success': false, 'error': 'Connection error: $e'};
    }
  }

  static Future<List<Map<String, dynamic>>> getPublicLibrariesList() async {
    try {
      final response = await http.get(
        Uri.parse('${ApiConfig.jsonTenant}?action=list'),
      ).timeout(_timeout);
      final res = _safeDecodeResponse(response);
      if (res['success'] == true && res['libraries'] is List) {
        return List<Map<String, dynamic>>.from(res['libraries']);
      }
    } catch (_) {}
    return [];
  }
}
