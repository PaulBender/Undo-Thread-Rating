<?php
/**
 * Undo Thread Rating
 * Copyright 2011 Starpaul20
 */

// Disallow direct access to this file for security reasons
if(!defined("IN_MYBB"))
{
	die("Direct initialization of this file is not allowed.<br /><br />Please make sure IN_MYBB is defined.");
}

// Neat trick for caching our custom template(s)
if(my_strpos($_SERVER['PHP_SELF'], 'showthread.php'))
{
	global $templatelist;
	if(isset($templatelist))
	{
		$templatelist .= ',';
	}
	$templatelist .= 'showthread_ratethread_undo';
}

// Tell MyBB when to run the hooks
$plugins->add_hook("misc_start", "undorating_run");
$plugins->add_hook("showthread_end", "undorating_link");

$plugins->add_hook("admin_formcontainer_output_row", "undorating_usergroup_permission");
$plugins->add_hook("admin_user_groups_edit_commit", "undorating_usergroup_permission_commit");

// The information that shows up on the plugin manager
function undorating_info()
{
	return array(
		"name"				=> "Undo Thread Rating",
		"description"		=> "Allows users to delete their rating on a thread.",
		"website"			=> "http://galaxiesrealm.com/index.php",
		"author"			=> "Starpaul20",
		"authorsite"		=> "http://galaxiesrealm.com/index.php",
		"version"			=> "1.1.2",
		"guid"				=> "46dcbb808564708d2577711ae0443b7a",
		"compatibility"		=> "16*"
	);
}

// This function runs when the plugin is activated.
function undorating_activate()
{
	global $db, $cache;
	$db->add_column("usergroups", "canundorating", "int(1) NOT NULL default '1'");
	$cache->update_usergroups();

	$insert_array = array(
		'title'		=> 'showthread_ratethread_undo',
		'template'	=> $db->escape_string('<span class="smalltext">[<a href="misc.php?action=do_undorating&amp;tid={$thread[\'tid\']}&amp;my_post_key={$mybb->post_code}">{$lang->undo_rating}</a>]</span>'),
		'sid'		=> '-1',
		'version'	=> '',
		'dateline'	=> TIME_NOW
	);
	$db->insert_query("templates", $insert_array);

	include MYBB_ROOT."/inc/adminfunctions_templates.php";
	find_replace_templatesets("showthread_ratethread", "#".preg_quote('</ul>
		</div>')."#i", '</ul>
		</div><!-- undorating -->');
}

// This function runs when the plugin is deactivated.
function undorating_deactivate()
{
	global $db, $cache;
	if($db->field_exists('canundorating', 'usergroups'))
	{
		$db->drop_column("usergroups", "canundorating");
	}
	$cache->update_usergroups();

	$db->delete_query("templates", "title IN('showthread_ratethread_undo')");

	include MYBB_ROOT."/inc/adminfunctions_templates.php";
	find_replace_templatesets("showthread_ratethread", "#".preg_quote('<!-- undorating -->')."#i", '', 0);
}

// Delete the rating
function undorating_run()
{
	global $db, $mybb, $lang;
	$lang->load("undorating");

	if($mybb->input['action'] == "do_undorating")
	{
		verify_post_check($mybb->input['my_post_key']);
	
		if($mybb->usergroup['canundorating'] != 1)
		{
			error_no_permission();
		}

		$tid = intval($mybb->input['tid']);
		$thread = get_thread($tid);

		if(!$thread['tid'])
		{
			error($lang->error_invalidthread);
		}

		if($thread['closed'] == 1)
		{
			error($lang->error_threadclosed);
		}

		// Check if the user has rated before...
		if($mybb->user['uid'])
		{
			$query = $db->simple_select("threadratings", "rating", "uid='".$mybb->user['uid']."' AND tid='".$thread['tid']."'");
			$rating = $db->fetch_array($query);
		}
		else
		{
			// for Guests, we simply see if they've got the cookie
			$rating = explode(',', $mybb->cookies['mybbratethread'][$thread['tid']]);
		}

		if(!$rating)
		{
			error($lang->error_notrated);
		}

		$updatedrating = array(
			"numratings" => intval($thread['numratings']) - 1,
			"totalratings" => intval($thread['totalratings']) - $rating['rating'],
		);

		if($mybb->user['uid'])
		{
			$db->delete_query("threadratings", "uid='".$mybb->user['uid']."' AND tid='".$thread['tid']."'");
			$db->update_query("threads", $updatedrating, "tid='".$thread['tid']."'");
		}
		else
		{
			// clear cookie for Guests
			my_setcookie("mybbratethread[{$thread['tid']}]", "");
		}

		redirect(get_thread_link($thread['tid']), $lang->redirect_unrated);
	}
}

// Undo Rating link on showthread
function undorating_link()
{
	global $db, $mybb, $lang, $thread, $templates, $tid, $ratethread;
	$lang->load("undorating");
	
	$query = $db->simple_select("threadratings", "uid", "tid='{$tid}' AND uid='{$mybb->user['uid']}'");
	$rated = $db->fetch_field($query, 'uid');
	
	if($mybb->usergroup['canundorating'] == 1 && $rated)
	{
		eval("\$undorating = \"".$templates->get("showthread_ratethread_undo")."\";");
		$ratethread = str_replace("<!-- undorating -->", $undorating, $ratethread);
	}
}

// Admin CP permission control
function undorating_usergroup_permission($above)
{
	global $mybb, $lang, $form;
	$lang->load("undorating", true);

	if($above['title'] == $lang->posting_rating_options && $lang->posting_rating_options)
	{
		$above['content'] .= "<div class=\"group_settings_bit\">".$form->generate_check_box("canundorating", 1, $lang->can_undo_ratings, array("checked" => $mybb->input['canundorating']))."</div>";
	}
}

function undorating_usergroup_permission_commit()
{
	global $mybb, $updated_group;
	$updated_group['canundorating'] = intval($mybb->input['canundorating']);
}

?>