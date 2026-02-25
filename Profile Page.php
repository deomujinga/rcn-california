<?php
/**
 * Frontend Profile Page
 * Shortcode: [disciple_profile]
 * 
 * Allows users to update their profile information including:
 * - First name, Last name
 * - Email address
 * - Phone number
 * - Password
 * - Profile picture (direct upload)
 */

if (!defined('ABSPATH')) {
    exit;
}

// Register AJAX handlers
add_action('wp_ajax_update_disciple_profile', 'handle_update_disciple_profile');
add_action('wp_ajax_upload_profile_picture', 'handle_upload_profile_picture');
add_action('wp_ajax_remove_profile_picture', 'handle_remove_profile_picture');
add_action('wp_ajax_set_avatar_from_media', 'handle_set_avatar_from_media');

/**
 * Grant upload_files capability to disciple role
 * This allows disciples to upload their own profile pictures
 * Run once to add the capability to the role
 */
add_action('init', 'rcn_grant_disciple_upload_capability');
function rcn_grant_disciple_upload_capability() {
    // Only run once - check if we've already done this
    if (get_option('rcn_disciple_upload_cap_added')) {
        return;
    }
    
    $disciple_role = get_role('disciple');
    if ($disciple_role) {
        $disciple_role->add_cap('upload_files');
        update_option('rcn_disciple_upload_cap_added', true);
    }
}

/**
 * Temporarily grant upload capability during avatar upload
 * This ensures the upload works even if the role wasn't updated
 */
add_filter('user_has_cap', 'rcn_temp_grant_upload_for_avatar', 10, 4);
function rcn_temp_grant_upload_for_avatar($allcaps, $caps, $args, $user) {
    // Only apply during our specific AJAX actions
    if (!defined('DOING_AJAX') || !DOING_AJAX) {
        return $allcaps;
    }
    
    $action = isset($_POST['action']) ? sanitize_text_field($_POST['action']) : '';
    $allowed_actions = ['upload_profile_picture', 'set_avatar_from_media'];
    
    if (!in_array($action, $allowed_actions)) {
        return $allcaps;
    }
    
    // Verify nonce before granting capability
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'disciple_profile_nonce')) {
        return $allcaps;
    }
    
    // Check if user has disciple role
    if (!in_array('disciple', (array) $user->roles)) {
        return $allcaps;
    }
    
    // Grant upload_files capability for this request
    $allcaps['upload_files'] = true;
    
    return $allcaps;
}

/**
 * Sync cover image to avatar for membership plugins
 * When a user uploads a cover image, also set it as their avatar
 */

// Ultimate Member - sync cover photo to avatar
add_action('um_after_upload_db_meta_cover_photo', 'rcn_sync_cover_to_avatar_um', 10, 1);
function rcn_sync_cover_to_avatar_um($user_id) {
    $cover_photo = get_user_meta($user_id, 'cover_photo', true);
    if (!empty($cover_photo)) {
        // Get the cover photo URL
        $upload_dir = wp_upload_dir();
        $cover_url = $upload_dir['baseurl'] . '/ultimatemember/' . $user_id . '/' . $cover_photo;
        
        // Set as avatar using Simple Local Avatars format
        rcn_set_avatar_from_url($user_id, $cover_url);
    }
}

// Ultimate Member - also hook into profile photo upload to sync to Simple Local Avatars
add_action('um_after_upload_db_meta_profile_photo', 'rcn_sync_um_photo_to_sla', 10, 1);
function rcn_sync_um_photo_to_sla($user_id) {
    $profile_photo = get_user_meta($user_id, 'profile_photo', true);
    if (!empty($profile_photo)) {
        $upload_dir = wp_upload_dir();
        $photo_url = $upload_dir['baseurl'] . '/ultimatemember/' . $user_id . '/' . $profile_photo;
        rcn_set_avatar_from_url($user_id, $photo_url);
    }
}

// BuddyPress/BuddyBoss - sync cover image to avatar
add_action('bp_members_cover_image_uploaded', 'rcn_sync_cover_to_avatar_bp', 10, 3);
function rcn_sync_cover_to_avatar_bp($user_id, $name, $cover_url) {
    if (!empty($cover_url)) {
        rcn_set_avatar_from_url($user_id, $cover_url);
    }
}

