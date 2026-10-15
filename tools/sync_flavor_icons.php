<?php
// tools/sync_flavor_icons.php - Generate and synchronize Android launcher icons across flavor resource directories

require_once __DIR__ . '/../config/master_db.php';

function generate_branded_png($width, $height, $primary_r, $primary_g, $primary_b, $flavor_code) {
    $raw_data = '';
    $max_radius = ($width / 2) * 0.92;
    $center_x = $width / 2;
    $center_y = $height / 2;
    
    // Distinguish motifs by flavor
    // LIB001: Book shape in center
    // LIB002: Diamond / Emerald hall shape in center
    // SUPERADMIN: Crown / Shield shape in center
    for ($y = 0; $y < $height; $y++) {
        $raw_data .= "\x00"; // Filter type: None
        for ($x = 0; $x < $width; $x++) {
            $dx = $x - $center_x;
            $dy = $y - $center_y;
            $dist = sqrt($dx * $dx + $dy * $dy);
            
            // Outer squircle / rounded badge
            $corner_r = $width * 0.22;
            $in_squircle = false;
            if (abs($dx) <= ($width * 0.45) && abs($dy) <= ($height * 0.45)) {
                $qx = max(0, abs($dx) - ($width * 0.45 - $corner_r));
                $qy = max(0, abs($dy) - ($height * 0.45 - $corner_r));
                if (sqrt($qx * $qx + $qy * $qy) <= $corner_r) {
                    $in_squircle = true;
                }
            }
            
            if ($in_squircle) {
                // Determine motif pixel
                $is_motif = false;
                $motif_r = 255;
                $motif_g = 255;
                $motif_b = 255;
                
                $rel_x = $dx / $width; // -0.5 to 0.5
                $rel_y = $dy / $height;
                
                if ($flavor_code === 'lib001') {
                    // Open Book motif
                    if (abs($rel_y) < 0.22 && abs($rel_x) < 0.26) {
                        // Spine line
                        if (abs($rel_x) < 0.02) {
                            $is_motif = false; // divider
                        } elseif ($rel_y > 0.18) {
                            $is_motif = true; // base
                        } elseif (abs($rel_x) > 0.03 && abs($rel_x) < 0.24 && $rel_y > -0.18) {
                            $is_motif = true;
                        }
                    }
                } elseif ($flavor_code === 'lib002') {
                    // Reading Lamp / Diamond motif
                    $diamond = abs($rel_x * 1.3) + abs($rel_y * 1.1);
                    if ($diamond < 0.28 && $diamond > 0.08) {
                        $is_motif = true;
                    } elseif (abs($rel_x) < 0.08 && abs($rel_y) < 0.08) {
                        $is_motif = true;
                        $motif_r = 253; $motif_g = 224; $motif_b = 71; // Amber Gold Center
                    }
                } else {
                    // Superadmin: Crown / Shield motif
                    // Shield outline
                    if (abs($rel_x) < 0.22 && $rel_y > -0.22 && $rel_y < 0.22) {
                        $shield_bound = 0.22 - max(0, ($rel_y - 0.05) * 1.2);
                        if (abs($rel_x) <= $shield_bound) {
                            // Keyhole or star inside
                            if (abs($rel_x) < 0.04 && $rel_y > -0.05 && $rel_y < 0.12) {
                                $is_motif = false;
                            } elseif (sqrt($rel_x * $rel_x + ($rel_y + 0.06) * ($rel_y + 0.06)) < 0.06) {
                                $is_motif = false;
                            } else {
                                $is_motif = true;
                                $motif_r = 251; $motif_g = 191; $motif_b = 36; // Golden Shield
                            }
                        }
                    }
                }
                
                if ($is_motif) {
                    $raw_data .= chr($motif_r) . chr($motif_g) . chr($motif_b) . "\xFF";
                } else {
                    // Background badge with slight border gradient
                    $edge_dist = max(abs($dx), abs($dy)) / ($width * 0.45);
                    $factor = max(0.7, 1.0 - ($edge_dist * 0.25));
                    $bg_r = (int)($primary_r * $factor);
                    $bg_g = (int)($primary_g * $factor);
                    $bg_b = (int)($primary_b * $factor);
                    $raw_data .= chr($bg_r) . chr($bg_g) . chr($bg_b) . "\xFF";
                }
            } else {
                $raw_data .= "\x00\x00\x00\x00"; // Transparent outside
            }
        }
    }
    
    $ihdr = pack('N', $width) . pack('N', $height) . "\x08\x06\x00\x00\x00";
    $ihdr_chunk = 'IHDR' . $ihdr;
    $ihdr_crc = pack('N', crc32($ihdr_chunk));
    
    $idat_data = gzcompress($raw_data, 9);
    $idat_chunk = 'IDAT' . $idat_data;
    $idat_crc = pack('N', crc32($idat_chunk));
    
    $iend_chunk = 'IEND';
    $iend_crc = pack('N', crc32($iend_chunk));
    
    return "\x89PNG\r\n\x1a\n" .
           pack('N', strlen($ihdr)) . $ihdr_chunk . $ihdr_crc .
           pack('N', strlen($idat_data)) . $idat_chunk . $idat_crc .
           pack('N', 0) . $iend_chunk . $iend_crc;
}

