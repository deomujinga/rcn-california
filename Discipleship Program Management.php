/**
 * Shortcode: [program_management]
 * Leader interface for managing discipleship programs and levels.
 */
add_shortcode('program_management', function() {

    if (!current_user_can('access_leadership')) {
        return '<div class="pm-alert pm-warning">
                    <strong>Access Restricted:</strong> Only leaders can manage this section.
                </div>';
    }

    global $wpdb;

    $programs_table = "{$wpdb->prefix}discipleship_programs";
    $levels_table   = "{$wpdb->prefix}discipleship_levels";

    // Current active program
    $program = $wpdb->get_row("SELECT * FROM $programs_table WHERE is_active = 1 LIMIT 1");

    // Derive safe defaults if table is empty or no active program yet
    $program_id         = $program ? (int) $program->id : 0;
    $program_name       = $program ? $program->name : 'RCNCA Discipleship Program';
    $program_duration   = $program ? (int) $program->default_duration : 90;
    $program_threshold  = $program ? (float) $program->promotion_threshold : 90;
    $program_total_lvl  = $program ? (int) $program->total_levels : 1;
    $program_is_active  = $program ? (int) $program->is_active : 0;

    // Load all levels for this (active) program
    $levels = [];
    $level_counts = [];

    if ($program_id > 0) {
        $levels = $wpdb->get_results($wpdb->prepare("
            SELECT *
            FROM $levels_table
            WHERE program_id = %d
            ORDER BY level_number ASC
        ", $program_id));

        foreach ($levels as $lvl) {
            $level_counts[$lvl->id] = (int)$wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->prefix}discipleship_participants
                WHERE current_level_id = %d
            ", $lvl->id));
        }
    }

    ob_start();
?>
<style>
    .pm-wrap {
        font-family: system-ui, Segoe UI, Roboto, Arial;
        max-width: 1000px;
        margin: 0 auto;
        padding: 32px;
        color: #111;
    }
    .pm-title {
        font-size: 28px;
        font-weight: 800;
        margin-bottom: 20px;
    }
    .pm-card {
        background: #fff;
        padding: 24px;
        border-radius: 16px;
        box-shadow: 0 4px 20px rgba(0,0,0,.08);
        margin-bottom: 28px;
    }
    .pm-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 16px;
    }
    .pm-grid label {
        font-size: 13px;
        font-weight: 700;
        color:#374151;
        display:block;
    }
    .pm-grid input,
    .pm-grid textarea {
        padding: 8px;
        border:1px solid #d1d5db;
        border-radius: 8px;
        width:100%;
        font-size:14px;
    }
    .pm-btn {
        background:#111827;
        color:#fff;
        border:none;
        padding:10px 16px;
        border-radius:8px;
        cursor:pointer;
        font-weight:700;
        margin-right:8px;
    }
    .pm-btn:hover {
        background:#374151;
    }
    .pm-level {
        background:#f9fafb;
        border:1px solid #e5e7eb;
        padding:20px;
        border-radius:12px;
        margin-bottom:16px;
    }
    .pm-level-title {
        font-size:18px;
        font-weight:800;
        margin-bottom:10px;
    }
    .pm-stats {
        font-size:14px;
        color:#374151;
        margin-bottom:8px;
    }
    .pm-danger {
        background:#fef2f2;
        border:1px solid #fecaca;
        color:#991b1b;
        padding:8px 12px;
        border-radius:8px;
        margin-bottom:12px;
    }
    .pm-muted {
        font-size: 13px;
        color: #6b7280;
        margin-top: 8px;
    }
	
	/* Notification panel / shadow box */
.rcn-send-notifications {
    background: #ffffff;
    border-radius: 14px;
    padding: 28px;
    box-shadow:
        0 10px 30px rgba(0, 0, 0, 0.08),
        0 2px 6px rgba(0, 0, 0, 0.05);
    border: 1px solid rgba(0,0,0,0.05);
}

</style>

<div class="pm-wrap">
    <div class="pm-title">Discipleship Program Management</div>

    <!-- Program Settings Card -->
    <div class="pm-card">
        <h3>Program Settings</h3>

        <?php if (!$program_id): ?>
            <div class="pm-danger">
                No active program found. Fill the fields below and click <strong>"Create Program"</strong> to seed the first program.
            </div>
        <?php endif; ?>

        <div class="pm-grid">
            <label>Program Name
                <input id="pm_name" value="<?= esc_attr($program_name) ?>">
            </label>

            <label>Default Duration (days)
                <input type="number" id="pm_duration" value="<?= esc_attr($program_duration) ?>">
            </label>

            <label>Promotion Threshold (%)
                <input type="number" id="pm_threshold" value="<?= esc_attr($program_threshold) ?>">
            </label>

            <label>Total Levels
                <input type="number" id="pm_levels" value="<?= esc_attr($program_total_lvl) ?>">
            </label>
        </div>

        <div style="margin-top:16px;">
            <!-- Create program always available -->
            <button class="pm-btn" id="pmCreateProgramBtn">➕ Create Program</button>

            <!-- Save only meaningful if we have an active program -->
            <button class="pm-btn" id="pmSaveBtn" <?= $program_id ? '' : 'disabled style="opacity:0.5;cursor:not-allowed;"' ?>>
                💾 Save Changes
            </button>

            <?php if ($program_id): ?>
                <?php if ($program_is_active): ?>
                    <button class="pm-btn" style="background:#92400e" id="pmPauseBtn">⏸ Pause Program</button>
                <?php else: ?>
                    <button class="pm-btn" style="background:#047857" id="pmResumeBtn">▶ Resume Program</button>
                <?php endif; ?>
            <?php endif; ?>

            <div class="pm-muted">
                "Create Program" will insert a <strong>new program record</strong> based on the fields above
                and mark it as the active program. Existing programs are kept but only one is active at a time.
            </div>
        </div>
    </div>

    <!-- Levels Card -->
    <div class="pm-card">
        <h3>Levels</h3>

        <?php if (!$program_id): ?>
            <p class="pm-muted">You need an active program before adding levels.</p>
        <?php endif; ?>

        <?php if ($program_id): ?>
			
		<div class="pm-card" style="margin-bottom: 24px; padding: 20px; background:#f3f4f6;">
			<h4 style="font-weight:700;margin-bottom:12px;">Create New Level</h4>

			<div class="pm-grid">
				<label>Level Name
					<input type="text" id="new_lvl_name" placeholder="e.g. Foundation Level">
				</label>

				<label>Duration (days)
					<input type="number" id="new_lvl_duration" value="90">
				</label>

				<label>Promotion Threshold (%)
					<input type="number" id="new_lvl_threshold" value="95">
				</label>

				<label>Grace Period (days)
					<input type="number" id="new_lvl_grace" value="0">
				</label>

				<label>Max Grace Cycles
					<input type="number" id="new_lvl_max_grace" placeholder="Leave blank = unlimited">
				</label>
			</div>

			<label style="display:block;margin-top:12px;font-weight:700;font-size:13px;">
				Description
				<textarea id="new_lvl_description" rows="2" placeholder="Level overview..."></textarea>
			</label>

			<button class="pm-btn" id="pmCreateLevelBtn" style="margin-top:12px;">
				➕ Create Level
			</button>
		</div>

		<?php foreach ($levels as $lvl): ?>
			<div class="pm-level" data-id="<?= $lvl->id ?>">

				<div class="pm-level-title">
					Level <?= $lvl->level_number ?> — <?= esc_html($lvl->name) ?>
				</div>

				<div class="pm-grid">
					<label>Level Name
						<input type="text" class="lvl-name" value="<?= esc_attr($lvl->name) ?>">
					</label>

					<label>Duration (days)
						<input type="number" class="lvl-duration" value="<?= esc_attr($lvl->duration_days) ?>">
					</label>

					<label>Promotion Threshold (%)
						<input type="number" class="lvl-threshold" value="<?= esc_attr($lvl->promotion_threshold) ?>">
					</label>

					<label>Grace Period (days)
						<input type="number" class="lvl-grace" value="<?= esc_attr($lvl->grace_period_days) ?>">
					</label>

					<label>Max Grace Cycles
						<input type="number" class="lvl-max-grace"
							   value="<?= esc_attr($lvl->max_grace_cycles) ?>"
							   placeholder="Leave blank = unlimited">
					</label>
				</div>

				<label style="display:block;margin-top:12px;font-weight:700;font-size:13px;">
					Description
					<textarea class="lvl-description" rows="2"><?= esc_textarea($lvl->description) ?></textarea>
				</label>

				<div class="pm-stats">
					Participants in this level: <?= isset($level_counts[$lvl->id]) ? $level_counts[$lvl->id] : 0 ?>
				</div>

				<button class="pm-btn pm-save-level" data-id="<?= $lvl->id ?>">💾 Save Level</button>

				<?php if ($lvl->level_number != 1): ?>
					<button class="pm-btn pm-delete-level" data-id="<?= $lvl->id ?>" style="background:#b91c1c">
						🗑 Delete Level
					</button>
				<?php endif; ?>

			</div>
		<?php endforeach; ?>

            <!-- <button class="pm-btn" id="pmAddLevelBtn" style="margin-top:12px;">➕ Add Level</button> -->
        <?php endif; ?>

    </div>
</div>

<script>
const ajaxurl = "<?= esc_url( admin_url('admin-ajax.php') ); ?>";

document.addEventListener("DOMContentLoaded", () => {

    function post(data){
        return fetch(ajaxurl,{
            method:"POST",
            headers:{ "Content-Type":"application/x-www-form-urlencoded" },
            body:new URLSearchParams(data)
        }).then(r=>r.json());
    }

    // Create Program (always available)
    document.getElementById("pmCreateProgramBtn")?.addEventListener("click", async ()=>{
        const name = document.getElementById("pm_name").value || "RCNCA Discipleship Program";
        const duration = document.getElementById("pm_duration").value || 90;
        const threshold = document.getElementById("pm_threshold").value || 90;
        const levels = document.getElementById("pm_levels").value || 1;

        const res = await post({
            action: "pm_create_program",
            name,
            duration,
            threshold,
            levels
        });

        alert(res.message);
        if (res.success) {
            location.reload();
        }
    });

    // Save Program (update current active program)
    document.getElementById("pmSaveBtn")?.addEventListener("click", async ()=>{
        const res = await post({
            action:"pm_save_program",
            name: pm_name.value,
            duration: pm_duration.value,
            threshold: pm_threshold.value,
            levels: pm_levels.value
        });
        alert(res.message);
        if (res.success) {
            location.reload();
        }
    });

    // Pause / Resume
    document.getElementById("pmPauseBtn")?.addEventListener("click", async ()=>{
        const res = await post({ action:"pm_toggle_program", mode:"pause" });
        alert(res.message);
        if (res.success) location.reload();
    });

    document.getElementById("pmResumeBtn")?.addEventListener("click", async ()=>{
        const res = await post({ action:"pm_toggle_program", mode:"resume" });
        alert(res.message);
        if (res.success) location.reload();
    });
	
	// Create Level
	document.getElementById("pmCreateLevelBtn")?.addEventListener("click", async ()=>{
		const payload = {
			action: "pm_create_level",
			name: document.getElementById("new_lvl_name").value,
			duration: document.getElementById("new_lvl_duration").value,
			threshold: document.getElementById("new_lvl_threshold").value,
			grace: document.getElementById("new_lvl_grace").value,
			max_grace: document.getElementById("new_lvl_max_grace").value,
			description: document.getElementById("new_lvl_description").value
		};

		const res = await fetch(ajaxurl, {
			method: "POST",
			headers: { "Content-Type":"application/x-www-form-urlencoded" },
			body: new URLSearchParams(payload)
		}).then(r => r.json());

		alert(res.message);
		if (res.success) location.reload();
	});


    // Save Level
    document.querySelectorAll(".pm-save-level").forEach(btn=>{
        btn.addEventListener("click", async ()=>{
            const id = btn.dataset.id;
            const levelEl = btn.closest(".pm-level");

            const payload = {
                action:"pm_save_level",
                id,
                name: levelEl.querySelector(".lvl-name").value,
                description: levelEl.querySelector(".lvl-description").value,
                duration: levelEl.querySelector(".lvl-duration").value,
                threshold: levelEl.querySelector(".lvl-threshold").value,
                grace: levelEl.querySelector(".lvl-grace").value,
                max_grace: levelEl.querySelector(".lvl-max-grace").value
            };

            const res = await post(payload);
            alert(res.message);
        });
    });

    // Delete Level
    document.querySelectorAll(".pm-delete-level").forEach(btn=>{
        btn.addEventListener("click", async ()=>{
            if (!confirm("Are you sure? This cannot be undone.")) return;

            const res = await post({
                action:"pm_delete_level",
                id:btn.dataset.id
            });

            alert(res.message);
            if (res.success) location.reload();
        });
    });

    // Add Level
    document.getElementById("pmAddLevelBtn")?.addEventListener("click", async ()=>{
        const res = await post({ action:"pm_add_level" });
        alert(res.message);
        if (res.success) location.reload();
    });
});
</script>

<?php
    return ob_get_clean();
});

