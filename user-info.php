<?php
/*
 * Plugin Name: WP User Manager
 * Plugin URI: https://www.manco.com.tw/plugins/
 * Description: 資訊安全帳號管理。
 * Version: 1.0.0
 * Author: Manco
 * Author URI: https://www.manco.com.tw
 * License: GPLv2 or later
 * Text Domain: WP User Manager
 * Domain Path: /languages
 * 文本域: WP User Manager
 */

// Load plugin text domain for translations
add_action('plugins_loaded', 'user_info_load_textdomain');
function user_info_load_textdomain() {
    load_plugin_textdomain('user-info', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}

// 添加主選單
add_action('admin_menu', 'user_info_menu');
function user_info_menu() {
    add_menu_page(__('User Info', 'user-info'), __('User Info', 'user-info'), 'manage_options', 'user_info', 'display_user_info_page', 'none');
    
    // 添加子選單
    add_submenu_page('user_info', __('User Information', 'user-info'), __('User Information', 'user-info'), 'manage_options', 'user_info', 'display_user_info_page');
    add_submenu_page('user_info', __('Locked Accounts', 'user-info'), __('Locked Accounts', 'user-info'), 'manage_options', 'locked_accounts', 'display_locked_accounts_page');
    add_submenu_page('user_info', __('Settings', 'user-info'), __('Settings', 'user-info'), 'manage_options', 'user_info_settings', 'display_user_info_settings_page');

}

add_action('admin_head', 'my_custom_admin_menu_icon');
function my_custom_admin_menu_icon() {
  echo '<style>
    #adminmenu #toplevel_page_user_info div.wp-menu-image:before {
      content: "\f110";  /* 這是一個 Dashicons 的例子 */
      font-family: "dashicons";
      font-size: 20px;
    }
  </style>';
}
// Record last login time
add_action('wp_login', 'record_last_login_time', 10, 2);
function record_last_login_time($user_login, $user) {
	update_user_meta($user->ID, 'last_login_time', current_time('mysql'));
	update_user_meta($user->ID, 'remaining_attempts', 3);
}

// Record password change time
add_action('after_password_reset', 'record_password_reset_time', 10, 2);
function record_password_reset_time($user, $new_pass) {
    record_password_change($user->ID);
}

// Record password change time using profile_update hook
add_action('profile_update', 'record_password_change_on_profile_update', 10, 2);
function record_password_change_on_profile_update($user_id, $old_user_data) {
    $user = get_userdata($user_id);
    if ($user->user_pass != $old_user_data->user_pass) {
        record_password_change($user_id);
    }
}

// Common function to record password change
function record_password_change($user_id) {
    $current_time = current_time('mysql');
    $history = get_user_meta($user_id, 'password_change_history', true);
    if (!is_array($history)) {
        $history = [];
    }
    array_unshift($history, $current_time);
    $history = array_slice($history, 0, 3); // Keep only the last 3 records
    update_user_meta($user_id, 'password_change_history', $history);
}


// Add account status column in user table
add_filter('manage_users_columns', 'add_account_status_column');
function add_account_status_column($columns) {
    $columns['account_status'] = __('Account Status', 'user-info');
    return $columns;
}

add_action('manage_users_custom_column', 'show_account_status', 10, 3);
function show_account_status($value, $column_name, $user_id) {
    if ('account_status' == $column_name) {
        $status = get_user_meta($user_id, 'account_status', true);
        return $status ? __('Enabled', 'user-info') : __('Disabled', 'user-info');
    }
    return $value;
}

add_action('wp_ajax_toggle_account_status', 'toggle_account_status');
function toggle_account_status() {
    $user_id = intval($_POST['user_id']);
    $status = get_user_meta($user_id, 'account_status', true) ? 0 : 1;
    update_user_meta($user_id, 'account_status', $status);
    echo $status ? __('Enabled', 'user-info') : __('Disabled', 'user-info');
    wp_die();
}

add_action('validate_password_reset', 'check_previous_passwords', 20, 2);
function check_previous_passwords($errors, $user) {
    $new_pass = $_POST['pass1'];
    if (empty($new_pass)) {
        return;
    }

    $password_history = get_user_meta($user->ID, 'password_change_history', true);
    if (!is_array($password_history)) {
        $password_history = [];
    }

    foreach ($password_history as $old_pass) {
        if (wp_check_password($new_pass, $old_pass, $user->ID)) {
            $errors->add('password_used_before', __('您不能使用之前用過的密碼。', 'user-info'));
            return;
        }
    }
}

