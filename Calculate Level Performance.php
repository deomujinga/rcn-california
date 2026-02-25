<?php
/**
 * Calculate Level Performance
 * 
 * Computes the average attainment per practice for a participant's current level.
 * Determines strongest and weakest practices based on these averages.
 * Stores results in discipleship_level_performance table.
 * 
 * Called at the end of rcn_calculate_attainment() after the attainment summary is updated.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Calculate and store level performance for a participant.
 * 
 * @param int $participant_user_id The WordPress user ID of the participant
 * @param int $program_id The program ID
 * @param int $level_id The level ID
 * @return bool True on success, false on failure
 */
function rcn_calculate_level_performance($participant_user_id, $program_id, $level_id) {
    global $wpdb;

    // Table names
    $summary_tbl     = "{$wpdb->prefix}discipleship_attainment_summary";
    $performance_tbl = "{$wpdb->prefix}discipleship_level_performance";
    $participants    = "{$wpdb->prefix}discipleship_participants";

    // Validate inputs
    if (!$participant_user_id || !$program_id || !$level_id) {
        error_log("[LEVEL PERF] Missing required parameters: user=$participant_user_id, program=$program_id, level=$level_id");
        return false;
    }

    // Get participant DB ID
    $participant = $wpdb->get_row($wpdb->prepare("
        SELECT id FROM $participants WHERE user_id = %d LIMIT 1
    ", $participant_user_id));

    if (!$participant) {
        error_log("[LEVEL PERF] No participant found for user_id=$participant_user_id");
        return false;
    }

    $participant_db_id = (int) $participant->id;

    /* ---------------------------------------------------------
     * 1. Calculate average attainment per practice for this level
     * --------------------------------------------------------- */
    $averages = $wpdb->get_row($wpdb->prepare("
        SELECT 
            AVG(br_attainment) AS avg_br,
            AVG(fasting_attainment) AS avg_fasting,
            AVG(memorization_attainment) AS avg_memorization,
            AVG(bible_study_attainment) AS avg_bible_study,
            AVG(mp_attainment) AS avg_mp,
            AVG(cp_attainment) AS avg_cp,
            AVG(mi_attainment) AS avg_mi
        FROM $summary_tbl
        WHERE participant_id = %d
          AND program_id = %d
          AND level_id = %d
    ", $participant_user_id, $program_id, $level_id));

    if (!$averages) {
        error_log("[LEVEL PERF] No summary data found for user=$participant_user_id, program=$program_id, level=$level_id");
        return false;
    }

    /* ---------------------------------------------------------
     * 2. Build practice averages array
     * --------------------------------------------------------- */
    $practice_averages = [
        'Bible Reading'                => round((float) $averages->avg_br, 2),
        'Fasting'                      => round((float) $averages->avg_fasting, 2),
        'Scripture Memorization'       => round((float) $averages->avg_memorization, 2),
        'Bible Study & Meditation'     => round((float) $averages->avg_bible_study, 2),
        'Midnight Intercessory Prayer' => round((float) $averages->avg_mp, 2),
        'Corporate Prayers'            => round((float) $averages->avg_cp, 2),
        'Morning Intimacy'             => round((float) $averages->avg_mi, 2),
    ];

    error_log("[LEVEL PERF] Practice averages: " . json_encode($practice_averages));

    /* ---------------------------------------------------------
     * 3. Determine strongest and weakest practices
     * --------------------------------------------------------- */
    $max_val = max($practice_averages);
    $min_val = min($practice_averages);

    // Check if all practices have the same average
    $all_equal = ($max_val === $min_val);

    if ($all_equal) {
        // All practices have the same average - no strongest or weakest
        $strongest = '';
        $weakest = '';
        error_log("[LEVEL PERF] All practices equal at $max_val - no strongest/weakest");
    } else {
        // Find strongest (highest average)
        $strongest = array_keys($practice_averages, $max_val)[0];
        
        // Find weakest (lowest average, including 0s)
        $weakest = array_keys($practice_averages, $min_val)[0];
        
        error_log("[LEVEL PERF] Strongest: $strongest ($max_val%), Weakest: $weakest ($min_val%)");
    }

    /* ---------------------------------------------------------
     * 4. Insert or update the level performance row
     * --------------------------------------------------------- */
    $result = $wpdb->query($wpdb->prepare("
        INSERT INTO $performance_tbl (
            participant_id,
            program_id,
            level_id,
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
            %d, %d, %d,
            %f, %f, %f, %f, %f, %f, %f,
            %s, %s,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
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
        $participant_db_id,
        $program_id,
        $level_id,
        $practice_averages['Bible Reading'],
        $practice_averages['Fasting'],
        $practice_averages['Scripture Memorization'],
        $practice_averages['Bible Study & Meditation'],
        $practice_averages['Midnight Intercessory Prayer'],
        $practice_averages['Corporate Prayers'],
        $practice_averages['Morning Intimacy'],
        $strongest,
        $weakest
    ));

    if ($result === false) {
        error_log("[LEVEL PERF] Database error: " . $wpdb->last_error);
        return false;
    }

    $insert_id = $wpdb->insert_id;
    
    if ($insert_id) {
        error_log("[LEVEL PERF] INSERTED new performance row (ID=$insert_id) for user=$participant_user_id, program=$program_id, level=$level_id");
    } elseif ($result === 2) {
        error_log("[LEVEL PERF] UPDATED existing performance row for user=$participant_user_id, program=$program_id, level=$level_id");
    } elseif ($result === 0) {
        error_log("[LEVEL PERF] No changes to existing performance row for user=$participant_user_id, program=$program_id, level=$level_id");
    }

    return true;
}

/**
 * Action hook for calculating level performance.
 * Can be triggered via do_action('rcn_calculate_level_performance', $user_id, $program_id, $level_id);
 */
add_action('rcn_calculate_level_performance', 'rcn_calculate_level_performance', 10, 3);
