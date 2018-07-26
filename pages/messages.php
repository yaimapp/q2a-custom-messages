<?php

  if (!defined('QA_VERSION')) { // don't allow this page to be requested directly from browser
    header('Location: ../');
    exit;
  }

  require_once QA_INCLUDE_DIR.'db/selects.php';
  require_once QA_INCLUDE_DIR.'app/users.php';
  require_once QA_INCLUDE_DIR.'app/format.php';
  require_once QA_INCLUDE_DIR.'app/limits.php';
  require_once CML_DIR.'/cml-db-client.php';
  require_once CML_DIR.'/model/msg-groups.php';
  require_once CML_DIR.'/model/msg-group-messages.php';

  $loginUserId = qa_get_logged_in_userid();
  $loginUserHandle = qa_get_logged_in_handle();


//  Check which box we're showing (inbox/sent), we're not using Q2A's single-sign on integration and that we're logged in

  $req = qa_request_part(1);
  if ($req !== null) {
    return include QA_INCLUDE_DIR.'qa-page-not-found.php';
  }
  

  if (QA_FINAL_EXTERNAL_USERS)
    qa_fatal_error('User accounts are handled by external code');

  if (!isset($loginUserId)) {
    $qa_content = qa_content_prepare();
    $qa_content['error'] = qa_insert_login_links(qa_lang_html('misc/message_must_login'), qa_request());
    return $qa_content;
  }

  if (!qa_opt('allow_private_messages') || !qa_opt('show_message_history')) {
    return include QA_INCLUDE_DIR.'qa-page-not-found.php';
  }

//  Find the messages for this user

  $start = qa_get_start();
  $pagesize = qa_opt('page_size_pms');

  $state = qa_get_state();
  if ($state === 'user') {
    return include CML_DIR . '/pages/select-user.php';
  } elseif ($state === 'group') {
    return include CML_DIR . '/pages/select-group.php';
  }

  // get number of messages then actual messages for this page
  $userMessages = cml_db_client::get_user_messages($loginUserId);
  $count = count($userMessages);
  $userMessages = array_slice($userMessages, $start, $pagesize);
  
