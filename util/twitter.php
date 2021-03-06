<?php
require_once( dirname(__FILE__) . "/../init.php" );
$message = array();
$url = "https://twitter.com/eve_kill/status/";
$storageName = "twitterLatestID";

$latest = Db::queryField("SELECT contents FROM zz_storage WHERE locker = '$storageName'", "contents", array(), 0);
if ($latest == null) $latest = 0;
$maxID = $latest;
$twitter = Twit::getMessages(25);

foreach ($twitter as $status) {
	$text = (array) $status->text;
	$createdAt = (array) $status->created_at;
	$postedBy = (array) $status->user->name;
	$screenName = (array) $status->user->screen_name;
	$id = (int) $status->id;

	if ($id <= $latest) continue;
	$maxID = max($id, $maxID);

	$message = array("message" => $text[0], "postedAt" => $createdAt[0], "postedBy" => $postedBy[0], "screenName" => $screenName[0], "url" => $url.$id[0]);
	$msg = "Twitter: |g|" . $message["postedBy"] . "|n| (|g|@". $screenName[0] ."|n|) / |g|" . date("Y-m-d H:i:s", strtotime($message["postedAt"])) . " Message:|n| " . $message["message"];
	Log::irc($msg, "");
}
if (sizeof($twitter)) {
	Db::execute("INSERT INTO zz_storage (contents, locker) VALUES (:contents, :locker) ON DUPLICATE KEY UPDATE contents = :contents", array(":locker" => $storageName, ":contents" => $maxID));
}
