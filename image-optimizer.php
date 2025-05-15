<?php
/*
Plugin Name: Auto Image Optimizer
Description: Smart image compression with notifications and stats
Version: 1.0
Author: Your Name
*/

class AutoImageOptimizer {

    private $min_quality = 40;
    private $max_quality = 85;
    private $stats_option = 'aic_compression_stats';

    public function __construct() {
        add_filter('wp_handle_upload', [$this, 'process_upload']);
        add_action('admin_notices', [$this, 'show_notice']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function process_upload($upload) {
        if (in_array($upload['type'], ['image/jpeg', 'image/png'])) {
            $file_path = $upload['file'];
            $original_size = filesize($file_path);
            
            // Get editor instance
            $editor = wp_get_image_editor($file_path);
            
            if (!is_wp_error($editor)) {
                // Auto quality calculation based on file size
                $quality = $this->calculate_quality($original_size);
                
                // Apply compression
                $editor->set_quality($quality);
                $result = $editor->save($file_path);
                
                if ($result) {
                    // Track statistics
                    $compressed_size = filesize($file_path);
                    $this->update_stats($original_size, $compressed_size);
                    
                    // Store notice data
                    set_transient('aic_upload_notice', [
                        'original' => size_format($original_size, 2),
                        'compressed' => size_format($compressed_size, 2),
                        'savings' => $original_size - $compressed_size
                    ], 30);
                }
            }
        }
        return $upload;
    }

    private function calculate_quality($file_size) {
        // Auto quality formula (adjustable)
        $size_mb = $file_size / 1024 / 1024;
        $quality = $this->max_quality - ($size_mb * 4);
        
        return max($this->min_quality, min($this->max_quality, $quality));
    }

    private function update_stats($original, $compressed) {
        $stats = get_option($this->stats_option, [
            'count' => 0,
            'original' => 0,
            'compressed' => 0
        ]);
        
        $stats['count']++;
        $stats['original'] += $original;
        $stats['compressed'] += $compressed;
        
        update_option($this->stats_option, $stats);
    }

    public function show_notice() {
        if ($data = get_transient('aic_upload_notice')) {
            delete_transient('aic_upload_notice');
            ?>
            <div class="notice notice-success is-dismissible">
                <p>✅ Image optimized! 
                    (<?php echo $data['original'] ?> → <?php echo $data['compressed'] ?> 
                    - Saved <?php echo size_format($data['savings'], 2) ?>)
                </p>
            </div>
            <?php
        }
    }

    public function add_admin_menu() {
        add_options_page(
            'Image Compression Stats',
            'Image Stats',
            'manage_options',
            'image-compression-stats',
            [$this, 'stats_page']
        );
    }

    public function stats_page() {
        $stats = get_option($this->stats_option); ?>
        
        <div class="wrap aic-stats">
            <h1>📈 Compression Statistics</h1>
            
            <div class="stats-grid">
                <div class="stat-box">
                    <h3>Total Images Compressed</h3>
                    <div class="stat-number"><?php echo number_format($stats['count'] ?? 0); ?></div>
                </div>
                
                <div class="stat-box">
                    <h3>Total Space Saved</h3>
                    <div class="stat-number">
                        <?php echo size_format(($stats['original'] ?? 0) - ($stats['compressed'] ?? 0), 2); ?>
                    </div>
                </div>
                
                <div class="stat-box">
                    <h3>Original Total Size</h3>
                    <div class="stat-number"><?php echo size_format($stats['original'] ?? 0, 2); ?></div>
                </div>
                
                <div class="stat-box">
                    <h3>Compressed Total Size</h3>
                    <div class="stat-number"><?php echo size_format($stats['compressed'] ?? 0, 2); ?></div>
                </div>
            </div>
        </div>

        <style>
        .aic-stats {
            max-width: 1200px;
            padding: 20px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.5rem;
            margin-top: 2rem;
        }

        .stat-box {
            background: #fff;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            text-align: center;
        }

        .stat-box h3 {
            margin: 0 0 1rem;
            font-size: 1.1em;
            color: #666;
        }

        .stat-number {
            font-size: 2em;
            font-weight: bold;
            color: #2271b1;
        }
        </style>
        <?php
    }

    public function register_settings() {
        register_setting('aic_options', 'aic_min_quality');
        register_setting('aic_options', 'aic_max_quality');
    }
}

new AutoImageOptimizer();