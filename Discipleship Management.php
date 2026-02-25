/**
 * Discipleship Management — Improved
 * Shortcode: [discipleship_management]
 */
if (!defined('ABSPATH')) exit;

/* =========================
   CONFIG & ACCESS
========================= */
if (!defined('DASDM_SEND_STATUS_EMAILS')) define('DASDM_SEND_STATUS_EMAILS', true);
if (!defined('DASDM_LEADER_CAP'))        define('DASDM_LEADER_CAP', 'access_leadership');

function dasdm_can_manage(){
    if (!is_user_logged_in()) return false;
    $u = wp_get_current_user();
    return in_array('administrator', (array)$u->roles, true) || user_can($u, DASDM_LEADER_CAP);
}

function dasdm_bootstrap_participant_on_approve($user_id) {
    global $wpdb;

    $participants_table = "{$wpdb->prefix}discipleship_participants";
    $programs_table     = "{$wpdb->prefix}discipleship_programs";
    $levels_table       = "{$wpdb->prefix}discipleship_levels";

    $now = current_time('mysql');

    // Skip if already exists
    $existing_id = (int) $wpdb->get_var($wpdb->prepare("
        SELECT id FROM {$participants_table}
        WHERE user_id = %d
        LIMIT 1
    ", $user_id));

    if ($existing_id) {
        return ['action' => 'skipped', 'participant_id' => $existing_id];
    }

    // First program
    $program_id = (int) $wpdb->get_var("
        SELECT id FROM {$programs_table}
        ORDER BY id ASC
        LIMIT 1
    ");
    if (!$program_id) return new WP_Error('no_program', 'No discipleship program found.');

    // First level of that program
    $level_id = (int) $wpdb->get_var($wpdb->prepare("
        SELECT id FROM {$levels_table}
        WHERE program_id = %d
        ORDER BY level_number ASC, id ASC
        LIMIT 1
    ", $program_id));
    if (!$level_id) return new WP_Error('no_level', 'No levels found for selected program.');

    // Insert participant
    $ok = $wpdb->insert(
        $participants_table,
        [
            'user_id'              => $user_id,
            'program_id'           => $program_id,
            'current_level_id'     => $level_id,
            'level_start_date'     => $now,
            'status'               => 'active',
            'attainment'           => 0,
            'created_at'           => $now,
            'updated_at'           => $now,
            'in_grace_period'      => 0,
            'grace_start_date'     => null,
            'promoted_at'          => null,
            'next_level_id'        => null,
            'last_attainment_calc' => null,
            'grace_cycle_count'    => 0,
        ]
    );

    if (!$ok) return new WP_Error('participant_insert_failed', $wpdb->last_error ?: 'Insert failed');

    return ['action' => 'inserted', 'participant_id' => (int)$wpdb->insert_id];
}

function dasdm_get_participant_context($user_id) {
    global $wpdb;

    $participants_table = "{$wpdb->prefix}discipleship_participants";

    $p = $wpdb->get_row($wpdb->prepare("
        SELECT id, program_id, current_level_id
        FROM {$participants_table}
        WHERE user_id = %d
        LIMIT 1
    ", $user_id));

    return $p ?: null;
}


/* =========================
   AJAX — FETCH USERS
   Includes engine data: level, attainment, last submission
========================= */
add_action('wp_ajax_dasdm_fetch', function(){
    if (!dasdm_can_manage()) wp_send_json_error('Access denied');
    check_ajax_referer('dasdm_nonce','nonce');

    global $wpdb;

    $status = sanitize_text_field($_POST['status'] ?? '');

    $meta_query = [];
    if ($status) {
        // Specific status (active/pending/rejected)
        $meta_query[] = [
            'key'   => 'disciple_status',
            'value' => $status,
        ];
    } else {
        // Any disciple who has a disciple_status
        $meta_query[] = [
            'key'     => 'disciple_status',
            'compare' => 'EXISTS',
        ];
    }

	$args = [
		'number'     => 999,
		'fields'     => ['ID','display_name','user_email'],
		'role'       => 'disciple', 
		'meta_query' => $meta_query,
	];

    $users = get_users($args);

    $participants_table = "{$wpdb->prefix}discipleship_participants";
    $levels_table       = "{$wpdb->prefix}discipleship_levels";
    $commitments_table  = "{$wpdb->prefix}discipleship_commitments";

    $rows = [];

    foreach ($users as $u) {
        $id    = $u->ID;
        $email = $u->user_email;
        $email_lc = strtolower($email);

        // Meta status (application-level)
        $disciple_status = get_user_meta($id, 'disciple_status', true);
        if (!$disciple_status) {
            $disciple_status = 'pending'; // default
        }

        // Participant (engine-level)
        $participant = $wpdb->get_row($wpdb->prepare("
            SELECT p.*, l.name AS level_name, l.level_number
            FROM $participants_table p
            LEFT JOIN $levels_table l ON p.current_level_id = l.id
            WHERE p.user_id = %d
            LIMIT 1
        ", $id));

        $level_label     = '—';
        $engine_status   = '—';
        $attainment      = null;
        $level_start     = '—';
		
		$pauses_table = "{$wpdb->prefix}discipleship_pauses";

		$is_paused = false;
		$open_pause_id = null;

		if ($participant && !empty($participant->id)) {
			$open_pause_id = $wpdb->get_var($wpdb->prepare("
				SELECT id
				FROM $pauses_table
				WHERE participant_id = %d
				  AND resumed_at IS NULL
				ORDER BY paused_at DESC, id DESC
				LIMIT 1
			", (int)$participant->id));

			$is_paused = !empty($open_pause_id);
		}

        if ($participant) {
            if ($participant->level_number && $participant->level_name) {
                $level_label = 'Level ' . $participant->level_number . ' — ' . $participant->level_name;
            }
            $engine_status = $participant->status ?: 'active';
            $attainment    = isset($participant->attainment) ? (float)$participant->attainment : null;
            $level_start   = $participant->level_start_date ?: '—';
        }

        // Last submission date from commitments (by email as participant_id)
        $last_submission = $wpdb->get_var($wpdb->prepare("
            SELECT MAX(date)
            FROM $commitments_table
            WHERE participant_id = %s
        ", $email_lc));
        if (!$last_submission) $last_submission = '—';

        $rows[] = [
            'id'                      => $id,
            'name'                    => $u->display_name,
            'email'                   => $email,
            'status'                  => $disciple_status, // active / pending / rejected
            'registered'              => get_user_meta($id,'disciple_registered_at',true) ?: '—',
            'reason'                  => get_user_meta($id,'disciple_reject_reason',true) ?: '',
            'saved'                   => get_user_meta($id,'disciple_saved',true) ?: '—',
            'baptized'                => get_user_meta($id,'disciple_baptized',true) ?: '—',
            'phone'                   => get_user_meta($id,'disciple_phone',true),
            'born_again'              => get_user_meta($id,'disciple_born_again',true),
            'born_date'               => get_user_meta($id,'disciple_born_date',true),
            'spiritual_covering'      => get_user_meta($id,'disciple_spiritual_covering',true),
            'bible_reading'           => get_user_meta($id,'disciple_bible_reading',true),
            'fasting'                 => get_user_meta($id,'disciple_fasting',true),
            'memorization'            => get_user_meta($id,'disciple_memorization',true),
            'morning_prayer'          => get_user_meta($id,'disciple_morning_prayer',true),
            'midnight_prayer'         => get_user_meta($id,'disciple_midnight_prayer',true),
            'bible_study'             => get_user_meta($id,'disciple_bible_study',true),
            'bible_study_other'       => get_user_meta($id,'disciple_bible_study_other',true),
            'commitment_duration'     => get_user_meta($id,'disciple_commitment_duration',true),
            'commitment_duration_other'=> get_user_meta($id,'disciple_commitment_duration_other',true),
            'agree_commitment'        => get_user_meta($id,'disciple_agree_commitment',true),
            'agree_commitment_other'  => get_user_meta($id,'disciple_agree_commitment_other',true),

            // Engine-related fields added:
            'level'           => $level_label,
            'engine_status'   => $engine_status,
            'attainment'      => $attainment !== null ? round($attainment, 1) : null,
            'level_start'     => $level_start,
            'last_submission' => $last_submission,
			
			'is_paused'    => $is_paused ? 1 : 0,
			'open_pause_id'=> $open_pause_id ? (int)$open_pause_id : null,
        ];
    }

    wp_send_json_success($rows);
});

/* =========================
   AJAX — UPDATE STATUS
   Uses statuses: active / pending / rejected
========================= */

add_action('wp_mail_failed', function($wp_error){
  error_log('[wp_mail_failed] ' . $wp_error->get_error_message());
});


add_action('wp_ajax_dasdm_update', function(){
    if (!dasdm_can_manage()) wp_send_json_error('Access denied');
    check_ajax_referer('dasdm_nonce','nonce');

    $uid    = intval($_POST['user_id'] ?? 0);
    $status = sanitize_text_field($_POST['status'] ?? '');
    $reason = isset($_POST['reason']) ? wp_kses_post($_POST['reason']) : '';

    if (!$uid || !in_array($status, ['active', 'inactive','pending','rejected'], true)) {
        wp_send_json_error('Invalid status');
    }

    update_user_meta($uid, 'disciple_status', $status);
	
	if ($status === 'active') {
    $result = dasdm_bootstrap_participant_on_approve($uid);
		
		
	// Send "approved" email to disciple
	if (defined('DASDM_SEND_STATUS_EMAILS') && DASDM_SEND_STATUS_EMAILS) {
		

		if (defined('DAS_EMAILS_ASYNC') && DAS_EMAILS_ASYNC && function_exists('wp_schedule_single_event')) {
			do_action('das_async_send_approved_email', $uid);

		}

	}

    if (is_wp_error($result)) {
        wp_send_json_error('Approved status saved, but participant insert failed: ' . $result->get_error_message());
    }
 }


    if ($status === 'rejected') {
        update_user_meta($uid, 'disciple_reject_reason', $reason);
    } else {
        delete_user_meta($uid, 'disciple_reject_reason');
    }

    wp_send_json_success([
        'user_id' => $uid,
        'status'  => $status
    ]);
});

add_action('wp_ajax_dasdm_deactivate', function () {

    if (!dasdm_can_manage()) {
        wp_send_json_error('Access denied');
    }

    check_ajax_referer('dasdm_nonce', 'nonce');
    $uid = intval($_POST['user_id'] ?? 0);

    if (!$uid) {
        wp_send_json_error('Invalid user ID');
    }

    update_user_meta($uid, 'disciple_status', 'inactive');

    $user = get_user_by('id', $uid);
    if (!$user) {
        wp_send_json_error('User not found');
    }

    $user->set_role('subscriber');

    update_user_meta($uid, 'disciple_deactivated_at', current_time('mysql'));

    wp_send_json_success([
        'user_id' => $uid,
        'status'  => 'inactive',
        'role'    => 'subscriber'
    ]);
});

add_action('wp_ajax_dasdm_pause', function () {
    if (!dasdm_can_manage()) wp_send_json_error('Access denied');
    check_ajax_referer('dasdm_nonce', 'nonce');

    global $wpdb;

    $uid    = intval($_POST['user_id'] ?? 0);
    $reason = sanitize_text_field($_POST['reason'] ?? '');

    if (!$uid) wp_send_json_error('Invalid user ID');
    if (!$reason) wp_send_json_error('Reason is required');

    $ctx = dasdm_get_participant_context($uid);
    if (!$ctx) wp_send_json_error('Participant record not found for this user');

    $pauses_table = "{$wpdb->prefix}discipleship_pauses";

    $now = current_time('mysql');
    $actor_id = get_current_user_id();

    $ok = $wpdb->insert($pauses_table, [
        'participant_id' => (int)$ctx->id,
        'program_id'     => (int)$ctx->program_id,
        'level_id'       => (int)$ctx->current_level_id,
        'paused_at'      => $now,
        'resumed_at'     => null,
        'reason'         => $reason,
        'created_by'     => $actor_id ?: null,
        'updated_by'     => $actor_id ?: null,
        'updated_at'     => $now, // DB also updates automatically, but this is fine
        'created_at'     => $now, // DB default exists, but explicit is OK
    ]);

    if (!$ok) wp_send_json_error($wpdb->last_error ?: 'Pause insert failed');

    wp_send_json_success([
        'user_id' => $uid,
        'participant_id' => (int)$ctx->id,
        'pause_id' => (int)$wpdb->insert_id
    ]);
});

add_action('wp_ajax_dasdm_pause', function () {
    if (!dasdm_can_manage()) wp_send_json_error('Access denied');
    check_ajax_referer('dasdm_nonce', 'nonce');

    global $wpdb;

    $uid    = intval($_POST['user_id'] ?? 0);
    $reason = sanitize_text_field($_POST['reason'] ?? '');

    if (!$uid) wp_send_json_error('Invalid user ID');
    if (!$reason) wp_send_json_error('Reason is required');

    $ctx = dasdm_get_participant_context($uid);
    if (!$ctx) wp_send_json_error('Participant record not found for this user');

    $pauses_table = "{$wpdb->prefix}discipleship_pauses";

    $now = current_time('mysql');
    $actor_id = get_current_user_id();

    $ok = $wpdb->insert($pauses_table, [
        'participant_id' => (int)$ctx->id,
        'program_id'     => (int)$ctx->program_id,
        'level_id'       => (int)$ctx->current_level_id,
        'paused_at'      => $now,
        'resumed_at'     => null,
        'reason'         => $reason,
        'created_by'     => $actor_id ?: null,
        'updated_by'     => $actor_id ?: null,
        'updated_at'     => $now, // DB also updates automatically, but this is fine
        'created_at'     => $now, // DB default exists, but explicit is OK
    ]);

    if (!$ok) wp_send_json_error($wpdb->last_error ?: 'Pause insert failed');

    wp_send_json_success([
        'user_id' => $uid,
        'participant_id' => (int)$ctx->id,
        'pause_id' => (int)$wpdb->insert_id
    ]);
});

add_action('wp_ajax_dasdm_resume', function () {
    if (!dasdm_can_manage()) wp_send_json_error('Access denied');
    check_ajax_referer('dasdm_nonce', 'nonce');

    global $wpdb;

    $uid = intval($_POST['user_id'] ?? 0);
    if (!$uid) wp_send_json_error('Invalid user ID');

    $ctx = dasdm_get_participant_context($uid);
    if (!$ctx) wp_send_json_error('Participant record not found for this user');

    $pauses_table = "{$wpdb->prefix}discipleship_pauses";
    $now = current_time('mysql');
    $actor_id = get_current_user_id();

    // Find most recent pause that hasn't been resumed
    $pause_id = (int)$wpdb->get_var($wpdb->prepare("
        SELECT id
        FROM {$pauses_table}
        WHERE participant_id = %d
          AND resumed_at IS NULL
        ORDER BY paused_at DESC, id DESC
        LIMIT 1
    ", (int)$ctx->id));

    if (!$pause_id) {
        wp_send_json_error('No active pause found to resume.');
    }

    $ok = $wpdb->update(
        $pauses_table,
        [
            'resumed_at' => $now,
            'updated_by' => $actor_id ?: null,
            'updated_at' => $now,
        ],
        ['id' => $pause_id],
        ['%s','%d','%s'],
        ['%d']
    );

    if ($ok === false) wp_send_json_error($wpdb->last_error ?: 'Resume update failed');

    wp_send_json_success([
        'user_id' => $uid,
        'participant_id' => (int)$ctx->id,
        'pause_id' => $pause_id
    ]);
});


/* =========================
   SHORTCODE FRONTEND
========================= */
add_shortcode('discipleship_management',function(){
    if(!is_user_logged_in()) return '<div>Please login.</div>';
    if(!dasdm_can_manage())  return '<div>Access denied.</div>';

    wp_enqueue_script('jquery');

    $ajaxurl = admin_url('admin-ajax.php');
    $nonce   = wp_create_nonce('dasdm_nonce');

    ob_start(); ?>
<style>
.dasdm-wrap{max-width:1200px;margin:30px auto;padding:28px;background:#fff;border-radius:18px;
    box-shadow:0 10px 25px rgba(0,0,0,.08);border-top:5px solid #1e3a8a;font-family:system-ui;}
h2{text-align:center;color:#1e3a8a;margin-bottom:20px;}
.dasdm-grid{display:flex;justify-content:center;gap:18px;margin-bottom:20px;flex-wrap:wrap;}
.dasdm-card{padding:16px 24px;background:#f8fafc;border-radius:10px;border:1px solid #e2e8f0;text-align:center;}
.dasdm-card h3 {color: #7f1d1d; /* muted maroon */}
.dasdm-card {background: #fff;}
.dasdm-card span{font-size:13px;color:#475569;text-transform:uppercase;}
.dasdm-controls{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;flex-wrap:wrap;gap:8px;}
.dasdm-controls input,.dasdm-controls select{padding:6px 10px;border-radius:8px;border:1px solid #cbd5e1;}
table{width:100%;border-collapse:collapse;border:1px solid #e5e7eb;border-radius:10px;overflow:hidden;}
th,td{padding:10px 12px;border-bottom:1px solid #e5e7eb;text-align:left;font-size:14px;}
th{background:#1e3a8a;color:#fff;text-transform:uppercase;font-size:13px;}
tr:nth-child(even){background:#f8fafc;} tr:hover{background:#f1f5f9;cursor:pointer;}
.dasdm-status{padding:3px 8px;border-radius:999px;font-weight:600;}
.dasdm-status.active{background:rgba(16,185,129,.1);color:#047857;}
.dasdm-status.pending{background:rgba(59,130,246,.1);color:#1e3a8a;}
.dasdm-status.rejected{background:rgba(239,68,68,.1);color:#b91c1c;}
.dasdm-btn{padding:6px 12px;border-radius:8px;border:1px solid #1e3a8a;background:#1e3a8a;color:#fff;cursor:pointer;}
.dasdm-btn.secondary{background:#fff;color:#1e3a8a;}
.dasdm-pagination{display:flex;justify-content:space-between;align-items:center;margin-top:10px;font-size:14px;}
.dasdm-pagination button {background: linear-gradient(135deg, #9f1239, #7f1d1d);color: #fff;}
.dasdm-pagination button:disabled {opacity: 0.45;cursor: not-allowed;}
.dasdm-pagination select{border:1px solid #cbd5e1;border-radius:6px;padding:4px 6px;}
.dasdm-modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:9999;}
.dasdm-modal{width:700px;max-width:90%;background:#fff;border-radius:14px;overflow:hidden;max-height:80vh;display:flex;flex-direction:column;}
.dasdm-modal header {background: linear-gradient(135deg, #9f1239, #7f1d1d);color: #fff;}
.dasdm-modal .body{padding:16px;overflow:auto;}
.dasdm-details{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.dasdm-details div{background:#f8fafc;padding:8px 10px;border-radius:8px;font-size:14px;}
.dasdm-details strong{display:block;color:#1e3a8a;margin-bottom:4px;font-size:13px;}
.dasdm-drilldown{
    display:block;width:100%;margin-top:18px;padding:14px 20px;
    background:linear-gradient(135deg,#9f1239,#7f1d1d);color:#fff;
    font-size:15px;font-weight:700;text-align:center;text-decoration:none;
    border:none;border-radius:12px;cursor:pointer;
    box-shadow:0 4px 14px rgba(159,18,57,.25);
    transition:transform .15s ease,box-shadow .15s ease,background .15s ease;
}
.dasdm-drilldown:hover{
    transform:translateY(-2px);
    box-shadow:0 6px 20px rgba(127,29,29,.35);
    background:linear-gradient(135deg,#be123c,#991b1b);
}
.dasdm-drilldown:active{transform:translateY(0);box-shadow:0 2px 8px rgba(159,18,57,.2);}
.dasdm-drilldown svg{vertical-align:middle;margin-right:8px;}
/* Primary Actions */
.dasdm-btn.approve {
    background: linear-gradient(135deg, #16a34a, #15803d);
    border-color: #15803d;
}
.dasdm-btn.approve:hover {
    background: linear-gradient(135deg, #15803d, #166534);
}
/* Destructive */
.dasdm-btn.reject {
    background: linear-gradient(135deg, #dc2626, #991b1b);
    border-color: #991b1b;
}
.dasdm-btn.reject:hover {
    background: linear-gradient(135deg, #b91c1c, #7f1d1d);
}
.dasdm-wrap {
    border-top:5px solid #9a0e0e;
}

h2 {
    color:#9a0e0e;
}

th {
    background: linear-gradient(135deg, #b91c1c, #7f1d1d);
    color: #fff;
}

.dasdm-btn {
    background: linear-gradient(135deg, #c91111, #9a0e0e);
    border:1px solid #9a0e0e;
}
.dasdm-status.active {
    background: rgba(22,163,74,.12);
    color:#14532d;
}

.dasdm-status.pending {
    background: rgba(201,17,17,.12);
    color:#9a0e0e;
}

.dasdm-status.rejected {
    background: rgba(153,27,27,.15);
    color:#7f1d1d;
}
								
.dasdm-btn.approve {
    background: linear-gradient(135deg, #22c55e, #16a34a);
    border-color: #15803d;
}

.dasdm-btn.approve:hover {
    background: linear-gradient(135deg, #16a34a, #15803d);
}

.dasdm-btn.reject {
    background: linear-gradient(135deg, #ef4444, #b91c1c);
    border-color: #b91c1c;
}

.dasdm-btn.reject:hover {
    background: linear-gradient(135deg, #dc2626, #991b1b);
}

.dasdm-status {
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 999px;
}

/* Active – calm green */
.dasdm-status.active {
    background: #dcfce7;
    color: #166534;
}

/* Pending – muted amber */
.dasdm-status.pending {
    background: #fef3c7;
    color: #92400e;
}

/* Rejected – gentle rose */
.dasdm-status.rejected {
    background: #fee2e2;
    color: #7f1d1d;
}

.dasdm-wrap {
    border-top: 4px solid #9f1239; /* softer maroon */
}

h2 {
    color: #9f1239;
}
.dasdm-btn.disabled,
.dasdm-btn:disabled {
    opacity: 0.45;
    cursor: not-allowed;
    filter: grayscale(30%);
}
@keyframes pulseFade {
    0%   { background-color: #fff; }
    40%  { background-color: #fef2f2; }
    100% { background-color: #fff; }
}

tr.status-updated {
    animation: pulseFade 0.9s ease;
}
.dasdm-details strong {
    display:block;
    color:#1e3a8a;
    margin-bottom:4px;
    font-size:13px;
}

.dasdm-details strong {
    color: #7f1d1d; /* muted maroon */
}
.dasdm-btn.delete {
    background: linear-gradient(135deg, #6b7280, #4b5563);
    border-color: #4b5563;
    color: #fff;
}

.dasdm-btn.delete:hover {
    background: linear-gradient(135deg, #4b5563, #374151);
}
.dasdm-status.inactive {
    background: #f3f4f6;
    color: #374151;
}
td {
    white-space: normal;
    word-break: break-word;
}

/* Only keep nowrap where it makes sense */

td:nth-child(8) { /* Actions */
    white-space: nowrap;
}
td:nth-child(2), /* Email */
td:nth-child(4) { /* Level */
    max-width: 220px;
    overflow: hidden;
    text-overflow: ellipsis;
}
th {
    position: sticky;
    top: 0;
    z-index: 2;
}								
td .dasdm-btn {margin-right: 6px;}
.dasdm-actions {
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: nowrap;
}
.dasdm-table-wrap {
    width: 100%;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}
								
.dasdm-btn.pause {
    background: linear-gradient(135deg, #f59e0b, #b45309);
    border-color: #b45309;
    color: #fff;
}

.dasdm-btn.pause:hover {
    background: linear-gradient(135deg, #d97706, #92400e);
}

.dasdm-btn.resume {
    background: linear-gradient(135deg, #22c55e, #15803d);
    border-color: #15803d;
    color: #fff;
}

.dasdm-btn.resume:hover {
    background: linear-gradient(135deg, #16a34a, #166534);
}

.dasdm-btn.pause::before {
    content: '⏸ ';
}

.dasdm-btn.resume::before {
    content: '▶ ';
}					

/* Prevent table from forcing container wider */
#disciple-table {
    width: 100%;
    min-width: 1100px; /* keeps columns readable */
    table-layout: fixed;
}
								
/* 1) Let the whole shortcode area use full available width */
.dasdm-wrap{
  width: 100%;
  max-width: 100% !important;  /* override theme/container constraints */
  box-sizing: border-box;
}

/* 2) Make the wrapper own the border + rounding, and ensure scrolling works */
.dasdm-table-wrap{
  width: 100%;
  display: block;
  overflow-x: auto !important;
  overflow-y: hidden;
  -webkit-overflow-scrolling: touch;

  border: 1px solid #e5e7eb;
  border-radius: 10px;
}

/* 3) Remove “overflow hidden” from the table itself (it can clip) */
.dasdm-table-wrap table{
  border: 0 !important;
  border-radius: 0 !important;
  overflow: visible !important;
}

/* 4) Keep your table wide, but scrollable on smaller screens */
#disciple-table{
  width: 100%;
  min-width: 1100px;          /* keep readability */
  table-layout: auto;         /* fixed can cause weird truncation */
}

/* 5) If the theme is clipping children, force visibility for common wrappers */
.entry-content,
.site-content,
.elementor-widget-container,
.elementor-section,
.elementor-container,
.elementor-column,
.elementor-column-wrap{
  overflow: visible !important;
}
								

/* 6) Mobile: allow narrower table so you don't *need* huge width */
@media (max-width: 900px){
  #disciple-table{ min-width: 900px; }
}
@media (max-width: 600px){
  #disciple-table{ min-width: 750px; }
}

/* ===== ATTAINMENT COLOR CODING ===== */
.attainment-pill{
    display:inline-block;padding:4px 10px;border-radius:999px;font-weight:600;font-size:13px;
}
.attainment-pill.high{
    background:#dcfce7;color:#166534;
}
.attainment-pill.medium{
    background:#fef3c7;color:#92400e;
}
.attainment-pill.low{
    background:#fee2e2;color:#991b1b;
}
.attainment-pill.none{
    background:#f3f4f6;color:#6b7280;
}

/* ===== AT-RISK INDICATOR ===== */
.at-risk-badge{
    display:inline-flex;align-items:center;gap:4px;
    padding:3px 8px;border-radius:999px;font-size:11px;font-weight:600;
    margin-left:6px;
}
.at-risk-badge.warning{
    background:#fef3c7;color:#92400e;
}
.at-risk-badge.critical{
    background:#fee2e2;color:#991b1b;
    animation:pulse-risk 2s infinite;
}
@keyframes pulse-risk{
    0%,100%{opacity:1;}
    50%{opacity:0.6;}
}
.at-risk-badge svg{width:12px;height:12px;}

/* ===== SORTABLE COLUMNS ===== */
th.sortable{
    cursor:pointer;user-select:none;position:relative;padding-right:22px;
    transition:background .15s ease;
}
th.sortable:hover{
    background:linear-gradient(135deg,#991b1b,#7f1d1d);
}
th.sortable::after{
    content:'⇅';position:absolute;right:8px;top:50%;transform:translateY(-50%);
    font-size:11px;opacity:0.5;
}
th.sortable.asc::after{
    content:'↑';opacity:1;
}
th.sortable.desc::after{
    content:'↓';opacity:1;
}
								
</style>

<div class="dasdm-wrap" data-url="<?php echo esc_attr($ajaxurl); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
    <h2>Discipleship Management</h2>

    <div class="dasdm-grid">
        <div class="dasdm-card"><h3 id="count-total">0</h3><span>Total</span></div>
        <div class="dasdm-card"><h3 id="count-active">0</h3><span>Active</span></div>
        <div class="dasdm-card"><h3 id="count-pending">0</h3><span>Pending</span></div>
        <div class="dasdm-card"><h3 id="count-rejected">0</h3><span>Rejected</span></div>
    </div>

    <div class="dasdm-controls">
        <div>
            Show 
            <select id="rows-per-page">
                <option value="10">10</option>
                <option value="25">25</option>
                <option value="50">50</option>
                <option value="all">All</option>
            </select> entries
        </div>
        <input type="text" id="search" placeholder="Search name or email...">
        <div>
            Filter:
            <select id="filter-status">
                <option value="">All</option>
                <option value="active">Active</option>
                <option value="pending">Pending</option>
                <option value="rejected">Rejected</option>
				<option value="inactive">Inactive</option>
            </select>
        </div>
    </div>

    <div class="dasdm-table-wrap">
		<table id="disciple-table">
			<thead>
				<tr>
					<th class="sortable" data-sort="name">Name</th>
					<th class="sortable" data-sort="email">Email</th>
					<th class="sortable" data-sort="status">Status</th>
					<th class="sortable" data-sort="level">Level</th>
					<th class="sortable" data-sort="attainment">Attainment</th>
					<th class="sortable" data-sort="last_submission">Last Activity</th>
					<th class="sortable" data-sort="registered">Registered</th>
					<th>Actions</th>
				</tr>
			</thead>
			<tbody><tr><td colspan="7">Loading...</td></tr></tbody>
		</table>
	</div>
    
	<div class="dasdm-pagination">
        <button id="prev">◀ Prev</button>
        <span id="page-info"></span>
        <button id="next">Next ▶</button>
    </div>
</div>

<!-- Details Modal -->
<div class="dasdm-modal-backdrop" id="details-modal">
    <div class="dasdm-modal">
        <header>Disciple Details</header>
        <div class="body" id="details-body"></div>
    </div>
</div>
								
<!-- Confirm Action Modal -->
<div class="dasdm-modal-backdrop" id="confirm-modal">
  <div class="dasdm-modal" style="max-width:480px">
    <header id="confirm-title">Confirm Action</header>
    <div class="body">
      <p id="confirm-message"></p>

      <textarea id="reject-reason"
        placeholder="Reason for rejection (required)"
        style="width:100%;margin-top:10px;display:none;padding:8px;border-radius:8px;border:1px solid #e5e7eb"></textarea>

      <div style="margin-top:14px;display:flex;gap:10px;justify-content:flex-end">
        <button class="dasdm-btn secondary" id="confirm-cancel">Cancel</button>
        <button class="dasdm-btn reject" id="confirm-ok">Confirm</button>
      </div>
    </div>
  </div>
</div>

<script>

(function($){

    const $wrap    = $('.dasdm-wrap'),
          url      = $wrap.data('url'),
          nonce    = $wrap.data('nonce'),
          $tbody   = $('#disciple-table tbody'),
          $filter  = $('#filter-status'),
          $search  = $('#search'),
          $rowsSel = $('#rows-per-page'),
          $details = $('#details-modal'),
          $detailsBody = $('#details-body'),
          $prev    = $('#prev'),
          $next    = $('#next'),
          $pageInfo= $('#page-info');

    const $confirm = $('#confirm-modal'),
          $confirmMsg = $('#confirm-message'),
          $confirmTitle = $('#confirm-title'),
          $confirmOk = $('#confirm-ok'),
          $confirmCancel = $('#confirm-cancel'),
          $rejectReason = $('#reject-reason');

    let pendingAction = null;
    let data = [], filtered = [], page = 1, perPage = 10;
    let sortColumn = null, sortDir = 'asc';

    function esc(str) {
        str = (str === null || str === undefined) ? '' : String(str);
        return str.replace(/[&<>"']/g, function(ch){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]) || ch;
        });
    }

    // Attainment color class
    function attainmentClass(val) {
        if (val === null || val === undefined) return 'none';
        if (val >= 94) return 'high';
        if (val >= 80) return 'medium';
        return 'low';
    }

    // Days since last submission
    function daysSince(dateStr) {
        if (!dateStr || dateStr === '—') return null;
        const then = new Date(dateStr);
        if (isNaN(then)) return null;
        const now = new Date();
        return Math.floor((now - then) / (1000 * 60 * 60 * 24));
    }

    // At-risk badge HTML
    function atRiskBadge(days, status) {
        if (status !== 'active' || days === null) return '';
        if (days >= 14) {
            return `<span class="at-risk-badge critical">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                    <line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>
                </svg>${days}d
            </span>`;
        }
        if (days >= 7) {
            return `<span class="at-risk-badge warning">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>${days}d
            </span>`;
        }
        return '';
    }

    function renderTable(){
        let start = (page-1)*perPage,
            end   = perPage === 'all' ? filtered.length : start + perPage,
            subset= perPage === 'all' ? filtered : filtered.slice(start,end);

        $tbody.empty();

        if (!subset.length){
            $tbody.html('<tr><td colspan="8">No records found</td></tr>');
            return;
        }

        subset.forEach(u=>{
            const attVal = (u.attainment !== null && u.attainment !== undefined) ? u.attainment : null;
            const attDisplay = attVal !== null ? (attVal + '%') : '—';
            const attClass = attainmentClass(attVal);
            const days = daysSince(u.last_submission);
            const riskBadge = atRiskBadge(days, u.status);
            const lastActivity = u.last_submission && u.last_submission !== '—' 
                ? esc(u.last_submission) + riskBadge 
                : '—' + (u.status === 'active' ? '<span class="at-risk-badge critical"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>No data</span>' : '');

            $tbody.append(`
                <tr data-id="${u.id}">
                    <td>${esc(u.name)}</td>
                    <td>${esc(u.email)}</td>
                    <td><span class="dasdm-status ${esc(u.status)}">${esc(u.status)}</span></td>
                    <td>${esc(u.level || '—')}</td>
                    <td><span class="attainment-pill ${attClass}">${attDisplay}</span></td>
                    <td>${lastActivity}</td>
                    <td>${esc(u.registered || '—')}</td>
					<td>
						<div class="dasdm-actions">
						  ${u.status !== 'active' ? `
							<button class="dasdm-btn approve" data-id="${u.id}">Approve</button>
						  ` : ''}

						  <button class="dasdm-btn reject ${u.status === 'rejected' ? 'disabled' : ''}"
							data-id="${u.id}" ${u.status === 'rejected' ? 'disabled' : ''}>
							Reject
						  </button>

						  ${(u.status === 'inactive' || u.status === 'rejected') ? `
							<button class="dasdm-btn delete" data-id="${u.id}">
							  Remove
							</button>
						  ` : ''}

						  ${u.status === 'active' ? (
							u.is_paused ? `
							  <button class="dasdm-btn secondary resume" data-id="${u.id}">Resume</button>
							` : `
							  <button class="dasdm-btn secondary pause" data-id="${u.id}">Pause</button>
							`
						  ) : ''}
							
						</div>
					</td>
                </tr>
            `);
        });

        const totalPages = Math.ceil(filtered.length/(perPage === 'all' ? filtered.length : perPage)) || 1;
        $pageInfo.text(`Page ${page} of ${totalPages}`);
        $prev.prop('disabled', page <= 1);
        $next.prop('disabled', page >= totalPages);
    }

    // Sorting comparator
    function sortData(arr) {
        if (!sortColumn) return arr;
        
        return [...arr].sort((a, b) => {
            let valA = a[sortColumn];
            let valB = b[sortColumn];
            
            // Handle null/undefined
            if (valA === null || valA === undefined || valA === '—') valA = '';
            if (valB === null || valB === undefined || valB === '—') valB = '';
            
            // Numeric comparison for attainment
            if (sortColumn === 'attainment') {
                valA = parseFloat(valA) || 0;
                valB = parseFloat(valB) || 0;
                return sortDir === 'asc' ? valA - valB : valB - valA;
            }
            
            // Date comparison
            if (sortColumn === 'registered' || sortColumn === 'last_submission') {
                const dateA = new Date(valA);
                const dateB = new Date(valB);
                if (!isNaN(dateA) && !isNaN(dateB)) {
                    return sortDir === 'asc' ? dateA - dateB : dateB - dateA;
                }
            }
            
            // String comparison (default)
            valA = String(valA).toLowerCase();
            valB = String(valB).toLowerCase();
            if (valA < valB) return sortDir === 'asc' ? -1 : 1;
            if (valA > valB) return sortDir === 'asc' ? 1 : -1;
            return 0;
        });
    }

    function applyFilters(){
        const q      = $search.val().toLowerCase(),
              status = $filter.val();

        filtered = data.filter(u=>{
            const match = (u.name + u.email).toLowerCase().includes(q);
            return (!status || u.status === status) && match;
        });

        // Apply sorting
        filtered = sortData(filtered);

        page = 1;
        renderTable();

        let a=0,p=0,r=0;
        filtered.forEach(u=>{
            if (u.status === 'active') a++;
            else if (u.status === 'pending') p++;
            else if (u.status === 'rejected') r++;
        });

        $('#count-total').text(filtered.length);
        $('#count-active').text(a);
        $('#count-pending').text(p);
        $('#count-rejected').text(r);
        
        // Update sort indicators
        $('#disciple-table th.sortable').removeClass('asc desc');
        if (sortColumn) {
            $(`#disciple-table th[data-sort="${sortColumn}"]`).addClass(sortDir);
        }
    }

    function load(){
        $.post(url,{action:'dasdm_fetch',nonce:nonce,status:$filter.val()},r=>{
            if (!r || !r.success){
                alert('Error loading disciples'); 
                return;
            }
            data = r.data || [];
            applyFilters();
        });
    }


	function updateStatus(id, status, reason = '') {
		const row = $tbody.find(`tr[data-id="${id}"]`);
		row.addClass('status-updated');

		$.post(url, {
			action: 'dasdm_update',
			nonce: nonce,
			user_id: id,
			status: status,
			reason: reason
		}, r => {
			if (!r || !r.success) {
				alert(r?.data || 'Error updating status');
				row.removeClass('status-updated');
				return;
			}
			setTimeout(load, 300);
		});
	}

    $filter.on('change',applyFilters);
    $search.on('input',applyFilters);

    $rowsSel.on('change',()=>{
        perPage = $rowsSel.val() === 'all' ? 'all' : parseInt($rowsSel.val(),10);
        applyFilters();
    });

    // Sortable column headers
    $('#disciple-table').on('click', 'th.sortable', function(){
        const col = $(this).data('sort');
        if (sortColumn === col) {
            // Toggle direction
            sortDir = sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            sortColumn = col;
            sortDir = 'asc';
        }
        applyFilters();
    });

    $prev.on('click',()=>{
        if (page > 1){ page--; renderTable(); }
    });

    $next.on('click',()=>{
        const totalPages = Math.ceil(filtered.length/(perPage === 'all' ? filtered.length : perPage)) || 1;
        if (page < totalPages){ page++; renderTable(); }
    });

    // Row click -> modal details
    $tbody.on('click','tr',function(e){
        if ($(e.target).is('button')) return;
        const id = $(this).data('id');
        const u  = filtered.find(x => x.id == id);
        if (!u) return;

        let html = '<div class="dasdm-details">';
        const keys = [
            'saved','baptized','phone','born_again','born_date',
            'spiritual_covering','bible_reading','fasting','memorization',
            'morning_prayer','midnight_prayer','bible_study','bible_study_other',
            'commitment_duration','commitment_duration_other',
            'agree_commitment','agree_commitment_other',
            'engine_status','level','attainment','level_start','last_submission'
        ];

        keys.forEach(k=>{
            let label = k.replace(/_/g,' ');
            let val   = u[k];
            if (k === 'attainment' && val !== null && val !== undefined){
                val = val + '%';
            }
            html += `<div><strong>${esc(label)}</strong>${esc(val || '—')}</div>`;
        });

        html += '</div>';

        // Drill-down button to disciple dashboard
        const dashboardUrl = '<?php echo esc_url(home_url("/disciple-dashboard/")); ?>';
        const drillUrl = dashboardUrl + '?participant=' + encodeURIComponent(u.email.toLowerCase());
        html += `
            <a href="${drillUrl}" class="dasdm-drilldown">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
                View Disciple Dashboard
            </a>
        `;

        $detailsBody.html(html);
        $details.css('display','flex');
    });

    $details.on('click',function(e){
        if (e.target.id === 'details-modal') $details.hide();
    });

	$tbody.on('click','.reject',function(e){
		e.stopPropagation();

		if ($(this).hasClass('disabled')) return;

		const id = $(this).data('id');

		pendingAction = {
							id,
							type: 'status',
							status: 'rejected'
						};

		$confirmTitle.text('Reject Disciple');
		$confirmMsg.text('Please confirm rejection and provide a reason.');
		$rejectReason.val('').show();

		$confirm.css('display','flex');
	});
	
	$tbody.on('click','.approve',function(e){
		e.stopPropagation();

		const id = $(this).data('id');

		pendingAction = {
							id,
							type: 'status',
							status: 'active'
						};

		$confirmTitle.text('Approve Disciple');
		$confirmMsg.text('Are you sure you want to approve this disciple?');
		$rejectReason.hide();

		$confirm.css('display','flex');
	});
	
	$tbody.on('click', '.delete', function (e) {
		e.stopPropagation();

		const id = $(this).data('id');

		pendingAction = { id, status: 'inactive', type: 'deactivate' };

		$confirmTitle.text('Remove Disciple');
		$confirmMsg.text(
			'This will remove the disciple from the discipleship program.'
		);
		$rejectReason.hide();

		$confirm.css('display', 'flex');
	});
	
	$tbody.on('click', '.pause', function (e) {
	  e.stopPropagation();
	  const id = $(this).data('id');

	  pendingAction = { id, type: 'pause' };

	  $confirmTitle.text('Pause Disciple');
	  $confirmMsg.text('Provide a reason for pausing this disciple:');
	  $rejectReason.val('').show(); // reusing textarea as reason input
	  $rejectReason.attr('placeholder', 'Reason for pause (required)');

	  $confirm.css('display', 'flex');
	});
	
	$tbody.on('click', '.resume', function (e) {
	  e.stopPropagation();
	  const id = $(this).data('id');

	  pendingAction = { id, type: 'resume' };

	  $confirmTitle.text('Resume Disciple');
	  $confirmMsg.text('Resume this disciple from their last pause?');
	  $rejectReason.hide();

	  $confirm.css('display', 'flex');
	});

	$confirmCancel.on('click', () => {
		pendingAction = null;
		$confirm.hide();
	});

	$confirmOk.on('click', () => {
    if (!pendingAction) return;

    if (pendingAction.type === 'deactivate') {

        $.post(url, {
            action: 'dasdm_deactivate',
            nonce,
            user_id: pendingAction.id
        }, r => {
            if (!r || !r.success) {
                alert(r?.data || 'Deactivate failed');
                return;
            }
            load();
        });

    } else if (pendingAction.type === 'status') {

        let reason = '';
        if (pendingAction.status === 'rejected') {
            reason = $rejectReason.val().trim();
            if (!reason) {
                alert('Please provide a rejection reason.');
                return;
            }
        }

        updateStatus(pendingAction.id, pendingAction.status, reason);
		
    }  else if (pendingAction.type === 'pause') {

	  const reason = $rejectReason.val().trim();
	  if (!reason) {
		alert('Please provide a pause reason.');
		return;
	  }

	  $.post(url, {
		action: 'dasdm_pause',
		nonce,
		user_id: pendingAction.id,
		reason
	  }, r => {
		if (!r || !r.success) {
		  alert(r?.data || 'Pause failed');
		  return;
		}
		load();
	  });

	} else if (pendingAction.type === 'resume') {

	  $.post(url, {
		action: 'dasdm_resume',
		nonce,
		user_id: pendingAction.id
	  }, r => {
		if (!r || !r.success) {
		  alert(r?.data || 'Resume failed');
		  return;
		}
		load();
	  });

	}	

    pendingAction = null;
    $confirm.hide();
});

    load();
})(jQuery);
</script>
<?php
    return ob_get_clean();
});
