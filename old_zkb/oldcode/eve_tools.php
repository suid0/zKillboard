<?php

/*
 * This file has been my dumping ground for many utility functions that help drive many parts
 * of the site.
 *
 * TODO Refactor the code into classes with proper organization.
 */

/**
 * @param array $context
 * @param boolean $isVictim
 * @param null $additionalWhere
 * @param int $limit
 * @return array
 */
function getKills(&$context, $isVictim, $additionalWhere = null, $limit = 10)
{
		$query = getQuery($context, $isVictim, $additionalWhere, $limit);
		$year = $query["year"];
		$week = $query["week"];

		$results = array();
		$attempts = 0;
		$newWeek = $week;
		$newYear = $year;
		$yearWeek = array();

		$extra = "-- " . date("G:i");
		$results = Db::query($query["query"] . $extra, $query["parameters"]);

		$kills = array();
		foreach ($results as $result) {
				$kills[] = $result['killID'];
		}
		arsort($kills);
		return getKillInfo($kills);
}


/**
 * @param array $context Global scoped parameter
 * @param boolean $isVictim Define friendlies as the victim
 * @param null $additionalWhere Add this where statement to the whereclauses
 * @param int $limit Default the search result to this limit
 * @return array An array containing the "query" and its "parameters"
 */
function getQuery(&$context, $isVictim, $additionalWhere = null, $limit = 10, $preferredOrderBy = "")
{
		$queryInfo = buildQuery($context, $isVictim, $additionalWhere, $limit);
		$whereClauses = $queryInfo["whereClauses"];
		$tables = $queryInfo["tables"];
		$orderBy = $preferredOrderBy == "" ? " kills.killID " . $queryInfo["orderBy"] : $preferredOrderBy;
		$queryParameters = $queryInfo["parameters"];
		$limit = $queryInfo["limit"];

		// Build the query
		$query = "select kills.killID from ";
		$query .= implode(",", $tables);
		$query .= " where ";
		$query .= implode(" and ", $whereClauses);
		$query .= " group by kills.killID order by $orderBy";
		if ($limit != -1) $query .= " limit $limit";

		return array("query" => $query, "parameters" => $queryParameters, "year" => $queryInfo["year"], "month" => $queryInfo["month"], "week" => $queryInfo["week"]);
}

/**
 * Returns a pre-built query as an array.
 *
 * @param array $context Global scoped parameter
 * @param boolean $isVictim Define "friendlies" as the victim
 * @param null $additionalWhere Add this where statement to the whereclauses
 * @param int $limit Default the search result to this limit
 * @return array ("tables" => $tables, "whereClauses" => $whereClauses, "orderBy" => $orderBy, "parameters" => $queryParameters, "limit" => $limit);
 */
