<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
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
 * Update to native inventory
 *
 * @return bool for success (will die for most error)
**/
function updatenativeinv() {
   global $CFG_GLPI, $DB, $migration;

   $updateresult     = true;
   $ADDTODISPLAYPREF = [];
   $update_dir = __DIR__ . '/update_9.5.x_to_10.0.0/';

   //TRANS: %s is the number of new version
   $migration->displayTitle(sprintf(__('Update to %s'), '9.5.7-natinv'));
   $migration->setVersion('9.5.7');

   $update_scripts = scandir($update_dir);
   foreach ($update_scripts as $update_script) {
      if (preg_match('/\.php$/', $update_script) !== 1) {
         continue;
      }
      require $update_dir . $update_script;
   }

   // ************ Keep it at the end **************
    foreach ($ADDTODISPLAYPREF as $type => $tab) {
        $rank = 1;
        foreach ($tab as $newval) {
            $DB->updateOrInsert(
                "glpi_displaypreferences",
                [
                    'rank'      => $rank++
                ],
                Toolbox::addslashes_deep(
                    [
                        'users_id'  => "0",
                        'itemtype'  => $type,
                        'num'       => $newval,
                    ]
                )
            );
        }
    }

   $migration->executeMigration();

   return $updateresult;
}
