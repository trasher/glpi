<?php
/*
 
  ----------------------------------------------------------------------
GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2004 by the INDEPNET Development Team.
 
 http://indepnet.net/   http://glpi.indepnet.org
 ----------------------------------------------------------------------
 LICENSE

This file is part of GLPI.

    GLPI is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    GLPI is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with GLPI; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

// ----------------------------------------------------------------------
// Original Author of file: Julien Dombre
// Purpose of file:
// ----------------------------------------------------------------------

include ("_relpos.php");
include ($phproot . "/glpi/includes.php");
include ($phproot . "/glpi/includes_financial.php");

if(isset($_GET)) $tab = $_GET;
if(empty($tab) && isset($_POST)) $tab = $_POST;
if(!isset($tab["ID"])) $tab["ID"] = "";

if (isset($_POST["add"]))
{
	checkAuthentication("admin");

	addEnterprise($_POST);
	logEvent(0, "enterprise", 4, "financial", $_SESSION["glpiname"]." added item ".$_POST["name"].".");
	header("Location: ".$_SERVER['HTTP_REFERER']);
} 
else if (isset($_POST["delete"]))
{
	checkAuthentication("admin");
	deleteEnterprise($_POST);
	logEvent($tab["ID"], "enterprise", 4, "financial", $_SESSION["glpiname"]." deleted item.");
	header("Location: ".$cfg_install["root"]."/enterprises/");
}
else if (isset($_POST["restore"]))
{
	checkAuthentication("admin");
	restoreEnterprise($_POST);
	logEvent($tab["ID"], "enterprise", 4, "financial", $_SESSION["glpiname"]." restored item.");
	header("Location: ".$cfg_install["root"]."/enterprises/");
}
else if (isset($_POST["purge"]))
{
	checkAuthentication("admin");
	deleteEnterprise($_POST,1);
	logEvent($tab["ID"], "enterprise", 4, "financial", $_SESSION["glpiname"]." purge item.");
	header("Location: ".$cfg_install["root"]."/enterprises/");
}
else if (isset($_POST["addcontact"])){
	checkAuthentication("admin");
	addContactEnterprise($_POST["eID"],$_POST["cID"]);
	logEvent($tab["ID"], "enterprise", 4, "financial", $_SESSION["glpiname"]." associate type.");
	header("Location: ".$_SERVER['HTTP_REFERER']);
}
else if (isset($_GET["deletecontact"])){
	checkAuthentication("admin");
	deleteContactEnterprise($_GET["ID"]);
	logEvent($tab["ID"], "enterprise", 4, "financial", $_SESSION["glpiname"]." delete type.");
	header("Location: ".$_SERVER['HTTP_REFERER']);
}
else if (isset($_POST["update"]))
{
	checkAuthentication("admin");
	updateEnterprise($_POST);
	logEvent($_POST["ID"], "enterprise", 4, "financial", $_SESSION["glpiname"]." updated item.");
	header("Location: ".$_SERVER['HTTP_REFERER']);

} 
else
{
	if (empty($tab["ID"]))
	checkAuthentication("admin");
	else checkAuthentication("normal");

	commonHeader($lang["title"][23],$_SERVER["PHP_SELF"]);
	showEnterpriseForm($_SERVER["PHP_SELF"],$tab["ID"]);

	commonFooter();
}

?>