// TODO Pass $p as a parameter
function buildQuery(&$context, $isVictim, $additionalWhere = null, $limit = 10)
{
		global $p, $dbPrefix, $subDomain, $subDomainEveID, $subDomainGroupID, $pModified;
		if (!isset($pModified)) $pModified = false;

		if (strlen($subDomain) > 0 && !$pModified) {
				$pModified = true;
				$subDomain = str_replace(".", " ", $subDomain);

				$subDomainInfo = Db::query("select 'alli' type, alliance_id id from {$dbPrefix}alliances where shortName = :subdomain", array(":subdomain" => $subDomain), 90);
				if (sizeof($subDomainInfo) == 0) $subDomainInfo = Db::query("select 'alli' type, alliance_id id from {$dbPrefix}alliances where name = :subdomain", array(":subdomain" => $subDomain), 90);
				if (sizeof($subDomainInfo) == 0) $subDomainInfo = Db::query("select 'corp' type, corporation_id id from {$dbPrefix}corporations where ticker = :subdomain", array(":subdomain" => $subDomain), 00);
				if (sizeof($subDomainInfo) == 0) $subDomainInfo = Db::query("select 'corp' type, corporation_id id from {$dbPrefix}corporations where name = :subdomain", array(":subdomain" => $subDomain), 90);
				if (sizeof($subDomainInfo) == 0) $subDomainInfo = Db::query("select 'pilot' type, character_id id from {$dbPrefix}characters where name = :subdomain", array(":subdomain" => $subDomain), 90);

				if (sizeof($subDomainInfo) == 0) {
						// No idea who they're looking for, therefore send them to our main URL.
						header('Location: http://zkillboard.com/');
						exit;
				}

				foreach ($subDomainInfo as $subDomainRow) {
						switch ($subDomainRow['type']) {
								case "alli":
										$subDomainGroupID = $subDomainRow["id"];
								$subDomainEveID = $subDomainGroupID;
								array_unshift($p, "with", "alli", Info::getAlliName($subDomainGroupID));
								$context['isAlliancePage'] = true;
								$context['subDomainPageType'] = 'alli';
								$context['pageTitle'] = Info::getAlliName($subDomainGroupID);
								break;
								case "corp":
										$subDomainGroupID = $subDomainRow["id"];
										$corpName = Info::getCorpName($subDomainGroupID);
								array_unshift($p, "with", "corp", $corpName);
								$context['isCorpPage'] = true;
								$context['subDomainPageType'] = 'corp';
								$context['pageTitle'] = $corpName;
								break;
								case "pilot":
										$subDomainGroupID = $subDomainRow["id"];
										$name = Info::getCharName($subDomainGroupID);
								array_unshift($p, "with", "pilot", $name);
								$context['isPilotPage'] = true;
								$context['subDomainPageType'] = 'pilot';
								$context['pageTitle'] = $name;
								break;
						}
				}
		}
		if (!isset($context['subDomainPageType'])) $context['subDomainPageType'] = "all";


		$queryKey = "buildQuery_$isVictim $additionalWhere $limit";
		$retValue = Bin::get($queryKey, FALSE);
		if ($retValue !== FALSE) return $retValue;

		$whereClauses = array();
		$queryParameters = array();

		$coalition = false;
		$pilots = array();
		$corps = array();
		$allis = array();
		$ships = array();
		$tables = array();
		$year = null;
		$month = null;
		$week = null;
		$context['SearchParameters'] = array();

		$specificMail = false;

		$pCount = sizeof($p);
		for ($i = 0; $i < $pCount; $i++) {
				$key = $p[$i];
				$value = $i < ($pCount - 1) ? $p[$i + 1] : null;
				switch ($key) {
						case "against":
								$coalition = false;
						break;
						case "with":
								$coalition = true;
						break;
						case "killmail":
								$specificMail = true;
						$whereClauses[] = "kills.killID = :killID";
						$queryParameters[":killID"] = $value;
						case "pilot":
								$context['SearchParameters']["$value"] = "P:$value";
						$pilotID = Info::getCharId($value);
						if ($pilotID == null) throw new Exception("$value is an unknown pilot.");
						$pilots[$pilotID] = $coalition;
						break;
						case "corp":
								$context['SearchParameters']["$value"] = "C:$value";
						$corpID = Info::getCorpId($value);
						if ($corpID == null) throw new Exception("$value is an unknown corporation.");
						$corps[$corpID] = $coalition;
						break;
						case "alli":
								$context['SearchParameters']["$value"] = "A:$value";
						$alliID = Info::getAlliId($value);
						if ($alliID == null) throw new Exception("$value is an unknown alliance.");
						$allis[$alliID] = $coalition;
						break;
						case "ship":
								$itemID = Info::getItemID($value);
						$context['SearchParameters'][] = "Ship: " . Info::getItemName($itemID);
						$ships[$itemID] = $coalition;
						break;
						case "system":
								$systemID = Info::getSystemID($value);
						$whereClauses[] = "solarSystemID = :systemID";
						$queryParameters[":systemID"] = $systemID;
						$context['SearchParameters'][] = "System: $value";
						break;
						case "shipTypeID":
								$context['SearchParameters'][] = "Ship: " . Info::getItemName($value);
						$whereClauses[] = " kills.killID in (select killID from {$dbPrefix}participants where shipTypeID = :shipTypeID)";
						$queryParameters[":shipTypeID"] = $value;
						break;
						case "year":
								$year = min(date("Y"), max(2003, (int)$value));
						break;
						case "month":
								$month = min(12, max(1, (int)$value));
						break;
						case "week":
								$week = min(53, max(1, (int) $value));
						break;
						case "day":
								$day = min(31, max(1, (int)$value));
						$whereClauses[] = "day = :day";
						$queryParameters[":day"] = $day;
						$context['SearchParameters'][] = "Day: $day";
						$context['searchDay'] = $day;
						$pageNumber = 0;
						break;
						case "related":
								$split = explode(",", $value);

						$system = $split[0];
						$systemID = Info::getSystemID($system);
						if ($systemID != null) {
								$whereClauses[] = "solarSystemID = :systemID";
								$queryParameters[":systemID"] = $systemID;
								$context['SearchParameters'][] = "System: $system";
						}

						$date = $split[1];
						$year = max(1, (int)substr($date, 0, 4));
						$month = max(1, (int)substr($date, 4, 2));
						$day = max(1, (int)substr($date, 6, 2));
						$hours = max(1, (int)substr($date, 8, 2));
						@$time = mktime($hours, 0, 0, $month, $day, $year, 0);
						$prevHour = $time - 3600;
						$nextHour = $time + 7200;
						$whereClauses[] = "unix_timestamp >= $prevHour and unix_timestamp <= $nextHour";
						$context['SearchParameters'][] = "Related";
						$value = $date;
						// No break here, let it flow through
						case "date":
								$date = $value;
						$year = max(1, (int)substr($date, 0, 4));
						$month = max(1, (int)substr($date, 4, 2));
						$day = max(1, (int)substr($date, 6, 2));
						$whereClauses[] = "day = :day";
						$queryParameters[":day"] = $day;
						$whereClauses["limit"] = -1;
						$context['searchDay'] = $day;
						$pageNumber = 0;
						break;
						case "display_after":
								if (!$additionalWhere) {
										$whereClauses[] = "kills.killID > :afterKillID";
										$queryParameters[":afterKillID"] = $value;
										$whereClauses["orderBy"] = "asc";
								}
						break;
						case "display_before":
								if (!$additionalWhere) {
										$whereClauses[] = "kills.killID < :beforeKillID";
										$queryParameters[":beforeKillID"] = $value;
								}
						break;
						case "before":
								//$yearWeek = Db::queryRow("select year, week from zz_killmail where killID = :killID", array(":killID" => $value));
								//$year = $yearWeek["year"];
								//$week = $yearWeek["week"];
								$whereClauses[] = "kills.killID < :before";
								$queryParameters[":before"] = $value;
						break;
						case "after":
								//$yearWeek = Db::queryRow("select year, week from zz_killmail where killID = :killID", array(":killID" => $value));
								//$year = $yearWeek["year"];
								//$week = $yearWeek["week"];
								$whereClauses[] = "kills.killID > :after";
						$queryParameters[":after"] = $value;
						break;
						case "page":
								$pageNumber = intvalue($value);
						break;
				}
		}

		if (sizeof($whereClauses) == 0) {
			if ($week != null) $year = date("Y");
			if ($year != null) $whereClauses[] = "kills.year = $year";
			if ($year != null) $whereClauses[] = "joined.year = $year";
			if ($week != null) $whereClauses[] = "kills.week = $week";
			if ($week != null) $whereClauses[] = "joined.week = $week";
		}

		if (!$specificMail) {
				// Add year and month
				if ($week == null) $week = date("W");
				if ($year == null) $year = date("Y");
				$context['searchYear'] = $year;
				$context['searchWeek'] = $week;
		}

		$month = strlen("$month") < 2 ? "0$month" : $month;
		$week = strlen("$week") < 2 ? "0$week" : $week;
		Tables::ensureTableExist($year, $week);

		addSubQuery($tables, $whereClauses, $pilots, $queryParameters, "characterID", ":charID", $year, $week);
		addSubQuery($tables, $whereClauses, $corps, $queryParameters, "corporationID", ":corpID", $year, $week);
		addSubQuery($tables, $whereClauses, $allis, $queryParameters, "allianceID", ":alliID", $year, $week);
		addSubQuery($tables, $whereClauses, $ships, $queryParameters, "shipTypeID", ":shipID", $year, $week);

		$whereClauses[] = "isVictim = :victim";
		$queryParameters[":victim"] = $isVictim ? "T" : "F";

		if ($additionalWhere != null) $whereClauses[] = $additionalWhere;

		/*if (isset($context['searchYear']) || isset($context['searchWeek']) || isset($context['searchDay'])) {
				$months = array("Jan.", "Feb.", "March", "April", "May", "June",
								"July", "Aug.", "Sep.", "Oct.", "Nov.", "Dec.");
				$monthName = isset($context['searchMonth']) ? $months[$context['searchMonth'] - 1] . ", " : "";
				$day = isset($context['searchDay']) ? " " . $context['searchDay'] : "";
				$context['SearchParameters'][] = " $monthName$day $year ";
		}*/
		if (isset($context['searchYear']) || isset($context['searchWeek'])) $context['SearchParameters'][] = "Week $week, $year";

		$orderBy = isset($whereClauses["orderBy"]) ? $whereClauses["orderBy"] : "desc";
		unset($whereClauses["orderBy"]);

		$limit = isset($whereClauses["limit"]) ? $whereClauses["limit"] : $limit;
		unset($whereClauses["limit"]);
		if (isset($pageNumber)) {
				$limit = ($pageNumber * 30) . " , 30";
		}
		//if ($week != null) $whereClauses[] = " week = $week ";

		$tables[] = "{$dbPrefix}kills kills left join {$dbPrefix}participants joined on (joined.killID = kills.killID)";

		$retValue = array("tables" => $tables, "whereClauses" => $whereClauses, "orderBy" => $orderBy, "parameters" => $queryParameters, "limit" => $limit, "year" => $year, "month" => $month, "week" => $week);
		Bin::set($queryKey, $retValue);
		return $retValue;
}