function sync_all_flavor_launcher_icons($master_pdo = null) {
    if (!$master_pdo) {
        $master_pdo = get_master_pdo();
    }
    
    $densities = [
        'mipmap-mdpi' => 48,
        'mipmap-hdpi' => 72,
        'mipmap-xhdpi' => 96,
        'mipmap-xxhdpi' => 144,
        'mipmap-xxxhdpi' => 192,
    ];
    
    $flavors = [
        'lib001' => [
            'code' => 'LIB001',
            'color' => [29, 78, 216], // #1D4ED8
        ],
        'lib002' => [
            'code' => 'LIB002',
            'color' => [16, 185, 129], // #10B981
        ],
        'superadmin' => [
            'code' => 'SUPERADMIN',
            'color' => [124, 58, 237], // #7C3AED
        ]
    ];
    
    $base_src_dir = realpath(__DIR__ . '/../flutter_app/android/app/src');
    if (!$base_src_dir) {
        $base_src_dir = __DIR__ . '/../flutter_app/android/app/src';
    }
    
    $results = [];
    
    foreach ($flavors as $flavor_name => $cfg) {
        $code = $cfg['code'];
        $rgb = $cfg['color'];
        
        // Check if there is an uploaded app icon in DB
        $custom_icon_data = null;
        if ($code !== 'SUPERADMIN') {
            $stmt = $master_pdo->prepare("SELECT app_icon_url, primary_color FROM library_branding WHERE library_code = ?");
            $stmt->execute([$code]);
            $b = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($b && !empty($b['app_icon_url'])) {
                $icon_url = $b['app_icon_url'];
                // Check local uploads path
                $filename = basename(parse_url($icon_url, PHP_URL_PATH));
                $local_path = realpath(__DIR__ . '/../uploads/icons/' . $filename);
                if ($local_path && file_exists($local_path)) {
                    $custom_icon_data = @file_get_contents($local_path);
                }
            }
            if ($b && !empty($b['primary_color'])) {
                $hex = ltrim($b['primary_color'], '#');
                if (strlen($hex) === 6) {
                    $rgb = [
                        hexdec(substr($hex, 0, 2)),
                        hexdec(substr($hex, 2, 2)),
                        hexdec(substr($hex, 4, 2))
                    ];
                }
            }
        }
        
        $flavor_res_dir = $base_src_dir . '/' . $flavor_name . '/res';
        $flavor_results = [];
        
        foreach ($densities as $folder => $size) {
            $target_dir = $flavor_res_dir . '/' . $folder;
            if (!file_exists($target_dir)) {
                @mkdir($target_dir, 0777, true);
            }
            @chmod($target_dir, 0777);
            
            $target_file = $target_dir . '/ic_launcher.png';
            
            // If custom uploaded icon is available and matches this exact density
            $use_custom = false;
            if ($custom_icon_data !== null && strlen($custom_icon_data) > 0) {
                $c_info = @getimagesizefromstring($custom_icon_data);
                if ($c_info && $c_info[0] === $size && $c_info[1] === $size) {
                    $use_custom = true;
                }
            }
            if ($use_custom) {
                file_put_contents($target_file, $custom_icon_data);
            } else {
                // Generate branded density-accurate icon for this flavor
                $png_data = generate_branded_png($size, $size, $rgb[0], $rgb[1], $rgb[2], $flavor_name);
                file_put_contents($target_file, $png_data);
            }
            
            $sha256 = hash_file('sha256', $target_file);
            $flavor_results[$folder] = [
                'path' => $target_file,
                'size' => filesize($target_file),
                'sha256' => $sha256
            ];
        }
        
        $results[$flavor_name] = $flavor_results;
    }
    
    return $results;
}

if (basename(__FILE__) == basename($_SERVER['PHP_SELF'] ?? '')) {
    echo "Synchronizing Android flavor launcher icons...\n";
    $results = sync_all_flavor_launcher_icons();
    foreach ($results as $flavor => $folders) {
        echo "Flavor [$flavor]:\n";
        foreach ($folders as $folder => $info) {
            echo "  $folder: {$info['size']} bytes, SHA256: " . substr($info['sha256'], 0, 16) . "...\n";
        }
    }
    echo "Launcher icon synchronization complete.\n";
}