// 跟踪登录失败
add_action('wp_login_failed', 'track_login_failures');
function track_login_failures($username) {
    $user = get_user_by('login', $username);
    if ($user) {
        $max_attempts = get_option('max_attempts', 3);  // 从设置中获取最大尝试次数
        $remaining_attempts = get_user_meta($user->ID, 'remaining_attempts', true);
        $remaining_attempts = empty($remaining_attempts) ? $max_attempts : $remaining_attempts; // 使用设置中的最大尝试次数
        $remaining_attempts = $remaining_attempts - 1;
        update_user_meta($user->ID, 'remaining_attempts', $remaining_attempts);

        if ($remaining_attempts <= 0) {
		// 在这里设置锁定时间
		$lockout_time = get_option('lockout_time', 15);  // 从设置中获取锁定时间
		update_user_meta($user->ID, 'lockout_time', current_time('timestamp'));

          //    update_user_meta($user->ID, 'lockout_time', current_time('timestamp') + ($lockout_time * MINUTE_IN_SECONDS)); // 使用设置中的锁定时间
        }
    }
}

add_filter('wp_login_errors', 'show_remaining_attempts', 10, 2);
function show_remaining_attempts($errors, $redirect_to) {
    // 檢查 'log' 是否在 $_POST 數組中設定
    if (isset($_POST['log'])) {
        $username = trim($_POST['log']);  // 使用 trim() 移除任何多餘的空格
        $user = get_user_by('login', $username);

        if ($user) {
            $remaining_attempts = get_user_meta($user->ID, 'remaining_attempts', true);
            $lockout_time = get_user_meta($user->ID, 'lockout_time', true);

            // 只有在帳戶未被鎖定的情況下才顯示剩餘嘗試次數
            if (!$lockout_time && $remaining_attempts > 0) {
                $errors->add('remaining_attempts_error', sprintf(__('密碼錯誤，你剩餘 %d 次', 'your-text-domain'), $remaining_attempts));
            }
        }
    }

    return $errors;
}

add_filter('authenticate', 'check_account_lock', 30, 3);
function check_account_lock($user, $username, $password) {
    if ($username && $user instanceof WP_User) {
        $max_attempts = get_option('max_attempts', 3);  // 默認值為 3
        $lockout_time_minutes = get_option('lockout_time', 15);  // 默認值為 15 分鐘

        $lockout_time = get_user_meta($user->ID, 'lockout_time', true);
        $remaining_attempts = get_user_meta($user->ID, 'remaining_attempts', true);

        if ($lockout_time) {
            $current_time = current_time('timestamp');
            $unlock_time = $lockout_time + ($lockout_time_minutes * MINUTE_IN_SECONDS);
            $time_left = ceil(($unlock_time - $current_time) / MINUTE_IN_SECONDS); // 轉換為分鐘並四捨五入
            if ($current_time < $unlock_time) {
                return new WP_Error('account_locked', sprintf(__('您的帳號已被鎖定，將在 %s 分鐘後解鎖。', 'user-info'), $time_left));
            } else {
                // 解鎖帳戶並重置剩餘嘗試次數
                delete_user_meta($user->ID, 'lockout_time');
                update_user_meta($user->ID, 'remaining_attempts', $max_attempts);
            }
        } else {
            if ($remaining_attempts <= 0) {
                return new WP_Error('account_locked', __('您的帳號已被鎖定。', 'user-info'));
            }
        }
    }
    return $user;
}

    // 管理者解鎖
add_action('wp_ajax_admin_unlock_account', 'admin_unlock_account');
function admin_unlock_account() {
    $user_id = intval($_POST['user_id']);
    if (current_user_can('manage_options')) {
        delete_user_meta($user_id, 'lockout_time');
        delete_user_meta($user_id, 'login_failures');
        echo '解鎖成功';
    } else {
        echo '您沒有權限進行此操作';
    }
    wp_die();
}



add_filter('wp_authenticate_user', 'check_account_status', 10, 2);
function check_account_status($user, $password) {
    if (is_wp_error($user)) {
        return $user;
    }

    $status = get_user_meta($user->ID, 'account_status', true);
    if (!$status) {
        return new WP_Error('account_disabled', __('您的帳戶已被停用。', 'user-info'));
    }

    return $user;
}



