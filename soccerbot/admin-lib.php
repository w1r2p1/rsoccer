<?php

require_once('reddit-lib.php');
require_once('flair-lib.php');

function upload_sprites($subreddit, $delete = false) {
  global $SPRITES;

  print("Uploading sprites for $subreddit...\n");

  for ($s = 1; $s <= $SPRITES; $s++) {
    $sprite = "./sprites/s$s.gif";
    if (file_exists($sprite)) {
      print("Uploading sprite $sprite...\n");
      reddit_upload_sr_img($subreddit, $sprite);
      if ($delete) {
        unlink($sprite);
      }
    }
  }

  print("Uploaded sprites for $subreddit.\n");
}

function upload_css($subreddit) {
  global $db, $SPRITES;

  print("Generating CSS for $subreddit...\n");

  $timestamp = gmdate('d M Y H:i:s');

  $css = file_get_contents("$subreddit.css");

  $css .= "\n/* GENERATED BY SOCCERBOT: $timestamp */\n\n";

  for ($sprite = 1; $sprite <= $SPRITES; $sprite++) {
    $pos = 0;
    $query = $db->query("SELECT * FROM teams WHERE sprite=$sprite");

    while ($row = $query->fetch()) {
      $pos -= 21;
      $flair = $row['flair'];
      $css .= ".flair-$flair";
      if ($row['site'] != '' || $row['twitter'] != '') {
        $css .= ",.linkflair-$flair .linkflairlabel";
      }
      $css .= "{background-position:0 $pos"."px}\n";
    }
  }

  print("Uploading CSS for $subreddit...\n");

  reddit_subreddit_stylesheet($subreddit, $css);

  print("Uploaded CSS for $subreddit.\n");
}

function upload_bot_css($subreddit) {
  global $db, $SPRITES;

  print("Generating CSS for $subreddit...\n");

  $timestamp = gmdate('d M Y H:i:s');

  $css = file_get_contents("$subreddit.css");

  $css .= "\n/* GENERATED BY SOCCERBOT: $timestamp */\n\n";

  for ($sprite = 1; $sprite <= $SPRITES; $sprite++) {
    $pos = 0;
    $query = $db->query("SELECT * FROM teams WHERE sprite=$sprite");

    while ($row = $query->fetch()) {
      $pos -= 21;
      $flair = $row['flair'];
      $css .= "a[href$=\"=$flair\"]:before{background-position:0 $pos"."px}\n";
    }
  }

  print("Uploading CSS for $subreddit...\n");

  reddit_subreddit_stylesheet($subreddit, $css);

  print("Uploaded CSS for $subreddit.\n");
}

function upload_bot_sidebar($subreddit) {
  print("Generating sidebar for $subreddit...\n");

  $teams = sqlCount("SELECT COUNT(*) AS count FROM teams");
  $countries = sqlCount("SELECT COUNT(*) AS count FROM countries");

  $markdown = file_get_contents("./soccerbot.md");
  $markdown .= "\n\nThere are currently $teams teams from $countries countries in soccerbot's database.";

  print("Uploading sidebar for $subreddit...\n");

  reddit_wiki_edit($subreddit, 'config/sidebar', $markdown);

  print("Sidebar for $subreddit uploaded.\n");
}

function upload_bot_index($subreddit) {
  global $db, $SPRITES, $bot_index;

  print("Uploading index for $subreddit...\n");

  $db->sqliteCreateFunction("REGEXP", "preg_match", 2);

  foreach ($bot_index as $id => $where) {
    $country = '';
    $text = '';

    $query = $db->query(
      "SELECT teams.flair AS flair,teams.name AS name,teams.count AS count,countries.name AS country,countries.region AS region ".
      "FROM teams LEFT JOIN countries WHERE country=countries.code AND $where ORDER BY region,countries.name,teams.fileName"
    );

    while ($row = $query->fetch()) {
      if ($country != $row['country']) {
        $country = $row['country'];
        if ($country != 'England') {
          $text .= "\n\n#$country\n";
        }
      }
      $name = $row['name'];
      $flair = $row['flair'];
      $count = $row['count'];

      $text .= "\n* [$name *\\($count\\)*](/message/compose/?to=soccerbot&subject=crest&message=$flair)";
    }

    reddit_editusertext($subreddit, $id, $text);
  }

  print("Index for $subreddit uploaded.\n");
}