//  Prepare content for theme

  $qa_content = qa_content_prepare();
  $qa_content['title'] = qa_lang_html( 'custom_messages/messages_page_title' );
  $qa_content['script_rel'][] = 'qa-content/qa-user.js?'.QA_VERSION;

  $qa_content['message_list'] = array(
    'tags' => 'id="privatemessages"',
    'messages' => array(),
  );

  $messages = array();
  $sort = array();
  foreach ($userMessages as $message) {
    $msgFormat = array();
    if ($loginUserId === $message['touserid']) {
      $replyHandle = $message['fromhandle'];
      $replyBlobid = $message['fromavatarblobid'];
      $replyLocation = $message['fromlocation'];
      $replyFlags = $message['fromflags'];
      $replyUserid = $message['fromuserid'];
      $replyUserLevel = $message['fromlevel'];
      $loginFlags = $message['toflags'];
    } else {
      $replyHandle = $message['tohandle'];
      $replyBlobid = $message['toavatarblobid'];
      $replyLocation = $message['tolocation'];
      $replyFlags = $message['toflags'];
      $replyUserid = $message['touserid'];
      $replyUserLevel = $message['tolevel'];
      $loginFlags = $message['fromflags'];
    }

    // メッセージ利用できない場合飛ばす
    if (!allow_message($loginFlags, $loginUserId, $replyFlags, $replyUserid, $replyUserLevel)) {
          continue;
    }
    
    $msgFormat['avatarblobid'] = $replyBlobid;
    $msgFormat['handle'] = $replyHandle;
    $msgFormat['location'] = $replyLocation;
    $tmp_date = new DateTime($message['created']);
    $create_a = qa_when_to_html($tmp_date->getTimestamp(), 30);
    if(isset($create_a['suffix']) && !empty($create_a['suffix'])) {
      $msgFormat['create_date'] = $create_a['data'] . $create_a['suffix'];
    } else {
      $msgFormat['create_date'] = $tmp_date->format(qa_lang_html('custom_messages/date_format'));
    }
    $content = strip_tags($message['content']);
    $content = mb_strimwidth($content, 0, 100, "...", "utf-8");
    $msgFormat['content'] = $content;
    $msgFormat['messageurl'] = qa_path_html('message/'.$replyHandle);
    $msgFormat['type'] = 'user';
    // ソートのための値
    $sort[] = $message['created'];

    $messages[] = $msgFormat;
  }
  
  $gmessages = msg_group_messages::get_recent_groups_by_user($loginUserId);
  
  foreach ($gmessages as $message) {
    $msgFormat = array();
    $groupid = $message['groupid'];
    $cur_group = new msg_groups($groupid);

    $msgFormat['avatarblobid'] = CML_RELATIVE_PATH.'images/group_icon.png';
    $usercount = '('.count($cur_group->join_users).')';
    if (empty($cur_group->title)) {
      $title = qa_lang_sub('custom_messages/group_users', $cur_group->get_group_handles());
      $title.= $usercount;
    } else {
      $title = $cur_group->title . $usercount;
    }
    $msgFormat['handle'] = $title;
    $msgFormat['location'] = '';
    $tmp_date = new DateTime($message['created']);
    $create_a = qa_when_to_html($tmp_date->getTimestamp(), 30);
    if(isset($create_a['suffix']) && !empty($create_a['suffix'])) {
      $msgFormat['create_date'] = $create_a['data'] . $create_a['suffix'];
    } else {
      $msgFormat['create_date'] = $tmp_date->format(qa_lang_html('custom_messages/date_format'));
    }
    $content = strip_tags($message['content']);
    $content = mb_strimwidth($content, 0, 100, "...", "utf-8");
    $msgFormat['content'] = $content;
    $msgFormat['messageurl'] = qa_path_html('groupmsg/'.$groupid, null, qa_opt('site_url'));
    $msgFormat['type'] = 'group';
    // ソートのための値
    $sort[] = $message['created'];
    $messages[] = $msgFormat;
  }
  
  // created で降順にソート
  array_multisort($sort, SORT_DESC, $messages);

  $qa_content['message_list']['messages'] = $messages;

  $qa_content['page_links'] = qa_html_page_links(qa_request(), $start, $pagesize, $count, qa_opt('pages_prev_next'));

  return $qa_content;

  function follow_each_other($loginuserid, $touserid)
  {
    $sql = "SELECT COUNT(*)";
    $sql.= " FROM ^userfavorites";
    $sql.= " WHERE entitytype = 'U'";
    $sql.= " AND userid = $";
    $sql.= " AND entityid = $";

    $following = qa_db_read_one_value(qa_db_query_sub($sql, $loginuserid, $touserid));

    $followed = qa_db_read_one_value(qa_db_query_sub($sql, $touserid, $loginuserid));

    return $following && $followed;
  }

  /*  
   * 管理人とはやりとりできる
   * 自分または相手の「すべてのユーザーとメッセージをやりとり」オプションがオフで
   * 相手と相互フォローでない場合は、メッセージリストに表示しない
   */
  function allow_message($loginFlags, $loginUserId, $replyFlags, $replyUserId, $replyUserLevel)
  {
    // 自分、または相手が管理権限であればOK
    if (qa_get_logged_in_level() >= QA_USER_LEVEL_ADMIN
        || $replyUserLevel >= QA_USER_LEVEL_ADMIN) {
      return true;
    }
    // フォローしていれば無条件でOK
    if (follow_each_other($loginUserId, $replyUserId)) {
      return true;
    }

    $me_ok = !($loginFlags & QA_USER_FLAGS_NO_MESSAGES);
    $from_ok = !($replyFlags & QA_USER_FLAGS_NO_MESSAGES);
 
    if($me_ok && $from_ok) {
      return true;
    }
    return false;
  }