// Generic helper to set avatar from URL
function rcn_set_avatar_from_url($user_id, $image_url) {
    if (empty($user_id) || empty($image_url)) {
        return false;
    }
    
    // Try to get attachment ID if it's a media library image
    $attachment_id = attachment_url_to_postid($image_url);
    
    if ($attachment_id) {
        // Use Simple Local Avatars plugin method if available
        if (class_exists('Simple_Local_Avatars')) {
            global $simple_local_avatars;
            if ($simple_local_avatars && method_exists($simple_local_avatars, 'assign_new_user_avatar')) {
                $simple_local_avatars->assign_new_user_avatar($attachment_id, $user_id);
                return true;
            }
        }
        
        // Fallback: set meta directly
        $full_url = wp_get_attachment_url($attachment_id);
        $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
    } else {
        // Use the URL directly if not in media library
        $full_url = $image_url;
        $thumbnail_url = $image_url;
    }
    
    // Set Simple Local Avatars meta
    $simple_local_avatar = array(
        'full'     => $full_url,
        '96'       => $thumbnail_url,
        '64'       => $thumbnail_url,
        '32'       => $thumbnail_url,
    );
    if ($attachment_id) {
        $simple_local_avatar['media_id'] = $attachment_id;
    }
    update_user_meta($user_id, 'simple_local_avatar', $simple_local_avatar);
    
    // Also set our custom meta
    update_user_meta($user_id, 'profile_picture_url', $thumbnail_url);
    if ($attachment_id) {
        update_user_meta($user_id, 'profile_picture_id', $attachment_id);
    }
    
    return true;
}

// Hook into user meta updates to catch any cover image changes
add_action('updated_user_meta', 'rcn_sync_on_meta_update', 10, 4);
add_action('added_user_meta', 'rcn_sync_on_meta_update', 10, 4);
function rcn_sync_on_meta_update($meta_id, $user_id, $meta_key, $meta_value) {
    // List of meta keys that might be cover images
    $cover_meta_keys = array('cover_photo', 'cover_image', '_cover_photo', 'bp_cover_image');
    
    if (in_array($meta_key, $cover_meta_keys) && !empty($meta_value)) {
        // Try to determine the URL
        if (is_numeric($meta_value)) {
            // It's an attachment ID
            $image_url = wp_get_attachment_url($meta_value);
        } elseif (filter_var($meta_value, FILTER_VALIDATE_URL)) {
            // It's a URL
            $image_url = $meta_value;
        } else {
            // It might be a filename - try to construct URL
            $upload_dir = wp_upload_dir();
            $image_url = $upload_dir['baseurl'] . '/' . $meta_value;
        }
        
        if (!empty($image_url)) {
            rcn_set_avatar_from_url($user_id, $image_url);
        }
    }
}

/**
 * Handle setting avatar from WordPress Media Library
 * Integrates with Simple Local Avatars plugin
 */
