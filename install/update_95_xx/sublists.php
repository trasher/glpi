<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2021 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */
/**
 * @var DB $DB
 * @var Migration $migration
 * @var array $ADDTODISPLAYPREF
 */


/** Add main column on displaypreferences */
if ($migration->addField(
      'glpi_displaypreferences',
      'is_main',
      'bool',
      ['value' => 1]
   )) {
   $migration->addKey('glpi_displaypreferences', 'is_main');
   $migration->dropKey('glpi_displaypreferences', 'unicity');
   $migration->migrationOneTable('glpi_displaypreferences');
   $migration->addKey(
      'glpi_displaypreferences',
      ['users_id', 'itemtype', 'num', 'is_main'],
      'unicity',
      'UNIQUE'
   );
}
/** /Add main column on displaypreferences */

/** add display preferences for sub items */
$ADDTODISPLAYPREF['Contract'] = [3, 4, 29, 5];
$ADDTODISPLAYPREF['Item_Disk'] = [2, 3, 4, 5, 6, 7, 8];
$ADDTODISPLAYPREF['Certificate'] = [7, 4, 8, 121, 10, 31];
$ADDTODISPLAYPREF['Notepad'] = [200, 201, 202, 203, 204];
$ADDTODISPLAYPREF['SoftwareVersion'] = [3, 31, 2, 122, 123, 124];
$ADDTODISPLAYPREF['ComputerVirtualMachine'] = [1, 6, 7, 5, 2, 3, 4, 8];
$ADDTODISPLAYPREF['NetworkPort'] = [3, 30, 31, 32, 33, 34, 35, 36, 38, 39, 40];
foreach ($ADDTODISPLAYPREF as $type => $tab) {
   $rank = 1;
   foreach ($tab as $newval) {
      $query = "REPLACE INTO `glpi_displaypreferences`
                        (`itemtype` ,`num` ,`rank` ,`users_id`, `is_main`)
                  VALUES ('$type', '$newval', '".$rank++."', '0', '0')";
      $DB->query($query);
   }
}
/** /add display preferences for sub items */
