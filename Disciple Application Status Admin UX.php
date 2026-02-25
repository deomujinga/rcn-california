if (!defined('ABSPATH')) exit;

if (!defined('DISC_META_STATUS'))  define('DISC_META_STATUS', 'disciple_status');
if (!defined('DISC_META_REASON'))  define('DISC_META_REASON', 'disciple_reject_reason');
if (!defined('DISC_STATUS_ACTIVE')) define('DISC_STATUS_ACTIVE', 'active');
if (!defined('DISC_STATUS_INACT'))  define('DISC_STATUS_INACT', 'pending');
if (!defined('DISC_STATUS_REJECT')) define('DISC_STATUS_REJECT', 'rejected');

/* Column: Disciple Status */
add_filter('manage_users_columns', function($cols){
  $cols['disciple_status'] = 'Disciple Status';
  return $cols;
});
add_filter('manage_users_custom_column', function($val,$col,$user_id){
  if ($col!=='disciple_status') return $val;
  if (!user_can($user_id,'access_discipleship')) return '';
  $st = get_user_meta($user_id, DISC_META_STATUS, true) ?: DISC_STATUS_INACT;
  $label = [
    DISC_STATUS_ACTIVE=>'Active',
    DISC_STATUS_INACT =>'Pending',
    DISC_STATUS_REJECT=>'Rejected',
  ][$st] ?? esc_html($st);
  return '<span style="padding:2px 8px;border-radius:999px;border:1px solid #e5e7eb;">'.$label.'</span>';
},10,3);

/* Row actions: Approve / Reject… */
add_filter('user_row_actions', function($actions,$user){
  if(!current_user_can('promote_users')) return $actions;
  if(!user_can($user,'access_discipleship')) return $actions;

  $approve = wp_nonce_url(
    add_query_arg(['disc_act'=>'approve','disc_uid'=>$user->ID], admin_url('users.php')),
    'disc_act_'.$user->ID
  );
  $reject = wp_nonce_url(
    add_query_arg(['page'=>'disciple-reject','disc_uid'=>$user->ID], admin_url('users.php')),
    'disc_rej_'.$user->ID
  );
  $actions['disc_approve'] = '<a href="'.$approve.'">Approve</a>';
  $actions['disc_reject']  = '<a href="'.$reject.'">Reject…</a>';
  return $actions;
},10,2);

/* Handle Approve */
add_action('load-users.php', function(){
  if(($_GET['disc_act'] ?? '')!=='approve') return;
  $uid = absint($_GET['disc_uid'] ?? 0);
  if(!$uid) return;
  if(!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'disc_act_'.$uid)) wp_die('Bad nonce');
  if(!current_user_can('promote_users')) wp_die('Not allowed');
  das_set_disciple_status($uid, DISC_STATUS_ACTIVE, '');
  wp_safe_redirect(remove_query_arg(['disc_act','disc_uid','_wpnonce'])); exit;
});

/* Simple Reject screen */
add_action('admin_menu', function(){
  add_users_page('Reject Disciple','Reject Disciple','promote_users','disciple-reject', function(){
    $uid = absint($_GET['disc_uid'] ?? 0);
    if(!$uid) { echo '<div class="wrap"><h1>Reject Disciple</h1><p>Missing user.</p></div>'; return; }
    if(!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'disc_rej_'.$uid)) wp_die('Bad nonce');
    if(!current_user_can('promote_users')) wp_die('Not allowed');

    if($_SERVER['REQUEST_METHOD']==='POST' && check_admin_referer('disc_rej_post_'.$uid)){
      $reason = wp_unslash($_POST['disc_reason'] ?? '');
      das_set_disciple_status($uid, DISC_STATUS_REJECT, $reason);
      echo '<div class="notice notice-success"><p>User rejected and notified.</p></div>';
    }
    $u = get_userdata($uid);
    ?>
    <div class="wrap">
      <h1>Reject <?php echo esc_html($u->display_name); ?></h1>
      <form method="post">
        <?php wp_nonce_field('disc_rej_post_'.$uid); ?>
        <p><label for="disc_reason">Reason (shown to user):</label></p>
        <p><textarea id="disc_reason" name="disc_reason" rows="6" class="large-text" placeholder="Optional but recommended"></textarea></p>
        <p><button class="button button-primary">Reject and notify</button></p>
      </form>
    </div>
    <?php
  });
});

/* OPTIONAL: Edit User profile box */
add_action('show_user_profile','das_disciple_profile_box');
add_action('edit_user_profile','das_disciple_profile_box');
function das_disciple_profile_box($user){
  if(!user_can($user,'access_discipleship')) return;
  $status = get_user_meta($user->ID, DISC_META_STATUS, true) ?: DISC_STATUS_INACT;
  $reason = get_user_meta($user->ID, DISC_META_REASON, true);
  ?>
  <h2>Discipleship Status</h2>
  <table class="form-table" role="presentation">
    <tr>
      <th><label for="disciple_status">Status</label></th>
      <td>
        <select name="disciple_status" id="disciple_status">
          <option value="<?php echo esc_attr(DISC_STATUS_INACT); ?>"  <?php selected($status, DISC_STATUS_INACT); ?>>Pending</option>
          <option value="<?php echo esc_attr(DISC_STATUS_ACTIVE); ?>" <?php selected($status, DISC_STATUS_ACTIVE); ?>>Active</option>
          <option value="<?php echo esc_attr(DISC_STATUS_REJECT); ?>" <?php selected($status, DISC_STATUS_REJECT); ?>>Rejected</option>
        </select>
        <p class="description">Changing to Approved/Rejected will email the user.</p>
      </td>
    </tr>
    <tr>
      <th><label for="disciple_reject_reason">Reject reason</label></th>
      <td><textarea name="disciple_reject_reason" id="disciple_reject_reason" rows="4" class="regular-text"><?php echo esc_textarea($reason); ?></textarea></td>
    </tr>
  </table>
  <?php
}
add_action('personal_options_update','das_disciple_profile_save');
add_action('edit_user_profile_update','das_disciple_profile_save');
function das_disciple_profile_save($user_id){
  if(!current_user_can('promote_users')) return;
  if(!user_can($user_id,'access_discipleship')) return;
  $new  = sanitize_text_field($_POST['disciple_status'] ?? '');
  $reas = wp_kses_post($_POST['disciple_reject_reason'] ?? '');
  $old  = get_user_meta($user_id, DISC_META_STATUS, true) ?: DISC_STATUS_INACT;
  $oldr = get_user_meta($user_id, DISC_META_REASON, true);
  if ($new!==$old || ($new===DISC_STATUS_REJECT && $reas!==$oldr)){
    das_set_disciple_status($user_id,$new,$reas);
  }
}