/**
 * Builds a subquery for insertion into the larger query.
 * I'm quite sure there is a better way to do this as well, but with a dynamically built query the challenge
 * is a bit more difficult.  TODO See about converting this subqueries into LEFT JOINs
 *
 * @param  $tables
 * @param  $whereClauses
 * @param  $values
 * @param  $queryParameters
 * @param  $column
 * @param  $shortHand
 * @return void
 */
function addSubQuery(&$tables, &$whereClauses, &$values, &$queryParameters, $column, $shortHand, $year, $week)
{
		global $dbPrefix, $p;

		$indexCount = 1;
		asort($values);
		foreach ($values as $value => $coalition) {
				$victimMatch = $coalition ? "=" : "!=";
				$tCount = sizeof($tables);
				$finalBlow = in_array("finalBlow", $p);
				$finalBlow = $finalBlow ? " and finalBlow = 1 " : "";
				$tables[] = "(select distinct kills.killID from {$dbPrefix}kills kills left join {$dbPrefix}participants joined on (kills.killID = joined.killID)
						where $column = $shortHand$indexCount $finalBlow and isVictim $victimMatch :victim ) as t$tCount";
						//and kills.year = $year and joined.year = $year and kills.week = $week and joined.week = $week) as t$tCount";
				$whereClauses[] = "kills.killID = t$tCount.killID";
				$queryParameters["$shortHand$indexCount"] = $value;
				$indexCount++;
		}
}

