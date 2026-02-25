/**
 * AJAX: get discipleship data (v2) with strict filtering + DEBUG LOGS
 */
add_action('wp_ajax_get_discipleship_data_v2', 'ajax_get_discipleship_data_v2');

function ajax_get_discipleship_data_v2() {
    if (!is_user_logged_in()) {
        wp_send_json_error('Not logged in', 401);
    }

    global $wpdb;

    error_log('[dd_v2] === START get_discipleship_data_v2 ===');

    // ---------------------------------------------------------
    // 1) Viewer / Leader Resolution
    // ---------------------------------------------------------
    $viewer         = wp_get_current_user();
    $viewer_email_l = strtolower(trim($viewer->user_email));
    $user_id        = (int) $viewer->ID;

    $viewer_is_leader =
        user_can($viewer, 'manage_options') ||
        user_can($viewer, 'edit_others_posts') ||
        user_can($viewer, 'access_leadership') ||
        in_array('administrator', (array) $viewer->roles, true);

    $requested = isset($_REQUEST['participant'])
        ? sanitize_email(wp_unslash($_REQUEST['participant']))
        : '';

    $requested_l = strtolower(trim($requested));

    if ($viewer_is_leader && $requested_l) {
        $participant_l = $requested_l;
        $source = 'requested';
    } else {
        $participant_l = $viewer_email_l;
        $source = 'self';
    }

    error_log('[dd_v2] viewer_email_l=' . $viewer_email_l);
    error_log('[dd_v2] requested_l=' . $requested_l . ' source=' . $source);
    error_log('[dd_v2] effective participant_l=' . $participant_l);

    // ---------------------------------------------------------
    // 2) Load participant row to get: program_id, current_level_id
    // ---------------------------------------------------------
    $participants_tbl = $wpdb->prefix . 'discipleship_participants';

    // Determine which user_id to look up:
    // - If leader is viewing a requested participant, look up by the disciple's email
    // - Otherwise, use the viewer's user_id
    $lookup_user_id = $user_id; // default: viewer's own ID

    if ($viewer_is_leader && $requested_l && $requested_l !== $viewer_email_l) {
        // Leader is viewing someone else's dashboard - find that user's ID by email
        $target_user = get_user_by('email', $requested_l);
        if ($target_user) {
            $lookup_user_id = (int) $target_user->ID;
            error_log('[dd_v2] Leader viewing disciple, resolved email=' . $requested_l . ' to user_id=' . $lookup_user_id);
        } else {
            error_log('[dd_v2] WARNING: Could not find user by email=' . $requested_l);
        }
    }

    $p_row = $wpdb->get_row($wpdb->prepare("
        SELECT *
        FROM $participants_tbl
        WHERE user_id = %d
        LIMIT 1
    ", $lookup_user_id));

    error_log('[dd_v2] participant lookup by user_id=' . $lookup_user_id . ' => ' . var_export($p_row, true));

    if (!$p_row) {
        error_log('[dd_v2] ERROR: Participant not found for user_id=' . $user_id);
        wp_send_json_error("Participant not found");
    }

    $participant_db_id = (int) $p_row->id;
    $program_id        = (int) $p_row->program_id;
    $level_id          = (int) $p_row->current_level_id;

    error_log('[dd_v2] participant_db_id=' . $participant_db_id . ' program_id=' . $program_id . ' level_id=' . $level_id);

    // ---------------------------------------------------------
    // 3) Optional date filters
    // ---------------------------------------------------------
    $date_min = isset($_REQUEST['date_min'])
        ? sanitize_text_field($_REQUEST['date_min'])
        : '';
    $date_max = isset($_REQUEST['date_max'])
        ? sanitize_text_field($_REQUEST['date_max'])
        : '';

    error_log('[dd_v2] date_min=' . $date_min . ' date_max=' . $date_max);

    // ---------------------------------------------------------
    // 4) BUILD SQL
    // ---------------------------------------------------------
    $commit_tbl = $wpdb->prefix . 'discipleship_commitments';

    $select_cols = "
        c.participant_id,
        c.program_id,
        c.level_id,
        c.week_start,
        c.date,
        c.practice,
        c.unit_type,
        c.value,
        c.comment_general,
        c.comment_other
    ";

    // IMPORTANT:
    // - You told me: in discipleship_commitments, participant_id = email address
    //   so we MUST treat it as string (%s) and compare to $participant_l (email).
    $sql = "
        SELECT $select_cols
        FROM $commit_tbl c
        WHERE c.participant_id = %s
          AND c.program_id     = %d
          AND c.level_id       = %d
    ";

    $params = [
        $participant_l, // email stored in commitments.participant_id
        $program_id,
        $level_id
    ];

    // ---- optional date range ----
    if ($date_min) {
        $sql .= "
          AND (
                (c.date       >= %s)
            OR  (c.week_start >= %s)
          )
        ";
        $params[] = $date_min;
        $params[] = $date_min;
    }

    if ($date_max) {
        $sql .= "
          AND (
                (c.date       <= %s)
            OR  (c.week_start <= %s)
          )
        ";
        $params[] = $date_max;
        $params[] = $date_max;
    }

    $sql .= " ORDER BY c.week_start ASC, c.date ASC, c.practice ASC";

    error_log('[dd_v2] COMMIT QUERY base (unprepared): ' . $sql);
    error_log('[dd_v2] COMMIT QUERY params: ' . var_export($params, true));

    // ---------------------------------------------------------
    // 5) Query commitments
    // ---------------------------------------------------------
    $rows = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

    if (!is_array($rows)) {
        error_log('[dd_v2] get_results returned non-array: ' . var_export($rows, true));
        $rows = [];
    }

    $row_count = count($rows);
    error_log('[dd_v2] commitments row_count=' . $row_count);

    if ($row_count > 0) {
        error_log('[dd_v2] first row sample: ' . var_export($rows[0], true));
    }

    // ---------------------------------------------------------
    // 6) Build participant display name (for front-end)
    // ---------------------------------------------------------
    $user_obj = get_user_by('email', $participant_l);

    if ($user_obj) {
        $fn = trim((string) get_user_meta($user_obj->ID, 'first_name', true));
        $ln = trim((string) get_user_meta($user_obj->ID, 'last_name', true));
        $display_name = trim($fn . ' ' . $ln) ?: ($user_obj->display_name ?: $user_obj->user_login);
    } else {
        $local = preg_replace('/\d+/', '', explode('@', $participant_l)[0]);
        $parts = preg_split('/[._-]+/', $local) ?: [];
        $display_name = (count($parts) >= 2)
            ? ucwords($parts[0] . ' ' . $parts[1])
            : ucwords($local);
    }

    // ---------------------------------------------------------
    // 7) Dedupe weekly comments (same as before)
    // ---------------------------------------------------------
    $dedupe_comments = !isset($_REQUEST['dedupe_comments'])
        || (bool) intval($_REQUEST['dedupe_comments']);

    $week_comments = [];
    $seen_week     = [];

    if ($dedupe_comments && !empty($rows)) {
        foreach ($rows as $i => $r) {
            $wk = (!empty($r['week_start'])) ? $r['week_start'] : null;

            if (!$wk) {
                if (!empty($r['date'])) {
                    $wk = date('Y-m-d', strtotime('last sunday', strtotime($r['date'])));
                    $rows[$i]['week_start'] = $wk;
                } else {
                    continue;
                }
            }

            if (!isset($week_comments[$wk])) {
                $week_comments[$wk] = [
                    'general' => $r['comment_general'] ?? null,
                    'other'   => $r['comment_other']   ?? null,
                ];
            }

            if (isset($seen_week[$wk])) {
                $rows[$i]['comment_general'] = null;
                $rows[$i]['comment_other']   = null;
            } else {
                $seen_week[$wk] = true;
            }
        }
    }

    // ---------------------------------------------------------
    // 8) Normalize rows: clamp value 0–1, override participant_id with email
    // ---------------------------------------------------------
    foreach ($rows as &$r) {
        $r['participant_id']   = $participant_l;  // front-end expects email here
        $r['participant_name'] = $display_name;

        $v = isset($r['value']) ? (float) $r['value'] : 0;
        $r['value'] = max(0, min(1, $v));
    }
    unset($r);

    error_log('[dd_v2] normalized row_count=' . count($rows));
    error_log('[dd_v2] === END get_discipleship_data_v2 ===');

    // ---------------------------------------------------------
    // 9) Respond
    // ---------------------------------------------------------
    wp_send_json([
        'meta' => [
            'marker'                => 'get_discipleship_data_v2',
            'viewer_email'          => $viewer_email_l,
            'is_leader'             => (bool) $viewer_is_leader,
            'requested_participant' => $requested_l ?: null,
            'effective_participant' => $participant_l,
            'source'                => $source,
            'participant_db_id'     => $participant_db_id,
            'program_id'            => $program_id,
            'level_id'              => $level_id,
            'row_count'             => count($rows),
            'dedupe_comments'       => (bool) $dedupe_comments,
        ],
        'rows'          => $rows,
        'week_comments' => $dedupe_comments ? $week_comments : new stdClass(),
    ]);
}