function handle_set_avatar_from_media() {
    if (!check_ajax_referer('disciple_profile_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $attachment_id = intval($_POST['attachment_id'] ?? 0);
    if (!$attachment_id) {
        wp_send_json_error(['message' => 'No image selected.']);
    }

    // Verify it's a valid image attachment
    if (!wp_attachment_is_image($attachment_id)) {
        wp_send_json_error(['message' => 'Selected file is not a valid image.']);
    }

    $user_id = get_current_user_id();

    // Get image URLs for different sizes
    $full_url = wp_get_attachment_url($attachment_id);
    $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
    $medium_url = wp_get_attachment_image_url($attachment_id, 'medium');
    
    if (!$full_url) {
        wp_send_json_error(['message' => 'Could not get image URL.']);
    }

    // Try to use Simple Local Avatars plugin's method if available
    if (class_exists('Simple_Local_Avatars')) {
        // Get the plugin instance
        global $simple_local_avatars;
        if ($simple_local_avatars && method_exists($simple_local_avatars, 'assign_new_user_avatar')) {
            $simple_local_avatars->assign_new_user_avatar($attachment_id, $user_id);
        } else {
            // Fallback: set the meta directly in Simple Local Avatars format
            $simple_local_avatar = array(
                'media_id' => $attachment_id,
                'full'     => $full_url,
                '96'       => $thumbnail_url ?: $full_url,
                '64'       => $thumbnail_url ?: $full_url,
                '32'       => $thumbnail_url ?: $full_url,
            );
            update_user_meta($user_id, 'simple_local_avatar', $simple_local_avatar);
        }
    } else {
        // Simple Local Avatars not installed - use our own meta format
        // This still follows the SLA format for future compatibility
        $simple_local_avatar = array(
            'media_id' => $attachment_id,
            'full'     => $full_url,
            '96'       => $thumbnail_url ?: $full_url,
            '64'       => $thumbnail_url ?: $full_url,
            '32'       => $thumbnail_url ?: $full_url,
        );
        update_user_meta($user_id, 'simple_local_avatar', $simple_local_avatar);
    }

    // Also set our custom meta for backward compatibility with existing code
    update_user_meta($user_id, 'profile_picture_id', $attachment_id);
    update_user_meta($user_id, 'profile_picture_url', $thumbnail_url ?: $full_url);

    // Return a suitable display URL
    $display_url = $medium_url ?: $thumbnail_url ?: $full_url;

    wp_send_json_success([
        'message' => 'Profile picture updated!',
        'image_url' => $display_url
    ]);
}

/**
 * Handle profile picture upload
 * Integrates with Simple Local Avatars plugin using its native hooks
 */
function handle_upload_profile_picture() {
    // Verify nonce
    if (!check_ajax_referer('disciple_profile_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    if (empty($_FILES['profile_picture'])) {
        wp_send_json_error(['message' => 'No file uploaded.']);
    }

    $file = $_FILES['profile_picture'];
    $user_id = get_current_user_id();

    // Validate file type
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($file['type'], $allowed_types)) {
        wp_send_json_error(['message' => 'Invalid file type. Please upload a JPG, PNG, GIF, or WebP image.']);
    }

    // Validate file size (max 2MB)
    $max_size = 2 * 1024 * 1024; // 2MB
    if ($file['size'] > $max_size) {
        wp_send_json_error(['message' => 'File too large. Maximum size is 2MB.']);
    }

    // Include WordPress media/user handling
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    require_once(ABSPATH . 'wp-admin/includes/file.php');
    require_once(ABSPATH . 'wp-admin/includes/media.php');
    require_once(ABSPATH . 'wp-admin/includes/user.php');

    // === Method 1: Try Simple Local Avatars native processing ===
    // Copy file to the field name SLA expects
    $_FILES['simple-local-avatar'] = $_FILES['profile_picture'];
    
    // Trigger the profile update hooks that SLA listens to
    // This lets the plugin handle the upload natively
    do_action('personal_options_update', $user_id);
    do_action('edit_user_profile_update', $user_id);
    
    // Check if SLA processed the avatar
    $sla_avatar = get_user_meta($user_id, 'simple_local_avatar', true);
    
    if (!empty($sla_avatar) && !empty($sla_avatar['full'])) {
        // SLA handled it - get the URL it set
        $image_url = !empty($sla_avatar['96']) ? $sla_avatar['96'] : $sla_avatar['full'];
        
        // Also store in our custom meta for backward compatibility
        if (!empty($sla_avatar['media_id'])) {
            update_user_meta($user_id, 'profile_picture_id', $sla_avatar['media_id']);
        }
        update_user_meta($user_id, 'profile_picture_url', $image_url);
        
        wp_send_json_success([
            'message' => 'Profile picture updated!',
            'image_url' => $image_url
        ]);
        return;
    }
    
    // === Method 2: Fallback - manual upload if SLA didn't process ===
    // Delete old profile picture if exists
    $old_attachment_id = get_user_meta($user_id, 'profile_picture_id', true);
    if ($old_attachment_id) {
        wp_delete_attachment($old_attachment_id, true);
    }

    // Upload the file to media library
    $attachment_id = media_handle_upload('profile_picture', 0);

    if (is_wp_error($attachment_id)) {
        wp_send_json_error(['message' => 'Upload failed: ' . $attachment_id->get_error_message()]);
    }

    // Get image URLs for different sizes
    $full_url = wp_get_attachment_url($attachment_id);
    $thumbnail_url = wp_get_attachment_image_url($attachment_id, 'thumbnail');
    $medium_url = wp_get_attachment_image_url($attachment_id, 'medium');
    
    // Use medium for display, fallback to thumbnail, then full
    $image_url = $medium_url ?: ($thumbnail_url ?: $full_url);

    // Save to user meta (custom)
    update_user_meta($user_id, 'profile_picture_id', $attachment_id);
    update_user_meta($user_id, 'profile_picture_url', $image_url);

    // Set Simple Local Avatars meta format for get_avatar() compatibility
    $simple_local_avatar = array(
        'media_id' => $attachment_id,
        'full'     => $full_url,
        '192'      => $medium_url ?: $full_url,
        '96'       => $thumbnail_url ?: $full_url,
        '64'       => $thumbnail_url ?: $full_url,
        '32'       => $thumbnail_url ?: $full_url,
    );
    update_user_meta($user_id, 'simple_local_avatar', $simple_local_avatar);

    wp_send_json_success([
        'message' => 'Profile picture updated!',
        'image_url' => $image_url
    ]);
}

/**
 * Handle profile picture removal
 * Also clears Simple Local Avatars meta via native hooks
 */
function handle_remove_profile_picture() {
    if (!check_ajax_referer('disciple_profile_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $user_id = get_current_user_id();

    // === Method 1: Try Simple Local Avatars native deletion ===
    // SLA looks for this POST field to trigger avatar deletion
    $_POST['simple-local-avatar-erase'] = '1';
    
    // Trigger the profile update hooks
    do_action('personal_options_update', $user_id);
    do_action('edit_user_profile_update', $user_id);
    
    // === Method 2: Manual cleanup (in case SLA didn't handle it) ===
    // Delete the attachment (from custom meta)
    $attachment_id = get_user_meta($user_id, 'profile_picture_id', true);
    if ($attachment_id) {
        wp_delete_attachment($attachment_id, true);
    }

    // Also check Simple Local Avatars for a different attachment
    $simple_local_avatar = get_user_meta($user_id, 'simple_local_avatar', true);
    if (!empty($simple_local_avatar['media_id']) && $simple_local_avatar['media_id'] != $attachment_id) {
        wp_delete_attachment($simple_local_avatar['media_id'], true);
    }

    // Remove all user meta (custom + Simple Local Avatars)
    delete_user_meta($user_id, 'profile_picture_id');
    delete_user_meta($user_id, 'profile_picture_url');
    delete_user_meta($user_id, 'simple_local_avatar');
    delete_user_meta($user_id, 'simple_local_avatar_rating');

    wp_send_json_success(['message' => 'Profile picture removed.']);
}

/**
 * Handle profile update
 */
function handle_update_disciple_profile() {
    // Verify nonce
    if (!check_ajax_referer('disciple_profile_nonce', 'nonce', false)) {
        wp_send_json_error(['message' => 'Security check failed.']);
    }

    // Must be logged in
    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'You must be logged in.']);
    }

    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    // Sanitize inputs
    $first_name = sanitize_text_field($_POST['first_name'] ?? '');
    $last_name = sanitize_text_field($_POST['last_name'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $errors = [];

    // Validate email
    if (empty($email)) {
        $errors[] = 'Email address is required.';
    } elseif (!is_email($email)) {
        $errors[] = 'Please enter a valid email address.';
    } elseif ($email !== $current_user->user_email) {
        // Check if email already exists
        if (email_exists($email)) {
            $errors[] = 'This email address is already in use.';
        }
    }

    // Validate password change (only if attempting to change)
    if (!empty($new_password) || !empty($confirm_password)) {
        if (empty($current_password)) {
            $errors[] = 'Current password is required to set a new password.';
        } elseif (!wp_check_password($current_password, $current_user->user_pass, $user_id)) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($new_password) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($new_password !== $confirm_password) {
            $errors[] = 'New passwords do not match.';
        }
    }

    if (!empty($errors)) {
        wp_send_json_error(['message' => implode(' ', $errors)]);
    }

    // Update user data
    $user_data = [
        'ID' => $user_id,
        'first_name' => $first_name,
        'last_name' => $last_name,
        'display_name' => trim($first_name . ' ' . $last_name) ?: $current_user->user_login,
    ];

    // Update email if changed
    if ($email !== $current_user->user_email) {
        $user_data['user_email'] = $email;
    }

    // Update password if provided
    if (!empty($new_password) && $new_password === $confirm_password) {
        $user_data['user_pass'] = $new_password;
    }

    $result = wp_update_user($user_data);

    if (is_wp_error($result)) {
        wp_send_json_error(['message' => $result->get_error_message()]);
    }

    // Update phone number in user meta
    update_user_meta($user_id, 'phone_number', $phone);

    wp_send_json_success([
        'message' => 'Profile updated successfully!',
        'redirect' => !empty($new_password) ? true : false
    ]);
}

// Shortcode
add_shortcode('disciple_profile', function() {
    if (!is_user_logged_in()) {
        return '<p style="text-align:center;padding:40px;">Please <a href="' . wp_login_url(get_permalink()) . '">log in</a> to view your profile.</p>';
    }

    $current_user = wp_get_current_user();
    $user_id = $current_user->ID;

    // Get user data
    $first_name = get_user_meta($user_id, 'first_name', true);
    $last_name = get_user_meta($user_id, 'last_name', true);
    $email = $current_user->user_email;
    $phone = get_user_meta($user_id, 'phone_number', true);
    
    // Profile picture - check Simple Local Avatars first, then custom meta
    $profile_picture_url = '';
    $has_profile_picture = false;
    
    // First check Simple Local Avatars
    $simple_local_avatar = get_user_meta($user_id, 'simple_local_avatar', true);
    if (!empty($simple_local_avatar) && !empty($simple_local_avatar['full'])) {
        $profile_picture_url = $simple_local_avatar['full'];
        $has_profile_picture = true;
    } else {
        // Fallback to custom profile_picture_url meta
        $profile_picture_url = get_user_meta($user_id, 'profile_picture_url', true);
        $has_profile_picture = !empty($profile_picture_url);
    }

    // Nonce for AJAX
    $nonce = wp_create_nonce('disciple_profile_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    $dashboard_url = home_url('/disciple-dashboard/');

    ob_start();
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>My Profile</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --primary: #6366F1;
    --primary-dark: #4F46E5;
    --primary-light: #818CF8;
    --success: #10B981;
    --warning: #F59E0B;
    --danger: #EF4444;
    --bg: #FAFBFC;
    --card: #FFFFFF;
    --ink: #1E293B;
    --ink-light: #475569;
    --muted: #94A3B8;
    --border: #E2E8F0;
    --border-light: #F1F5F9;
    --shadow: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -4px rgba(0,0,0,0.04);
    --radius: 12px;
    --radius-lg: 16px;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }
  
  body {
    background: var(--bg);
    color: var(--ink);
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 14px;
    line-height: 1.6;
    min-height: 100vh;
  }

  .profile-header {
    background: var(--card);
    border-bottom: 1px solid var(--border);
    padding: 16px 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
  }

  .profile-header h1 {
    font-size: 18px;
    font-weight: 700;
    color: var(--ink);
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .back-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--ink-light);
    text-decoration: none;
    font-weight: 600;
    font-size: 13px;
    padding: 8px 16px;
    border-radius: var(--radius);
    border: 1px solid var(--border);
    background: var(--card);
    transition: all 0.15s ease;
  }
  .back-link:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
  }

  .profile-container {
    max-width: 640px;
    margin: 40px auto;
    padding: 0 24px;
  }

  .profile-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    overflow: hidden;
  }

  .avatar-section {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    padding: 40px 24px;
    text-align: center;
    position: relative;
  }

  .avatar-wrapper {
    position: relative;
    display: inline-block;
  }

  .avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid rgba(255,255,255,0.3);
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
    object-fit: cover;
  }

  .avatar-overlay {
    position: absolute;
    inset: 4px;
    border-radius: 50%;
    background: rgba(0,0,0,0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity 0.2s ease;
    cursor: pointer;
  }
  .avatar-wrapper:hover .avatar-overlay {
    opacity: 1;
  }
  .avatar-overlay svg {
    width: 32px;
    height: 32px;
    color: #fff;
  }

  .avatar-name {
    color: #fff;
    font-size: 20px;
    font-weight: 700;
    margin-top: 16px;
  }

  .avatar-email {
    color: rgba(255,255,255,0.8);
    font-size: 13px;
    margin-top: 4px;
  }

  /* Avatar upload controls */
  .avatar-controls {
    margin-top: 20px;
    display: flex;
    gap: 10px;
    justify-content: center;
    flex-wrap: wrap;
  }
  
  .avatar-btn {
    padding: 10px 20px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s ease;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }
  
  .avatar-btn-upload {
    background: rgba(255,255,255,0.2);
    color: #fff;
    border: 1px solid rgba(255,255,255,0.3);
  }
  .avatar-btn-upload:hover {
    background: rgba(255,255,255,0.3);
  }
  
  .avatar-btn-remove {
    background: rgba(239, 68, 68, 0.2);
    color: #fff;
    border: 1px solid rgba(239, 68, 68, 0.3);
  }
  .avatar-btn-remove:hover {
    background: rgba(239, 68, 68, 0.4);
  }
  
  .avatar-btn svg {
    width: 16px;
    height: 16px;
  }
  
  #avatarFileInput {
    display: none;
  }
  
  .upload-hint {
    margin-top: 12px;
    font-size: 11px;
    color: rgba(255,255,255,0.6);
  }
  
  .avatar-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(255,255,255,0.1);
    color: rgba(255,255,255,0.8);
    font-size: 42px;
    font-weight: 700;
  }
  
  .upload-spinner {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.5);
    border-radius: 50%;
    display: none;
    align-items: center;
    justify-content: center;
  }
  .upload-spinner.active {
    display: flex;
  }
  .upload-spinner .spinner {
    width: 32px;
    height: 32px;
    border-width: 3px;
  }

  .form-section {
    padding: 32px 24px;
  }

  .section-title {
    font-size: 14px;
    font-weight: 700;
    color: var(--ink);
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .section-title svg {
    width: 18px;
    height: 18px;
    color: var(--primary);
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 20px;
  }
  @media (max-width: 480px) {
    .form-row { grid-template-columns: 1fr; }
  }

  .form-group {
    margin-bottom: 20px;
  }
  .form-row .form-group {
    margin-bottom: 0;
  }

  .form-label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    color: var(--ink-light);
    margin-bottom: 6px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }

  .form-input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    font-size: 14px;
    font-family: inherit;
    color: var(--ink);
    background: var(--card);
    transition: all 0.15s ease;
  }
  .form-input:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
  }
  .form-input::placeholder {
    color: var(--muted);
  }

  .form-hint {
    font-size: 11px;
    color: var(--muted);
    margin-top: 6px;
  }

  .password-section {
    background: var(--border-light);
    margin: 0 -24px;
    padding: 24px;
    margin-top: 8px;
  }

  .form-actions {
    padding: 24px;
    background: var(--border-light);
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: flex-end;
    gap: 12px;
  }

  .btn {
    padding: 12px 24px;
    border-radius: var(--radius);
    font-size: 14px;
    font-weight: 600;
    font-family: inherit;
    cursor: pointer;
    transition: all 0.15s ease;
    border: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
  }

  .btn-primary {
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff;
    box-shadow: 0 4px 14px rgba(99, 102, 241, 0.25);
  }
  .btn-primary:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(99, 102, 241, 0.35);
  }
  .btn-primary:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
  }

  .btn-secondary {
    background: var(--card);
    color: var(--ink-light);
    border: 1px solid var(--border);
  }
  .btn-secondary:hover {
    background: var(--border-light);
  }

  .alert {
    padding: 14px 18px;
    border-radius: var(--radius);
    margin-bottom: 20px;
    font-size: 13px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .alert-success {
    background: #ECFDF5;
    color: #059669;
    border: 1px solid rgba(16, 185, 129, 0.2);
  }
  .alert-error {
    background: #FEF2F2;
    color: #DC2626;
    border: 1px solid rgba(239, 68, 68, 0.2);
  }
  .alert svg {
    width: 18px;
    height: 18px;
    flex-shrink: 0;
  }

  .spinner {
    width: 18px;
    height: 18px;
    border: 2px solid rgba(255,255,255,0.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
  }
  @keyframes spin {
    to { transform: rotate(360deg); }
  }
</style>
</head>
<body>

<header class="profile-header">
  <h1>
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
      <circle cx="12" cy="7" r="4"></circle>
    </svg>
    My Profile
  </h1>
  <a href="<?php echo esc_url($dashboard_url); ?>" class="back-link">
    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
      <path d="M19 12H5M12 19l-7-7 7-7"/>
    </svg>
    Back to Dashboard
  </a>
</header>

<div class="profile-container">
  <div id="alertBox"></div>

  <form id="profileForm" class="profile-card">
    <!-- Avatar Section -->
    <div class="avatar-section">
      <div class="avatar-wrapper">
        <?php if ($has_profile_picture): ?>
          <img src="<?php echo esc_url($profile_picture_url); ?>" alt="Profile" class="avatar" id="avatarImg">
        <?php else: ?>
          <div class="avatar" id="avatarImg" style="display:flex;">
            <div class="avatar-placeholder" id="avatarPlaceholder">
              <?php echo esc_html(strtoupper(substr($first_name ?: $current_user->display_name, 0, 1) . substr($last_name, 0, 1))); ?>
            </div>
          </div>
        <?php endif; ?>
        <div class="upload-spinner" id="uploadSpinner">
          <div class="spinner"></div>
        </div>
      </div>
      <div class="avatar-name" id="displayName"><?php echo esc_html(trim($first_name . ' ' . $last_name) ?: $current_user->display_name); ?></div>
      <div class="avatar-email"><?php echo esc_html($email); ?></div>
      
      <!-- Upload Controls -->
      <div class="avatar-controls">
        <input type="file" id="avatarFileInput" accept="image/jpeg,image/png,image/gif,image/webp">
        <button type="button" class="avatar-btn avatar-btn-upload" id="uploadBtn">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
            <polyline points="17 8 12 3 7 8"></polyline>
            <line x1="12" y1="3" x2="12" y2="15"></line>
          </svg>
          Upload Photo
        </button>
        <?php if ($has_profile_picture): ?>
        <button type="button" class="avatar-btn avatar-btn-remove" id="removeBtn">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
          </svg>
          Remove
        </button>
        <?php endif; ?>
      </div>
      <p class="upload-hint">JPG, PNG, GIF or WebP. Max 2MB.</p>
    </div>

    <!-- Personal Information -->
    <div class="form-section">
      <h2 class="section-title">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
          <circle cx="12" cy="7" r="4"></circle>
        </svg>
        Personal Information
      </h2>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="first_name">First Name</label>
          <input type="text" id="first_name" name="first_name" class="form-input" 
                 value="<?php echo esc_attr($first_name); ?>" placeholder="Enter first name">
        </div>
        <div class="form-group">
          <label class="form-label" for="last_name">Last Name</label>
          <input type="text" id="last_name" name="last_name" class="form-input" 
                 value="<?php echo esc_attr($last_name); ?>" placeholder="Enter last name">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="email">Email Address</label>
        <input type="email" id="email" name="email" class="form-input" 
               value="<?php echo esc_attr($email); ?>" placeholder="Enter email address" required>
        <p class="form-hint">This is the email address associated with your account.</p>
      </div>

      <div class="form-group">
        <label class="form-label" for="phone">Phone Number</label>
        <input type="tel" id="phone" name="phone" class="form-input" 
               value="<?php echo esc_attr($phone); ?>" placeholder="Enter phone number">
      </div>
    </div>

    <!-- Password Section -->
    <div class="form-section">
      <h2 class="section-title">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
          <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
        </svg>
        Change Password
      </h2>
      <p class="form-hint" style="margin-bottom:20px;">Leave blank to keep your current password.</p>

      <div class="form-group">
        <label class="form-label" for="current_password">Current Password</label>
        <input type="password" id="current_password" name="current_password" class="form-input" 
               placeholder="Enter current password">
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="new_password">New Password</label>
          <input type="password" id="new_password" name="new_password" class="form-input" 
                 placeholder="Enter new password">
        </div>
        <div class="form-group">
          <label class="form-label" for="confirm_password">Confirm New Password</label>
          <input type="password" id="confirm_password" name="confirm_password" class="form-input" 
                 placeholder="Confirm new password">
        </div>
      </div>
      <p class="form-hint">Password must be at least 8 characters long.</p>
    </div>

    <!-- Actions -->
    <div class="form-actions">
      <a href="<?php echo esc_url($dashboard_url); ?>" class="btn btn-secondary">Cancel</a>
      <button type="submit" class="btn btn-primary" id="submitBtn">
        <span id="btnText">Save Changes</span>
        <div class="spinner" id="btnSpinner" style="display:none;"></div>
      </button>
    </div>
  </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('profileForm');
  const alertBox = document.getElementById('alertBox');
  const submitBtn = document.getElementById('submitBtn');
  const btnText = document.getElementById('btnText');
  const btnSpinner = document.getElementById('btnSpinner');
  const displayName = document.getElementById('displayName');
  const firstNameInput = document.getElementById('first_name');
  const lastNameInput = document.getElementById('last_name');
  
  // Avatar elements
  const avatarFileInput = document.getElementById('avatarFileInput');
  const uploadBtn = document.getElementById('uploadBtn');
  const removeBtn = document.getElementById('removeBtn');
  const avatarImg = document.getElementById('avatarImg');
  const avatarPlaceholder = document.getElementById('avatarPlaceholder');
  const uploadSpinner = document.getElementById('uploadSpinner');

  const AJAX_URL = '<?php echo esc_url($ajax_url); ?>';
  const NONCE = '<?php echo esc_js($nonce); ?>';

  // Update display name preview
  function updateDisplayName() {
    const fn = firstNameInput.value.trim();
    const ln = lastNameInput.value.trim();
    displayName.textContent = (fn + ' ' + ln).trim() || '<?php echo esc_js($current_user->display_name); ?>';
    
    // Update placeholder initials if visible
    if (avatarPlaceholder) {
      const initials = (fn.charAt(0) + ln.charAt(0)).toUpperCase() || '<?php echo esc_js(strtoupper(substr($current_user->display_name, 0, 1))); ?>';
      avatarPlaceholder.textContent = initials;
    }
  }
  firstNameInput.addEventListener('input', updateDisplayName);
  lastNameInput.addEventListener('input', updateDisplayName);

  // Show alert
  function showAlert(type, message) {
    const icon = type === 'success' 
      ? '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>'
      : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
    
    alertBox.innerHTML = `<div class="alert alert-${type}">${icon}<span>${message}</span></div>`;
    alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

  // Update avatar display helper
  function updateAvatarDisplay(imageUrl) {
    const currentAvatar = document.getElementById('avatarImg');
    if (!currentAvatar) return;
    
    const wrapper = currentAvatar.parentElement;
    
    // Create new image element
    const newImg = document.createElement('img');
    newImg.src = imageUrl;
    newImg.alt = 'Profile';
    newImg.className = 'avatar';
    newImg.id = 'avatarImg';
    
    // Replace current avatar
    currentAvatar.replaceWith(newImg);
    
    // Show remove button if not already visible
    let rmBtn = document.getElementById('removeBtn');
    if (!rmBtn) {
      const controls = document.querySelector('.avatar-controls');
      const removeBtnHtml = `
        <button type="button" class="avatar-btn avatar-btn-remove" id="removeBtn">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
          </svg>
          Remove
        </button>
      `;
      controls.insertAdjacentHTML('beforeend', removeBtnHtml);
      attachRemoveHandler();
    }
  }

  // === AVATAR UPLOAD - Direct file upload (avoids conflicts with cover image plugins) ===
  uploadBtn.addEventListener('click', function(e) {
    e.preventDefault();
    avatarFileInput.click();
  });

  // Fallback file input handler (if WP media library not loaded)
  avatarFileInput.addEventListener('change', async function() {
    const file = this.files[0];
    if (!file) return;

    // Validate file type
    const allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!allowedTypes.includes(file.type)) {
      showAlert('error', 'Invalid file type. Please upload a JPG, PNG, GIF, or WebP image.');
      return;
    }

    // Validate file size (2MB)
    if (file.size > 2 * 1024 * 1024) {
      showAlert('error', 'File too large. Maximum size is 2MB.');
      return;
    }

    // Show spinner
    uploadSpinner.classList.add('active');

    const formData = new FormData();
    formData.append('action', 'upload_profile_picture');
    formData.append('nonce', NONCE);
    formData.append('profile_picture', file);

    try {
      const response = await fetch(AJAX_URL, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
      });

      const result = await response.json();

      if (result.success) {
        showAlert('success', result.data.message);
        updateAvatarDisplay(result.data.image_url);
      } else {
        showAlert('error', result.data.message || 'Upload failed.');
      }
    } catch (error) {
      showAlert('error', 'Network error. Please try again.');
      console.error('Upload error:', error);
    } finally {
      uploadSpinner.classList.remove('active');
      avatarFileInput.value = '';
    }
  });

  // === AVATAR REMOVE ===
  function attachRemoveHandler() {
    const btn = document.getElementById('removeBtn');
    if (!btn) return;
    
    btn.addEventListener('click', async function() {
      if (!confirm('Remove your profile picture?')) return;

      uploadSpinner.classList.add('active');

      const formData = new FormData();
      formData.append('action', 'remove_profile_picture');
      formData.append('nonce', NONCE);

      try {
        const response = await fetch(AJAX_URL, {
          method: 'POST',
          credentials: 'same-origin',
          body: formData
        });

        const result = await response.json();

        if (result.success) {
          showAlert('success', result.data.message);
          
          // Replace with placeholder
          const currentAvatar = document.getElementById('avatarImg');
          const fn = firstNameInput.value.trim();
          const ln = lastNameInput.value.trim();
          const initials = (fn.charAt(0) + ln.charAt(0)).toUpperCase() || '<?php echo esc_js(strtoupper(substr($current_user->display_name, 0, 1))); ?>';
          
          const placeholder = document.createElement('div');
          placeholder.className = 'avatar';
          placeholder.id = 'avatarImg';
          placeholder.style.display = 'flex';
          placeholder.innerHTML = `<div class="avatar-placeholder" id="avatarPlaceholder">${initials}</div>`;
          
          currentAvatar.replaceWith(placeholder);
          
          // Remove the remove button
          this.remove();
        } else {
          showAlert('error', result.data.message || 'Failed to remove picture.');
        }
      } catch (error) {
        showAlert('error', 'Network error. Please try again.');
        console.error('Remove error:', error);
      } finally {
        uploadSpinner.classList.remove('active');
      }
    });
  }
  
  // Initialize remove handler if button exists
  attachRemoveHandler();

  // === FORM SUBMIT ===
  form.addEventListener('submit', async function(e) {
    e.preventDefault();

    submitBtn.disabled = true;
    btnText.textContent = 'Saving...';
    btnSpinner.style.display = 'block';
    alertBox.innerHTML = '';

    const formData = new FormData(form);
    formData.append('action', 'update_disciple_profile');
    formData.append('nonce', NONCE);

    try {
      const response = await fetch(AJAX_URL, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData
      });

      const result = await response.json();

      if (result.success) {
        showAlert('success', result.data.message);
        
        document.getElementById('current_password').value = '';
        document.getElementById('new_password').value = '';
        document.getElementById('confirm_password').value = '';

        if (result.data.redirect) {
          setTimeout(() => {
            window.location.href = '<?php echo esc_url($dashboard_url); ?>';
          }, 1500);
        }
      } else {
        showAlert('error', result.data.message || 'An error occurred.');
      }
    } catch (error) {
      showAlert('error', 'Network error. Please try again.');
      console.error('Profile update error:', error);
    } finally {
      submitBtn.disabled = false;
      btnText.textContent = 'Save Changes';
      btnSpinner.style.display = 'none';
    }
  });
});
</script>

</body>
</html>
    <?php
    return ob_get_clean();
});