// Admin page content
function display_user_info_page() {
    echo '<div class="wrap">';
    echo '<h1>' . __('User Information', 'user-info') . '</h1>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>' . __('Username', 'user-info') . '</th><th>' . __('Display Name', 'user-info') . '</th><th>' . __('Last Login', 'user-info') . '</th><th>' . __('Account Status', 'user-info') . '</th><th>' . __('Last Password Change', 'user-info') . '</th></tr></thead>';
    echo '<tbody>';

    $users = get_users();
    foreach ($users as $user) {
        $username = $user->user_login;
        $display_name = $user->display_name;
        $last_login = get_user_meta($user->ID, 'last_login_time', true);
        $last_login = $last_login ? date("Y-m-d H:i:s", strtotime($last_login)) : 'N/A';
        $account_status = get_user_meta($user->ID, 'account_status', true) ? __('Enabled', 'user-info') : __('Disabled', 'user-info');
        $password_history = get_user_meta($user->ID, 'password_change_history', true);
        $last_password_change = $password_history ? reset($password_history) : 'N/A';
        echo "<tr><td>{$username}</td><td>{$display_name}</td><td>{$last_login}</td><td><button class='toggle-status' data-user-id='" . $user->ID . "'>{$account_status}</button></td><td><span class='last-change'>{$last_password_change}</span>";
        if (is_array($password_history) && count($password_history) > 1) {
            echo '<button class="toggle-history">' . __('Show More', 'user-info') . '</button>';
            echo '<div class="history hidden">';
            foreach ($password_history as $time) {
                echo "<p>{$time}</p>";
            }
            echo '</div>';
        }
        echo '</td></tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '<style>
        .history.hidden { display: none; }
    </style>';
    echo '<script>
    document.addEventListener("DOMContentLoaded", function() {
        // 這是您現有的代碼，用於切換密碼歷史顯示
        document.querySelectorAll(".toggle-history").forEach(function(button) {
            button.addEventListener("click", function() {
                var history = button.nextElementSibling;
                var lastChange = button.previousElementSibling;
                if (history.classList.contains("hidden")) {
                    history.classList.remove("hidden");
                    lastChange.classList.add("hidden");
                    button.textContent = "' . __('Hide', 'user-info') . '";
                } else {
                    history.classList.add("hidden");
                    lastChange.classList.remove("hidden");
                    button.textContent = "' . __('Show More', 'user-info') . '";
                }
            });
        });

        // 這是新添加的代碼，用於切換帳戶狀態
        document.querySelectorAll(".toggle-status").forEach(function(button) {
            button.addEventListener("click", function() {
                var userId = button.getAttribute("data-user-id");
                var data = {
                    "action": "toggle_account_status",
                    "user_id": userId
                };
                jQuery.post(ajaxurl, data, function(response) {
                    button.textContent = response;
                });
            });
        });
    });
</script>';

}

// Admin page content for locked accounts
function display_locked_accounts_page() {
    echo '<div class="wrap">';
    echo '<h1>' . __('Locked Accounts', 'user-info') . '</h1>';
    echo '<table class="wp-list-table widefat fixed striped">';
    echo '<thead><tr><th>' . __('Username', 'user-info') . '</th><th>' . __('Locked IP', 'user-info') . '</th><th>' . __('Actions', 'user-info') . '</th></tr></thead>';
    echo '<tbody>';

    $users = get_users();
    foreach ($users as $user) {
        $lockout_time = get_user_meta($user->ID, 'lockout_time', true);
        $locked_ip = get_user_meta($user->ID, 'locked_ip', true); // Assuming you are saving the locked IP

        if ($lockout_time) {
            echo "<tr><td>{$user->user_login}</td><td>{$locked_ip}</td><td><button class='unlock-account' data-user-id='{$user->ID}'>" . __('Unlock', 'user-info') . "</button></td></tr>";
        }
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
    echo '<script>
        jQuery(document).ready(function($) {
            $(".unlock-account").click(function() {
                var userId = $(this).data("user-id");
                var data = {
                    "action": "admin_unlock_account",
                    "user_id": userId
                };
                $.post(ajaxurl, data, function(response) {
                    alert(response);
                    location.reload();
                });
            });
        });
    </script>';
}

function display_user_info_settings_page() {
    // 檢查用戶是否有權限
    if (!current_user_can('manage_options')) {
        return;
    }

    // 保存設定
    if (isset($_POST['max_attempts']) && isset($_POST['lockout_time'])) {
        update_option('max_attempts', intval($_POST['max_attempts']));
        update_option('lockout_time', intval($_POST['lockout_time']));
    }

    // 獲取當前設定
    $max_attempts = get_option('max_attempts', 3);  // 默認值為 3
    $lockout_time = get_option('lockout_time', 15);  // 默認值為 15 分鐘

    ?>
    <div class="wrap">
        <h1><?= esc_html(get_admin_page_title()); ?></h1>
        <form method="post">
	    <table class="form-table">
               <tr>
                   <th scope="row"><label for="max_attempts"><?= __('max_attempts', 'user-info'); ?></label></th>
                   <td><input type="number" id="max_attempts" name="max_attempts" value="<?= $max_attempts; ?>"></td>
              </tr>
              <tr>
                    <th scope="row"><label for="lockout_time"><?= __('lockout_time', 'user-info'); ?></label></th>
                    <td><input type="number" id="lockout_time" name="lockout_time" value="<?= $lockout_time; ?>"></td>
                  </tr>
	    </table>
            <input type="submit" value="<?= __('Save', 'user-info'); ?>" class="button button-primary">
        </form>
    </div>
    <?php
}

