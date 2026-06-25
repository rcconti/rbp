<?php

require('config.inc.php');

header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Pragma: no-cache');

if ($config['auth_required'] == true || isset($_REQUEST['needauth'])) {
  if (!isset($mysqli_link)) {
    $mysqli_link = mysqli_connect($config['db_host'],$config['db_user'],$config['db_pass'],$config['db_name']) or error($config['db_errstr'],$config['admin_email'],"mysqli_connect(" . $config['db_host'] . "," . $config['db_user'] . "," . $config['db_pass'] . ")\n".mysqli_error());
  }
  session_start();
  if (isset($_SESSION['username']) && isset($_SESSION['password'])) {
    $query = "select username from " . $locations['auth_table'] . " where username = '" . $_SESSION['username'] . "' and password = '" . $_SESSION['password'] . "'";
    $result = mysqli_query($mysqli_link, $query) or error($config['db_errstr'],$config['admin_email'],$query."\n".mysqli_error());
    if (mysqli_num_rows($result) != 1) {
      session_destroy();
      header("Location: " . $locations['login']);
      exit();
    }
  } else {
    header("Location: " . $locations['login']);
    exit();
  }
} elseif ($config['auth_post_required'] == true && !isset($_REQUEST['nocache'])) {
  if (!ini_get('session.auto_start')) {
    session_cache_limiter('private_no_cache');
    session_name($config['session_name']);
    session_save_path($locations['session_path']);
    ini_set('session.gc_maxlifetime','604800');
    session_start();
  }
  $err_array = array();
  $val_array = array();
  if (isset($_SESSION['errors'])) {
    $err_array = unserialize($_SESSION['errors']);
    $val_array = unserialize($_SESSION['values']);
    unset($_SESSION['errors']);
    unset($_SESSION['values']);
  }
  if (isset($_REQUEST['logout'])) {
    session_destroy();
    header("Location: wheel_of_images.php");
    exit();
  }
}

// copy_mode: 'random' (default) or 'all'
if (isset($_GET['copy_mode'])) {
  if ($_GET['copy_mode'] === 'all') {
    setcookie('copy_mode', 'all', 0, '/');
    $_COOKIE['copy_mode'] = 'all';
  } else {
    setcookie('copy_mode', '', 0, '/');
    $_COOKIE['copy_mode'] = '';
  }
}
$copy_mode = (isset($_COOKIE['copy_mode']) && $_COOKIE['copy_mode'] === 'all') ? 'all' : 'random';

// Returns null if $image_url is not a local _copy image.
// Returns array of variant URLs (original + lower-numbered copies that exist on disk).
function get_copy_variants($image_url) {
  global $config;
  if (!preg_match('/^(?:https?:)?\/\/rbp\.f0e\.net\/.+\/([^\/]+)$/i', $image_url, $m)) {
    return null;
  }
  $filename = $m[1];
  if (!preg_match('/^(.+)_copy(\d+)(\.[^.]+)$/i', $filename, $parts)) {
    return null;
  }
  $base = $parts[1];
  $n    = (int)$parts[2];
  $ext  = $parts[3];
  $url_prefix = preg_replace('/[^\/]+$/', '', $image_url);
  $variants = array();
  if (file_exists($config['image_path'] . $base . $ext)) {
    $variants[] = $url_prefix . $base . $ext;
  }
  for ($i = 1; $i < $n; $i++) {
    $copy_name = $base . '_copy' . $i . $ext;
    if (file_exists($config['image_path'] . $copy_name)) {
      $variants[] = $url_prefix . $copy_name;
    }
  }
  return $variants;
}

function woi_display_image($url) {
  if (preg_match('/\.mp4$/i', $url)) {
    echo "<video autoplay='' loop='' muted=''><source src='" . $url . "' type='video/mp4'></video><br /><a href='" . $url . "'>source</a><br /><br />\n";
  } elseif (preg_match('/\.webm$/i', $url)) {
    echo "<video autoplay='' loop='' muted=''><source src='" . $url . "' type='video/webm'></video><br /><a href='" . $url . "'>source</a><br /><br />\n";
  } else {
    echo "<img src='" . $url . "' alt='' /><br /><br />\n";
  }
}