/* ================================================================
 *  Utility: Get active program ID (for runtime use)
 * ================================================================ */
function rcn_get_program_id() {
    global $wpdb;
    return (int) $wpdb->get_var("
        SELECT id FROM {$wpdb->prefix}discipleship_programs
        WHERE is_active = 1
        LIMIT 1
    ");
}

/* ======================================================================
 * Utility: Resequence all levels for a program
 * Ensures level_number is continuous (1..n) and sets next_level_id properly
 * ====================================================================== */
function rcn_resequence_levels($program_id) {
    global $wpdb;
    $levels_table = "{$wpdb->prefix}discipleship_levels";

    $levels = $wpdb->get_results($wpdb->prepare("
        SELECT id
        FROM $levels_table
        WHERE program_id = %d
        ORDER BY level_number ASC
    ", $program_id));

    $count = 1;
    $prev_id = null;

    foreach ($levels as $lvl) {
        // Update level number
        $wpdb->update(
            $levels_table,
            ['level_number' => $count],
            ['id' => $lvl->id],
            ['%d'],
            ['%d']
        );

        // Set next_level_id for previous level
        if ($prev_id !== null) {
            $wpdb->update(
                $levels_table,
                ['next_level_id' => $lvl->id],
                ['id' => $prev_id],
                ['%d'],
                ['%d']
            );
        }

        $prev_id = $lvl->id;
        $count++;
    }

    // Last level has no next level
    if ($prev_id !== null) {
        $wpdb->update(
            $levels_table,
            ['next_level_id' => null],
            ['id' => $prev_id],
            ['%d'],
            ['%d']
        );
    }

    return true;
}

/* ================================================================
 * AJAX: Create New Program (can be used anytime)
 * ================================================================ */
add_action('wp_ajax_pm_create_program', function() {
    global $wpdb;

    if (!current_user_can('access_leadership')) {
        wp_send_json(['success'=>false, 'message'=>'Unauthorized.']);
    }

    $name       = sanitize_text_field($_POST['name'] ?? 'RCNCA Discipleship Program');
    $duration   = intval($_POST['duration'] ?? 90);
    $threshold  = floatval($_POST['threshold'] ?? 90);
    $levels_cnt = intval($_POST['levels'] ?? 1);

    // Only one active program at a time: deactivate all
    $wpdb->query("UPDATE {$wpdb->prefix}discipleship_programs SET is_active = 0");

    // Insert new active program
    $wpdb->insert(
        "{$wpdb->prefix}discipleship_programs",
        [
            'name'                 => $name,
            'default_duration'     => $duration,
            'promotion_threshold'  => $threshold,
            'total_levels'         => $levels_cnt,
            'is_active'            => 1
        ],
        ['%s','%d','%f','%d','%d']
    );

    $program_id = (int) $wpdb->insert_id;

    // Seed Level 1 if none exists yet for this program
    if ($program_id) {
        $wpdb->insert(
            "{$wpdb->prefix}discipleship_levels",
            [
                'program_id'          => $program_id,
                'level_number'        => 1,
                'name'                => 'Level 1',
                'duration_days'       => $duration,
                'promotion_threshold' => 95.00,
                'description'         => 'Initial level of the program.',
                'grace_period_days'   => 0,
                'max_grace_cycles'    => null,
                'next_level_id'       => null
            ],
            ['%d','%d','%s','%d','%f','%s','%d','%d','%d']
        );
    }

    wp_send_json([
        'success'=>true,
        'message'=>'New program created and set as active.'
    ]);
});

/* ================================================================
 * AJAX: Save Program Settings (update active program)
 * ================================================================ */
add_action('wp_ajax_pm_save_program', function() {
    global $wpdb;

    if (!current_user_can('access_leadership')) {
        wp_send_json(['success'=>false, 'message'=>'Unauthorized.']);
    }

    $program_id = rcn_get_program_id();

    if (!$program_id) {
        wp_send_json(['success'=>false, 'message'=>'No active program found. Create one first.']);
    }

    $data = [
        'name'                 => sanitize_text_field($_POST['name']),
        'default_duration'     => intval($_POST['duration']),
        'promotion_threshold'  => floatval($_POST['threshold']),
        'total_levels'         => intval($_POST['levels']),
    ];

    $wpdb->update(
        "{$wpdb->prefix}discipleship_programs",
        $data,
        ['id' => $program_id]
    );

    wp_send_json(['success'=>true, 'message'=>'Program updated successfully.']);
});

/* ================================================================
 * AJAX: Pause or Resume Program (active one)
 * ================================================================ */
add_action('wp_ajax_pm_toggle_program', function() {
    global $wpdb;

    if (!current_user_can('access_leadership')) {
        wp_send_json(['success'=>false, 'message'=>'Unauthorized.']);
    }

    $mode = sanitize_text_field($_POST['mode']);
    $status = ($mode === 'pause') ? 0 : 1;

    $program_id = rcn_get_program_id();

    if (!$program_id) {
        wp_send_json(['success'=>false, 'message'=>'No active program found.']);
    }

    $wpdb->update(
        "{$wpdb->prefix}discipleship_programs",
        ['is_active' => $status],
        ['id' => $program_id]
    );

    $msg = ($mode === 'pause') ? 'Program paused.' : 'Program resumed.';
    wp_send_json(['success'=>true, 'message'=>$msg]);
});

/* ================================================================
 * AJAX: Save Individual Level (name, desc, duration, threshold)
 * ================================================================ */
add_action('wp_ajax_pm_save_level', function() {
    global $wpdb;

    if (!current_user_can('access_leadership')) {
        wp_send_json(['success'=>false, 'message'=>'Unauthorized.']);
    }

    $id          = intval($_POST['id']);
    $name        = sanitize_text_field($_POST['name']);
    $description = sanitize_textarea_field($_POST['description']);
    $duration    = intval($_POST['duration']);
    $threshold   = floatval($_POST['threshold']);
    $grace       = intval($_POST['grace']);
    $max_cycles  = ($_POST['max_grace'] === '' ? null : intval($_POST['max_grace']));

    $wpdb->update(
        "{$wpdb->prefix}discipleship_levels",
        [
            'name'                => $name,
            'description'         => $description,
            'duration_days'       => $duration,
            'promotion_threshold' => $threshold,
            'grace_period_days'   => $grace,
            'max_grace_cycles'    => $max_cycles
        ],
        ['id' => $id],
        ['%s','%s','%d','%f','%d','%d'],
        ['%d']
    );

    wp_send_json(['success'=>true, 'message'=>'Level updated successfully.']);
});

/* ================================================================
 * AJAX: Delete a Level (safe)
 * ================================================================ */
add_action('wp_ajax_pm_delete_level', function() {
    global $wpdb;

    if (!current_user_can('access_leadership')) {
        wp_send_json(['success'=>false, 'message'=>'Unauthorized.']);
    }

    $level_id   = intval($_POST['id']);
    $program_id = rcn_get_program_id();

    // Get level_number
    $level = $wpdb->get_row($wpdb->prepare("
        SELECT level_number
        FROM {$wpdb->prefix}discipleship_levels
        WHERE id=%d
    ", $level_id));

    if (!$level) {
        wp_send_json(['success'=>false, 'message'=>'Level not found.']);
    }

    if ($level->level_number == 1) {
        wp_send_json(['success'=>false, 'message'=>'Cannot delete Level 1.']);
    }

    // Check participants in the level
    $count = (int)$wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->prefix}discipleship_participants
        WHERE current_level_id=%d
    ", $level_id));

    if ($count > 0) {
        wp_send_json(['success'=>false, 'message'=>'Cannot delete level with active participants.']);
    }

    // Delete level
    $wpdb->delete(
        "{$wpdb->prefix}discipleship_levels",
        ['id' => $level_id],
        ['%d']
    );

    // Resequence levels
    if ($program_id) {
        rcn_resequence_levels($program_id);
    }

    wp_send_json(['success'=>true, 'message'=>'Level deleted and sequence updated.']);
});

/* ================================================================
 * AJAX: Add New Level (for active program)
 * ================================================================ */
add_action('wp_ajax_pm_add_level', function() {
    global $wpdb;

    if (!current_user_can('access_leadership')) {
        wp_send_json(['success'=>false, 'message'=>'Unauthorized.']);
    }

    $program_id = rcn_get_program_id();

    if (!$program_id) {
        wp_send_json(['success'=>false, 'message'=>'No active program found.']);
    }

    // Count existing levels
    $count = (int)$wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$wpdb->prefix}discipleship_levels
        WHERE program_id=%d
    ", $program_id));

    // Insert new level
    $wpdb->insert("{$wpdb->prefix}discipleship_levels", [
        'program_id'          => $program_id,
        'level_number'        => $count + 1,
        'name'                => "New Level ".($count + 1),
        'duration_days'       => 90,
        'promotion_threshold' => 95.00,
        'description'         => 'New level created by leader.',
        'grace_period_days'   => 0,
        'max_grace_cycles'    => null,
        'next_level_id'       => null,
    ], [
        '%d','%d','%s','%d','%f','%s','%d','%d','%d'
    ]);

    // Resequence
    rcn_resequence_levels($program_id);

    wp_send_json(['success'=>true, 'message'=>'New level added.']);
});

/* ================================================================
 * AJAX: Create New Level (with full UI fields)
 * ================================================================ */
add_action('wp_ajax_pm_create_level', function() {
    global $wpdb;

    if (!current_user_can('access_leadership')) {
        wp_send_json(['success'=>false, 'message'=>'Unauthorized.']);
    }

    $program_id = rcn_get_program_id();
    if (!$program_id) {
        wp_send_json(['success'=>false, 'message'=>'No active program found.']);
    }

    // Count existing levels
    $count = (int)$wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->prefix}discipleship_levels
        WHERE program_id=%d
    ", $program_id));

    $name        = sanitize_text_field($_POST['name']);
    $duration    = intval($_POST['duration']);
    $threshold   = floatval($_POST['threshold']);
    $grace       = intval($_POST['grace']);
    $max_grace   = ($_POST['max_grace'] === '' ? null : intval($_POST['max_grace']));
    $description = sanitize_textarea_field($_POST['description']);

    if (!$name) {
        wp_send_json(['success'=>false, 'message'=>'Level name is required.']);
    }

    $wpdb->insert(
        "{$wpdb->prefix}discipleship_levels",
        [
            'program_id'          => $program_id,
            'level_number'        => $count + 1,
            'name'                => $name,
            'duration_days'       => $duration,
            'promotion_threshold' => $threshold,
            'grace_period_days'   => $grace,
            'max_grace_cycles'    => $max_grace,
            'description'         => $description
        ],
        ['%d','%d','%s','%d','%f','%d','%d','%s']
    );

    // Resequence and clean
    rcn_resequence_levels($program_id);

    wp_send_json(['success'=>true, 'message'=>'New level created successfully.']);
});
