/**
 * Notification templates.
 * These are NOT logged — just templates for generating messages.
 *
 * {name} = disciple name
 * {level} = current level name
 * {prev_level} = previous level name
 * {next_level} = next level name
 * {attainment} = final attainment percent
 * {threshold} = required percent
 * {grace_cycle} = grace cycle count
 */
function rcn_get_notification_templates() {
    return [

        'missed_weeks' => [
            'type'    => 'missed',
            'subject' => 'You missed some discipleship submissions',
            'body'    => "Hello {name},\n\nWe noticed you have missed some reporting recently.\nPlease continue your discipleship commitments. We're here to help and support you.\n\nBlessings!"
        ],

        'enter_grace_period' => [
            'type'    => 'grace',
            'subject' => 'You have entered grace period',
            'body'    => "Hello {name},\n\nYou have entered grace period cycle {grace_cycle}.\nThis is additional time to reach the required attainment.\nStay encouraged — you can do this!\n\nBlessings!"
        ],

        'promotion' => [
            'type'    => 'promotion',
            'subject' => 'You have been promoted!',
            'body'    => "Congratulations {name}!\n\nYou have been promoted from {prev_level} to {next_level}.\nYour attainment was {attainment}% (required {threshold}%).\n\nKeep going — God is doing a work in you!"
        ],
		'program_completed' => [
			'type'    => 'completion',
			'subject' => 'You have completed the program!',
			'body'    => "Congratulations {name}!\n\nYou have successfully completed the discipleship program.\nWe celebrate your commitment, growth, and perseverance.\n\nMay God continue to guide and strengthen you on your journey.\n\nBlessings!"
		],

        'general' => [
            'type'    => 'general',
            'subject' => 'Notification',
            'body'    => "Hello {name},\n\n{message}\n\nBlessings!"
        ],
        
        // Friday encouragement templates (randomly selected by cron)
        'friday_encouragement_1' => [
            'type'    => 'encouragement',
            'subject' => 'You missed some discipleship submissions',
            'body'    => "Grace upon grace, {name}. We know this journey isn't always easy, but don't give up. You're growing more than you realize.\n\nBlessings!"
        ],
        
        'friday_encouragement_2' => [
            'type'    => 'encouragement',
            'subject' => 'You missed some discipleship submissions',
            'body'    => "Hello {name},\n\nIt's okay to stumble. What matters is that you keep going. We're cheering you on every step of the way!\n\nBlessings!"
        ],
        
        'friday_encouragement_3' => [
            'type'    => 'encouragement',
            'subject' => 'You missed some discipleship submissions',
            'body'    => "This path takes courage. If it's been hard, you're not alone. Take a breath, reset, and keep moving forward. You've got this!\n\nBlessings!"
        ],
        
        'friday_encouragement_4' => [
            'type'    => 'encouragement',
            'subject' => 'You missed some discipleship submissions',
            'body'    => "Hey {name}, we noticed you missed a submission. That's okay, sometimes the journey stretches us. Give yourself grace and keep going. You're doing better than you think.\n\nBlessings!"
        ],
        
        // Zero attainment - scored 0% for missed week(s)
        'zero_attainment' => [
            'type'    => 'zero_attainment',
            'subject' => 'Missed week(s) scored as 0%',
            'body'    => "Hello {name},\n\nWe noticed you missed {weeks_count} week(s) of discipleship submissions.\n\nThe following week(s) have been recorded as 0% attainment:\n{weeks_list}\n\nYour cumulative attainment is now {attainment}%.\n\nIt's not too late to get back on track! Please submit your weekly report to continue your discipleship journey.\n\nBlessings!"
        ]
    ];
}


/**
 * Replace placeholders in a notification body/subject.
 */
function rcn_render_template($template, $vars = []) {
    foreach ($vars as $key => $val) {
        $template = str_replace('{' . $key . '}', $val, $template);
    }
    return $template;
}


