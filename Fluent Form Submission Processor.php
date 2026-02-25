/* ---------------------------------------------------------
 *  Discipleship Submission Processor 
 * --------------------------------------------------------- */
add_action('fluentform/submission_inserted', 'rcn_process_discipleship_submission', 10, 3);

function rcn_process_discipleship_submission($entry_id, $form_data, $form) {

    $FORM_ID = 3;
    if ((int)$form->id !== (int)$FORM_ID) return;

    global $wpdb;
    $table = $wpdb->prefix . 'discipleship_commitments';
	error_log("[discipleship] Enter");
	
    /* ---------------------------------------------------------
     * HELPERS
     * --------------------------------------------------------- */

    $parse_date = static function($raw){
        if (!$raw) return '';
        $raw = trim((string)$raw);
        $formats = ['Y-m-d','d/m/Y','m/d/Y','Y/m/d','d-m-Y','m-d-Y','M d, Y','d M Y'];
        foreach ($formats as $f) {
            $dt = DateTime::createFromFormat($f, $raw);
            if ($dt && $dt->format($f) === $raw) {
                return $dt->format('Y-m-d');
            }
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : '';
    };

    $pick = static function($src, $keys, $def='') {
        foreach ((array)$keys as $k)
            if (isset($src[$k]) && $src[$k] !== '') return $src[$k];
        return $def;
    };

    $normalize_day = static function($day){
        $day = strtolower(trim($day));
        $map = [
            'sun'=>'Sunday','sunday'=>'Sunday',
            'mon'=>'Monday','monday'=>'Monday',
            'tue'=>'Tuesday','tues'=>'Tuesday','tuesday'=>'Tuesday',
            'wed'=>'Wednesday','wednesday'=>'Wednesday',
            'thu'=>'Thursday','thur'=>'Thursday','thurs'=>'Thursday','thursday'=>'Thursday',
            'fri'=>'Friday','friday'=>'Friday',
            'sat'=>'Saturday','saturday'=>'Saturday'
        ];
        
		$map[$day] ?? '';
    };

    $normalize_days = static function($arr) use($normalize_day){
        return array_filter(array_map($normalize_day, (array)$arr));
    };

    $weekly_to_value = static function($response){
        if (is_array($response)) $response = implode(' ', $response);
        $r = trim(strtolower((string)$response));
        $map = [
            'fully completed'      => 1,
            'fully complete'       => 1,
            'partially completed'  => 0.5,
            'partally complete'    => 0.5,
            'not completed'        => 0,
            'not complete'         => 0,
        ];
        return $map[$r] ?? null;
    };

    /* ---------------------------------------------------------
     * IDENTIFY PARTICIPANT
     * --------------------------------------------------------- */
	error_log("[discipleship] Identity");
    $participant_email = sanitize_email($pick($form_data, [
        'email_address','Email_Address','participant_email','participant_id','email'
    ]));

    if (!$participant_email && is_user_logged_in()) {
        $participant_email = wp_get_current_user()->user_email;
    }

    $participant_email = strtolower(trim($participant_email));
    if (!$participant_email) {
        error_log("[discipleship] Missing participant email in submission");
        return;
    }

    $user = get_user_by('email', $participant_email);
    if (!$user) {
        error_log("[discipleship] No WP user for email: $participant_email");
        return;
    }

    $user_id = $user->ID;

    // Pull participant row (contains program + level)
    $participant_row = $wpdb->get_row($wpdb->prepare("
        SELECT id, program_id, current_level_id
        FROM {$wpdb->prefix}discipleship_participants
        WHERE user_id = %d
        LIMIT 1
    ", $user_id));

    if (!$participant_row) {
        error_log("[discipleship] No participants table row for email $participant_email");
        return;
    }

    //$participant_db_id = (int)$participant_row->id;
    $program_id        = (int)$participant_row->program_id;
    $level_id          = (int)$participant_row->current_level_id;

    /* ---------------------------------------------------------
     * WEEK START
     * --------------------------------------------------------- */
    $week_start_raw = $pick($form_data, [
        'week_start','week_start_date','Week_Start','reporting_week','week'
    ]);

    $week_start = $parse_date($week_start_raw);
    if (!$week_start) {
        error_log("[discipleship] Invalid week_start");
        return;
    }
	
	/* ---------------------------------------------------------
	 * FIRST SUBMISSION CHECK — set level_start_week
	 * --------------------------------------------------------- */

	// Check if ANY commitments already exist for this participant+program+level
	$existing_rows = $wpdb->get_var($wpdb->prepare("
		SELECT COUNT(*) 
		FROM {$wpdb->prefix}discipleship_commitments
		WHERE participant_id = %s
		  AND program_id     = %d
		  AND level_id       = %d
		LIMIT 1
	", $participant_email, $program_id, $level_id));

	$is_first_submission = ((int)$existing_rows === 0);

	// Only update participant row if this is the FIRST submission ever for this level
	if ($is_first_submission) {

		error_log("[discipleship] First submission detected — setting level_start_week to $week_start");

		// Safety check: ensure participant row matches program + level
		if ((int)$participant_row->program_id === $program_id &&
			(int)$participant_row->current_level_id === $level_id) {

			$wpdb->update(
				"{$wpdb->prefix}discipleship_participants",
				['level_start_date' => $week_start],
				['user_id' => $user_id],
				['%s'],
				['%d']
			);

			if ($wpdb->last_error) {
				error_log("[discipleship] Error updating level_start_week: " . $wpdb->last_error);
			}

		} else {
			error_log("[discipleship] WARNING — participant table program/level mismatch. Start week NOT updated.");
		}
		
		    /* ---------------------------------------------------------
		 * Update attainment summary: set the start week
		 * --------------------------------------------------------- */
		$wpdb->update(
			"{$wpdb->prefix}discipleship_attainment_summary",
			['start_week' => $week_start], 
			[
				'participant_id' => $user_id,
				'program_id'     => $program_id,
				'level_id'       => $level_id
			],
			['%s'],
			['%d','%d','%d']
		);
	} 

    /* ---------------------------------------------------------
     * DELETE all existing rows for this (participant, program, level, week)
     * --------------------------------------------------------- */
    $wpdb->delete(
        $table,
        [
            'participant_id' => $participant_email,
            'program_id'     => $program_id,
            'level_id'       => $level_id,
            'week_start'     => $week_start
        ],
        ['%s','%d','%d','%s']
    );

    /* ---------------------------------------------------------
     * DAILY PRACTICES
     * --------------------------------------------------------- */
	
	error_log("[discipleship] FULL FORM DATA >>> " . print_r($form_data, true));

    //$bible_reading    = $normalize_days($pick($form_data,['bible_reading','Bible_Reading'],[]));
    //$morning_intimacy = $normalize_days($pick($form_data,['morning_intimacy','Morning_Intimacy'],[]));
	
	$bible_reading    = isset($form_data['bible_reading']) ? (array) $form_data['bible_reading'] : [];
	$morning_intimacy = isset($form_data['morning_intimacy']) ? (array) $form_data['morning_intimacy'] : [];

    $comment_general = $pick($form_data, ['comment_general','general_comment','comments_general'], '');
    $comment_other   = $pick($form_data, ['comment_other','other_comment','comments_other'], '');

    $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

    $daily_practices = [
        'Bible Reading'    => $bible_reading,
        'Morning Intimacy' => $morning_intimacy,
    ];
	
	error_log("[discipleship] $i");

    for ($i=0; $i<7; $i++) {
		
		error_log("[discipleship] inside insert");
		
        $date = date('Y-m-d', strtotime("$week_start +$i days"));
        $day  = $days[$i];

		error_log("[discipleship] inside insert  $day");

        foreach ($daily_practices as $practice => $selected_days) {
            $value = in_array($day, $selected_days, true) ? 1 : 0;
			
			error_log("[discipleship] inside insert $practice => $selected_days");

            rcn_upsert_commitment($table, [
                'participant_id'  => $participant_email,
                'program_id'      => $program_id,
                'level_id'        => $level_id,
                'week_start'      => $week_start,
                'date'            => $date,
                'practice'        => $practice,
                'unit_type'       => 'daily',
                'value'           => $value,
                'comment_general' => $comment_general,
                'comment_other'   => $comment_other
            ]);
        }
    }

    /* ---------------------------------------------------------
     * WEEKLY PRACTICES
     * --------------------------------------------------------- */
    $weekly_practices = [
        'Fasting'                     => $pick($form_data,['fasting','Fasting'],''),
        'Bible Study & Meditation'    => $pick($form_data,['bible_study_meditation'], ''),
        'Scripture Memorization'      => $pick($form_data,['scripture_memorization'], ''),
        'Midnight Intercessory Prayer'=> $pick($form_data,['midnight_intercessory_prayer'], ''),
        'Corporate Prayers'           => $pick($form_data,['corporate_prayers','corporate_gathering_prayers_commitment'],'')
    ];

    foreach ($weekly_practices as $practice => $resp) {
        $val = $weekly_to_value($resp);
        if ($val === null) continue;

        rcn_upsert_commitment($table, [
            'participant_id'  => $participant_email,
            'program_id'      => $program_id,
            'level_id'        => $level_id,
            'week_start'      => $week_start,
            'date'            => $week_start,
            'practice'        => $practice,
            'unit_type'       => 'weekly',
            'value'           => $val,
            'comment_general' => $comment_general,
            'comment_other'   => $comment_other
        ]);
    }
	
	/* ---------------------------------------------------------
	 * UPDATE ATTAINMENT SUMMARY (runs every submission)
	 * --------------------------------------------------------- */

	/*$existing_summary_row = $wpdb->get_var($wpdb->prepare("
		SELECT id
		FROM {$wpdb->prefix}discipleship_attainment_summary
		WHERE participant_id     = %d
		  AND program_id         = %d
		  AND level_id           = %d
		  AND report_week_start  = %s
		LIMIT 1
	", $user_id, $program_id, $level_id, $week_start));

	if ($existing_summary_row) {
		// This is a RESUBMISSION for the same week
		rcn_update_attainment_summary($user_id, $program_id, $level_id, $week_start);
	} */

    /* ---------------------------------------------------------
     * BUSINESS LOGIC
     * --------------------------------------------------------- */
    rcn_calculate_attainment($user_id);
    rcn_check_promotion($user_id);
	rcn_upsert_week_summary($user_id, $program_id, $level_id, $week_start);

}

/*function rcn_update_attainment_summary($user_id, $program_id, $level_id, $week_start) {
    global $wpdb;

    $summary = "{$wpdb->prefix}discipleship_attainment_summary";

    // 1. Does a summary row already exist?
    $existing = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM $summary
        WHERE participant_id = %d
          AND program_id     = %d
          AND level_id       = %d
        LIMIT 1
    ", $user_id, $program_id, $level_id));

    // 2. If missing → create it (baseline row)
    if (!$existing) {
        $wpdb->insert($summary, [
            'participant_id'        => $user_id,
            'program_id'            => $program_id,
            'level_id'              => $level_id,
            'start_week'            => $week_start,
            'overall_attainment'    => 0,
            'br_attainment'         => 0,
            'fasting_attainment'    => 0,
            'memorization_attainment'=> 0,
            'bible_study_attainment'=> 0,
            'updated_at'            => current_time('mysql')
        ]);

        if ($wpdb->last_error) {
            error_log("[discipleship] SUMMARY INSERT ERROR: " . $wpdb->last_error);
        }
    }

    // 3. Recalculate attainment using your existing logic
    $attainment = rcn_calculate_attainment($user_id, $program_id, $level_id);

    // 4. Update summary with fresh scores
    $wpdb->update(
        $summary,
        [
            'overall_attainment'     => $attainment['overall'] ?? 0,
            'br_attainment'          => $attainment['bible_reading'] ?? 0,
            'fasting_attainment'     => $attainment['fasting'] ?? 0,
            'memorization_attainment'=> $attainment['memorization'] ?? 0,
            'bible_study_attainment' => $attainment['bible_study'] ?? 0,
            'updated_at'             => current_time('mysql')
        ],
        [
            'participant_id' => $user_id,
            'program_id'     => $program_id,
            'level_id'       => $level_id
        ],
        ['%f','%f','%f','%f','%f','%s'],
        ['%d','%d','%d']
    );

    if ($wpdb->last_error) {
        error_log("[discipleship] SUMMARY UPDATE ERROR: " . $wpdb->last_error);
    }
}
*/

/*function rcn_update_attainment_summary($user_id, $program_id, $level_id, $week_start) {
    global $wpdb;

    $summary = "{$wpdb->prefix}discipleship_attainment_summary";

    // 1. Does a row already exist FOR THIS WEEK?
    $existing = $wpdb->get_var($wpdb->prepare("
        SELECT id
        FROM $summary
        WHERE participant_id = %d
          AND program_id     = %d
          AND level_id       = %d
          AND week_start     = %s
        LIMIT 1
    ", $user_id, $program_id, $level_id, $week_start));

    // 2. Recalculate attainment for this submission
    // (YOUR function must return structured array)
    $attainment = rcn_calculate_attainment($user_id, $program_id, $level_id, $week_start);

    // If no row for this week, INSERT
    if (!$existing) {

        $wpdb->insert($summary, [
            'participant_id'         => $user_id,
            'program_id'             => $program_id,
            'level_id'               => $level_id,
            'week_start'             => $week_start,
            'overall_attainment'     => $attainment['overall'] ?? 0,
            'br_attainment'          => $attainment['bible_reading'] ?? 0,
            'fasting_attainment'     => $attainment['fasting'] ?? 0,
            'memorization_attainment'=> $attainment['memorization'] ?? 0,
            'bible_study_attainment' => $attainment['bible_study'] ?? 0,
            'updated_at'             => current_time('mysql')
        ]);

        if ($wpdb->last_error) {
            error_log("[SUMMARY INSERT ERROR] " . $wpdb->last_error);
        }

    } else {
        // Optional: update if they resubmit the same week
        $wpdb->update(
            $summary,
            [
                'overall_attainment'     => $attainment['overall'] ?? 0,
                'br_attainment'          => $attainment['bible_reading'] ?? 0,
                'fasting_attainment'     => $attainment['fasting'] ?? 0,
                'memorization_attainment'=> $attainment['memorization'] ?? 0,
                'bible_study_attainment' => $attainment['bible_study'] ?? 0,
                'updated_at'             => current_time('mysql')
            ],
            ['id' => (int)$existing],
            ['%f','%f','%f','%f','%f','%s'],
            ['%d']
        );

        if ($wpdb->last_error) {
            error_log("[SUMMARY UPDATE ERROR] " . $wpdb->last_error);
        }
    }
}*/

function rcn_upsert_commitment($table, $data) {
    global $wpdb;

	error_log('We are in rcn_upsert_commitment');
	
    // Define unique row identity.
    // One row per participant + program + level + day + practice + type.
    $where = [
        'participant_id' => $data['participant_id'],
        'program_id'     => $data['program_id'],
        'level_id'       => $data['level_id'],
        'week_start'     => $data['week_start'],
        'date'           => $data['date'],
        'practice'       => $data['practice'],
        'unit_type'      => $data['unit_type']
    ];

    // Look for an existing row
    $existing_id = $wpdb->get_var($wpdb->prepare("
        SELECT id
        FROM {$table}
        WHERE participant_id = %d
          AND program_id     = %d
          AND level_id       = %d
          AND week_start     = %s
          AND date           = %s
          AND practice       = %s
          AND unit_type      = %s
        LIMIT 1
    ",
        $where['participant_id'],
        $where['program_id'],
        $where['level_id'],
        $where['week_start'],
        $where['date'],
        $where['practice'],
        $where['unit_type']
    ));

    if ($existing_id) {
        // UPDATE   
        $wpdb->update(
            $table,
            $data,
            ['id' => (int)$existing_id],
            null,
            ['%d']
        );
		
		if ($wpdb->last_error) {
			error_log('[RCN DB ERROR] ' . $wpdb->last_error);
			error_log('[RCN DB LAST QUERY] ' . $wpdb->last_query);
		}

    } else {
        // INSERT
        $wpdb->insert($table, $data);
		
		if ($wpdb->last_error) {
			error_log('[RCN DB ERROR] ' . $wpdb->last_error);
			error_log('[RCN DB LAST QUERY] ' . $wpdb->last_query);
		}

    }
}


/**
 * Compute weekly attainment for THIS week only
 * and insert/update summary row accordingly.
 */
function rcn_upsert_week_summary($participant_user_id, $program_id, $level_id, $week_start) {
    global $wpdb;

    error_log("[ATT SUMMARY] rcn_upsert_week_summary START for week=$week_start");

    $summary     = "{$wpdb->prefix}discipleship_attainment_summary";
    $commitments = "{$wpdb->prefix}discipleship_commitments";

    $all_practices = [
        'Bible Reading'                => 'daily',
        'Morning Intimacy'             => 'daily',
        'Fasting'                      => 'weekly',
        'Scripture Memorization'       => 'weekly',
        'Bible Study & Meditation'     => 'weekly',
        'Midnight Intercessory Prayer' => 'weekly',
        'Corporate Prayers'            => 'weekly'
    ];

    //-------------------------------------------
    // 1) Fetch commitments for THIS exact week
    //-------------------------------------------
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT practice, unit_type, value
        FROM $commitments
        WHERE participant_id = (
            SELECT user_email FROM {$wpdb->prefix}users WHERE ID = %d
        )
          AND program_id = %d
          AND level_id   = %d
          AND week_start = %s
    ", $participant_user_id, $program_id, $level_id, $week_start));

    if (empty($rows)) {
        error_log("[ATT SUMMARY] No commitments found for week $week_start — summary not created");
        return false;
    }

    //-------------------------------------------
    // 2) Compute per-practice attainment
    //-------------------------------------------
    $pd = [];
    foreach ($all_practices as $p => $_) {
        $pd[$p] = ['done'=>0, 'total'=>0];
    }

    foreach ($rows as $r) {
        if (!isset($pd[$r->practice])) continue;
        $pd[$r->practice]['done']  += (float)$r->value;
        $pd[$r->practice]['total'] += 1;
    }

	$per = [];
	$earned_total   = 0.0;
	$possible_total = 0.0;

	foreach ($all_practices as $practice => $type) {

		// Correct denominators for your system
		$possible = ($type === 'daily') ? 7 : 1;

		$earned = isset($pd[$practice]) ? (float)$pd[$practice]['done'] : 0.0;

		// Prevent duplicates or extra rows from inflating earned
		$earned = min($earned, $possible);

		// Per-practice %
		$per[$practice] = ($possible > 0)
			? round(($earned / $possible) * 100, 2)
			: 0;

		// Weighted overall totals
		$earned_total   += $earned;
		$possible_total += $possible;
	}

	// Correct overall
	$overall = ($possible_total > 0)
		? round(($earned_total / $possible_total) * 100, 2)
		: 0;

    $all_equal = (min($per) === max($per));
    $strongest = $all_equal ? '' : array_keys($per, max($per))[0];
    $weakest   = $all_equal ? '' : array_keys($per, min($per))[0];

    //-------------------------------------------
    // 3) Check for existing row (manual upsert)
    //-------------------------------------------
    $existing_id = $wpdb->get_var($wpdb->prepare("
        SELECT id
        FROM $summary
        WHERE participant_id = %d
          AND program_id     = %d
          AND level_id       = %d
          AND week_start     = %s
        LIMIT 1
    ", $participant_user_id, $program_id, $level_id, $week_start));

    //-------------------------------------------
    // 4) Branch: Insert OR Update
    //-------------------------------------------
    if ($existing_id) {
        // UPDATE
        $wpdb->update(
            $summary,
            [
                'overall_attainment'      => $overall,
                'br_attainment'           => $per['Bible Reading'],
                'fasting_attainment'      => $per['Fasting'],
                'memorization_attainment' => $per['Scripture Memorization'],
                'bible_study_attainment'  => $per['Bible Study & Meditation'],
                'mp_attainment'           => $per['Midnight Intercessory Prayer'],
                'cp_attainment'           => $per['Corporate Prayers'],
                'mi_attainment'           => $per['Morning Intimacy'],
                'strongest_practice'      => $strongest,
                'weakest_practice'        => $weakest,
                'computed_at'             => current_time('mysql'),
            ],
            ['id' => (int)$existing_id],
            ['%f','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s'],
            ['%d']
        );

        error_log("[ATT SUMMARY] UPDATED existing summary row id=$existing_id for week=$week_start");
    }
    else {
        // INSERT
        $wpdb->insert(
            $summary,
            [
                'participant_id'          => $participant_user_id,
                'program_id'              => $program_id,
                'level_id'                => $level_id,
                'week_start'              => $week_start,
                'overall_attainment'      => $overall,
                'br_attainment'           => $per['Bible Reading'],
                'fasting_attainment'      => $per['Fasting'],
                'memorization_attainment' => $per['Scripture Memorization'],
                'bible_study_attainment'  => $per['Bible Study & Meditation'],
                'mp_attainment'           => $per['Midnight Intercessory Prayer'],
                'cp_attainment'           => $per['Corporate Prayers'],
                'mi_attainment'           => $per['Morning Intimacy'],
                'strongest_practice'      => $strongest,
                'weakest_practice'        => $weakest,
                'computed_at'             => current_time('mysql'),
            ],
            ['%d','%d','%d','%s','%f','%f','%f','%f','%f','%f','%f','%f','%s','%s','%s']
        );

        if ($wpdb->insert_id) {
            error_log("[ATT SUMMARY] INSERTED new row id={$wpdb->insert_id} for week=$week_start");
        } else {
            error_log("[ATT SUMMARY] INSERT FAILED for week=$week_start : ".$wpdb->last_error);
        }
    }

    return true;
}

