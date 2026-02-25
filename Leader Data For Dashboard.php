/**
 * AJAX: get_leadership_data → returns participants with attainment + commitment rows
 * Used by the Leadership (community) dashboard.
 */
add_action('wp_ajax_get_leadership_data', 'ajax_get_leadership_data');

function ajax_get_leadership_data() {
    if (!is_user_logged_in()) {
        wp_send_json_error('Not logged in', 401);
    }

    $allowed = current_user_can('manage_options') || current_user_can('access_leadership');
    if (!$allowed) {
        wp_send_json_error('Insufficient permissions', 403);
    }

    global $wpdb;
    $participants_table = $wpdb->prefix . 'discipleship_participants';
    $commitments_table  = $wpdb->prefix . 'discipleship_commitments';

    // ---------- PARTICIPANTS (with attainment from table) ----------
    $participants_sql = "
        SELECT 
            p.id,
            p.user_id,
            p.program_id,
            p.current_level_id,
            p.level_start_date,
            p.status,
            p.attainment,
            p.created_at,
            p.updated_at,
            u.user_email,
            u.display_name
        FROM {$participants_table} p
        LEFT JOIN {$wpdb->users} u ON p.user_id = u.ID
        ORDER BY p.id DESC
    ";

    $participants = $wpdb->get_results($participants_sql, ARRAY_A) ?: [];

    // Add first_name, last_name from user meta
    foreach ($participants as &$p) {
        $user_id = (int) ($p['user_id'] ?? 0);
        
        if ($user_id > 0) {
            $first = (string) get_user_meta($user_id, 'first_name', true);
            $last  = (string) get_user_meta($user_id, 'last_name', true);
            $full  = trim($first . ' ' . $last);

            if ($full === '') {
                $full = $p['display_name'] ?? '';
            }

            $p['first_name']       = $first;
            $p['last_name']        = $last;
            $p['participant_name'] = $full;
            $p['participant_id']   = strtolower(trim($p['user_email'] ?? ''));
        } else {
            $p['first_name']       = '';
            $p['last_name']        = '';
            $p['participant_name'] = '';
            $p['participant_id']   = '';
        }
    }
    unset($p);

    // ---------- COMMITMENTS (for calendar/radar per-practice breakdown) ----------
    $date_min = isset($_GET['date_min']) ? sanitize_text_field($_GET['date_min']) : '';
    $date_max = isset($_GET['date_max']) ? sanitize_text_field($_GET['date_max']) : '';
    $params   = [];

    $commitments_sql = "
        SELECT participant_id, week_start, date, practice, unit_type, value,
               comment_general, comment_other
        FROM {$commitments_table}
        WHERE 1=1
    ";

    if ($date_min) {
        $commitments_sql .= " AND ( (date IS NOT NULL AND date <> '' AND date >= %s)
                                 OR (week_start IS NOT NULL AND week_start <> '' AND week_start >= %s) )";
        $params[] = $date_min;
        $params[] = $date_min;
    }
    if ($date_max) {
        $commitments_sql .= " AND ( (date IS NOT NULL AND date <> '' AND date <= %s)
                                 OR (week_start IS NOT NULL AND week_start <> '' AND week_start <= %s) )";
        $params[] = $date_max;
        $params[] = $date_max;
    }

    $commitments_sql .= " ORDER BY week_start ASC, date ASC, practice ASC";

    $commitments = $params
        ? $wpdb->get_results($wpdb->prepare($commitments_sql, $params), ARRAY_A)
        : $wpdb->get_results($commitments_sql, ARRAY_A);

    $commitments = $commitments ?: [];

    // Add names to commitment rows (for calendar tooltips etc.)
    $emails = [];
    foreach ($commitments as $r) {
        $email = strtolower(trim($r['participant_id'] ?? ''));
        if ($email && is_email($email)) {
            $emails[$email] = true;
        }
    }

    $name_map = [];
    foreach (array_keys($emails) as $email) {
        $u = get_user_by('email', $email);
        if (!$u) continue;

        $first = (string) get_user_meta($u->ID, 'first_name', true);
        $last  = (string) get_user_meta($u->ID, 'last_name', true);
        $full  = trim($first . ' ' . $last);

        if ($full === '') {
            $full = $u->display_name;
        }

        $name_map[$email] = [
            'first_name' => $first,
            'last_name'  => $last,
            'full'       => $full,
        ];
    }

    foreach ($commitments as &$r) {
        $email = strtolower(trim($r['participant_id'] ?? ''));
        $nm = $name_map[$email] ?? null;

        $r['first_name']       = $nm['first_name'] ?? '';
        $r['last_name']        = $nm['last_name'] ?? '';
        $r['participant_name'] = $nm['full'] ?? '';
    }
    unset($r);

    // ---------- RESPONSE ----------
    wp_send_json([
        'participants' => $participants,
        'commitments'  => $commitments,
        'meta' => [
            'disciple_url' => home_url('/disciple-dashboard/'),
        ],
    ]);
}
