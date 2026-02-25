/**
 * STEP 2 — Check if a participant should be promoted to the next level.
 *
 * Called with WordPress user ID.
 * Uses updated attainment, level threshold, duration, grace, pauses, and next level.
 */
function rcn_check_promotion($participant_id) {
    global $wpdb;

    $participants = "{$wpdb->prefix}discipleship_participants";
    $levels       = "{$wpdb->prefix}discipleship_levels";
    $pauses_tbl   = "{$wpdb->prefix}discipleship_pauses";
	$summary_tbl = "{$wpdb->prefix}discipleship_attainment_summary";

    error_log("[discipleship] === rcn_check_promotion START for user_id={$participant_id} ===");

    /* ---------------------------------------------------------
     * 1) Always recalculate attainment first (with grace logic)
     * --------------------------------------------------------- */
    if (function_exists('rcn_calculate_attainment')) {
       // rcn_calculate_attainment($participant_id); We dont need to do this, we have just done it with the form submission.
    }

    /* ---------------------------------------------------------
     * 2) Reload participant
     * --------------------------------------------------------- */
    $p = $wpdb->get_row($wpdb->prepare("
        SELECT *
        FROM $participants
        WHERE user_id = %d
        LIMIT 1
    ", $participant_id));

    if (!$p) {
        error_log("[discipleship] rcn_check_promotion: No participant row for user_id={$participant_id}");
        return false;
    }

    /* ---------------------------------------------------------
     * 3) Load current level (needs duration + grace + threshold + program_id)
     * --------------------------------------------------------- */
    $level = $wpdb->get_row($wpdb->prepare("
        SELECT id, name, program_id, duration_days, grace_period_days, promotion_threshold, max_grace_cycles
        FROM $levels
        WHERE id = %d
        LIMIT 1
    ", $p->current_level_id));

    if (!$level) {
        error_log("[discipleship] rcn_check_promotion: No level row for level_id={$p->current_level_id}");
        return false;
    }

    $attainment = isset($p->attainment) ? (float)$p->attainment : 0.0;
    $threshold  = (float)$level->promotion_threshold;

    error_log("[discipleship] promotion check: attainment={$attainment}, threshold={$threshold}");

    /* ---------------------------------------------------------
     * 4) Compute days remaining for this level (duration + grace − pauses)
     * --------------------------------------------------------- */
    $days_remaining = null; // null means "could not compute" (no promotion in that case)

    if (!empty($p->level_start_date)) {
        if (!defined('DAY_IN_SECONDS')) {
            define('DAY_IN_SECONDS', 86400);
        }

        $level_start_ts = strtotime($p->level_start_date);
        $now_ts         = current_time('timestamp');

        if ($level_start_ts && $now_ts >= $level_start_ts) {
            $days_since_start = floor(($now_ts - $level_start_ts) / DAY_IN_SECONDS);

            /* ---------------------------------------------------------
			 * NEW PAUSE CALCULATION (MULTI-PAUSE SUPPORT)
			 * Reads from wp_discipleship_pauses
			 * Filters by: user_id + program_id + level_id
			 * --------------------------------------------------------- */

			$paused_days = 0;

			$pause_rows = $wpdb->get_results($wpdb->prepare("
				SELECT paused_at, resumed_at
				FROM $pauses_tbl
				WHERE participant_id = %d
				  AND program_id = %d
				  AND level_id   = %d
				ORDER BY paused_at ASC
			", 
				$participant_id,
				$level->program_id,
				$level->id
			));

			foreach ($pause_rows as $row) {
				if (empty($row->paused_at)) {
					continue;
				}

				$pause_start = strtotime($row->paused_at);
				$pause_end   = $row->resumed_at ? strtotime($row->resumed_at) : $now_ts;

				// Avoid invalid negative intervals
				if ($pause_start && $pause_end && $pause_end > $pause_start) {
					$paused_days += floor(($pause_end - $pause_start) / DAY_IN_SECONDS);
				}
			}

			error_log("[discipleship] NEW pause calc: paused_days={$paused_days}");

            $valid_days_elapsed = max(0, $days_since_start - $paused_days);

            $duration_days = $level->duration_days !== null ? (int)$level->duration_days : 0;
            $grace_days    = $level->grace_period_days !== null ? (int)$level->grace_period_days : 0;
            $grace_cycles  = isset($p->grace_cycle_count) ? max(0, (int)$p->grace_cycle_count) : 0;

            $total_days = $duration_days + ($grace_days * $grace_cycles);

            // If no grace cycles yet, still show duration_days
            if ($total_days === 0 && $duration_days > 0) {
                $total_days = $duration_days;
            }

            if ($total_days > 0) {
                $remaining       = max(0, $total_days - $valid_days_elapsed);
                $days_remaining  = (int)$remaining;
                $dbg_msg = "level_start={$p->level_start_date}, days_since_start={$days_since_start}, paused_days={$paused_days}, valid_days_elapsed={$valid_days_elapsed}, total_days={$total_days}, days_remaining={$days_remaining}";
                error_log("[discipleship] promotion days calc: {$dbg_msg}");
            } else {
                error_log("[discipleship] promotion: total_days=0 (no duration configured). Skipping days-based promotion.");
            }
        } else {
            error_log("[discipleship] promotion: level_start_ts invalid or in future for participant_id={$participant_id}");
        }
    } else {
        error_log("[discipleship] promotion: participant has no level_start_date; cannot compute days_remaining.");
    }
		/* ---------------------------------------------------------
	 * GRACE PERIOD DETECTION ON EVERY SUBMISSION
	 * --------------------------------------------------------- */

	// Only compute grace if we know days_remaining
	if ($days_remaining !== null) {

		$attainment_below_threshold = ($attainment < $threshold);
		$level_completed            = ($days_remaining <= 7);  // threshold for “level time finished”

		// GRACE RULE:
		// If time is up (< 7 days remaining) AND attainment is still low,
		// we ALWAYS increment the grace cycle — PER SUBMISSION.
		if ($level_completed && $attainment_below_threshold) {

			// NEW: Always increment cycle count on submissions inside grace window
			$new_cycle_count = (int)$p->grace_cycle_count + 1;

			$wpdb->update(
				$participants,
				[
					'in_grace_period'   => 1,
					'grace_cycle_count' => $new_cycle_count,
					'updated_at'        => current_time('mysql'),
				],
				['id' => $p->id],
				['%d','%d','%s'],
				['%d']
			);

			error_log("[discipleship] GRACE ACTIVE for user_id={$participant_id}, cycle={$new_cycle_count}");

			// OPTIONAL: Notify user on each new cycle
			if (function_exists('rcn_trigger_grace_period_notification')) {
				rcn_trigger_grace_period_notification(
					$participant_id,
					$new_cycle_count
				);
			}

			// STOP — no promotion checked during grace
			return false;
		}

		// If threshold reached, exit grace mode automatically
		if ($p->in_grace_period == 1 && !$attainment_below_threshold) {
			$wpdb->update(
				$participants,
				[
					'in_grace_period' => 0,
					'updated_at'      => current_time('mysql'),
				],
				['id' => $p->id],
				['%d','%s'],
				['%d']
			);

			error_log("[discipleship] Grace ENDED for user_id={$participant_id} (threshold reached)");
		}
	}


    /* ---------------------------------------------------------
     * 5) Determine next level (next id in same program)
     * --------------------------------------------------------- */
    $next_level = $wpdb->get_row($wpdb->prepare("
        SELECT id, name
        FROM $levels
        WHERE program_id = %d
          AND id > %d
        ORDER BY id ASC
        LIMIT 1
    ", $level->program_id, $level->id));

    /* ---------------------------------------------------------
     * 6) Check if disciple qualifies for promotion
     *     Rule: attainment >= threshold AND no days remaining
     * --------------------------------------------------------- */
	
    // Final-level program completion check Rule: no next level AND attainment >= threshold AND no days remaining 

	if (!$next_level) {
		// Final level
		// Because we are weekly practices, so level should be done if there is less than a week to go.
		if ($days_remaining !== null && $days_remaining <= 7 && $attainment >= $threshold) {

			// Program truly completed
			if (function_exists('rcn_trigger_program_completed_notification')) {
				rcn_trigger_program_completed_notification($participant_id);
			}

			error_log("[discipleship] program COMPLETED for user_id={$participant_id}");
			return false;
		}

		// Final level but NOT completed yet
		error_log("[discipleship] final level but NOT completed (attainment or days_remaining not satisfied)");
		return false;
	}
	
    // If we cannot compute days_remaining, do NOT promote (safer)
    if ($days_remaining === null) {
        error_log("[discipleship] promotion: days_remaining is NULL; skipping promotion.");
        return false;
    }

    if ($attainment < $threshold) {
        error_log("[discipleship] promotion: attainment below threshold ({$attainment} < {$threshold}); not eligible.");
        return false;
    }

    if ($days_remaining >= 7) {
        error_log("[discipleship] promotion: still has {$days_remaining} day(s) remaining; too early to promote.");
        return false;
    }

    /* ---------------------------------------------------------
     * 7) Perform promotion (reset cycles, move to next level)
     * --------------------------------------------------------- */
    $old_level_id      = (int)$p->current_level_id;
    $prev_level_name   = $level->name;
    $next_level_id     = (int)$next_level->id;
    $next_level_name   = $next_level->name;
    $today             = date('Y-m-d', current_time('timestamp'));

	$updated = $wpdb->update(
		$participants,
		[
			'current_level_id'        => $next_level_id,
			'level_start_date'        => $today,
			'attainment'              => 0,
			'last_attainment_calc'    => current_time('mysql'),
			'grace_cycle_count'       => 0,
			'in_grace_period'         => 0,
			'updated_at'              => current_time('mysql'),
		],
		['id' => $p->id],
		['%d','%s','%d','%s','%d','%d','%s'],
		['%d']
	);

    if ($updated === false) {
        error_log("[discipleship] promotion: FAILED to update participant row for id={$p->id}. Error: {$wpdb->last_error}");
        return false;
    }

    error_log("[discipleship] promotion: user_id={$participant_id} promoted from level {$old_level_id} ({$prev_level_name}) to {$next_level_id} ({$next_level_name})");
	
	/* ---------------------------------------------------------
	 *  INSERT NEW SUMMARY ROW FOR THE NEW LEVEL
	 * --------------------------------------------------------- */

	$wpdb->insert(
		$summary_tbl,
		[
			'participant_id'        => $participant_id,
			'program_id'            => $level->program_id,
			'level_id'              => $next_level_id,
			'overall_attainment'    => 0,
			'br_attainment'         => 0,
			'fasting_attainment'    => 0,
			'memorization_attainment' => 0,
			'bible_study_attainment'  => 0,
			'mp_attainment'           => 0,
			'cp_attainment'           => 0,
			'mi_attainment'           => 0,
			'strongest_practice'    => null,
			'weakest_practice'      => null,
			'computed_at'           => current_time('mysql'),
		],
		[
			'%d','%d','%d',
			'%f','%f','%f','%f',
			'%f','%f','%f','%f',
			'%s','%s','%s'
		]
	);

	error_log("[discipleship] New attainment summary row created for user_id={$participant_id}, level={$next_level_id}");
	
	// Fire the promotion notification
	if (function_exists('rcn_trigger_promotion_notification')) {
		rcn_trigger_promotion_notification(
			$participant_id,
			$prev_level_name,
			$next_level_name,
			$attainment,
			$threshold
		);
	}

    /* ---------------------------------------------------------
     * 8) Optional: mark promotion flag for other systems
     * --------------------------------------------------------- */
    update_user_meta($participant_id, 'rcn_notify_promotion_flag', 1);

    /* ---------------------------------------------------------
     * 9) Fire optional custom hook/function
     * --------------------------------------------------------- */
    /*if (function_exists('rcn_notify_promotion')) {
        rcn_notify_promotion(
            $participant_id,
            $old_level_id,
            $next_level_id,
            $attainment,
            $threshold
        );
    } */

    /* ---------------------------------------------------------
     * 10) Fire your notification helper (email + dashboard)
     * --------------------------------------------------------- */
	
    /*if (function_exists('rcn_trigger_promotion_notification')) {
        rcn_trigger_promotion_notification(
            $participant_id,
            $prev_level_name,
            $next_level_name,
            $attainment,
            $threshold
        );
    }*/

    error_log("[discipleship] === rcn_check_promotion END for user_id={$participant_id} (PROMOTED) ===");
    return true;
}