/**
 * Returns kill details including the victim and the pilot that dealt the final blow.  Does not include all
 * involved pilots.
 *
 * @param  $killIds
 * @return array
 */
function getKillInfo($killIds)
{
		if (sizeof($killIds) == 0) return array();

		global $dbPrefix;

		if (!is_array($killIds)) {
				$killIds = array($killIds);
		}
		sort($killIds);

		$killInfo = array();
		$victims = array();
		$attackers = array();
		$imploded = implode(",", $killIds);

		$killDetail = Db::query("select * from {$dbPrefix}kills where killID in ($imploded) order by killID desc", array());
		$involved = Db::query("select * from {$dbPrefix}participants where killID in ($imploded) and (isVictim = 'T' or finalBlow = '1')", array());
		foreach ($involved as $pilot) {
				if ($pilot['isVictim'] == "T") $victims[] = $pilot;
				else if ($pilot['finalBlow'] == 1) $attackers[] = $pilot;
		}

		killMerge($killInfo, $killDetail, "detail");
		killMerge($killInfo, $victims, "victim");
		killMerge($killInfo, $attackers, "attacker");
		return $killInfo;
}

/**
 * Merges kill details into an array.
 *
 * @param  $killInfo
 * @param  $killRow
 * @param  $name
 * @return void
 */