function download_users($subreddit) {
  global $db;

  print("Creating users table...\n");

  $db->query("DROP TABLE users");
  $db->query("CREATE TABLE users (user TEXT NOT NULL, flair TEXT NOT NULL)");

  print("Fetching data from reddit...\n");

  $list = reddit_flairlist($subreddit);

  print("Populating users table...\n");

  $query = $db->prepare("INSERT INTO users (user, flair) VALUES (?,?)");

  $db->beginTransaction();

  foreach ($list as $entry) {
    $user = $entry->user;
    $flair = preg_replace('/\s+s\d+$/', '', $entry->flair_css_class);
    $query->execute(array($user, $flair));
  }

  $db->commit();

  print("Generating team counts...\n");

  $query = $db->query("SELECT flair FROM teams");
  $users = $db->prepare("SELECT COUNT(*) AS count FROM users WHERE flair=?");
  $setCount = $db->prepare("UPDATE teams SET count=? WHERE flair=?");

  $db->beginTransaction();

  while ($row = $query->fetch()) {
    $flair = $row['flair'];
    $users->execute(array($flair));
    $team = $users->fetch();
    if ($team) {
      $count = $team['count'];
      $setCount->execute(array($count, $flair));
    }
  }

  $db->commit();

  print("User table created.\n");
}

function upload_users($subreddit) {
  global $db;

  print("Uploading new users.\n");

  $data = array();

  $query = $db->query("SELECT * FROM uploads");

  while ($row = $query->fetch()) {
    $user = $row['user'];
    $text = $row['text'];
    $css_class = $row['css_class'];
    array_push($data, "$user,$text,$css_class");
  }

  flair_batch($subreddit, $data);

  $db->query("DROP TABLE uploads");
  $db->query("CREATE TABLE uploads (user TEXT PRIMARY KEY NOT NULL, text TEXT NOT NULL, css_class TEXT NOT NULL)");

  print("Users uploaded.\n");
}

function upload_stats($subreddit, $thing_id) {
  global $db;

  print("Generating stats...\n");

  $users = sqlCount("SELECT COUNT(*) AS count FROM users");
  $teams = sqlCount("SELECT COUNT(DISTINCT teams.flair) AS count FROM users LEFT JOIN teams WHERE users.flair=teams.flair");
  $countries = sqlCount("SELECT COUNT(DISTINCT teams.country) AS count FROM users LEFT JOIN teams WHERE users.flair=teams.flair");

  $timestamp = date('Y-m-d');

  $text  = "LAST UPDATED: $timestamp\n\n";
  $text .= "*There are currently $users users supporting $teams teams from $countries countries.*\n\n";

  $index = 0;
  $count = 0;

  $query = $db->query("SELECT * FROM teams ORDER BY count DESC");

  $text .= "#Top 100 Teams\n";
  $text .= "Pos|Team|Count|\n";
  $text .= "---:|:---|---:\n";

  while (($row = $query->fetch())) {
    $index++;
    $name = $row['name'];
    $pos = ' ';
    if ($count != $row['count']) {
      if ($index > 100) {
        break;
      }
      $count = $row['count'];
      $pos = $index;
    }
    $text .= "$pos|$name|$count\n";
  }

  print("Posting stats...\n");

  reddit_editusertext($subreddit, $thing_id, $text);

  print("Stats generated.\n");
}

function sqlCount($sql) {
  global $db;

  $query = $db->query($sql);
  $row = $query->fetch();
  return number_format($row['count']);
}

?>
