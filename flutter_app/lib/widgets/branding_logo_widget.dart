import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../providers/branding_provider.dart';
import '../config/api_config.dart';
import '../config/tenant_config.dart';

class BrandingLogoWidget extends StatelessWidget {
  final double size;
  final Color? fallbackColor;
  final Color? fallbackIconColor;

  const BrandingLogoWidget({
    Key? key,
    this.size = 64.0,
    this.fallbackColor,
    this.fallbackIconColor,
  }) : super(key: key);

  @override
  Widget build(BuildContext context) {
    final branding = Provider.of<BrandingProvider>(context);
    final logoUrl = branding.appLogoUrl.trim();

    if (logoUrl.isNotEmpty) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(size * 0.22),
        child: Image.network(
          logoUrl,
          width: size,
          height: size,
          fit: BoxFit.cover,
          errorBuilder: (context, error, stackTrace) => _buildFallback(context),
          loadingBuilder: (context, child, loadingProgress) {
            if (loadingProgress == null) return child;
            return Container(
              width: size,
              height: size,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: (fallbackColor ?? AppColors.primaryIndigo).withOpacity(0.12),
                borderRadius: BorderRadius.circular(size * 0.22),
              ),
              child: SizedBox(
                width: size * 0.4,
                height: size * 0.4,
                child: const CircularProgressIndicator(strokeWidth: 2, color: AppColors.primaryIndigo),
              ),
            );
          },
        ),
      );
    }

    return _buildFallback(context);
  }

  Widget _buildFallback(BuildContext context) {
    final bg = fallbackColor ?? AppColors.primaryIndigo;
    final iconColor = fallbackIconColor ?? Colors.white;

    if (TenantConfig.current.flavor == LibraryFlavor.lib001) {
      return ClipRRect(
        borderRadius: BorderRadius.circular(size * 0.22),
        child: Image.asset(
          'assets/branding/studyspace/logo.png',
          width: size,
          height: size,
          fit: BoxFit.cover,
          errorBuilder: (_, __, ___) => _buildIconBadge(bg, iconColor),
        ),
      );
    }

    return _buildIconBadge(bg, iconColor);
  }

  Widget _buildIconBadge(Color bg, Color iconColor) {

    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        color: bg,
        borderRadius: BorderRadius.circular(size * 0.22),
        boxShadow: [
          BoxShadow(
            color: bg.withOpacity(0.3),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
      ),
      child: Center(
        child: Icon(
          Icons.local_library_rounded,
          size: size * 0.55,
          color: iconColor,
        ),
      ),
    );
  }
}