function killMerge(&$killInfo, &$killRow, $name)
{
		foreach ($killRow as $row) {
				$killID = $row['killID'];
				if (!isset($killInfo["$killID"])) $killInfo["$killID"] = array();
				$killInfo["$killID"][$name] = $row;
		}
}

/**
 * Obtain the full kill detail for a single kill.
 *
 * @param  $killID
 * @return array
 */
function getKillDetail($killID)
{
		global $dbPrefix;

		$isValid = Db::queryField("select count(1) count from {$dbPrefix}killmail where killID = :killID", "count", array(":killID" => $killID));
		if ($isValid == 0) die("Invalid kill");

		$killDetail = Db::queryRow("select * from {$dbPrefix}kills where killID = :killID", array(":killID" => $killID));
		$victim = Db::queryRow("select * from {$dbPrefix}participants where isVictim = 'T' and killID = :killID", array(":killID" => $killID));
		$attackers = Db::query("select * from {$dbPrefix}participants where isVictim = 'F' and  killID = :killID order by damage desc", array(":killID" => $killID));
		$items = Db::query("select * from {$dbPrefix}items where killID = :killID order by flag, insertOrder", array(":killID" => $killID));

		return array(
						"killID" => $killID,
						"detail" => $killDetail,
						"victim" => $victim,
						"attackers" => $attackers,
						"items" => $items,
					);
}

/**
 * A helper function that converts a shorthand string into a full column name.
 *
 * @param  $type The shorthand string.
 * @return null|string The full column name if found, null otherwise.
 */
function getColumnType($type)
{
		switch ($type) {
				case "pilot":
						$typeID = "characterID";
				break;
				case "corp":
						$typeID = "corporationID";
				break;
				case "alli":
						$typeID = "allianceID";
				break;
				case "price":
						$typeID = "total_price";
				break;
				case "ship":
						$typeID = "shipTypeID";
				break;
				case "faction":
						$typeID = "factionID";
				break;
				default:
				$typeID = null;
		}
		return $typeID;
}

/**
 * @param  $context
 * @param  $type
 * @param bool $isVictim
 * @param int $limit
 * @return array|Returns
 */
