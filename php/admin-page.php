<?php
/**
 * Blockonomics Admin Setup Page
 *
 * Handles the admin setup page UI and form processing
 */
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

function blockonomics_setup_page() {
    $setup = new Blockonomics_Setup();
    // Check if form is submitted
    if (isset($_POST['submit'])) {
        if (!isset($_POST['blockonomics_setup_nonce']) || !wp_verify_nonce($_POST['blockonomics_setup_nonce'], 'blockonomics_setup_action')) {
            wp_die('Security check failed');
        }

        // Store the submitted API key
        $api_key = isset($_POST['blockonomics_api_key']) ? sanitize_text_field($_POST['blockonomics_api_key']) : '';
        if (empty($api_key)) {
            $error_message = 'Please enter your API key';
        } else {
            update_option('blockonomics_api_key', $api_key);
            $result = $setup->validate_api_key($api_key);

            if (isset($result['error'])) {
                $error_message = $result['error'];
            } else {
                // API key is valid and has wallets
                // Check store setup
                $store_result = $setup->check_store_setup();
                if (isset($store_result['needs_store'])) {
                    wp_redirect(admin_url('admin.php?page=blockonomics-setup&step=2'));
                    exit;
                } else if (isset($store_result['success'])) {
                    wp_redirect(admin_url('admin.php?page=blockonomics-setup&step=2&setup_complete=1'));
                    exit;
                } else {
                    $error_message = $store_result['error'];
                }
            }
        }
    }
    // Handle store name submission
    if (isset($_POST['submit_store'])) {
        if (!isset($_POST['blockonomics_setup_nonce']) || !wp_verify_nonce($_POST['blockonomics_setup_nonce'], 'blockonomics_setup_action')) {
            wp_die('Security check failed');
        }

        $store_name = sanitize_text_field($_POST['store_name']);
        $result = $setup->create_store($store_name);

        if (isset($result['error'])) {
            $store_error = $result['error'];
        } else {
            wp_redirect(admin_url('admin.php?page=blockonomics-setup&step=2&setup_complete=1'));
            exit;
        }
    }

    $api_key = get_option('blockonomics_api_key', '');
    $current_step = isset($_GET['step']) ? intval($_GET['step']) : 1;
    if (empty($api_key)) {
        $current_step = 1;
    }

    // Dummy API call to check store name
    $store_name = '';
    if ($current_step == 2) {
        // TODO: Add HTTP GET request to Blockonomics API to fetch store name
        // using the stored api_key
        // Current code only reads from WordPress options
        $store_name = get_option('blockonomics_store_name', '');
        if (empty($store_name)) {
            $needs_store_name = true;
        }
    }
    ?>
    <div class="wrap">
        <div class="bnomics-welcome-header">
            <!-- Empty h1 tag is required for UI consistency -->
            <h1></h1>
        </div>
        <!-- Moved progress bar outside the setup wizard -->
        <div class="bnomics-progress-bar">
            <div class="bnomics-progress-line">
                <div class="bnomics-progress-line-inner" style="width: 100%;"></div>
            </div>
            <div class="bnomics-progress-step active">1</div>
            <div class="bnomics-progress-line">
                <div class="bnomics-progress-line-inner" style="width: <?php echo $current_step >= 2 ? '100%' : '0%'; ?>;"></div>
            </div>
            <div class="bnomics-progress-step <?php echo $current_step >= 2 ? 'active' : ''; ?>">2</div>
            <div class="bnomics-progress-line">
                <div class="bnomics-progress-line-inner" style="width: <?php echo ($current_step == 2 && !isset($needs_store_name)) ? '100%' : '0%'; ?>;"></div>
            </div>
        </div>
        <div class="blockonomics-setup-wizard">
            <?php if ($current_step == 1): ?>
                <div class="bnomics-wizard-heading">
                    <h2>Get started with Blockonomics</h2>
                    <div class="blockonomics-logo">
                        <img src="<?php echo plugins_url('../img/blockonomics_logo_black.svg', __FILE__); ?>" alt="Blockonomics Logo">
                    </div>
                </div>
                <ol>
                    <li><a href="https://www.blockonomics.co/register" target="_blank">Sign up</a> to Blockonomics</li>
                    <li>Add a <a href="https://www.blockonomics.co/dashboard#/wallet" target="_blank">Wallet</a></li>
                    <li>Copy your <a href="https://www.blockonomics.co/dashboard#/store" target="_blank">API Key</a> and click Continue</li>
                </ol>
                <form method="post" action="" id="bnomics-setup-form">
                    <?php wp_nonce_field('blockonomics_setup_action', 'blockonomics_setup_nonce'); ?>
                    <input type="text" 
                           name="blockonomics_api_key"
                           id="blockonomics_api_key"
                           value="<?php echo esc_attr($api_key); ?>" 
                           placeholder="Enter your API Key" 
                           style="width: 100%;">
                    <?php if (isset($error_message)): ?>
                        <p class="bnomics-error-message"><?php echo esc_html($error_message); ?></p>
                    <?php endif; ?>
                    <p class="bnomics-error-message" id="api-key-error" style="display: none;">Please enter your API key</p>
                    <?php submit_button('Continue', 'primary', 'submit', false); ?>
                </form>
                <script>
                    document.getElementById('bnomics-setup-form').addEventListener('submit', function(e) {
                        const apiKey = document.getElementById('blockonomics_api_key').value.trim();
                        const errorElement = document.getElementById('api-key-error');
                        if (apiKey === '') {
                            e.preventDefault();
                            errorElement.style.display = 'block';
                            return false;
                        }
                        errorElement.style.display = 'none';
                    });
                </script>
            <?php else: ?>
                <?php if (isset($needs_store_name) && $needs_store_name): ?>
                    <!-- Store Name Input Screen -->
                    <script>
                        // Fill progress bar before step 2 circle
                        document.addEventListener('DOMContentLoaded', function() {
                            document.querySelector('.bnomics-progress-line:nth-child(3) .bnomics-progress-line-inner').style.width = '100%';
                        });
                    </script>
                    <div class="bnomics-wizard-heading">
                        <h2>Enter Your Store Name</h2>
                        <div class="blockonomics-logo">
                            <img src="<?php echo plugins_url('../img/blockonomics_logo_black.svg', __FILE__); ?>" alt="Blockonomics Logo">
                        </div>
                    </div>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            const progressSteps = document.querySelectorAll('.bnomics-progress-step');
                            progressSteps[1].classList.remove('active');
                        });
                    </script>
                    <form method="post" action="">
                        <?php if (isset($store_error)): ?>
                            <div class="notice notice-error">
                                <p><?php echo esc_html($store_error); ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php wp_nonce_field('blockonomics_setup_action', 'blockonomics_setup_nonce'); ?>
                        <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 20px;">
                            
                            <input type="text" 
                                   name="store_name" 
                                   placeholder="Enter your store name" 
                                   style="flex: 1;"
                                   value="<?php echo esc_attr($store_name); ?>">
                        </div>
                        <?php submit_button('Continue', 'primary', 'submit_store', false); ?>
                    </form>
                <?php else: ?>
                    <!-- Final Success Screen -->
                    <div class="bnomics-wizard-heading">
                        <h3>Congrats! Your store <?php echo esc_html(strtoupper($store_name)); ?> setup is ready!</h3>
                        <div class="blockonomics-logo">
                            <img src="<?php echo plugins_url('../img/blockonomics_logo_black.svg', __FILE__); ?>" alt="Blockonomics Logo">
                        </div>
                    </div>
                    <p><a href="<?php echo admin_url('admin.php?page=wc-settings&tab=checkout&section=blockonomics'); ?>" class="button button-primary">Done</a></p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Register settings
function blockonomics_register_settings() {
    register_setting('blockonomics_options', 'blockonomics_api_key');
}
add_action('admin_init', 'blockonomics_register_settings');
