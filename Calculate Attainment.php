/**
 * Calculate weekly attainment for the participant.
 * - One summary row per WEEK per PROGRAM per LEVEL.
 * - Reads raw commitments table.
 * - Handles pauses, grace, missed weeks.
 * - Updates participant attainment (latest week only).
 */
function rcn_calculate_attainment($participant_user_id) {
    global $wpdb;

    $participants = "{$wpdb->prefix}discipleship_participants";
    $commitments  = "{$wpdb->prefix}discipleship_commitments";
    $pauses_tbl   = "{$wpdb->prefix}discipleship_pauses";
    $levels_tbl   = "{$wpdb->prefix}discipleship_levels";
    $summary      = "{$wpdb->prefix}discipleship_attainment_summary";

    /* ---------------------------------------------------------
     * 0. WP User
     * --------------------------------------------------------- */
    $wp_user = get_userdata($participant_user_id);
    if (!$wp_user) return false;

    $email = strtolower(trim($wp_user->user_email));

    /* ---------------------------------------------------------
     * 1. Load participant row
     * --------------------------------------------------------- */
    $p = $wpdb->get_row($wpdb->prepare("
        SELECT *
        FROM $participants
        WHERE user_id = %d
        LIMIT 1
    ", $participant_user_id));

    if (!$p) {
        error_log("[discipleship] No participant row for ID $participant_user_id");
        return false;
    }

    //$participant_db_id = (int) $p->id; This is wrong this is the row id
    $program_id        = (int) $p->program_id;
    $level_id          = (int) $p->current_level_id;

    /* ---------------------------------------------------------
     * 2. Load all DISTINCT week_start for this participant/program/level
     * --------------------------------------------------------- */
    $weeks = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT week_start
        FROM $commitments
        WHERE participant_id = %s
          AND program_id     = %d
          AND level_id       = %d
        ORDER BY week_start ASC
    ", $email, $program_id, $level_id));

    if (empty($weeks)) {
        error_log("[discipleship] No weeks found for participant $participant_user_id");
        return false;
    }

    /* ---------------------------------------------------------
     * 3. Load pause rows (for missed weeks)
     * --------------------------------------------------------- */
    $pause_rows = $wpdb->get_results($wpdb->prepare("
        SELECT paused_at, resumed_at
        FROM $pauses_tbl
        WHERE participant_id = %d
    ", $participant_user_id));

    /* ---------------------------------------------------------
     * 4. Prepare practice definitions
     * --------------------------------------------------------- */
    $all_practices = [
        'Bible Reading'                  => 'daily',
        'Morning Intimacy'               => 'daily',
        'Fasting'                        => 'weekly',
        'Scripture Memorization'         => 'weekly',
        'Bible Study & Meditation'       => 'weekly',
        'Midnight Intercessory Prayer'   => 'weekly',
        'Corporate Prayers'              => 'weekly'
    ];

    /* ---------------------------------------------------------
     * 5. Loop through each week and compute weekly summary row
     * --------------------------------------------------------- */
	error_log("[discipleship] Weekly $weeks");
	
    foreach ($weeks as $week_start) {
		
		error_log("[discipleship] This is the weekly start week: $week_start");

        /* -----------------------------
         * Load all commitments for this week
         * ----------------------------- */
        $rows = $wpdb->get_results($wpdb->prepare("
            SELECT practice, unit_type, value
            FROM $commitments
            WHERE participant_id = %s
              AND program_id     = %d
              AND level_id       = %d
              AND week_start     = %s
        ", $email, $program_id, $level_id, $week_start));

        if (empty($rows)) continue;

        /* -----------------------------
         * Build counters
         * ----------------------------- */
        $pd = [];
        foreach ($all_practices as $practice => $type) {
            $pd[$practice] = ['done' => 0, 'total' => 0];
        }

        foreach ($rows as $r) {
            $practice = $r->practice;
            if (!isset($pd[$practice])) continue;

            $pd[$practice]['done']  += (float)$r->value;
            $pd[$practice]['total'] += 1;
        }
		
		/* ---------------------------------------------------------
		 * MISSED WEEKS DETECTION
		 * --------------------------------------------------------- */

		// Load previous submission week
		$prev_week_start = $wpdb->get_var($wpdb->prepare("
			SELECT week_start
			FROM $commitments
			WHERE participant_id = %s AND program_id = %d AND level_id = %d
			AND week_start < (
				SELECT MAX(week_start)
				FROM $commitments
				WHERE participant_id = %s AND program_id = %d AND level_id = %d
			)
			ORDER BY week_start DESC
			LIMIT 1
		", $email, $program_id, $level_id,
		   $email, $program_id, $level_id));

		// Load most recent submission week
		$current_week_start = $wpdb->get_var($wpdb->prepare("
			SELECT MAX(week_start)
			FROM $commitments
			WHERE participant_id = %s AND program_id = %d AND level_id = %d
		", $email, $program_id, $level_id));

		$missed_weeks = 0;

		if ($prev_week_start && $current_week_start) {
			$prev_ts    = strtotime($prev_week_start);
			$current_ts = strtotime($current_week_start);

			$weeks_between = floor(($current_ts - $prev_ts) / WEEK_IN_SECONDS);

			// Weeks missed = gaps between submissions
			$raw_missed = max(0, $weeks_between - 1);

			// Adjust for pause windows
			$paused_weeks = 0;
			foreach ($pause_rows as $row) {
				if (!$row->paused_at) continue;
				$p_start = strtotime($row->paused_at);
				$p_end   = $row->resumed_at ? strtotime($row->resumed_at) : time();

				// Skip if pause period is totally outside the gap
				if ($p_end < $prev_ts || $p_start > $current_ts) continue;

				// Clip pause window to the submission gap
				$clip_start = max($prev_ts, $p_start);
				$clip_end   = min($current_ts, $p_end);

				$paused_weeks += floor(($clip_end - $clip_start) / WEEK_IN_SECONDS);
			}

			$missed_weeks = max(0, $raw_missed - $paused_weeks);
		}

		static $sent_missed_notification = false;
		if (!$sent_missed_notification && $missed_weeks >= 1) {
			rcn_trigger_missed_days_notification($participant_user_id, $missed_weeks);
			$sent_missed_notification = true;
		}

        /* -----------------------------
		 * Per-practice attainment %
		 * + CORRECT weighted overall
		 * ----------------------------- */

		$per = [];
		$earned_total   = 0.0;
		$possible_total = 0.0;

		foreach ($all_practices as $practice => $type) {

			// Correct denominator
			$possible = ($type === 'daily') ? 7 : 1;

			// Earned value from commitments
			$earned = isset($pd[$practice]) ? (float)$pd[$practice]['done'] : 0.0;

			// Safety cap (prevents duplicates inflating score)
			$earned = min($earned, $possible);

			// Per-practice %
			$per[$practice] = ($possible > 0)
				? round(($earned / $possible) * 100, 2)
				: 0;

			// Overall math
			$earned_total   += $earned;
			$possible_total += $possible;
		}

		// ✅ Correct overall attainment
		$overall = ($possible_total > 0)
			? round(($earned_total / $possible_total) * 100, 2)
			: 0;

        /* -----------------------------
         * Strongest / weakest
         * ----------------------------- */
		$all_equal = (min($per) === max($per));
		
		$strongest = $all_equal ? '' : array_keys($per, max($per))[0];
		$weakest   = $all_equal ? '' : array_keys($per, min($per))[0];
		
        //$strongest = array_keys($per, max($per))[0];
        //$weakest   = array_keys($per, min($per))[0];

        /* -----------------------------
         * INSERT / UPDATE summary row for this week
         * ----------------------------- */
        $result = $wpdb->query($wpdb->prepare("
            INSERT INTO $summary (
                participant_id, program_id, level_id, week_start,
                overall_attainment,
                br_attainment,
                fasting_attainment,
                memorization_attainment,
                bible_study_attainment,
                mp_attainment,
                cp_attainment,
                mi_attainment,
                strongest_practice,
                weakest_practice,
                computed_at
            )
            VALUES (
                %d, %d, %d, %s,
                %f,
                %f, %f, %f, %f, %f, %f, %f,
                %s, %s,
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                overall_attainment      = VALUES(overall_attainment),
                br_attainment           = VALUES(br_attainment),
                fasting_attainment      = VALUES(fasting_attainment),
                memorization_attainment = VALUES(memorization_attainment),
                bible_study_attainment  = VALUES(bible_study_attainment),
                mp_attainment           = VALUES(mp_attainment),
                cp_attainment           = VALUES(cp_attainment),
                mi_attainment           = VALUES(mi_attainment),
                strongest_practice      = VALUES(strongest_practice),
                weakest_practice        = VALUES(weakest_practice),
                computed_at             = NOW()
        ",
            $participant_user_id, $program_id, $level_id, $week_start,
            $overall,
            $per['Bible Reading'],
            $per['Fasting'],
            $per['Scripture Memorization'],
            $per['Bible Study & Meditation'],
            $per['Midnight Intercessory Prayer'],
            $per['Corporate Prayers'],
            $per['Morning Intimacy'],
            $strongest,
            $weakest
        ));
		
		error_log("[discipleship] Results: $result");
		
		$insert_id = $wpdb->insert_id;
		
		if ($insert_id) {
			error_log("[ATT SUMMARY] INSERTED new summary row for week_start=$week_start (ID=$insert_id)");
		} else {
			// On duplicate OR no field values changed
			// Need to check affected rows to differentiate
			if ($result === 1) {
				error_log("[ATT SUMMARY] INSERT occurred WITHOUT duplication but no insert_id? (rare)");
			} elseif ($result === 2) {
				error_log("[ATT SUMMARY] UPDATED existing summary row for week_start=$week_start");
			} elseif ($result === 0) {
				error_log("[ATT SUMMARY] Existing summary row detected but NO field values changed");
			} else {
				error_log("[ATT SUMMARY] Query result=$result, insert_id=$insert_id (unusual)");
			}
		}

    } // end foreach week
	
	/* ---------------------------------------------------------
	 * 6. Compute cumulative attainment for this level
	 * --------------------------------------------------------- */
	$cumulative = $wpdb->get_var($wpdb->prepare("
		SELECT AVG(overall_attainment)
		FROM $summary
		WHERE participant_id = %d
		  AND program_id     = %d
		  AND level_id       = %d
	", $p->user_id, $program_id, $level_id));

	$cumulative = $cumulative ? round($cumulative, 2) : 0;
	error_log("[discipleship] Cumulative attainment: $cumulative");
 	
    /* ---------------------------------------------------------
     * 7. Update participant overall attainment
     * --------------------------------------------------------- */
	$update_result = $wpdb->update(
		$participants,
		[
			'attainment'   => $cumulative,
			'last_attainment_calc' => current_time('mysql'),
			'updated_at'           => current_time('mysql'),
		],
		['user_id' => $participant_user_id],
		['%f','%s','%s'],
		['%d']
	);
	
	error_log("[discipleship] Results for updating partiticpant table: $update_result");

    /* ---------------------------------------------------------
     * 8. Calculate level performance (average per practice)
     * --------------------------------------------------------- */
    if (function_exists('rcn_calculate_level_performance')) {
        rcn_calculate_level_performance($participant_user_id, $program_id, $level_id);
    } else {
        // Use action hook as fallback
        do_action('rcn_calculate_level_performance', $participant_user_id, $program_id, $level_id);
    }

    return true;
}
