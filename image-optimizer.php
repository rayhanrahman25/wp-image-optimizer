<?php
/*
Plugin Name: Auto Image Optimizer
Description: Smart image compression with notifications and stats
Version: 1.1
Author: Your Name
*/

class AutoImageOptimizer {

    private $min_quality = 40;
    private $max_quality = 85;
    private $stats_option = 'aic_compression_stats';
    private $bulk_option = 'aic_bulk_process';

    public function __construct() {
        add_filter('wp_handle_upload', [$this, 'process_upload']);
        add_action('admin_notices', [$this, 'show_notice']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        
        // Bulk processing AJAX handlers
        add_action('wp_ajax_aic_bulk_init', [$this, 'ajax_bulk_init']);
        add_action('wp_ajax_aic_bulk_process', [$this, 'ajax_bulk_process']);
        add_action('wp_ajax_aic_get_progress', [$this, 'ajax_get_progress']);
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

    public function register_settings() {
        register_setting('aic_options', 'aic_min_quality');
        register_setting('aic_options', 'aic_max_quality');
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

            <div class="bulk-optimization-section">
                <h2>Bulk Optimization</h2>
                <div id="bulk-progress" style="display:none;">
                    <div class="progress-bar">
                        <div class="progress-fill"></div>
                    </div>
                    <p class="progress-text">Processing... <span class="current">0</span>/<span class="total">0</span></p>
                    <p class="progress-status"></p>
                </div>
                <button id="start-bulk" class="button button-primary">Start Bulk Optimization</button>
                <p class="description">Optimize all existing images in your media library</p>
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

        .bulk-optimization-section {
            margin-top: 3rem;
            padding: 2rem;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }

        .progress-bar {
            width: 100%;
            height: 20px;
            background: #f1f1f1;
            border-radius: 10px;
            margin: 1rem 0;
        }

        .progress-fill {
            height: 100%;
            background: #2271b1;
            border-radius: 10px;
            transition: width 0.3s ease;
            width: 0%;
        }

        .progress-text {
            font-weight: bold;
            margin: 0.5rem 0;
        }
        </style>

        <script>
        jQuery(document).ready(function($) {
            $('#start-bulk').click(function() {
                $('#bulk-progress').show();
                $(this).prop('disabled', true);

                // Initialize bulk process
                $.post(ajaxurl, {
                    action: 'aic_bulk_init',
                    _wpnonce: '<?php echo wp_create_nonce('aic-bulk-nonce'); ?>'
                }, function(response) {
                    processNextImage();
                });
            });

            function processNextImage() {
                $.post(ajaxurl, {
                    action: 'aic_bulk_process',
                    _wpnonce: '<?php echo wp_create_nonce('aic-bulk-nonce'); ?>'
                }, function(response) {
                    if (response.data.done) {
                        updateProgress(response.data);
                        $('.progress-status').html('Bulk optimization complete!');
                        $('#start-bulk').prop('disabled', false);
                    } else {
                        updateProgress(response.data);
                        processNextImage();
                    }
                }).fail(function() {
                    $('.progress-status').html('Error processing images');
                    $('#start-bulk').prop('disabled', false);
                });
            }

            function updateProgress(data) {
                $('.progress-fill').css('width', data.percentage + '%');
                $('.current').text(data.processed);
                $('.total').text(data.total);
                $('.progress-status').text(data.current_file || '');
            }
        });
        </script>
        <?php
    }

    public function ajax_bulk_init() {
        check_ajax_referer('aic-bulk-nonce');
        
        $image_ids = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);

        update_option($this->bulk_option, [
            'total' => count($image_ids),
            'processed' => 0,
            'current' => 0,
            'images' => $image_ids
        ]);

        wp_send_json_success(['total' => count($image_ids)]);
    }

    public function ajax_bulk_process() {
        check_ajax_referer('aic-bulk-nonce');
        
        $bulk = get_option($this->bulk_option);
        if (!$bulk || $bulk['current'] >= $bulk['total']) {
            wp_send_json_success(['done' => true]);
        }

        $image_id = $bulk['images'][$bulk['current']];
        $file_path = get_attached_file($image_id);

        if ($file_path && file_exists($file_path)) {
            $original_size = filesize($file_path);
            $editor = wp_get_image_editor($file_path);

            if (!is_wp_error($editor)) {
                $quality = $this->calculate_quality($original_size);
                $editor->set_quality($quality);
                $result = $editor->save($file_path);

                if ($result) {
                    $compressed_size = filesize($file_path);
                    $this->update_stats($original_size, $compressed_size);
                }
            }
        }

        $bulk['current']++;
        $bulk['processed']++;
        update_option($this->bulk_option, $bulk);

        wp_send_json_success([
            'done' => $bulk['current'] >= $bulk['total'],
            'total' => $bulk['total'],
            'processed' => $bulk['processed'],
            'percentage' => round(($bulk['processed'] / $bulk['total']) * 100),
            'current_file' => basename($file_path)
        ]);
    }

    public function ajax_get_progress() {
        check_ajax_referer('aic-bulk-nonce');
        
        $bulk = get_option($this->bulk_option);
        wp_send_json_success([
            'total' => $bulk['total'] ?? 0,
            'processed' => $bulk['processed'] ?? 0,
            'percentage' => $bulk ? round(($bulk['processed'] / $bulk['total']) * 100) : 0
        ]);
    }
}

new AutoImageOptimizer();