function woi_toggle($t_param = null, $d_param = null) {
  global $copy_mode;
  $toggle_mode  = ($copy_mode === 'all') ? 'random' : 'all';
  $toggle_label = ($copy_mode === 'all') ? 'Switch to Random' : 'Show All Copies';
  $current_label = ($copy_mode === 'all') ? 'Showing All Copies' : 'Random Mode';
  $extra = '';
  if ($t_param !== null) $extra .= '&amp;t=' . htmlspecialchars($t_param);
  if ($d_param !== null) $extra .= '&amp;d=' . htmlspecialchars($d_param);
  echo "<span style='font-size: smaller'>[ $current_label | <a href='wheel_of_images.php?copy_mode=$toggle_mode$extra'><b>$toggle_label</b></a> ]</span>";
}

// SQL fragment to restrict to posts that have at least one _copy image
function copy_exists_clause($tablename, $images_table, $t_val) {
  global $config;
  $t_clause = $config['rotate_tables'] ? '' : " AND " . $images_table . ".t = '" . $t_val . "'";
  return "EXISTS (SELECT 1 FROM " . $images_table . " WHERE " . $images_table . ".id = " . $tablename . ".id" . $t_clause . " AND " . $images_table . ".image_url LIKE '%\\_copy%')";
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=.5, shrink-to-fit=no">
  <title>Wheel of Images</title>
  <script language="Javascript" type="text/javascript">
const currentTheme = localStorage.getItem('theme') ? localStorage.getItem('theme') : null;
if (currentTheme) {
    document.documentElement.setAttribute('data-theme', currentTheme);
    if (currentTheme === 'dark') { toggleSwitch.checked = true; }
}
  </script>
  <script language="Javascript" type="text/javascript">
window.onload=function(){
  const toggleSwitch = document.querySelector('.theme-switch input[type="checkbox"]');
  const currentTheme = localStorage.getItem('theme') ? localStorage.getItem('theme') : null;
  if (currentTheme) {
    document.documentElement.setAttribute('data-theme', currentTheme);
    if (currentTheme === 'dark') { toggleSwitch.checked = true; }
  }
  if (toggleSwitch != null) {
    toggleSwitch.addEventListener('change', function(e) {
      if (e.target.checked) {
        document.documentElement.setAttribute('data-theme', 'dark');
        localStorage.setItem('theme', 'dark');
      } else {
        document.documentElement.setAttribute('data-theme', 'light');
        localStorage.setItem('theme', 'light');
      }
    }, false);
  }
}
  </script>
  <link rel="stylesheet" type="text/css" href="<?=$locations['css']?>" />
</head>
<body class='body'>

<?php

// Default view: redirect to today's thread list
if (!isset($_GET['d']) && !isset($_GET['t'])) {
  header('Location: wheel_of_images.php?t=' . date('mdy'));
  exit();
}

if (!isset($mysqli_link)) {
  $mysqli_link = mysqli_connect($config['db_host'],$config['db_user'],$config['db_pass'],$config['db_name']) or error($config['db_errstr'],$config['admin_email'],"mysqli_connect(" . $config['db_host'] . "," . $config['db_user'] . "," . $config['db_pass'] . ")\n".mysqli_error());
}

$tablename = ($config['rotate_tables'] ? $locations['posts_table'].'_'.$_GET['t'] : $locations['posts_table']);

// -------------------------------------------------------------------------
// Individual post view
// -------------------------------------------------------------------------
if (isset($_GET['d']) && is_numeric($_GET['d']) && isset($_GET['t']) && is_numeric($_GET['t'])) {

  $query = 'select ' . $tablename . '.id, ' . $tablename . '.parent, ' . $tablename . '.message_author, ' . $tablename . '.message_author_email, ' .
           $tablename . '.message_subject, ' . $tablename . '.message_body, date_format(' . $tablename . '.date,"%m/%d/%Y - %l:%i:%s %p") as date, ' .
           $tablename . '.ip, ' . $tablename . '.thread, ' . $tablename . '.link, ' . $tablename . '.image, ' . $tablename . '.video ' .
           'from ' . $tablename . ' ' .
           'where id = ' . $_GET['d'] . (!$config['rotate_tables'] ? ' and t = "' . $_GET['t'] . '"' : '');

  $result = mysqli_query($mysqli_link, $query) or error($config['db_errstr'],$config['admin_email'],$query."\n".mysqli_error());

  if (mysqli_num_rows($result) == 1) {
    $post = mysqli_fetch_array($result);
?>
<table width='100%' border='0' cellpadding='0' cellspacing='0'>
  <tr>
    <td valign='top'>
<table width='100%' border='0' cellpadding='0' cellspacing='0'>
  <tr>
    <td class='borderoutline'>
<table width='100%' border='0' cellpadding='4' cellspacing='1'>
  <tr class='titlelarge'>
    <td><?=$post['message_subject']?></td>
    <td align='right'><?php woi_toggle($_GET['t'], $_GET['d']); ?></td>
  </tr>
  <tr class='main'>
    <td colspan='2'>
    <a href='#follow_ups'><b>[Follow Ups]</b></a>
    <a href='wheel_of_images.php?t=<?=$_GET['t']?>'><b>[Wheel of Images]</b></a>
    <a href='<?=$locations['forum']?>?d=<?=$_GET['d']?>&amp;t=<?=$_GET['t']?>'><b>[View on Main Board]</b></a>
    </td>
  </tr>
  <tr class='main'>
    <td colspan='2'>
<?php
    print "Posted by " . $post['message_author'];
    if (strlen($post['message_author_email']) > 0)
      print " &lt;<a href='mailto:" . $post['message_author_email'] . "'>" . $post['message_author_email'] . "</a>&gt;";
    print " on " . $post['date'] . "<br />\n";

    if ($post['id'] != $post['parent']) {
      $reply_ids = explode('.',$post['thread']);
      $reply_id = $reply_ids[count($reply_ids) - 2];
      unset($reply_ids);
      $q = "select $tablename.id, $tablename.message_author, $tablename.message_subject, date_format($tablename.date,'%m/%d/%Y - %l:%i:%s %p') as date from $tablename where $tablename.id = '$reply_id'" . (!$config['rotate_tables'] ? ' and t = "' . $_GET['t'] . '"' : '');
      $reply = mysqli_query($mysqli_link, $q) or error($config['db_errstr'],$config['admin_email'],$q."\n".mysqli_error());
      if (mysqli_num_rows($reply) == 1) {
        $reply = mysqli_fetch_array($reply);
        print "In Reply to: <a href='wheel_of_images.php?d=" . $reply['id'] . "&amp;t=" . $_GET['t'] . "'>" . $reply['message_subject'] . "</a> posted by " . $reply['message_author'] . " on " . $reply['date'] . "<br />\n";
      }
    }

    print "<hr /><br />\n";
    print nl2br($post['message_body']);
    print "\n<br /><br />\n";

    // Display only _copy images, showing variants based on copy_mode
    if ($post['image'] == 'y') {
      $q = "select " . $locations['images_table'] . ".image_url from " . $locations['images_table'] .
           " where " . $locations['images_table'] . ".id = '" . $post['id'] . "' and " . $locations['images_table'] . ".t = '" . $_GET['t'] . "'";
      $images = mysqli_query($mysqli_link, $q) or error($config['db_errstr'],$config['admin_email'],$q."\n".mysqli_error());
      if (mysqli_num_rows($images) > 0) {
        while ($image = mysqli_fetch_array($images)) {
          if (strlen($image['image_url']) == 0) continue;
          $variants = get_copy_variants($image['image_url']);
          if ($variants === null) continue; // not a local _copy image — skip
          if (empty($variants)) {
            // _copy image but no prior versions found on disk — show copy itself
            woi_display_image($image['image_url']);
          } elseif ($copy_mode === 'all') {
            foreach ($variants as $v) {
              woi_display_image($v);
            }
          } else {
            // random
            woi_display_image($variants[array_rand($variants)]);
          }
        }
      }
    }

    print "<br />\n";
?>
    </td>
  </tr>
  <tr class='title'>
    <td colspan='2'><a name='follow_ups'>Follow Ups</a></td>
  </tr>
  <tr class='main'>
    <td colspan='2'>
<?php
    $copy_clause = copy_exists_clause($tablename, $locations['images_table'], $_GET['t']);
    $query = 'select ' . $tablename . '.id, ' . $tablename . '.message_author, ' . $tablename . '.message_subject, ' . $tablename . '.thread, ' .
             $tablename . '.link, ' . $tablename . '.image, ' . $tablename . '.video, ifnull(' . $tablename . '.score, "null") as score, ifnull(' . $tablename . '.type, "null") as type, ' .
             'case when ' . $tablename . '.message_body = "" then "n" else "y" end as body, ' .
             'date_format(' . $tablename . '.date,"%m/%d/%Y - %l:%i:%s %p") as date ' .
             'from ' . $tablename . ' where ' . $tablename . '.parent = "' . $post['parent'] . '" and ' . $tablename . '.thread like "' . $post['thread'] . '.%" ' .
             (!$config['rotate_tables'] ? ' and t = "' . $_GET['t'] . '"' : '') . ' ' .
             'and ' . $copy_clause . ' ' .
             'order by ' . $tablename . '.parent desc,' . $tablename . '.thread asc';

    $replies = mysqli_query($mysqli_link, $query) or error($config['db_errstr'],$config['admin_email'],$query."\n".mysqli_error());

    if (mysqli_num_rows($replies) > 0) {
      print "<ul>\n";
      $lastthread = array();
      while ($reply = mysqli_fetch_array($replies)) {
        print str_repeat('</li></ul>',count(array_diff($lastthread,explode('.',$reply['thread']))));
        $lastthread = explode('.',$reply['thread']);
        $display_rate = null;
        if ($reply['score'] != 'null' || ($reply['type'] != 'null' && $reply['type'] != '')) {
          switch ($reply['type']) {
            case 'warn-g': $reply['type'] = "<b style='color: red; font-size: larger'>Warning</b> - Gross"; break;
            case 'warn-n': $reply['type'] = "<b style='color: red; font-size: larger'>Warning</b> - Nudity"; break;
            case 'nsfw':   $reply['type'] = "<b style='color: red; font-size: larger'>NSFW</b>"; break;
          }
          $display_rate = " - <span style='font-size: smaller'>( ";
          if ($reply['score'] != 'null') $display_rate .= $reply['score'];
          if ($reply['score'] != 'null' && $reply['type'] != 'null' && $reply['type'] != '') $display_rate .= ', ' . ucfirst($reply['type']);
          if ($reply['score'] == 'null') $display_rate .= ucfirst($reply['type']);
          $display_rate .= ' )</span>';
        }
        print "<ul><li><a href='wheel_of_images.php?d=" . $reply['id'] . "&amp;t=" . $_GET['t'] . "'>" . $reply['message_subject'] . "</a> " .
              options($reply['link'],$reply['video'],$reply['image'],$reply['body'],$reply['message_author']) .
              " - <b>" . $reply['message_author'] . "</b> - " . $reply['date'] . " $display_rate\n";
      }
      print str_repeat('</li></ul>',count($lastthread) - 1);
      print "</ul>\n";
    } else {
      print "No follow-ups with duplicate images.\n";
    }
?>
    </td>
  </tr>
</table>
    </td>
  </tr>
</table>
    </td>
  </tr>
</table>
<?php

  } else {
    print "Post not found\n";
  }

// -------------------------------------------------------------------------
// Thread list view
// -------------------------------------------------------------------------
} elseif (isset($_GET['t']) && is_numeric($_GET['t'])) {

  $timestamp = DateTime::createFromFormat('mdy', $_GET['t'])->getTimestamp();

  if ($config['rotate_tables'] == 'daily')
    $t = date('mdy', $timestamp);
  elseif ($config['rotate_tables'] == 'weekly')
    $t = strftime('%y%W', $timestamp);
  elseif ($config['rotate_tables'] == 'monthly')
    $t = date('my', $timestamp);
  elseif ($config['rotate_tables'] == 'yearly')
    $t = date('Y', $timestamp);
  else
    $t = date('mdy', $timestamp);

  $tablename = ($config['rotate_tables'] ? $locations['posts_table'].'_'.$t : $locations['posts_table']);
  $copy_clause = copy_exists_clause($tablename, $locations['images_table'], $t);

  $query = 'select ' . $tablename . '.id, ' . $tablename . '.parent, ' . $tablename . '.thread, ' . $tablename . '.message_author, ' . $tablename . '.message_subject, ' .
           'date_format(' . $tablename . '.date,"%m/%d/%Y - %l:%i:%s %p") as date, date_format(' . $tablename . '.date, "%l:%i:%s %p") as date_sm, "' . $t . '" as t, ' .
           $tablename . '.link, ' . $tablename . '.image, ' . $tablename . '.video, ifnull(' . $tablename . '.score, "null") as score, ifnull(' . $tablename . '.type, "null") as type, ' .
           'case when ' . $tablename . '.message_body = "" then "n" else "y" end as body, ' . $tablename . '.message_body ' .
           'from ' . $tablename . ' ' .
           'where ' . $copy_clause . ' ' .
           (!$config['rotate_tables'] ? ' and t = ' . $t . ' ' : '') .
           'order by ' . $tablename . '.parent desc, ' . $tablename . '.thread asc';

  $results = mysqli_query($mysqli_link, $query) or error($config['db_errstr'],$config['admin_email'],$query."\n".mysqli_error());

?>
<table width='100%' border='0' cellpadding='0' cellspacing='0' id='boardheader'>
  <tr>
    <td class='borderoutline'>
<table width='100%' border='0' cellpadding='4' cellspacing='1'>
  <tr class='title'>
    <td colspan='1'><h2>Wheel of Images</h2></td>
    <td>
    <div class="theme-switch-wrapper">
      <label class="theme-switch" for="checkbox">
        <input type="checkbox" id="checkbox" />
        <div class="slider round"></div>
      </label>
      <em>Enable Dark Mode!</em>
    </div>
    </td>
  </tr>
  <tr class='main' id="centerheader">
    <td class='menu'>
    <a href='#recent_messages'><b>Posts with Duplicate Images</b></a><br />
    <a href='<?=$locations['forum']?>?t=<?=$_GET['t']?>'><b>Main Board</b></a><br />
    </td>
    <td rowspan='2' class='info'>
    <?php woi_toggle($_GET['t']); ?>
    </td>
  </tr>
  <tr class='title'>
    <td colspan='2'><a name='recent_messages'>Messages</a></td>
  </tr>
  <tr class='main'>
    <td colspan='2'>
<?php
  print "Showing posts from " . date('m/d/Y', $timestamp) . " with duplicate (_copy) images.<br /><br />\n";

  $count = mysqli_num_rows($results);
  print "Matching posts: <b>$count</b><br />\n";
?>
    <div align='center'>
    [<a href='<?=$locations['forum']?>?t=<?=$_GET['t']?>'><b>Main Board</b></a>]
    [<a href='wheel_of_images.php'><b>Refresh (Today)</b></a>]
    </div>
    </td>
  </tr>
</table>
    </td>
  </tr>
</table>
<br />
<?php

  if ($count == 0) {
    print "No posts with duplicate images found for this date.\n";
  } else {
    $data = '';
    $lastthread = array();
    while ($posts = mysqli_fetch_array($results)) {
      $data .= str_repeat("</li></ul>", count(array_diff($lastthread, explode('.', $posts['thread']))));
      $lastthread = explode('.', $posts['thread']);

      $display_rate = null;
      if ($posts['score'] != 'null' || ($posts['type'] != 'null' && $posts['type'] != '')) {
        switch ($posts['type']) {
          case 'warn-g': $posts['type'] = "<b style='color: red; font-size: larger'>Warning</b> - Gross"; break;
          case 'warn-n': $posts['type'] = "<b style='color: red; font-size: larger'>Warning</b> - Nudity"; break;
          case 'nsfw':   $posts['type'] = "<b style='color: red; font-size: larger'>NSFW</b>"; break;
        }
        $display_rate = " - <span style='font-size: smaller'>( ";
        if ($posts['score'] != 'null') $display_rate .= $posts['score'];
        if ($posts['score'] != 'null' && $posts['type'] != 'null' && $posts['type'] != '') $display_rate .= ', ' . ucfirst($posts['type']);
        if ($posts['score'] == 'null') $display_rate .= ucfirst($posts['type']);
        $display_rate .= ' )</span>';
      }

      if ($config['always_display_date_full'])
        $display_date = ' - ' . $posts['date'];
      elseif ($config['always_display_date_small'])
        $display_date = ' - ' . $posts['date_sm'];
      else {
        if ($posts['id'] == $posts['parent']) $display_date = ' - ' . $posts['date_sm'];
        else $display_date = null;
      }

      $data .= '<ul><li><a href="wheel_of_images.php?d=' . $posts['id'] . '&amp;t=' . $posts['t'] . '" title="' . $posts['date'] . '">' . $posts['message_subject'] . '</a> ' .
               options($posts['link'], $posts['video'], $posts['image'], $posts['body'], $posts['message_author']) .
               ' - <b>' . $posts['message_author'] . '</b>' . $display_date . $display_rate;
    }
    $data .= str_repeat('</li></ul>', count($lastthread));
    print $data;
  }

}

?>

</body>
</html>