function topDogs(&$context, $type, $isVictim = false, $limit = 5)
{
		global $subDomainEveID, $subDomainGroupID, $p;
		global $subDomain;

		$queryInfo = buildQuery($context, $isVictim, null, $limit);

		$year = $queryInfo["year"];
		$week = $queryInfo["week"];

		$typeID = getColumnType($type);
		if ($typeID == null) return array();

		$pilotID = null;
		$corpID = null;
		$alliID = null;

		$whereClauses = $queryInfo["whereClauses"];
		switch ($subDomainGroupID) {
				case 32: // Alli Board
						$alliID = $subDomainEveID;
						break;
				case 2: // Corp Board
						$corpID = $subDomainEveID;

						break;
				case 1: // Pilot Board
						$pilotID = $subDomainEveID;
						break;
		}
		if ($pilotID != null) $whereClauses[] = "characterID = $pilotID";
		if ($corpID != null) $whereClauses[] = "corporationID = $corpID";
		if ($alliID != null) $whereClauses[] = "allianceID = $alliID";
		if ($typeID == "corporationID") $whereClauses[] = "corporationID > 1100000";
		$whereClauses[] = "$typeID != 0";
		$whereClauses[] = "kills.year = $year";
		$whereClauses[] = "joined.year = $year";
		$whereClauses[] = "kills.week = $week";
		$whereClauses[] = "joined.week = $week";

		$tables = $queryInfo["tables"];
		$queryParameters = $queryInfo["parameters"];

		// TODO Look into optimizing this query
		$query = "select $typeID, count(distinct kills.killID) count
				from " . implode(", ", $tables) . "
				where " . implode(" and ", $whereClauses) . "
				group by $typeID order by count desc";
		if ($limit > 0) $query .= " limit " . ($limit + 1);

		$result = Db::query($query, $queryParameters, 300);
		return $result;
}

function getBigIsk(&$context, $isVictim, $limit = 5) {
		global $subDomainEveID, $subDomainGroupID, $dbPrefix;

		$queryInfo = getQuery($context, !$isVictim, null, 5, "total_price desc");	
		$year = $queryInfo['year'];
		$week = $queryInfo['week'];
		$queryInfo = getQuery($context, !$isVictim, "joined.year = $year and joined.year = $year and kills.week = $week and joined.week = $week", 5, "total_price desc");	

		$results = Db::query($queryInfo['query'], $queryInfo['parameters']);
		if (sizeof($results) == 0) return array();
		$kills = array();
		foreach ($results as $result) {
				$kills[] = $result['killID'];
		}

		$query = "select distinct kills.killID, characterID, shipTypeID, kills.total_price
				from {$dbPrefix}kills kills left join {$dbPrefix}participants inv on (kills.killID = inv.killID)
				where kills.killID in (" . implode(", ", $kills) . ") and isVictim = 'T'
				and kills.year = $year and inv.year = $year and kills.week = $week and inv.week = $week
				order by total_price desc limit 5";

		return Db::query($query, array());
}

function calculateFirstAndPrevious(&$kills, &$context)
{
		global $p;

		$firstLast = findFirstAndLastKill($kills);
		$first = $firstLast["first"];
		$last = $firstLast["last"];
		$firstKillID = $first["detail"]["killID"];
		$lastKillID = $last["detail"]["killID"];
		if ($firstKillID != $lastKillID) {
				if (in_array("before", $p) || in_array("after", $p)) $context['display_after'] = $firstKillID;
				$context['display_before'] = $lastKillID;
		}
}

function findFirstAndLastKill(&$kills)
{
		$firstKill = null;
		$lastKill = null;
		foreach ($kills as $kill) {
				if ($firstKill == null) $firstKill = $kill;
				$lastKill = $kill;
		}
		return array("first" => $firstKill, "last" => $lastKill);
}

function shipGroupSort($a, $b)
{
		$split1 = explode("|", $a);
		$split2 = explode("|", $b);
		$shipTypeID1 = $split1[0];
		$shipTypeID2 = $split2[0];

		$volumeID1 = Db::queryField("select volume from invTypes where typeID = :typeID", "volume", array(":typeID" => $shipTypeID1));
		$volumeID2 = Db::queryField("select volume from invTypes where typeID = :typeID", "volume", array(":typeID" => $shipTypeID2));

		if ($volumeID1 != $volumeID2) return $volumeID2 - $volumeID1;

		if ($shipTypeID1 == $shipTypeID2) {
				if ($split1[1] == $split2[1]) {
						return $split1[2] - $split2[2];
				}
				return $split1[1] - $split2[1];
		}

		$groupID1 = Db::queryField("select groupID from invTypes where typeID = :typeID", "groupID", array(":typeID" => $shipTypeID1));
		$groupID2 = Db::queryField("select groupID from invTypes where typeID = :typeID", "groupID", array(":typeID" => $shipTypeID2));
		if ($groupID1 == $groupID2) {
				return $shipTypeID2 - $shipTypeID1;
		}
		if ($groupID1 == $groupID2) return 0;
		return $groupID2 - $groupID1;
}

