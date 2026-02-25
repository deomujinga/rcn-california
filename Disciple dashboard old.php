// add_action('wp_ajax_mark_notif_read', 'rcn_mark_notif_read');
// remove nopriv unless you truly want logged-out users to hit it
add_action('wp_ajax_mark_notif_read', 'rcn_mark_notif_read');

function rcn_mark_notif_read() {
    check_ajax_referer('notif_nonce', 'nonce');

    if (!is_user_logged_in()) {
        wp_send_json_error('Not logged in');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'discipleship_notifications';

    $notif_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $user_id  = get_current_user_id();

    if (!$notif_id) {
        wp_send_json_error('Invalid ID');
    }

    $updated = $wpdb->update(
        $table,
        [ 'is_read' => 1 ],
        [ 'id' => $notif_id, 'user_id' => $user_id ],
        [ '%d' ],
        [ '%d', '%d' ]
    );

    // NOTE: update() returns 0 if already read, which is still fine
    if ($updated !== false) {
        wp_send_json_success(['id' => $notif_id]);
    }

    wp_send_json_error('DB update failed');
}

// [discipleship_dashboard]
add_shortcode('discipleship_dashboard', function () {
    if (!is_user_logged_in()) {
        return '<p>Please log in to view your dashboard.</p>';
    }

    global $wpdb;

    // ---- Determine WHO we are viewing ----
    $current = wp_get_current_user();

    // Leaders/Admins may drill down via ?participant=<email>
    $target_email = '';
    if (current_user_can('access_leadership') && !empty($_GET['participant'])) {
        $target_email = sanitize_email(wp_unslash($_GET['participant']));
    }
    if (!$target_email) { // default: self
        $target_email = $current->user_email;
    }

    $target_email_l = strtolower(trim($target_email));
    error_log("target_email_l " . $target_email_l);

    // Build a friendly display name for the HEADER chip
    $view_user = get_user_by('email', $target_email_l);
    if ($view_user) {
        $fn = trim((string)get_user_meta($view_user->ID, 'first_name', true));
        $ln = trim((string)get_user_meta($view_user->ID, 'last_name', true));
        $view_name = trim($fn . ' ' . $ln);
        if ($view_name === '') {
            $view_name = $view_user->display_name ?: $view_user->user_login;
        }
    } else {
        $view_name = $target_email_l; // fallback
    }

    // Get profile picture - Simple Local Avatars integration
    // Uses get_avatar_url() which Simple Local Avatars hooks into
    $profile_picture_url = '';
    $has_profile_picture = false;
    if ($view_user) {
        // First check Simple Local Avatars
        $simple_local_avatar = get_user_meta($view_user->ID, 'simple_local_avatar', true);
        if (!empty($simple_local_avatar) && !empty($simple_local_avatar['full'])) {
            $profile_picture_url = $simple_local_avatar['full'];
            $has_profile_picture = true;
        } else {
            // Fallback to custom profile_picture_url meta
            $profile_picture_url = get_user_meta($view_user->ID, 'profile_picture_url', true);
            $has_profile_picture = !empty($profile_picture_url);
        }
        
        // If still no picture, try get_avatar_url (catches other avatar plugins)
        if (!$has_profile_picture) {
            $avatar_url = get_avatar_url($view_user->ID, ['size' => 80, 'default' => '']);
            // Only use if it's not a gravatar default
            if ($avatar_url && strpos($avatar_url, 'gravatar.com') === false) {
                $profile_picture_url = $avatar_url;
                $has_profile_picture = true;
            }
        }
    }

    $view_user_id = $view_user ? $view_user->ID : $current->ID;

    error_log("view_user_id " . var_export($view_user_id, true));
    if ($view_user) {
        error_log("view_user->ID " . $view_user->ID);
    }

    // ---------------------------------------------------------------------
    // Load participant + program + level (attainment & grace info from DB)
    // ---------------------------------------------------------------------
    $participants_tbl = $wpdb->prefix . 'discipleship_participants';
    $programs_tbl     = $wpdb->prefix . 'discipleship_programs';
    $levels_tbl       = $wpdb->prefix . 'discipleship_levels';
    $pauses_tbl       = $wpdb->prefix . 'discipleship_pauses';
    $notifs_tbl       = $wpdb->prefix . 'discipleship_notifications';
    $summary_tbl      = $wpdb->prefix . 'discipleship_attainment_summary';

    $participant = $wpdb->get_row($wpdb->prepare("
        SELECT 
            p.*,
            prog.name        AS program_name,
            prog.description AS program_description,
            lvl.name         AS level_name,
            lvl.level_number AS level_number,
            lvl.duration_days,
            lvl.grace_period_days,
            lvl.promotion_threshold,            
            lvl.description  AS level_description,
            lvl.max_grace_cycles
        FROM $participants_tbl p
        LEFT JOIN $programs_tbl prog ON prog.id = p.program_id
        LEFT JOIN $levels_tbl   lvl  ON lvl.id = p.current_level_id
        WHERE p.user_id = %d
        LIMIT 1
    ", $view_user_id));

    // ---------------------------------------------------------------------
    // Load summary rows for this participant/program/level
    // ---------------------------------------------------------------------
    $trend_rows      = [];
    $radar_data      = null;
    $weak_label      = '—';
    $strong_label    = '—';
    $weak_text       = '—';
    $strong_text     = '—';
    $level_perf_tbl  = $wpdb->prefix . 'discipleship_level_performance';

    if ($participant) {
        $prog_id  = (int)$participant->program_id;
        $level_id = (int)$participant->current_level_id;
        $participant_db_id = (int)$participant->id;

        // Trend rows (all weeks) - still from attainment_summary for weekly chart
        $trend_rows = $wpdb->get_results($wpdb->prepare("
            SELECT week_start, overall_attainment
            FROM $summary_tbl
            WHERE participant_id = %d
              AND program_id     = %d
              AND level_id       = %d
            ORDER BY week_start ASC
        ", $view_user_id, $prog_id, $level_id), ARRAY_A);

        // Level performance (averages across all weeks) - for radar + strong/weak KPIs
        $level_performance = $wpdb->get_row($wpdb->prepare("
            SELECT *
            FROM $level_perf_tbl
            WHERE participant_id = %d
              AND program_id     = %d
              AND level_id       = %d
            LIMIT 1
        ", $participant_db_id, $prog_id, $level_id));

        if ($level_performance) {
            $weak_label   = $level_performance->weakest_practice ?: '—';
            $strong_label = $level_performance->strongest_practice ?: '—';

            $radar_data = [
                'br'           => (float)$level_performance->br_attainment,
                'fasting'      => (float)$level_performance->fasting_attainment,
                'memorization' => (float)$level_performance->memorization_attainment,
                'bible_study'  => (float)$level_performance->bible_study_attainment,
                'mp'           => (float)$level_performance->mp_attainment,
                'cp'           => (float)$level_performance->cp_attainment,
                'mi'           => (float)$level_performance->mi_attainment,
            ];

            // Map practice name → percent (for nicer Weakest/Strongest labels)
            $practice_pct_map = [
                'Bible Reading'                  => $radar_data['br'],
                'Fasting'                        => $radar_data['fasting'],
                'Scripture Memorization'         => $radar_data['memorization'],
                'Bible Study & Meditation'       => $radar_data['bible_study'],
                'Midnight Intercessory Prayer'   => $radar_data['mp'],
                'Corporate Prayers'              => $radar_data['cp'],
                'Morning Intimacy'               => $radar_data['mi'],
            ];

            if ($weak_label !== '—' && isset($practice_pct_map[$weak_label])) {
                $weak_text = sprintf('%s (%.1f%%)', $weak_label, $practice_pct_map[$weak_label]);
            } else {
                $weak_text = $weak_label;
            }

            if ($strong_label !== '—' && isset($practice_pct_map[$strong_label])) {
                $strong_text = sprintf('%s (%.1f%%)', $strong_label, $practice_pct_map[$strong_label]);
            } else {
                $strong_text = $strong_label;
            }
        }
    }

    // --------- derive KPI values from participant row ----------
    $program_name        = $participant ? ($participant->program_name ?: 'Discipleship Program') : 'Discipleship Program';
    $program_description = $participant ? ($participant->program_description ?: '') : '';    
    $level_description   = $participant ? ($participant->level_description ?: '') : '';
    $level_name          = $participant ? ($participant->level_name ?: 'No level assigned') : 'No active level';
    $level_number        = $participant ? (int)$participant->level_number : 0;

    $in_grace    = $participant ? (int)$participant->in_grace_period === 1 : false;
    $grace_cycle = $participant ? (int)$participant->grace_cycle_count : 0;

    // Attainment (already computed & stored as 0–100 on participants table)
    $attainment_val   = null;
    $attn_pct_str     = '—';
    $attn_flag_class  = 'pill';
    $attn_flag_text   = 'No attainment yet';
    $level_threshold  = $participant ? (float)$participant->promotion_threshold : 95.0;

    if ($participant && $participant->attainment !== null) {
        $attainment_val = (float)$participant->attainment;
        $attn_pct_str   = number_format($attainment_val, 1) . '%';

        if ($attainment_val >= $level_threshold) {
            $attn_flag_class .= ' ok';
            $attn_flag_text = 'On track (≥ threshold)';
        } elseif ($attainment_val >= max(0, $level_threshold - 15)) {
            $attn_flag_class .= ' warn';
            $attn_flag_text = 'At risk (below threshold)';
        } else {
            $attn_flag_class .= ' bad';
            $attn_flag_text = 'Critical (well below threshold)';
        }
    }

    // Days remaining: based on level duration + grace periods − pauses
    $days_remaining_str = '—';
    $period_text        = 'Level duration not set';
    $days_bar_pct       = 0;

    error_log('participant: ' . var_export($participant, true));

    if ($participant && $participant->level_start_date) {
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }

        $level_start_ts = strtotime($participant->level_start_date);
        $now_ts         = current_time('timestamp');

        error_log("level_start_ts " . $level_start_ts);            
        error_log("now_ts " . $now_ts);    

        if ($level_start_ts && $now_ts >= $level_start_ts) {
            $days_since_start = floor(($now_ts - $level_start_ts) / DAY_IN_SECONDS);

            // paused days for this participant (by participant record id)
            $pause_rows = $wpdb->get_results($wpdb->prepare("
                SELECT paused_at, resumed_at
                FROM $pauses_tbl
                WHERE participant_id = %d
            ", $participant->user_id));

            $paused_days = 0;
            foreach ($pause_rows as $row) {
                if (!$row->paused_at) continue;
                $p_ts = strtotime($row->paused_at);
                $r_ts = $row->resumed_at ? strtotime($row->resumed_at) : $now_ts;
                if ($p_ts && $r_ts && $r_ts > $p_ts) {
                    $paused_days += floor(($r_ts - $p_ts) / DAY_IN_SECONDS);
                }
            }

            $valid_days_elapsed = max(0, $days_since_start - $paused_days);

            error_log("valid_days_elapsed " . $days_since_start);
            error_log("paused_days " . $paused_days);

            $duration_days   = $participant->duration_days !== null ? (int)$participant->duration_days : 0;
            $grace_days      = $participant->grace_period_days !== null ? (int)$participant->grace_period_days : 0;
            $grace_cycles    = max(0, (int)$participant->grace_cycle_count);
            $total_days      = $duration_days + ($grace_days * $grace_cycles);

            error_log("duration_days " . $duration_days);
            error_log("grace_days " . $grace_days);
            error_log("grace_cycles " . $grace_cycles);
            error_log("total_days " . $total_days);

            // if grace_cycles is 0, still show duration_days
            if ($total_days === 0 && $duration_days > 0) {
                $total_days = $duration_days;
            }

            if ($total_days > 0) {
                $remaining  = max(0, $total_days - $valid_days_elapsed);
                $days_remaining_str = (string)$remaining;
                $period_text = "of {$total_days}-day level (incl. grace)";
                $days_bar_pct = max(0, min(100, round(($valid_days_elapsed / $total_days) * 100)));
            }
        }
    }

    // -----------------------------------------------------------------
    // Load notifications for this user (from discipleship_notifications)
    // -----------------------------------------------------------------
    $notifications = $wpdb->get_results($wpdb->prepare("
        SELECT id, message, type, is_read, created_at
        FROM $notifs_tbl
        WHERE user_id = %d
        ORDER BY created_at DESC
        LIMIT 10
    ", $view_user_id));

    $unread_count = 0;
    foreach ($notifications as $n) {
        if (!(int)$n->is_read) {
            $unread_count++;
        }
    }

    // AJAX base + nonces for data endpoint
    $ajax_base    = admin_url('admin-ajax.php') . '?action=get_discipleship_data_v2';
    $dd_req_nonce = wp_create_nonce('dd_request');

    // pass-through view nonce for leaders
    $dd_view_nonce = '';
    if (current_user_can('access_leadership') && !empty($_GET['dd_view_nonce'])) {
        $dd_view_nonce = sanitize_text_field(wp_unslash($_GET['dd_view_nonce']));
    }

    // Trend + radar data for JS
    $trend_js  = $trend_rows ?: [];
    $radar_js  = $radar_data ?: null;

    $notif_nonce = wp_create_nonce('notif_nonce');

    ob_start(); ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<title>Discipleship Dashboard</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Cinzel:wght@400;600;700&family=IM+Fell+English:ital@0;1&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
  /* ===== MODERN SLEEK DESIGN SYSTEM ===== */
  :root {
    /* Colors */
    --primary: #6366F1;
    --primary-dark: #4F46E5;
    --primary-light: #818CF8;
    --accent: #F59E0B;
    --success: #10B981;
    --warning: #F59E0B;
    --danger: #EF4444;
    
    /* Neutrals */
    --bg: #FAFBFC;
    --card: #FFFFFF;
    --ink: #1E293B;
    --ink-light: #475569;
    --muted: #94A3B8;
    --border: #E2E8F0;
    --border-light: #F1F5F9;
    
    /* Effects */
    --shadow-sm: 0 1px 2px rgba(0,0,0,0.04);
    --shadow: 0 4px 6px -1px rgba(0,0,0,0.07), 0 2px 4px -2px rgba(0,0,0,0.05);
    --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.08), 0 4px 6px -4px rgba(0,0,0,0.04);
    --shadow-xl: 0 20px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.04);
    --radius: 12px;
    --radius-lg: 16px;
    --radius-xl: 24px;
    
    /* Layout */
    --drawer-w: 420px;
  }

  /* Prevent horizontal overflow from drawer width extension */
  html, body {
    overflow-x: hidden;
    max-width: 100vw;
  }

  /* ===== HEADER ===== */
  header {
    padding: 20px 32px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: var(--card);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 100;
    backdrop-filter: blur(12px);
    background: rgba(255,255,255,0.85);
  }
  

  .who {
    display: flex;
    align-items: center;
    gap: 14px;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    border-radius: 999px;
    padding: 8px 18px 8px 8px;
    box-shadow: var(--shadow), 0 0 0 1px rgba(99,102,241,0.1);
    transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    text-decoration: none;
    cursor: pointer;
    position: relative;
  }
  .who:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg), 0 0 0 2px rgba(99,102,241,0.3);
    background: linear-gradient(135deg, var(--primary-light), var(--primary));
  }
  .who:active {
    transform: translateY(0);
  }
  .who .avatar {
    width: 48px;
    height: 48px;
    min-width: 48px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    color: var(--primary);
    background: #fff;
    box-shadow: var(--shadow-sm);
    transition: transform 0.2s ease;
    overflow: hidden;
    position: relative;
  }
  .who .avatar-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
  }
  .who .avatar-initials {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 16px;
    color: var(--primary);
    background: #fff;
  }
  .who:hover .avatar {
    transform: scale(1.05);
  }
  .who .meta { display: flex; flex-direction: column; line-height: 1.2; }
  .who .name { font-weight: 600; font-size: 13px; color: #fff; }
  .who .mail { font-size: 11px; color: rgba(255,255,255,0.75); }
  .who .who-edit-icon {
    color: rgba(255,255,255,0.6);
    transition: color 0.2s ease, transform 0.2s ease;
    margin-left: 2px;
  }
  .who:hover .who-edit-icon {
    color: #fff;
    transform: rotate(-5deg);
  }

  /* ===== LAYOUT ===== */
  .wrap {
    padding: 32px;
    max-width: 1440px;
    margin: 0 auto;
    overflow-x: hidden;
  }
  @media (max-width: 768px) { .wrap { padding: 20px 16px; } }

  /* Drawer now overlays content instead of reserving side space */

  /* ===== KPI CARDS ===== */
  .kpis {
    display: grid;
    gap: 20px;
    grid-template-columns: repeat(4, 1fr);
    margin-bottom: 28px;
  }
  @media (max-width: 1200px) { .kpis { grid-template-columns: repeat(2, 1fr); } }
  @media (max-width: 640px) { .kpis { grid-template-columns: 1fr; } }

  .grid { display: grid; gap: 24px; }
  .mid { grid-template-columns: repeat(2, 1fr); }
  .bottom { grid-template-columns: 1fr; }
  @media (max-width: 980px) { .mid, .bottom { grid-template-columns: 1fr; } }

  /* ===== CARDS ===== */
  .card {
    background: var(--card);
    border-radius: var(--radius-lg);
    border: 1px solid var(--border);
    padding: 24px;
    min-height: 140px;
    display: flex;
    flex-direction: column;
    position: relative;
    transition: box-shadow 0.2s ease, transform 0.2s ease, border-color 0.2s ease;
  }
  .card:hover {
    box-shadow: var(--shadow-lg);
    border-color: var(--border-light);
    transform: translateY(-2px);
  }

  .card h3, .card h5 {
    margin: 0 0 8px;
    font-size: 11px;
    color: var(--muted);
    font-weight: 600;
    letter-spacing: 0.5px;
    text-transform: uppercase;
  }
  .card h2 {
    font-size: 16px;
    margin: 0 0 20px;
    color: var(--ink);
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .card h2::before {
    content: '';
    width: 4px;
    height: 20px;
    background: linear-gradient(180deg, var(--primary), var(--primary-light));
    border-radius: 2px;
  }

  .big {
    font-size: 22px;
    font-weight: 700;
    color: var(--ink);
    margin: 4px 0 8px;
    flex-grow: 1;
    display: flex;
    align-items: center;
    letter-spacing: -0.3px;
  }

  .sub {
    font-size: 13px;
    color: var(--muted);
    line-height: 1.5;
    margin-top: auto;
  }

  /* Status pills */
  .pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 12px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 600;
    margin-top: 8px;
    width: fit-content;
    border: none;
    background: var(--border-light);
    color: var(--ink-light);
  }
  .ok { background: #ECFDF5; color: #059669; }
  .warn { background: #FFFBEB; color: #D97706; }
  .bad { background: #FEF2F2; color: #DC2626; }

  /* Charts */
  canvas {
    width: 100%;
    height: auto;
    max-height: 360px;
    border-radius: var(--radius);
  }
  @media (max-width: 980px) { canvas { max-height: 300px; } }

  /* ===== CALENDAR - MODERN ===== */
  .calendar { display: flex; flex-direction: column; gap: 16px; }
  
  .cal-nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
  }
  
  .cal-actions { display: flex; gap: 8px; align-items: center; }
  
  .btn {
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 8px 16px;
    background: var(--card);
    cursor: pointer;
    font-weight: 600;
    font-size: 13px;
    color: var(--ink-light);
    transition: all 0.15s ease;
  }
  .btn:hover {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
    transform: translateY(-1px);
    box-shadow: var(--shadow);
  }
  .btn-ghost:hover { 
    background: var(--border-light);
    color: var(--ink);
    border-color: var(--border);
  }

  .cal-scroll { 
    overflow: auto; 
    -webkit-overflow-scrolling: touch; 
    border-radius: var(--radius); 
    padding: 4px;
  }
  
  .cal-grid { 
    display: grid; 
    grid-template-columns: repeat(7, minmax(120px, 1fr)); 
    gap: 12px; 
    min-width: 880px; 
  }
  @media (max-width: 480px) { 
    .cal-grid { 
      grid-template-columns: repeat(7, minmax(100px, 1fr)); 
      min-width: 720px; 
      gap: 8px; 
    } 
  }

  .cal-day {
    position: relative;
    min-height: 130px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--card);
    padding: 12px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    transition: all 0.2s ease;
    cursor: pointer;
  }
  .cal-day:hover {
    box-shadow: var(--shadow-lg);
    transform: translateY(-3px);
    border-color: var(--primary-light);
  }
  .cal-day.is-out { opacity: 0.4; }
  .cal-day.is-today {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15), var(--shadow-lg);
  }
  .cal-day.is-today .dnum {
    background: var(--primary);
    color: #fff;
    border-color: var(--primary);
  }

  .dhead { display: flex; align-items: center; justify-content: space-between; }
  .dnum {
    font-size: 12px;
    color: var(--ink-light);
    font-weight: 700;
    padding: 4px 8px;
    border-radius: 999px;
    border: 1px solid var(--border);
    background: var(--border-light);
  }

  .badges { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
  @media (max-width: 480px) { .badges { gap: 4px; } }

  .badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 28px;
    height: 26px;
    padding: 0 6px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 700;
    user-select: none;
    transition: transform 0.15s ease;
  }
  .badge:hover { transform: scale(1.05); }
  .badge.full { color: #fff; border: none; }
  .badge.partial { color: var(--ink); }

  .calendar .badge.full {
    background: linear-gradient(135deg, var(--success), #059669) !important;
    color: #fff !important;
  }
  .calendar .badge.partial {
    background: rgba(99, 102, 241, 0.12) !important;
    border: 1px solid rgba(99, 102, 241, 0.25) !important;
    color: var(--primary-dark) !important;
  }
  .calendar .p-0 {
    background: var(--border-light) !important;
    border: 1px solid var(--border) !important;
    color: var(--muted) !important;
  }

  /* Tooltip */
  #cal-tip {
    position: fixed;
    z-index: 9999;
    pointer-events: none;
    background: var(--ink);
    color: #fff;
    padding: 16px 20px;
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    font-size: 14px;
    line-height: 1.5;
    opacity: 0;
    transform: translateY(-8px);
    transition: opacity 0.15s ease, transform 0.15s ease;
    width: 280px;
    max-width: min(320px, 90vw);
  }
  #cal-tip .title { font-weight: 700; font-size: 15px; margin-bottom: 8px; }
  #cal-tip .meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
  #cal-tip .pill {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    border: none;
  }
  #cal-tip .pill.ok { background: var(--success); }
  #cal-tip .pill.warn { background: var(--warning); color: #000; }
  #cal-tip .pill.bad { background: var(--danger); }
  #cal-tip .sub { color: rgba(255,255,255,0.8); font-size: 12px; }
  #cal-tip .hint {
    margin-top: 12px;
    padding-top: 10px;
    border-top: 1px solid rgba(255,255,255,0.1);
    color: rgba(255,255,255,0.6);
    font-size: 11px;
    font-weight: 600;
  }

  /* Legend */
  .legend {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 16px;
    flex-wrap: wrap;
    justify-content: center;
  }
  .legend .lg-chip {
    display: flex;
    align-items: center;
    gap: 8px;
    background: var(--card);
    padding: 8px 14px;
    border-radius: 999px;
    border: 1px solid var(--border);
    font-weight: 600;
    font-size: 12px;
    transition: all 0.15s ease;
  }
  .legend .lg-chip:hover {
    border-color: var(--primary-light);
    box-shadow: var(--shadow);
  }
  .legend .lg-sw {
    width: 28px;
    height: 20px;
    display: grid;
    place-items: center;
    font-size: 10px;
    font-weight: 800;
    background: linear-gradient(135deg, var(--primary), var(--primary-dark));
    color: #fff;
    border-radius: 4px;
  }

  /* Drawer - Fixed overlay that slides in from right */
  .drawer-mask {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, 0.5);
    backdrop-filter: blur(4px);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.25s ease;
    z-index: 9998;
  }
  .drawer {
    position: fixed;
    right: calc(-1 * var(--drawer-w) - 20px);
    top: 0;
    height: 100vh;
    width: var(--drawer-w);
    max-width: 90vw;
    background: var(--card);
    box-shadow: var(--shadow-xl);
    transition: right 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 9999;
    display: flex;
    flex-direction: column;
    border-radius: var(--radius-xl) 0 0 var(--radius-xl);
    border: 1px solid var(--border);
    border-right: none;
  }
  .drawer.open { right: 0; }
  .drawer-mask.open { opacity: 1; pointer-events: auto; }
  
  .drawer .dh {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .drawer .dh h3 { margin: 0; font-size: 16px; font-weight: 700; }
  .drawer .dc { padding: 20px 24px; overflow: auto; flex: 1; }

  /* Meters */
  .meter {
    display: grid;
    grid-template-columns: 100px 1fr 48px;
    gap: 14px;
    align-items: center;
    margin-bottom: 14px;
  }
  .meter .tag { font-weight: 600; font-size: 12px; color: var(--ink-light); }
  
  /* ===== PROGRESS BAR (Simple CSS) ===== */
  .bar {
    position: relative;
    height: 24px;
    background: linear-gradient(180deg, #E8E0D4 0%, #F5F0E8 50%, #E8E0D4 100%);
    border-radius: 12px;
    overflow: hidden;
    box-shadow: inset 0 2px 4px rgba(100, 80, 50, 0.12);
  }
  
  .bar .scroll-parchment {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: var(--progress, 0%);
    background: linear-gradient(180deg, #C9A86C 0%, #E0C890 50%, #C9A86C 100%);
    border-radius: 12px;
    box-shadow: 2px 0 8px rgba(180, 140, 80, 0.3);
    transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  /* Hide unused elements in bar */
  .bar .scroll-rod-left,
  .bar .scroll-rod-right {
    display: none;
  }

  /* ===== SMALL METER (KPI cards) - Modern Elegant Style ===== */
  .meter-sm {
    position: relative;
    height: 8px;
    background: #E8ECF0;
    border-radius: 4px;
    overflow: hidden;
    margin-top: 10px;
    box-shadow: 
      inset 0 1px 2px rgba(0, 0, 0, 0.06),
      0 1px 0 rgba(255, 255, 255, 0.8);
  }
  
  .meter-sm .scroll-parchment {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: var(--progress, 0%);
    background: linear-gradient(90deg, #6366F1 0%, #818CF8 100%);
    border-radius: 4px;
    box-shadow: 0 0 8px rgba(99, 102, 241, 0.4);
    transition: width 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
  }
  
  /* Subtle shimmer effect on fill */
  .meter-sm .scroll-parchment::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
      90deg,
      transparent 0%,
      rgba(255, 255, 255, 0.25) 50%,
      transparent 100%
    );
    border-radius: 4px;
  }
  
  /* Hide unused elements */
  .meter-sm .scroll-rod-left,
  .meter-sm .scroll-rod-right,
  .meter-sm > span {
    display: none;
  }
  
  /* ===== ACCORDION FOR METERS SECTION ===== */
  .meters-accordion {
    border: 1px solid var(--border);
    border-radius: 12px;
    overflow: hidden;
    background: var(--card);
  }
  
  .meters-accordion-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 18px;
    background: linear-gradient(135deg, #F5EFE0 0%, #EDE4D0 100%);
    cursor: pointer;
    user-select: none;
    transition: background 0.2s ease;
    border-bottom: 1px solid var(--border);
  }
  
  .meters-accordion-header:hover {
    background: linear-gradient(135deg, #EDE4D0 0%, #E5DCC8 100%);
  }
  
  .meters-accordion-title {
    font-family: 'Cinzel', Georgia, serif;
    font-size: 14px;
    font-weight: 600;
    color: #4A3728;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  
  .meters-accordion-title::before {
    content: '';
    display: inline-block;
    width: 20px;
    height: 20px;
    background-image: var(--scroll-rod-image);
    background-size: contain;
    background-repeat: no-repeat;
    transform: rotate(90deg);
  }
  
  .meters-accordion-icon {
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(74, 55, 40, 0.1);
    border-radius: 50%;
    transition: transform 0.3s ease, background 0.2s ease;
  }
  
  .meters-accordion-icon svg {
    width: 14px;
    height: 14px;
    stroke: #4A3728;
    stroke-width: 2;
    fill: none;
    transition: transform 0.3s ease;
  }
  
  .meters-accordion.is-open .meters-accordion-icon {
    background: rgba(74, 55, 40, 0.2);
  }
  
  .meters-accordion.is-open .meters-accordion-icon svg {
    transform: rotate(180deg);
  }
  
  .meters-accordion-content {
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1);
  }
  
  .meters-accordion.is-open .meters-accordion-content {
    max-height: 800px;
  }
  
  .meters-accordion-inner {
    padding: 16px 18px;
  }
  
  @media (prefers-reduced-motion: reduce) {
    .meters-accordion-content {
      transition: none;
    }
    .meters-accordion-icon svg {
      transition: none;
    }
  }
    width: 0;
    transition: width 0.5s ease;
  }

  .dwMeters { display: flex; flex-direction: column; }
  .dwNotesStack { display: grid; grid-template-rows: 1fr 1fr; gap: 16px; margin-top: 16px; }
  
  .note-card {
    background: var(--border-light);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    min-height: 140px;
    max-height: 240px;
    overflow: auto;
  }
  .note-title {
    margin: 0 0 12px;
    font-size: 13px;
    font-weight: 700;
    color: var(--ink);
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    background: var(--border-light);
    padding-bottom: 8px;
  }
  .note-count { font-size: 11px; color: var(--muted); font-weight: 600; }
  .note-list { margin: 0; padding: 0; list-style: none; display: grid; gap: 8px; }
  .note-item {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 10px 12px;
    line-height: 1.4;
    font-size: 13px;
  }
  .note-empty {
    border: 1px dashed var(--border);
    background: var(--card);
    color: var(--muted);
    border-radius: 8px;
    padding: 16px;
    font-size: 13px;
    text-align: center;
  }

  /* ===== REALISTIC ANCIENT SCROLL - Like the reference image ===== */
  :root {
    --rod-w: clamp(32px, 6vw, 72px);
    --rod-image: url('https://rcncalifornia.org/staging2/wp-content/uploads/2026/02/ChatGPT-Image-Feb-3-2026-11_09_24-PM.png');
  }
  
  .scroll-container {
    position: relative;
    margin-bottom: 32px;
    margin-left: 12px;
    margin-right: 12px;
    max-width: 100%;
    overflow-x: clip;
    background: transparent;
    box-sizing: border-box;
  }
  
  .scroll-container *,
  .scroll-container *::before,
  .scroll-container *::after {
    box-sizing: border-box;
  }

  .scroll-wrapper {
    position: relative;
    overflow: visible;
    max-width: 100%;
    background: #FFFFFF;
    border-radius: 4px;
    padding-left: var(--rod-w);
    padding-right: var(--rod-w);
    transition: background 0.5s ease;
  }
  
  .scroll-container.is-open .scroll-wrapper {
    background: transparent;
  }

  /* Main parchment paper - fills inner area between rods */
  .scroll-parchment {
    position: relative;
    width: 100%;
    background: 
      linear-gradient(180deg, 
        #EDE4D3 0%,
        #F5EFE4 5%,
        #FAF7F0 15%,
        #F8F4EA 50%,
        #FAF7F0 85%,
        #F5EFE4 95%,
        #EDE4D3 100%
      );
    padding: 36px 20px;
    min-height: 380px;
    box-shadow: 
      0 4px 20px rgba(120, 100, 70, 0.1),
      inset 0 0 30px rgba(200, 180, 140, 0.08);
    display: flex;
    flex-direction: column;
    z-index: 1;
  }
  
  /* Subtle aged edge shadows at top and bottom */
  .scroll-parchment::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 30px;
    background: linear-gradient(180deg, rgba(180, 160, 120, 0.08) 0%, transparent 100%);
    z-index: 2;
    pointer-events: none;
  }
  
  .scroll-parchment::after {
    content: '';
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    height: 30px;
    background: linear-gradient(0deg, rgba(180, 160, 120, 0.08) 0%, transparent 100%);
    z-index: 2;
    pointer-events: none;
  }

  /* Paper texture overlay - subtle aging marks */
  .scroll-texture {
    position: absolute;
    inset: 0;
    background: 
      radial-gradient(ellipse 100px 80px at 15% 25%, rgba(180, 160, 120, 0.04) 0%, transparent 70%),
      radial-gradient(ellipse 80px 60px at 80% 70%, rgba(180, 160, 120, 0.03) 0%, transparent 70%);
    pointer-events: none;
    z-index: 0;
  }

  .scroll-content {
    position: relative;
    z-index: 1;
    opacity: 0;
    transition: opacity 1.5s ease 4s;
  }

  .scroll-container.is-open .scroll-content {
    opacity: 1;
  }

  /* ===== WOODEN ROLLERS - Background image approach ===== */
  .scroll-roller {
    position: absolute;
    top: 0;
    bottom: 0;
    width: var(--rod-w);
    z-index: 30;
    pointer-events: none;
    background-image: var(--rod-image);
    background-repeat: no-repeat;
    background-position: center;
    background-size: auto 100%;
    filter: drop-shadow(3px 3px 6px rgba(0,0,0,0.35));
    transition: 
      left 6s cubic-bezier(0.22, 0.61, 0.36, 1),
      right 6s cubic-bezier(0.22, 0.61, 0.36, 1);
  }
  
  /* Hide the old image elements - they must not affect layout */
  .scroll-roller .rod-wrap,
  .scroll-roller .rod-image {
    display: none !important;
  }

  /* Left roller - starts at center, opens to left inside edge */
  .scroll-roller.left {
    left: calc(50% - var(--rod-w) / 2);
    right: auto;
  }
  .scroll-container.is-open .scroll-roller.left {
    left: 0;
  }

  /* Right roller - starts at center, opens to right inside edge */
  .scroll-roller.right {
    right: calc(50% - var(--rod-w) / 2);
    left: auto;
  }
  .scroll-container.is-open .scroll-roller.right {
    right: 0;
  }

  /* ===== COVERING PANELS - Slide away to reveal parchment ===== */
  .scroll-cover-left,
  .scroll-cover-right {
    position: absolute;
    top: 0;
    bottom: 0;
    background: 
      linear-gradient(180deg, 
        #EDE4D3 0%,
        #F5EFE4 50%,
        #EDE4D3 100%
      );
    z-index: 15;
    transition: width 6s cubic-bezier(0.22, 0.61, 0.36, 1);
    overflow: hidden;
  }

  .scroll-cover-left {
    left: 0;
    width: 50%;
  }

  .scroll-cover-right {
    right: 0;
    width: 50%;
  }

  /* Subtle paper curl shadow on inner edges */
  .scroll-cover-left::after,
  .scroll-cover-right::after {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    width: 40px;
    pointer-events: none;
  }
  .scroll-cover-left::after { 
    right: 0; 
    background: linear-gradient(90deg, 
      transparent 0%,
      rgba(140, 120, 90, 0.12) 100%
    );
  }
  .scroll-cover-right::after { 
    left: 0; 
    background: linear-gradient(-90deg, 
      transparent 0%,
      rgba(140, 120, 90, 0.12) 100%
    );
  }

  /* Animation - Covers shrink to reveal content */
  .scroll-container.is-open .scroll-cover-left {
    width: 0;
  }
  .scroll-container.is-open .scroll-cover-right {
    width: 0;
  }

  /* ===== ANCIENT TYPOGRAPHY ON PARCHMENT ===== */
  .scroll-title {
    font-family: 'Cinzel', serif;
    font-size: 1.5rem;
    font-weight: 700;
    color: #4A3520;
    text-align: center;
    margin: 0 0 8px;
    letter-spacing: 4px;
    text-transform: uppercase;
    text-shadow: 1px 1px 0 rgba(255,255,255,0.3);
  }

  .scroll-subtitle {
    font-family: 'IM Fell English', serif;
    font-size: 1.05rem;
    color: #6B5035;
    text-align: center;
    font-style: italic;
    margin-bottom: 16px;
  }

  .scroll-divider {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 14px;
    margin-bottom: 20px;
  }
  .scroll-divider::before,
  .scroll-divider::after {
    content: '';
    flex: 1;
    max-width: 80px;
    height: 2px;
    background: linear-gradient(90deg, 
      transparent, 
      rgba(80, 50, 25, 0.35) 30%,
      rgba(80, 50, 25, 0.5) 50%,
      rgba(80, 50, 25, 0.35) 70%,
      transparent
    );
  }
  .scroll-divider-icon {
    font-size: 18px;
    color: #5A4028;
  }

  .commitment-list-scroll {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(240px, 100%), 1fr));
    gap: 12px 24px;
  }

  .commitment-row-scroll {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 14px 18px;
    background: rgba(200, 170, 120, 0.12);
    border-bottom: 1px solid rgba(100, 70, 35, 0.2);
    transition: all 0.2s ease;
  }

  .commitment-row-scroll:hover {
    background: rgba(200, 170, 120, 0.2);
  }

  .commitment-label-scroll {
    font-family: 'IM Fell English', serif;
    font-size: 1rem;
    color: #4A3520;
    font-weight: 400;
  }

  .commitment-value-scroll {
    font-family: 'Cinzel', serif;
    font-size: 0.85rem;
    font-weight: 600;
    color: #3A2815;
    background: rgba(160, 120, 60, 0.15);
    padding: 6px 14px;
    border-radius: 3px;
    min-width: 70px;
    text-align: center;
  }

  /* Wax Seal - Dark red */
  .scroll-seal {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: 50px;
    height: 50px;
    background: 
      radial-gradient(circle at 35% 35%, 
        #9B4040 0%, 
        #7B2828 50%, 
        #4A1010 100%
      );
    border-radius: 50%;
    box-shadow: 
      0 4px 16px rgba(60, 15, 15, 0.5),
      inset 2px 2px 6px rgba(255, 150, 150, 0.15),
      inset -1px -1px 4px rgba(0, 0, 0, 0.3);
    z-index: 20;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    transition: transform 0.25s ease, opacity 0.4s ease;
  }
  .scroll-seal:hover {
    transform: translate(-50%, -50%) scale(1.1);
  }
  .scroll-seal::before {
    content: '';
    position: absolute;
    inset: 5px;
    border-radius: 50%;
    border: 1px solid rgba(255, 200, 180, 0.12);
  }
  .scroll-seal::after {
    content: '\2020';
    font-size: 18px;
    color: rgba(255, 220, 200, 0.9);
    text-shadow: 0 1px 2px rgba(0,0,0,0.5);
  }

  /* Responsive adjustments */
  @media (max-width: 768px) {
    .scroll-container { margin-left: 8px; margin-right: 8px; }
    .scroll-parchment { padding: 32px 16px 28px; }
    .scroll-title { font-size: 1.2rem; letter-spacing: 2px; }
    .commitment-list-scroll { grid-template-columns: 1fr; gap: 8px; }
  }
  @media (max-width: 480px) {
    .scroll-container { margin-left: 4px; margin-right: 4px; }
    .scroll-parchment { padding: 24px 12px 20px; min-height: 300px; }
    .scroll-title { font-size: 1rem; letter-spacing: 1px; }
    .scroll-seal { width: 40px; height: 40px; }
    .scroll-seal::after { font-size: 16px; }
    .commitment-row-scroll { padding: 8px 10px; }
  }

  /* ===== NOTIFICATIONS - MODERN ===== */
  .notif-bell {
    position: relative;
    cursor: pointer;
    margin-right: 16px;
    color: var(--ink-light);
    width: 40px;
    height: 40px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--border-light);
    transition: all 0.2s ease;
  }
  .notif-bell:hover {
    background: var(--primary);
    color: #fff;
    transform: scale(1.05);
  }
  .notif-bell svg { width: 20px; height: 20px; }
  .notif-count {
    position: absolute;
    top: -2px;
    right: -2px;
    background: var(--danger);
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    border-radius: 999px;
    padding: 2px 6px;
    min-width: 18px;
    text-align: center;
    box-shadow: 0 2px 4px rgba(239, 68, 68, 0.3);
  }
  .notif-dropdown {
    display: none;
    position: absolute;
    right: 0;
    top: 48px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-xl);
    width: 320px;
    z-index: 999;
    overflow: hidden;
  }
  .notif-dropdown h4 {
    margin: 0;
    font-size: 13px;
    font-weight: 700;
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    background: var(--border-light);
    color: var(--ink);
  }
  .notif-dropdown .notif-item {
    padding: 14px 18px;
    font-size: 13px;
    border-bottom: 1px solid var(--border-light);
    cursor: pointer;
    transition: background 0.15s ease;
    color: var(--ink);
  }
  .notif-dropdown .notif-item:hover {
    background: var(--border-light);
    color: var(--ink);
  }
  .notif-dropdown .notif-item.is-read {
    color: var(--ink-light);
  }
  .notif-empty {
    padding: 20px;
    color: var(--muted);
    font-size: 13px;
    text-align: center;
  }
  .notif-item.notif-promotion { border-left: 4px solid var(--success); }
  .notif-item.notif-missed { border-left: 4px solid var(--danger); }
  .notif-item.notif-general { border-left: 4px solid var(--primary); }

  /* ===== PROGRAM CARD - MODERN ===== */
  .program-card {
    background: var(--card);
    padding: 28px 32px;
    border-radius: var(--radius-xl);
    border: 1px solid var(--border);
    margin-bottom: 28px;
    position: relative;
    overflow: hidden;
  }
  .program-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, var(--primary), var(--primary-light), var(--accent));
  }

  .pc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    gap: 20px;
  }
  .pc-title {
    font-size: 20px;
    font-weight: 700;
    color: var(--ink);
    letter-spacing: -0.3px;
  }
  .pc-sub {
    font-size: 13px;
    color: var(--muted);
    margin-top: 4px;
  }
  .pc-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 24px;
  }
  .pc-item {
    padding: 16px 20px;
    background: var(--border-light);
    border-radius: var(--radius);
    border: 1px solid transparent;
    transition: all 0.2s ease;
  }
  .pc-item:hover {
    border-color: var(--primary-light);
    transform: translateY(-2px);
  }
  .pc-label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 6px;
  }
  .pc-value {
    font-size: 15px;
    font-weight: 600;
    color: var(--ink);
  }
  .pc-grace-pill {
    background: #FFFBEB;
    padding: 6px 14px;
    border-radius: 999px;
    color: #D97706;
    font-size: 12px;
    font-weight: 600;
    border: 1px solid rgba(217, 119, 6, 0.2);
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .pc-flag {
    padding: 8px 16px;
    border-radius: 999px;
    font-weight: 600;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .pc-flag.ok { background: #ECFDF5; color: #059669; }
  .pc-flag.warn { background: #FFFBEB; color: #D97706; }
  .pc-flag.bad { background: #FEF2F2; color: #DC2626; }

  /* ===== FOCUS-TO-EXPAND BARS (Accordion) ===== */
  [data-bar] {
    position: relative;
  }
  
  [data-bar-trigger] {
    cursor: pointer;
    user-select: none;
    display: flex;
    align-items: center;
    justify-content: space-between;
    transition: background 0.2s ease;
  }
  
  [data-bar-trigger]:hover {
    background: var(--border-light);
  }
  
  [data-bar-trigger]:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
  }
  
  /* Expand icon indicator */
  [data-bar-trigger]::after {
    content: '';
    width: 20px;
    height: 20px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%236B7280' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-size: contain;
    background-repeat: no-repeat;
    transition: transform 0.3s ease;
    flex-shrink: 0;
  }
  
  [data-bar].is-open [data-bar-trigger]::after {
    transform: rotate(180deg);
  }
  
  /* Panel - closed by default */
  [data-bar-panel] {
    max-height: 0;
    opacity: 0;
    overflow: hidden;
    transform: translateY(-8px);
    transition: 
      max-height 0.4s cubic-bezier(0.4, 0, 0.2, 1),
      opacity 0.3s ease,
      transform 0.3s ease;
  }
  
  /* Panel - open state */
  [data-bar].is-open [data-bar-panel] {
    max-height: 2000px;
    opacity: 1;
    transform: translateY(0);
  }
  
  /* Pinned indicator */
  [data-bar][data-pinned="1"] [data-bar-trigger]::before {
    content: '';
    width: 8px;
    height: 8px;
    background: var(--primary);
    border-radius: 50%;
    margin-right: 8px;
    flex-shrink: 0;
  }
  
  /* Reduced motion */
  @media (prefers-reduced-motion: reduce) {
    [data-bar-panel] {
      transition: none;
    }
    [data-bar-trigger]::after {
      transition: none;
    }
  }
	
</style>
</head>
<body>
<header class="top-wide">
  <div style="display:flex;align-items:center;gap:20px;">
    <div style="font-size:22px;font-weight:700;color:var(--ink);letter-spacing:-0.5px;">
      <span style="background:linear-gradient(135deg, var(--primary), var(--primary-light));-webkit-background-clip:text;-webkit-text-fill-color:transparent;">Discipleship</span> Dashboard
    </div>
  </div>

  <div style="display:flex;align-items:center;gap:12px;">
  <!-- Notification Bell -->
  <div class="notif-bell" id="notifBell" title="Notifications">
    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9" />
    </svg>
    <span class="notif-count" id="notifCount" style="<?php echo $unread_count ? '' : 'display:none;'; ?>">
      <?php echo (int)$unread_count; ?>
    </span>

    <div class="notif-dropdown" id="notifDropdown">
      <h4>Notifications</h4>
      <div id="notifList">
        <?php if (empty($notifications)): ?>
            <p class="notif-empty">No notifications yet</p>
        <?php else: ?>
          <?php foreach ($notifications as $n): ?>
            <div class="notif-item notif-<?php echo esc_attr($n->type); ?> <?php echo $n->is_read ? 'is-read' : ''; ?>"
                 data-id="<?php echo (int)$n->id; ?>">
                <div style="font-size:11px;color:var(--muted);margin-bottom:4px;">
                <?php echo esc_html(date_i18n('M j, Y g:ia', strtotime($n->created_at))); ?>
              </div>
                <div style="font-weight:500;"><?php echo esc_html($n->message); ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    </div>

    <!-- User Badge - Clickable to Profile -->
    <a href="<?php echo esc_url(home_url('/profile/')); ?>" class="who" id="whoChip" title="Edit Profile"
         data-name="<?php echo esc_attr($view_name); ?>"
         data-email="<?php echo esc_attr($target_email_l); ?>"
         data-has-picture="<?php echo $has_profile_picture ? '1' : '0'; ?>"
         data-ajax-base="<?php echo esc_attr($ajax_base); ?>"
         data-req-nonce="<?php echo esc_attr($dd_req_nonce); ?>"
         data-view-nonce="<?php echo esc_attr($dd_view_nonce); ?>">
      <div class="avatar" id="whoAvatar">
        <?php if ($has_profile_picture): ?>
          <img src="<?php echo esc_url($profile_picture_url); ?>" 
               alt="<?php echo esc_attr($view_name); ?>" 
               class="avatar-img" 
               id="avatarImg"
               onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
          <span class="avatar-initials" id="avatarInitials" style="display:none;"></span>
        <?php else: ?>
          <span class="avatar-initials" id="avatarInitials"></span>
        <?php endif; ?>
      </div>
      <div class="meta">
        <div class="name" id="whoName"><?php echo esc_html($view_name); ?></div>
        <div class="mail" id="whoMail"><?php echo esc_html($target_email_l); ?></div>
      </div>
      <svg class="who-edit-icon" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
      </svg>
    </a>
  </div>
</header>

<div class="wrap">
  <div class="top-wide">

    <!-- Program / Level Card -->
    <div class="program-card">
      <div class="pc-header">
        <div>
          <div class="pc-title"><?php echo esc_html($program_name); ?></div>
        <?php if ($program_description): ?>
          <div class="pc-sub"><?php echo esc_html($program_description); ?></div>
        <?php endif; ?>
        </div>
      </div>

      <div class="pc-row">
        <div class="pc-item">
          <span class="pc-label">Current Level</span>
          <span class="pc-value">
            <?php 
              echo esc_html($level_name);
              if ($level_number > 0) {
                  echo " (Level " . intval($level_number) . ")";
              }
            ?>
          </span>
        </div>

        <div class="pc-item">
          <span class="pc-label">Description</span>
          <span class="pc-value" style="font-weight:400;">
            <?php echo esc_html($level_description ?: '—'); ?>
          </span>
        </div>

        <div class="pc-item">
          <span class="pc-label">Duration</span>
          <span class="pc-value">
            <?php echo $participant ? intval($participant->duration_days) : 0; ?> days
          </span>
        </div>

        <div class="pc-item">
          <span class="pc-label">Grace Period</span>
          <span class="pc-value">
            <?php echo $participant ? intval($participant->grace_period_days) : 0; ?> days
          </span>
        </div>

        <div class="pc-item">
          <span class="pc-label">Promotion Threshold</span>
          <span class="pc-value">
            <?php echo number_format((float)$level_threshold, 1); ?>%
          </span>
          </div>
      </div>
    </div>

    <!-- KPIs -->
    <div class="kpis">
      <div class="card">
        <h5>Overall Attainment</h5>
        <div id="attainment" class="big">
          <?php echo esc_html($attn_pct_str); ?>
        </div>
        <div id="attnFlag" class="<?php echo esc_attr($attn_flag_class); ?>">
          <?php echo esc_html($attn_flag_text); ?>
        </div>
      </div>

      <div class="card">
        <h5>Days Remaining</h5>
        <div id="days" class="big"><?php echo esc_html($days_remaining_str); ?></div>
        <div class="meter-sm">
          <div class="scroll-parchment" id="daysBar" style="--progress:<?php echo (int)$days_bar_pct; ?>%"></div>
        </div>
        <div id="period" class="sub"><?php echo esc_html($period_text); ?></div>
      </div>

      <div class="card">
        <h5>Weakest Area</h5>
        <div class="big" id="weak">
          <?php echo esc_html($weak_text); ?>
        </div>
        <div class="sub">Lowest average across this level</div>
      </div>

      <div class="card">
        <h5>Strongest Area</h5>
        <div class="big" id="strong">
          <?php echo esc_html($strong_text); ?>
        </div>
        <div class="sub">Highest average across this level</div>
      </div>
    </div>

    <!-- Commitments - Ancient Scroll -->
    <?php
    $commitments_user = $view_user ?: $current;
    $commitments = [
        'Bible Reading'            => get_user_meta($commitments_user->ID, 'disciple_bible_reading', true),
        'Fasting'                  => get_user_meta($commitments_user->ID, 'disciple_fasting', true),
        'Scripture Memorization'   => get_user_meta($commitments_user->ID, 'disciple_memorization', true),
        'Morning Prayer'           => get_user_meta($commitments_user->ID, 'disciple_morning_prayer', true),
        'Midnight Prayer'          => get_user_meta($commitments_user->ID, 'disciple_midnight_prayer', true),
        'Bible Study & Meditation' => get_user_meta($commitments_user->ID, 'disciple_bible_study', true),
        'Commitment Duration'      => get_user_meta($commitments_user->ID, 'disciple_commitment_duration', true),
    ];
    ?>
		
    <div class="scroll-container" id="commitmentsScroll">
      <div class="scroll-wrapper">
        <!-- Parchment paper -->
        <div class="scroll-parchment">
          <div class="scroll-texture"></div>
          <div class="scroll-content">
            <h2 class="scroll-title">My Commitments</h2>
            <p class="scroll-subtitle">~ Commitments made before the Lord ~</p>
            
            <div class="scroll-divider">
              <span class="scroll-divider-icon">&#10016;</span>
            </div>
            
            <div class="commitment-list-scroll">
        <?php foreach ($commitments as $label => $value) : ?>
              <div class="commitment-row-scroll">
                <span class="commitment-label-scroll"><?php echo esc_html($label); ?></span>
                <span class="commitment-value-scroll"><?php echo esc_html($value ?: '—'); ?></span>
          </div>
        <?php endforeach; ?>
            </div>
          </div>
        </div>
        
        <!-- Cover panels that slide away -->
        <div class="scroll-cover-left"></div>
        <div class="scroll-cover-right"></div>
        
        <!-- Wooden rollers with scroll rod image -->
        <div class="scroll-roller left">
          <div class="rod-wrap">
            <img src="https://rcncalifornia.org/staging2/wp-content/uploads/2026/02/ChatGPT-Image-Feb-3-2026-11_09_24-PM.png" alt="" class="rod-image">
          </div>
        </div>
        <div class="scroll-roller right">
          <div class="rod-wrap">
            <img src="https://rcncalifornia.org/staging2/wp-content/uploads/2026/02/ChatGPT-Image-Feb-3-2026-11_09_24-PM.png" alt="" class="rod-image">
          </div>
        </div>
        
        <!-- Wax Seal in center -->
        <div class="scroll-seal" id="scrollSeal" title="Click to unroll the scroll"></div>
      </div>
    </div>

    <!-- Charts -->
    <div class="grid mid section" style="margin-top:8px;">
      <div class="card">
        <h2>Weekly Attainment Trend</h2>
        <canvas id="trend"></canvas>
      </div>
      <div class="card">
        <h2>Level Practice Strength Map</h2>
        <canvas id="radar"></canvas>
        <div id="projection" class="sub" style="margin-top:8px">
          <?php
          if ($radar_data) {
              echo esc_html("Level Average — Strongest: {$strong_label} · Weakest: {$weak_label}");
          } else {
              echo 'No level data yet';
          }
          ?>
        </div>
      </div>
    </div>
  </div>

  <!-- CALENDAR -->
  <div class="grid bottom section" style="margin-top:24px;">
    <div class="card" id="calCard" style="position:relative;">
      <div class="calendar" id="cal">
        <div class="cal-nav">
          <div class="cal-title"><h2 id="calTitle">This Month's Discipleship Calendar</h2></div>
          <div class="cal-actions">
            <button class="btn btn-ghost" id="btnPrev" type="button">‹</button>
            <button class="btn btn-ghost" id="btnToday" type="button">Today</button>
            <button class="btn btn-ghost" id="btnNext" type="button">›</button>
          </div>
        </div>

        <div class="day-headers" style="display:grid;grid-template-columns:repeat(7,1fr);gap:12px;margin-top:12px;padding:0 4px;">
          <div class="day-header" style="text-align:center;font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Sun</div>
          <div class="day-header" style="text-align:center;font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Mon</div>
          <div class="day-header" style="text-align:center;font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Tue</div>
          <div class="day-header" style="text-align:center;font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Wed</div>
          <div class="day-header" style="text-align:center;font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Thu</div>
          <div class="day-header" style="text-align:center;font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Fri</div>
          <div class="day-header" style="text-align:center;font-size:11px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">Sat</div>
        </div>

        <div class="cal-scroll">
          <div class="cal-grid" id="calGrid"></div>
        </div>

        <div class="legend" id="legend"></div>
      </div>

      <div class="drawer-mask" id="drawerMask"></div>
      <div class="drawer" id="drawer" aria-modal="true" role="dialog">
        <div class="dh">
          <h3 id="dwTitle">Week Summary</h3>
          <button class="btn btn-ghost" id="dwClose" type="button">Close</button>
        </div>
        <div class="dc" id="dwBody"></div>
      </div>
    </div>
  </div>
</div>

<div id="cal-tip" role="status" aria-live="polite"></div>

<!-- Summary data for JS -->
<script>
  const DC_TREND = <?php echo wp_json_encode($trend_js); ?>;
  const DC_RADAR = <?php echo wp_json_encode($radar_js); ?>;
  const NOTIF_NONCE = "<?php echo esc_js($notif_nonce); ?>";
</script>

<script>
	
	
  // ===== ANCIENT SCROLL ANIMATION WITH SCROLL-DRIVEN ROD ROTATION =====
  document.addEventListener('DOMContentLoaded', () => {
    const scrollContainer = document.getElementById('commitmentsScroll');
    const scrollSeal = document.getElementById('scrollSeal');
    const rodWraps = document.querySelectorAll('.scroll-roller .rod-wrap');
    
    // Check for reduced motion preference
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    
    // ===== SCROLL-DRIVEN ROD ROTATION =====
    if (!prefersReducedMotion && rodWraps.length > 0) {
      let currentRotation = 0;
      let targetRotation = 0;
      let rafId = null;
      
      // Calculate rotation based on scroll position
      // One full viewport height = 360 degrees
      const updateTargetRotation = () => {
        const scrollY = window.scrollY || window.pageYOffset;
        const viewportHeight = window.innerHeight;
        // Rotation proportional to scroll: 1 viewport = 360deg
        targetRotation = (scrollY / viewportHeight) * 360;
      };
      
      // Smooth interpolation for inertial feel
      const lerp = (start, end, factor) => start + (end - start) * factor;
      
      // Animation loop using requestAnimationFrame
      const animateRotation = () => {
        // Smooth easing toward target (0.08 = smooth inertia)
        currentRotation = lerp(currentRotation, targetRotation, 0.08);
        
        // Apply rotation to both rod wraps
        // rotateY makes horizontal rod spin around its vertical axis (like a rolling pin viewed from side)
        rodWraps.forEach(rodWrap => {
          rodWrap.style.transform = `rotateY(${currentRotation}deg)`;
        });
        
        // Continue animation if not at target (within 0.1 degree tolerance)
        if (Math.abs(targetRotation - currentRotation) > 0.1) {
          rafId = requestAnimationFrame(animateRotation);
        } else {
          rafId = null;
        }
      };
      
      // Throttled scroll handler
      let scrollTicking = false;
      const onScroll = () => {
        updateTargetRotation();
        
        if (!scrollTicking) {
          scrollTicking = true;
          requestAnimationFrame(() => {
            scrollTicking = false;
          });
        }
        
        // Start animation loop if not already running
        if (!rafId) {
          rafId = requestAnimationFrame(animateRotation);
        }
      };
      
      // Listen for scroll events
      window.addEventListener('scroll', onScroll, { passive: true });
      
      // Initialize rotation on load
      updateTargetRotation();
      currentRotation = targetRotation; // Start at current position (no initial animation)
      rodWraps.forEach(rodWrap => {
        rodWrap.style.transform = `rotateY(${currentRotation}deg)`;
      });
    }
    
    // ===== SCROLL REVEAL ANIMATION - Triggers when in viewport =====
    if (scrollContainer) {
      let hasOpened = false;
      
      const openScroll = () => {
        if (hasOpened || scrollContainer.classList.contains('is-open')) return;
        hasOpened = true;
        
        scrollContainer.classList.add('is-open');
        
        // Fade out seal as rollers start moving apart
        if (scrollSeal) {
          setTimeout(() => {
            scrollSeal.style.opacity = '0';
            scrollSeal.style.pointerEvents = 'none';
          }, 400);
        }
      };
      
      // Use IntersectionObserver to trigger when scroll-container is in focus (60%+ visible)
      if ('IntersectionObserver' in window && !prefersReducedMotion) {
        const scrollObserver = new IntersectionObserver((entries) => {
          entries.forEach(entry => {
            if (entry.isIntersecting && entry.intersectionRatio >= 0.5) {
              openScroll();
              // Disconnect observer after opening (only need to trigger once)
              scrollObserver.disconnect();
            }
          });
        }, {
          threshold: [0, 0.5, 1],
          rootMargin: '0px'
        });
        
        scrollObserver.observe(scrollContainer);
      } else {
        // Fallback for browsers without IntersectionObserver or reduced motion
        setTimeout(openScroll, 1000);
      }
      
      // Also allow clicking the seal to open
      if (scrollSeal) {
        scrollSeal.addEventListener('click', (e) => {
          e.stopPropagation();
          openScroll();
        });
      }
      
      // Click anywhere on closed scroll to open
      scrollContainer.addEventListener('click', openScroll);
    }
  });
	
  // Notification click handler (mark as read)
  document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.notif-item').forEach(item => {
      item.addEventListener('click', async function() {
        const notifId = this.dataset.id;
        if (!notifId) return;

        try {
          const resp = await fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
            method: 'POST',
			credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
              action: 'mark_notif_read',
              id: notifId,
              nonce: NOTIF_NONCE
            })
          });

          const json = await resp.json();
          if (json.success) {
            this.classList.add('is-read');
            const c = document.getElementById('notifCount');
            if (c) {
              let num = parseInt(c.textContent || '0', 10);
              if (num > 0) {
                num--;
                if (num === 0) {
                  c.style.display = 'none';
                }
                c.textContent = num;
              }
            }
          }
        } catch (e) {
          console.error('Failed to mark notification read', e);
        }
      });
    });
  });

  const PRACTICES = [
    {abbr:'BR', db:'Bible Reading',                 label:'Bible Reading',                 unit:'daily'},
    {abbr:'F',  db:'Fasting',                       label:'Fasting',                       unit:'weekly'},
    {abbr:'MI', db:'Morning Intimacy',              label:'Morning Intimacy',              unit:'daily'},
    {abbr:'MP', db:'Midnight Intercessory Prayer',  label:'Midnight Prayer',               unit:'weekly'},
    {abbr:'BS', db:'Bible Study & Meditation',      label:'Bible Study & Meditation',      unit:'weekly'},
    {abbr:'SM', db:'Scripture Memorization',        label:'Scripture Memorization',        unit:'weekly'},
    {abbr:'CP', db:'Corporate Prayers',             label:'Corporate Prayers',             unit:'weekly'},
  ];
  const DB2ABBR   = Object.fromEntries(PRACTICES.map(p=>[p.db,p.abbr]));
  const ABBR2UNIT = Object.fromEntries(PRACTICES.map(p=>[p.abbr,p.unit]));
  const monthShort = d => d.toLocaleString(undefined,{month:'short'});
  const fmtMonth   = d => d.toLocaleString(undefined,{month:'long', year:'numeric'});
  const ymd  = d => d.toISOString().slice(0,10);
  const sod  = d => { const x=new Date(d); x.setHours(0,0,0,0); return x; };

  function weekStartSun(d){ const x=sod(d); const day=x.getDay(); x.setDate(x.getDate()-day); return x; }
  function weekEndSun(d){ const x=weekStartSun(d); const y=new Date(x); y.setDate(y.getDate()+6); return y; }

  function renderLegend(){
    const el = document.getElementById('legend'); if (!el) return;
    el.innerHTML = PRACTICES.map(p => `
      <div class="lg-chip"><span class="lg-sw">${p.abbr}</span><span>${p.label}</span></div>
    `).join('');
  }

  const chip = document.getElementById('whoChip');
  const AJAX_BASE     = chip?.dataset?.ajaxBase || '';
  const DD_REQ_NONCE  = chip?.dataset?.reqNonce || '';
  const DD_VIEW_NONCE = chip?.dataset?.viewNonce || '';

  const qParams = new URLSearchParams(window.location.search);
  const qParticipant = qParams.get('participant');

  let calendarMonth = (()=>{ const t=sod(new Date()); t.setDate(1); return t; })();
  const dailyMap  = {};
  const weeklyMap = {};
  const weekNotes = {};

  function esc(s){
    return String(s||'').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;', "'":'&#39;'}[m]));
  }
  function addNote(wsKey, kind, payload){
    const bucket = (weekNotes[wsKey] ||= { general:[], other:[] });
    bucket[kind].push(payload);
  }

  async function loadData(){
    const participant = qParticipant || (chip?.dataset?.email || '');
    const body = new URLSearchParams({
      dd_req_nonce: DD_REQ_NONCE,
      participant: participant || ''
    });
    if (DD_VIEW_NONCE) body.set('dd_view_nonce', DD_VIEW_NONCE);

    const r = await fetch(AJAX_BASE, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded'},
      credentials:'same-origin',
      body: body.toString()
    });

    const json = await r.json();
    if (Array.isArray(json)) return json;
    if (json && Array.isArray(json.rows)) return json.rows;
    if (json && json.success && Array.isArray(json.data?.rows)) return json.data.rows;
    if (json && json.success && Array.isArray(json.data)) return json.data;
    if (json && json.data && json.data.message) throw new Error(json.data.message);
    return [];
  }

  function ingest(rows){
    rows.forEach(r=>{
      const ab = DB2ABBR[r.practice]; if(!ab) return;
      const v  = Math.max(0, Math.min(1, Number(r.value||0)));

      let wsKey = r.week_start;
      if (!wsKey && r.date){
        const ws = weekStartSun(new Date(r.date));
        wsKey = ymd(ws);
      }
      if (!wsKey) return;

      if (ABBR2UNIT[ab]==='daily'){
        (dailyMap[r.date] ||= {})[ab] = v;
      } else {
        (weeklyMap[wsKey] ||= {})[ab] = v;
      }

      const cg = (r.comment_general||'').trim();
      const co = (r.comment_other||'').trim();
      if (cg){ addNote(wsKey, 'general', { txt: cg, date: r.date || wsKey, practice: ab }); }
      if (co){ addNote(wsKey, 'other',   { txt: co, date: r.date || wsKey, practice: ab }); }
    });
  }

  function buildMonthGrid(monthDate){
    const first = new Date(monthDate);
    const start = weekStartSun(first);
    return Array.from({length:42}, (_,i)=>{ const d=new Date(start); d.setDate(start.getDate()+i); return d; });
  }

  function renderCalendar(){
    const grid = document.getElementById('calGrid');
    if (!grid) return;
    grid.innerHTML = '';
    const today = ymd(sod(new Date()));
    const first = new Date(calendarMonth);
    const monthIdx = first.getMonth();
    document.getElementById('calTitle').textContent = fmtMonth(first);

    buildMonthGrid(first).forEach(d=>{
      const ds = ymd(d);
      const wkSun = ymd(weekStartSun(d));

      const cell = document.createElement('div');
      cell.className = 'cal-day';
      if (d.getMonth() !== monthIdx) cell.classList.add('is-out');
      if (ds === today) cell.classList.add('is-today');

      cell.innerHTML = `
        <div class="dhead"><div class="dnum">${d.getDate()}</div><div></div></div>
        <div class="badges" aria-label="Practice badges"></div>
      `;

      const wrap = cell.querySelector('.badges');
      PRACTICES.forEach(p=>{
        const v = (p.unit==='daily') ? (dailyMap[ds]?.[p.abbr] ?? 0) : (weeklyMap[wkSun]?.[p.abbr] ?? 0);
        const st = (v>=1) ? 'full' : (v>0 ? 'partial' : 'none');
        const b = document.createElement('div');
        b.className = 'badge p-'+p.abbr+' '+(st==='full'?'full':(st==='partial'?'partial':'p-0'));
        b.dataset.abbr=p.abbr; b.dataset.label=p.label; b.dataset.value=v; b.dataset.unit=p.unit;
        b.textContent=p.abbr;
        wrap.appendChild(b);
      });

      cell.addEventListener('click', ()=>openWeekDrawer(weekStartSun(d)));
      grid.appendChild(cell);
    });

    localStorage.setItem('calMonth', first.toISOString());
  }

  function weekSummary(wsDate){
    const ws = ymd(wsDate);
    const days = Array.from({length:7},(_,i)=>{ const x=new Date(wsDate); x.setDate(x.getDate()+i); return ymd(x); });
    const out = {};
    PRACTICES.forEach(p=>{
      if (p.unit==='weekly'){
        out[p.abbr] = Math.round(((weeklyMap[ws]?.[p.abbr]) ?? 0) * 100);
      } else {
        let sum=0; days.forEach(d => sum += (dailyMap[d]?.[p.abbr] ?? 0));
        out[p.abbr] = Math.round((sum/7)*100);
      }
    });
    return out;
  }

  function openWeekDrawer(weekStartDate){
    const ws = sod(weekStartDate);
    const we = weekEndSun(ws);
    const wsKey = ymd(ws);

    const title = `${monthShort(ws)} ${ws.getDate()} - ${monthShort(we)} ${we.getDate()}`;
    document.getElementById('dwTitle').textContent = `Week of ${title}`;

    const body = document.getElementById('dwBody');
    body.innerHTML = `
      <div class="dwMeters" id="dwMeters"></div>
      <div class="dwNotesStack" id="dwNotes"></div>
    `;

    const pct = weekSummary(ws);
    const metersEl = body.querySelector('#dwMeters');
    
    // Create accordion wrapper
    const accordion = document.createElement('div');
    accordion.className = 'meters-accordion is-open';
    accordion.innerHTML = `
      <div class="meters-accordion-header">
        <div class="meters-accordion-title">Practice Breakdown</div>
        <div class="meters-accordion-icon">
          <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"></polyline></svg>
        </div>
      </div>
      <div class="meters-accordion-content">
        <div class="meters-accordion-inner" id="metersInner"></div>
      </div>
    `;
    metersEl.appendChild(accordion);
    
    // Add click handler for accordion
    const header = accordion.querySelector('.meters-accordion-header');
    header.addEventListener('click', () => {
      accordion.classList.toggle('is-open');
    });
    
    // Render meters inside accordion
    const metersInner = accordion.querySelector('#metersInner');
    PRACTICES.forEach(p=>{
      const row = document.createElement('div');
      row.className = 'meter';
      row.innerHTML = `
        <div class="tag">${p.abbr} - ${p.label}</div>
        <div class="bar">
          <div class="scroll-parchment" style="--progress:${pct[p.abbr]}%"></div>
        </div>
        <div style="text-align:right;font-weight:800;font-size:13px;color:#5D4037;min-width:48px;">${pct[p.abbr]}%</div>
      `;
      metersInner.appendChild(row);
    });

    const notes = weekNotes[wsKey] || { general:[], other:[] };
    const dedupe = arr => {
      const seen = new Set(), out=[];
      arr.forEach(it=>{
        const key = `${(it.date||'')}|${(it.practice||'')}|${it.txt}`.toLowerCase();
        if (!seen.has(key)){ seen.add(key); out.push(it); }
      });
      return out;
    };
    const gen = dedupe(notes.general);
    const oth = dedupe(notes.other);

    const makeList = (items) => {
      if (!items.length) return `<div class="note-empty">No notes for this week.</div>`;
      const li = items.map(it => `
        <li class="note-item">
          <div>${esc(it.txt)}</div>
        </li>
      `).join('');
      return `<ul class="note-list">${li}</ul>`;
    };

    const notesEl = body.querySelector('#dwNotes');
    notesEl.innerHTML = `
      <div class="note-card">
        <h4 class="note-title">General Notes <span class="note-count">${gen.length}</span></h4>
        ${makeList(gen)}
      </div>
      <div class="note-card">
        <h4 class="note-title">Other Notes <span class="note-count">${oth.length}</span></h4>
        ${makeList(oth)}
      </div>
    `;

    document.getElementById('drawer').classList.add('open');
    document.getElementById('drawerMask').classList.add('open');
  }

  function closeWeekDrawer(){
    document.getElementById('drawer').classList.remove('open');
    document.getElementById('drawerMask').classList.remove('open');
  }

  const tip = document.getElementById('cal-tip');
  function showTip(html,x,y){ tip.innerHTML=html; tip.style.left=(x+12)+'px'; tip.style.top=(y+12)+'px'; tip.style.opacity=1; tip.style.transform='translateY(0)'; }
  function hideTip(){ tip.style.opacity=0; tip.style.transform='translateY(-6px)'; }
  function pctFmt(x){ return (Math.round(x*1000)/10).toFixed(1)+"%"; }

  function initials(full){
    const parts = (full||'').trim().split(/\s+/).slice(0,2);
    return parts.map(s=>s[0]?.toUpperCase()||'').join('') || 'U';
  }

  async function main(){
    const name = chip?.dataset?.name || '';
    const avatarInitials = document.getElementById('avatarInitials');
    if (avatarInitials && !avatarInitials.textContent) {
      avatarInitials.textContent = initials(name);
    }

    renderLegend();

    if (qParticipant) {
      const mailEl = document.getElementById('whoMail');
      if (mailEl) mailEl.textContent = (qParticipant || '').toLowerCase();
    }

    // ---- Load raw commitment rows for calendar / notes ----
    let rows = [];
    try {
      rows = await loadData();
    } catch (e) {
      console.error('Error loading discipleship data', e);
    }

    if (rows && rows.length){
      if (rows[0].participant_name) {
        const nEl = document.getElementById('whoName');
        if (nEl) nEl.textContent = rows[0].participant_name;
        if (avatarInitials) avatarInitials.textContent = initials(rows[0].participant_name);
      }
      if (rows[0].participant_id) {
        const mEl = document.getElementById('whoMail');
        if (mEl) mEl.textContent = rows[0].participant_id;
      }

      ingest(rows);
    }

    renderCalendar();

    const saved = localStorage.getItem('calMonth');
    if (saved){ const d=new Date(saved); if(!isNaN(d)){ calendarMonth = sod(new Date(d.getFullYear(), d.getMonth(), 1)); renderCalendar(); } }

    const grid = document.getElementById('calGrid');
    if (grid) {
      grid.addEventListener('mousemove', e=>{
        const b = e.target.closest('.badge');
        if (!b){ hideTip(); return; }
        const v = Number(b.dataset.value||0);
        const pctStr = Math.round(v*100) + '%';
        const status = (v>=1) ? {txt:'Full',cls:'ok'} : (v>0) ? {txt:'Partial',cls:'warn'} : {txt:'None',cls:'bad'};
        const cadence = (b.dataset.unit==='weekly') ? 'Weekly target' : 'Daily practice';
        const html = `
          <div class="title">${b.dataset.abbr} · ${b.dataset.label}</div>
          <div class="meta"><span class="pill ${status.cls}">${status.txt}</span><span class="sub">${cadence} · ${pctStr}</span></div>
          <div class="hint">Click to view this week</div>
        `;
        showTip(html, e.clientX, e.clientY);
      });
      grid.addEventListener('mouseleave', hideTip);
    }

    document.getElementById('btnPrev').addEventListener('click', ()=>{ const m=new Date(calendarMonth); m.setMonth(m.getMonth()-1); m.setDate(1); calendarMonth=sod(m); renderCalendar(); });
    document.getElementById('btnNext').addEventListener('click', ()=>{ const m=new Date(calendarMonth); m.setMonth(m.getMonth()+1); m.setDate(1); calendarMonth=sod(m); renderCalendar(); });
    document.getElementById('btnToday').addEventListener('click', ()=>{ const t=new Date(); calendarMonth=sod(new Date(t.getFullYear(), t.getMonth(), 1)); renderCalendar(); });
    document.getElementById('drawerMask').addEventListener('click', closeWeekDrawer);
    document.getElementById('dwClose').addEventListener('click', closeWeekDrawer);

    // ---- Charts from summary table ----
    if (Array.isArray(DC_TREND) && DC_TREND.length && window.Chart) {
      const trendCanvas = document.getElementById('trend');
      if (trendCanvas) {
        const trendCtx = trendCanvas.getContext('2d');
        const labels = DC_TREND.map(r => r.week_start);
        const vals   = DC_TREND.map(r => Number(r.overall_attainment || 0));
        new Chart(trendCtx, {
          type:'line',
          data:{
            labels: labels,
            datasets:[{
              label:'Weekly Attainment %',
              data: vals
            }]
          },
          options:{
            responsive:true,
            maintainAspectRatio:false,
            scales:{
              y:{ beginAtZero:true, suggestedMax:100 }
            }
          }
        });
      }
    }

    if (DC_RADAR && window.Chart) {
      const radarCanvas = document.getElementById('radar');
      if (radarCanvas) {
        const radarCtx = radarCanvas.getContext('2d');
        const labels = [
          'Bible Reading',
          'Morning Intimacy',
          'Fasting',
          'Scripture Memorization',
          'Bible Study & Meditation',
          'Midnight Prayer',
          'Corporate Prayers'
        ];
        const vals = [
          Number(DC_RADAR.br || 0),
          Number(DC_RADAR.mi || 0),
          Number(DC_RADAR.fasting || 0),
          Number(DC_RADAR.memorization || 0),
          Number(DC_RADAR.bible_study || 0),
          Number(DC_RADAR.mp || 0),
          Number(DC_RADAR.cp || 0),
        ];

        new Chart(radarCtx, {
          type:'radar',
          data:{
            labels,
            datasets:[{
              label:'Practice %',
              data: vals
            }]
          },
          options:{
            responsive:true,
            maintainAspectRatio:false,
            scales:{
              r:{ suggestedMin:0, suggestedMax:100 }
            }
          }
        });
      }
    }

    // Notification dropdown toggle (UI only; data came from PHP)
    const bell = document.getElementById('notifBell');
    const drop = document.getElementById('notifDropdown');
    if (bell && drop) {
      bell.addEventListener('click', e => {
        e.stopPropagation();
        drop.style.display = drop.style.display === 'block' ? 'none' : 'block';
      });
      document.addEventListener('click', () => {
        drop.style.display = 'none';
      });
    }
  }

  main();

  /* ===== FOCUS-TO-EXPAND BARS (Accordion) ===== */
  (function initFocusBars() {
    const bars = document.querySelectorAll('[data-bar]');
    if (!bars.length) return;
    
    // Flags to prevent auto-open on page load
    let userHasScrolled = false;
    let observerInitialized = false;
    
    // Listen for first scroll
    window.addEventListener('scroll', () => {
      userHasScrolled = true;
    }, { once: true, passive: true });
    
    // Close a bar
    function closeBar(bar) {
      bar.classList.remove('is-open');
      const trigger = bar.querySelector('[data-bar-trigger]');
      const panel = bar.querySelector('[data-bar-panel]');
      if (trigger) trigger.setAttribute('aria-expanded', 'false');
      if (panel) panel.setAttribute('aria-hidden', 'true');
    }
    
    // Open a bar (and close others - accordion)
    function openBar(bar, isPinned = false) {
      // Close all other bars and clear their pinned state
      bars.forEach(b => {
        if (b !== bar) {
          closeBar(b);
          delete b.dataset.pinned;
        }
      });
      
      // Open this bar
      bar.classList.add('is-open');
      if (isPinned) bar.dataset.pinned = '1';
      
      const trigger = bar.querySelector('[data-bar-trigger]');
      const panel = bar.querySelector('[data-bar-panel]');
      if (trigger) trigger.setAttribute('aria-expanded', 'true');
      if (panel) panel.setAttribute('aria-hidden', 'false');
    }
    
    // Toggle bar manually (always works, regardless of scroll state)
    function toggleBar(bar) {
      if (bar.classList.contains('is-open')) {
        closeBar(bar);
        delete bar.dataset.pinned;
      } else {
        openBar(bar, true); // Pin when manually opened
      }
    }
    
    // Ensure all bars start closed
    bars.forEach(bar => {
      const trigger = bar.querySelector('[data-bar-trigger]');
      const panel = bar.querySelector('[data-bar-panel]');
      
      // Force closed state on page load
      bar.classList.remove('is-open');
      delete bar.dataset.pinned;
      
      // Ensure accessibility attributes
      if (trigger) {
        if (!trigger.hasAttribute('role')) trigger.setAttribute('role', 'button');
        if (!trigger.hasAttribute('tabindex')) trigger.setAttribute('tabindex', '0');
        trigger.setAttribute('aria-expanded', 'false');
      }
      if (panel) {
        panel.setAttribute('aria-hidden', 'true');
      }
      
      // Click handler (always works)
      if (trigger) {
        trigger.addEventListener('click', (e) => {
          e.preventDefault();
          toggleBar(bar);
        });
        
        // Keyboard support (Enter/Space)
        trigger.addEventListener('keydown', (e) => {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toggleBar(bar);
          }
        });
      }
    });
    
    // IntersectionObserver for auto-expand on focus (only after scroll)
    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    
    if (!prefersReducedMotion && 'IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        // Ignore initial callback batch (fires immediately on observe)
        if (!observerInitialized) return;
        
        // Don't auto-open until user has scrolled
        if (!userHasScrolled) return;
        
        entries.forEach(entry => {
          const bar = entry.target;
          
          if (entry.isIntersecting && entry.intersectionRatio >= 0.6) {
            // Bar is in focus (60%+ visible) - auto-open (not pinned)
            if (!bar.classList.contains('is-open')) {
              openBar(bar, false);
            }
          } else {
            // Bar left focus - close if not pinned
            if (bar.classList.contains('is-open') && bar.dataset.pinned !== '1') {
              closeBar(bar);
            }
          }
        });
      }, {
        threshold: [0, 0.6, 1],
        rootMargin: '-10% 0px -10% 0px'
      });
      
      // Observe all bars
      bars.forEach(bar => observer.observe(bar));
      
      // Mark observer as initialized on next tick (ignore first callback batch)
      requestAnimationFrame(() => {
        observerInitialized = true;
      });
    }
  })();
</script>
</body>
</html>
<?php
    return ob_get_clean();
});