/**
 * Log notification to DB (your existing table).
 */
function rcn_log_notification($user_id, $type, $message) {
    global $wpdb;

    $wpdb->insert(
        "{$wpdb->prefix}discipleship_notifications",
        [
            'user_id' => $user_id,
            'message' => $message,
            'type'    => $type,
            'is_read' => 0
        ],
        ['%d','%s','%s','%d']
    );
}

if (!defined('RCN_SEND_EMAIL_NOTIFICATIONS')) {
    define('RCN_SEND_EMAIL_NOTIFICATIONS', true);
}

function rcn_send_email_notification($user_id, $subject, $body) {
    if (!RCN_SEND_EMAIL_NOTIFICATIONS) return;

    $user = get_user_by('ID', $user_id);
    if (!$user) return;

    wp_mail($user->user_email, $subject, $body);
}

/**
 * Master notification function.
 * Loads template → renders → logs → emails.
 */
function rcn_send_notification_from_template($user_id, $notif_key, $vars = []) {

    $templates = rcn_get_notification_templates();

    if (!isset($templates[$notif_key])) {
        return false;
    }

    $tpl = $templates[$notif_key];

    // Inject user name automatically
    $u = get_user_by('ID', $user_id);
    if ($u) {
        $vars['name'] = $u->display_name;
    }

    // Render subject & body
    $subject = rcn_render_template($tpl['subject'], $vars);
    $body    = rcn_render_template($tpl['body'], $vars);

    // Store in your existing notif table
    rcn_log_notification($user_id, $tpl['type'], $body);

    // Send email (optional)
    rcn_send_email_notification($user_id, $subject, $body);

    return true;
}

function rcn_trigger_missed_days_notification($user_id, $missed_weeks) {
    if ($missed_weeks >= 1) {
        rcn_send_notification_from_template($user_id, 'missed_weeks', [
            'missed_days' => $missed_weeks
        ]);
		
		error_log("[discipleship] === rcn_trigger_missed_days_notification");
    }
}

function rcn_trigger_grace_period_notification($user_id, $grace_cycle) {
    rcn_send_notification_from_template($user_id, 'enter_grace_period', [
        'grace_cycle' => $grace_cycle
    ]);
	
	error_log("[discipleship] === rcn_trigger_grace_period_notification");
}

function rcn_trigger_promotion_notification($user_id, $prev_level_name, $next_level_name, $attainment, $threshold) {
    rcn_send_notification_from_template($user_id, 'promotion', [
        'prev_level' => $prev_level_name,
        'next_level' => $next_level_name,
        'attainment' => $attainment,
        'threshold'  => $threshold
    ]);
	
	error_log("[discipleship] === rcn_trigger_promotion_notification");
}

function rcn_trigger_program_completed_notification($user_id) {
    rcn_send_notification_from_template($user_id, 'program_completed', []);
	error_log("[discipleship] === rcn_trigger_program_completed_notification");
}

/**
 * Trigger zero attainment notification when disciple misses week(s)
 * 
 * @param int   $user_id      The disciple's user ID
 * @param array $weeks        Array of week_start dates that were scored 0%
 * @param float $attainment   The new cumulative attainment after zeros applied
 */
function rcn_trigger_zero_attainment_notification($user_id, $weeks, $attainment) {
    $weeks_count = count($weeks);
    
    // Format weeks list for email
    $weeks_list = '';
    foreach ($weeks as $week) {
        $weeks_list .= "- Week of {$week}\n";
    }
    
    rcn_send_notification_from_template($user_id, 'zero_attainment', [
        'weeks_count' => $weeks_count,
        'weeks_list'  => trim($weeks_list),
        'attainment'  => number_format($attainment, 1),
    ]);
    
    error_log("[discipleship] === rcn_trigger_zero_attainment_notification for user {$user_id}, {$weeks_count} week(s)");
}
