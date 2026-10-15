import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../config/api_config.dart';
import '../config/tenant_config.dart';
import '../providers/auth_provider.dart';
import '../providers/tenant_provider.dart';
import '../widgets/branding_logo_widget.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({Key? key}) : super(key: key);

  @override
  _LoginScreenState createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _obscurePassword = true;

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  void _handlePasswordLogin() async {
    final email = _emailController.text.trim();
    final password = _passwordController.text.trim();

    if (email.isEmpty || password.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Please enter both username/email and password.'),
          backgroundColor: Colors.redAccent,
        ),
      );
      return;
    }

    final authProvider = Provider.of<AuthProvider>(context, listen: false);
    final success = await authProvider.login(email, password);

    if (mounted) {
      if (success) {
        final user = authProvider.currentUser;
        if (user?.role == 'admin') {
          Navigator.of(context).pushReplacementNamed('/admin_dashboard');
        } else if (user?.role == 'parent') {
          Navigator.of(context).pushReplacementNamed('/parent_dashboard');
        } else {
          Navigator.of(context).pushReplacementNamed('/student_dashboard');
        }
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(authProvider.errorMessage ?? 'Login failed. Please check your credentials.'),
            backgroundColor: Colors.redAccent,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final authProvider = Provider.of<AuthProvider>(context);
    final isDark = Theme.of(context).brightness == Brightness.dark;
    final isSingleTenant = TenantConfig.current.isSingleTenant;

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.symmetric(horizontal: 24.0, vertical: 20.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const BrandingLogoWidget(size: 72),
                const SizedBox(height: 14),
                Consumer<TenantProvider>(
                  builder: (context, tenant, _) => Column(
                    children: [
                      Text(
                        tenant.activeLibraryName,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          fontSize: 24,
                          fontWeight: FontWeight.bold,
                          color: isDark ? Colors.white : const Color(0xFF0F172A),
                        ),
                      ),
                      const SizedBox(height: 4),
                      Text(
                        tenant.activeTagline,
                        textAlign: TextAlign.center,
                        style: TextStyle(
                          color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF475569),
                          fontSize: 13,
                          fontWeight: FontWeight.w500,
                        ),
                      ),
                      const SizedBox(height: 12),

                      // Dedicated library badge: Immutable for single-tenant, selectable only for multi-tenant dev
                      if (isSingleTenant)
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                          decoration: BoxDecoration(
                            color: tenant.activePrimaryColor.withOpacity(0.12),
                            border: Border.all(color: tenant.activePrimaryColor.withOpacity(0.4)),
                            borderRadius: BorderRadius.circular(20),
                          ),
                          child: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Icon(Icons.apartment_rounded, size: 14, color: tenant.activePrimaryColor),
                              const SizedBox(width: 6),
                              Text(
                                "Library: ${tenant.activeLibraryCode}",
                                style: TextStyle(
                                  color: tenant.activePrimaryColor,
                                  fontWeight: FontWeight.bold,
                                  fontSize: 12,
                                ),
                              ),
                            ],
                          ),
                        )
                      else
                        InkWell(
                          onTap: () async {
                            await Provider.of<TenantProvider>(context, listen: false).changeLibrary();
                            await Provider.of<AuthProvider>(context, listen: false).logout();
                          },
                          borderRadius: BorderRadius.circular(20),
                          child: Container(
                            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 6),
                            decoration: BoxDecoration(
                              color: tenant.activePrimaryColor.withOpacity(0.12),
                              border: Border.all(color: tenant.activePrimaryColor.withOpacity(0.4)),
                              borderRadius: BorderRadius.circular(20),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(Icons.apartment_rounded, size: 14, color: tenant.activePrimaryColor),
                                const SizedBox(width: 6),
                                Text(
                                  "Active Library: ${tenant.activeLibraryCode}",
                                  style: TextStyle(
                                    color: tenant.activePrimaryColor,
                                    fontWeight: FontWeight.bold,
                                    fontSize: 12,
                                  ),
                                ),
                                const SizedBox(width: 8),
                                const Icon(Icons.swap_horiz_rounded, size: 14, color: Color(0xFF64748B)),
                                const Text(" (Change)", style: TextStyle(fontSize: 11, color: Color(0xFF64748B))),
                              ],
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 24),

                // Clean Password Login Card
                Card(
                  elevation: 4,
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(16),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(22.0),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Row(
                          children: [
                            Icon(Icons.lock_rounded, size: 20, color: AppColors.primaryIndigo),
                            const SizedBox(width: 8),
                            const Text(
                              'Sign In to Your Account',
                              style: TextStyle(
                                fontSize: 16,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 6),
                        Text(
                          'Enter your registered username/email and password to continue.',
                          style: TextStyle(
                            fontSize: 12,
                            color: isDark ? const Color(0xFF94A3B8) : const Color(0xFF64748B),
                          ),
                        ),
                        const SizedBox(height: 20),

                        // Username / Email Input
                        TextField(
                          controller: _emailController,
                          keyboardType: TextInputType.emailAddress,
                          textInputAction: TextInputAction.next,
                          decoration: const InputDecoration(
                            labelText: 'Username or Email Address',
                            hintText: 'e.g. rahul@gmail.com or admin',
                            prefixIcon: Icon(Icons.person_outline),
                            border: OutlineInputBorder(),
                          ),
                        ),
                        const SizedBox(height: 16),

                        // Password Input with show/hide toggle
                        TextField(
                          controller: _passwordController,
                          obscureText: _obscurePassword,
                          textInputAction: TextInputAction.done,
                          onSubmitted: (_) => _handlePasswordLogin(),
                          decoration: InputDecoration(
                            labelText: 'Password',
                            prefixIcon: const Icon(Icons.lock_outline),
                            border: const OutlineInputBorder(),
                            suffixIcon: IconButton(
                              icon: Icon(
                                _obscurePassword ? Icons.visibility_off : Icons.visibility,
                                color: Colors.grey,
                              ),
                              onPressed: () {
                                setState(() {
                                  _obscurePassword = !_obscurePassword;
                                });
                              },
                            ),
                          ),
                        ),
                        const SizedBox(height: 24),

                        // Login Action Button
                        ElevatedButton(
                          onPressed: authProvider.isLoading ? null : _handlePasswordLogin,
                          style: ElevatedButton.styleFrom(
                            backgroundColor: AppColors.primaryIndigo,
                            padding: const EdgeInsets.symmetric(vertical: 14),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(10),
                            ),
                          ),
                          child: authProvider.isLoading
                              ? const SizedBox(
                                  height: 20,
                                  width: 20,
                                  child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                                )
                              : const Text(
                                  'LOGIN WITH PASSWORD',
                                  style: TextStyle(
                                    fontSize: 15,
                                    fontWeight: FontWeight.bold,
                                    color: Colors.white,
                                  ),
                                ),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 20),

                // Register Link
                Row(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    const Text("Don't have a library seat account? "),
                    GestureDetector(
                      onTap: () {
                        Navigator.of(context).pushNamed('/register');
                      },
                      child: const Text(
                        'Register Here',
                        style: TextStyle(
                          color: AppColors.primaryIndigo,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 24),
                const Center(
                  child: Text(
                    'Powered by Ramxonwebwork',
                    style: TextStyle(
                      fontSize: 12,
                      fontWeight: FontWeight.bold,
                      color: AppColors.primaryIndigo,
                    ),
                  ),
                ),
                const SizedBox(height: 10),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