/**
 * @param array $context
 * @param string $type
 * @param int $eveID
 * @return mixed|null|Returns
 */
function getStatistics(&$context, $type, $eveID)
{
		global $p, $dbPrefix;

		$builtQuery = buildQuery($context, false);
		$year = $builtQuery["year"];
		$week = $builtQuery["week"];
		$tables = $builtQuery["tables"];
		$whereClauses = $builtQuery["whereClauses"];
		$parameters = $builtQuery["parameters"];

		$hash = md5(implode("/", $p));
		if ($eveID == null) $eveID = 0;

		// See if we already have this query in the statistics table
		$statsQuery = "select uncompress(result) result from {$dbPrefix}cache where type = 'stat' and year = :year and month = :month and query = :hash and eve_id = :eveID";
		$statsParameters = array(":year" => $builtQuery["year"], ":month" => $builtQuery["month"], ":eveID" => $eveID, ":hash" => $hash);
		$statistics = Db::queryField($statsQuery, "result", $statsParameters, 120);

		if (true || $statistics == null) {
				// Get the full column name
				$typeID = getColumnType($type);

				$whereClauses[] = "characterID != 0";
				$whereClauses[] = "kills.year = $year";
				$whereClauses[] = "joined.year = $year";
				$whereClauses[] = "kills.week = $week";
				$whereClauses[] = "joined.week = $week";
				// And build the query.
				$query = "select kills.year, kills.week month, joined.groupID,
						sum(if(finalBlow=1,1,0)) kills_num, sum(if(finalBlow = 1,total_price,0)) kills_value,
						sum(if(isVictim='T',1,0)) losses_num,sum(if(isVictim='T',total_price,0)) losses_value ";
				$query .= " from " . implode(", ", $tables);
				$query .= " where " . implode(" and ", $whereClauses);
				if ($type != "all") $query .= " and $typeID = $eveID ";
				$query .= " group by kills.year, kills.week, joined.groupID";

				$stat1 = Db::query($query, $parameters);
				$parameters[":victim"] = $parameters[":victim"] == "F" ? "T" : "F";
				$stat2 = Db::query($query, $parameters);

				$statistics = array();
				statsMerge($statistics, $stat1, "kills_num", "kills_value");
				statsMerge($statistics, $stat2, "losses_num", "losses_value");
				$statistics = array_values($statistics);

				$json = json_encode($statistics);
				Db::execute("replace into {$dbPrefix}cache (eve_id, type, year, month, query, result) values (:eveID, 'stat', :year, :month, :hash, compress(:result))",
								array(":year" => $builtQuery["year"], ":month" => $builtQuery["month"], ":eveID" => $eveID, ":hash" => $hash, ":result" => $json));
				$key = Db::getKey($statsQuery, $statsParameters);
				Memcached::delete($key);
		} else {
				$statistics = json_decode($statistics, true);
		}

		return $statistics;
}

function statsMerge(&$statistics, &$statArray, $col1, $col2)
{
		foreach ($statArray as $stat) {
				$key = $stat['year'] . "|" . $stat['month'] . "|" . $stat['groupID'];
				if (!isset($statistics[$key])) $statistics[$key] = array();
				$statistics[$key]["year"] = $stat["year"];
				$statistics[$key]["month"] = $stat["month"];
				$statistics[$key]["groupID"] = $stat["groupID"];
				$statistics[$key][$col1] = $stat[$col1];
				$statistics[$key][$col2] = $stat[$col2];
		}
}