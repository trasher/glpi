<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2017 Teclib' and contributors.
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
 * Update from 9.2 to 9.3
 *
 * @return bool for success (will die for most error)
**/
function update92to93() {
   global $DB, $migration, $CFG_GLPI;

   $current_config   = Config::getConfigurationValues('core');
   $updateresult     = true;
   $ADDTODISPLAYPREF = [];

   //TRANS: %s is the number of new version
   $migration->displayTitle(sprintf(__('Update to %s'), '9.3'));
   $migration->setVersion('9.3');

   /** Datacenters */
   if (!$DB->tableExists('glpi_datacenters')) {
      $query = "CREATE TABLE `glpi_datacenters` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `entities_id` int(11) NOT NULL DEFAULT '0',
                  `is_recursive` tinyint(1) NOT NULL DEFAULT '0',
                  `locations_id` int(11) NOT NULL DEFAULT '0',
                  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
                  `date_mod` datetime DEFAULT NULL,
                  `date_creation` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `entities_id` (`entities_id`),
                  KEY `is_recursive` (`is_recursive`),
                  KEY `locations_id` (`locations_id`),
                  KEY `is_deleted` (`is_deleted`)
                  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "9.3 add table glpi_datacenters");
   }

   if (!$DB->tableExists('glpi_dcrooms')) {
      $query = "CREATE TABLE `glpi_dcrooms` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `entities_id` int(11) NOT NULL DEFAULT '0',
                  `is_recursive` tinyint(1) NOT NULL DEFAULT '0',
                  `locations_id` int(11) NOT NULL DEFAULT '0',
                  `vis_cols` int(11) DEFAULT NULL,
                  `vis_rows` int(11) DEFAULT NULL,
                  `datacenters_id` int(11) NOT NULL DEFAULT '0',
                  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
                  `date_mod` datetime DEFAULT NULL,
                  `date_creation` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `entities_id` (`entities_id`),
                  KEY `is_recursive` (`is_recursive`),
                  KEY `locations_id` (`locations_id`),
                  KEY `datacenters_id` (`datacenters_id`),
                  KEY `is_deleted` (`is_deleted`)
                  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "9.3 add table glpi_dcrooms");
   }

   if (!$DB->tableExists('glpi_rackmodels')) {
      $query = "CREATE TABLE `glpi_rackmodels` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `comment` text COLLATE utf8_unicode_ci,
                  `product_number` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `date_mod` datetime DEFAULT NULL,
                  `date_creation` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `name` (`name`),
                  KEY `product_number` (`product_number`)
                  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "9.3 add table glpi_rackmodels");
   }

   if (!$DB->tableExists('glpi_racktypes')) {
      $query = "CREATE TABLE `glpi_racktypes` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `entities_id` int(11) NOT NULL DEFAULT '0',
                  `is_recursive` tinyint(1) NOT NULL DEFAULT '0',
                  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `comment` text COLLATE utf8_unicode_ci,
                  `date_creation` datetime DEFAULT NULL,
                  `date_mod` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `entities_id` (`entities_id`),
                  KEY `is_recursive` (`is_recursive`),
                  KEY `name` (`name`),
                  KEY `date_creation` (`date_creation`),
                  KEY `date_mod` (`date_mod`)
                  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
      $DB->queryOrDie($query, "9.3 add table glpi_racktypes");
   }

   if (!$DB->tableExists('glpi_racks')) {
      $query = "CREATE TABLE `glpi_racks` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `entities_id` int(11) NOT NULL DEFAULT '0',
                  `is_recursive` tinyint(1) NOT NULL DEFAULT '0',
                  `locations_id` int(11) NOT NULL DEFAULT '0',
                  `serial` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `otherserial` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `rackmodels_id` int(11) DEFAULT NULL,
                  `manufacturers_id` int(11) NOT NULL DEFAULT '0',
                  `racktypes_id` int(11) NOT NULL DEFAULT '0',
                  `states_id` int(11) NOT NULL DEFAULT '0',
                  `users_id_tech` int(11) NOT NULL DEFAULT '0',
                  `groups_id_tech` int(11) NOT NULL DEFAULT '0',
                  `width` int(11) DEFAULT NULL,
                  `height` int(11) DEFAULT NULL,
                  `depth` int(11) DEFAULT NULL,
                  `number_units` int(11) DEFAULT '0',
                  `is_template` tinyint(1) NOT NULL DEFAULT '0',
                  `template_name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
                  `dcrooms_id` int(11) NOT NULL DEFAULT '0',
                  `position` varchar(50),
                  `bgcolor` varchar(7) DEFAULT NULL,
                  `max_power` int(11) NOT NULL DEFAULT '0',
                  `mesured_power` int(11) NOT NULL DEFAULT '0',
                  `max_weight` int(11) NOT NULL DEFAULT '0',
                  `date_mod` datetime DEFAULT NULL,
                  `date_creation` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  KEY `entities_id` (`entities_id`),
                  KEY `is_recursive` (`is_recursive`),
                  KEY `locations_id` (`locations_id`),
                  KEY `rackmodels_id` (`rackmodels_id`),
                  KEY `manufacturers_id` (`manufacturers_id`),
                  KEY `racktypes_id` (`racktypes_id`),
                  KEY `states_id` (`states_id`),
                  KEY `users_id_tech` (`users_id_tech`),
                  KEY `group_id_tech` (`groups_id_tech`),
                  KEY `is_template` (`is_template`),
                  KEY `is_deleted` (`is_deleted`),
                  KEY `dcrooms_id` (`dcrooms_id`)
                  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "9.3 add table glpi_racks");
   }

   if (!$DB->tableExists('glpi_items_racks')) {
      $query = "CREATE TABLE `glpi_items_racks` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `racks_id` int(11) NOT NULL,
                  `itemtype` varchar(255) COLLATE utf8_unicode_ci NOT NULL,
                  `items_id` int(11) NOT NULL,
                  `position` int(11) NOT NULL,
                  `orientation` tinyint(1),
                  `bgcolor` varchar(7) DEFAULT NULL,
                  `hpos` tinyint(1) NOT NULL DEFAULT '0',
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `item` (`itemtype`,`items_id`),
                  KEY `relation` (`racks_id`,`itemtype`,`items_id`)
                  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci";
      $DB->queryOrDie($query, "9.3 add table glpi_items_racks");
   }

   if (countElementsInTable("glpi_profilerights", "`name` = 'datacenter'") == 0) {
      //new right for datacenters
      //give full rights to profiles having config right
      foreach ($DB->request("glpi_profilerights", "`name` = 'config'") as $profrights) {
         if ($profrights['rights'] && (READ + UPDATE)) {
            $rightValue = CREATE | READ | UPDATE | DELETE  | PURGE | READNOTE | UPDATENOTE | UNLOCK;
         } else {
            $rightValue = 0;
         }
         $query = "INSERT INTO `glpi_profilerights`
                          (`id`, `profiles_id`, `name`, `rights`)
                   VALUES (NULL, '".$profrights['profiles_id']."', 'datacenter',
                           '".$rightValue."')";
         $DB->queryOrDie($query, "9.1 add right for datacenter");
      }
   }

   //devices models enhancement for datacenters
   $models = [
      'computer',
      'monitor',
      'networkequipment',
      'peripheral'
   ];

   $models_fields = [
      [
         'name'   => 'weight',
         'type'   => "int(11) NOT NULL DEFAULT '0'"
      ], [
         'name'   => 'required_units',
         'type'   => "int(11) NOT NULL DEFAULT '0'"
      ], [
         'name'   => 'depth',
         'type'   => "float NOT NULL DEFAULT 0"
      ], [
         'name'   => 'power_connections',
         'type'   => "int(11) NOT NULL DEFAULT '0'"
      ], [
         'name'   => 'power_consumption',
         'type'   => "int(11) NOT NULL DEFAULT '0'"
      ], [
         'name'   => 'is_half_rack',
         'type'   => "tinyint(1) NOT NULL DEFAULT '0'"
      ]
   ];

   foreach ($models as $model) {
      $table = "glpi_{$model}models";
      foreach ($models_fields as $field) {
         if (!$DB->fieldExists($table, $field['name'])) {
            $migration->addField(
               $table,
               $field['name'],
               $field['type'],
               ['after' => 'product_number']
            );
         }
      }
   }

   /** /Datacenters */

   if (!$DB->tableExists('glpi_assettypes')) {
      $query = "CREATE TABLE `glpi_assettypes` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `name` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
                  `comment` text COLLATE utf8_unicode_ci,
                  `is_rackable` tinyint(1) NOT NULL DEFAULT '0',
                  `date_mod` datetime DEFAULT NULL,
                  `date_creation` datetime DEFAULT NULL,
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `name` (`name`),
                  KEY `date_mod` (`date_mod`),
                  KEY `date_creation` (`date_creation`)
                  ) ENGINE=MyISAM DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
      $DB->queryOrDie($query, "9.3 add table glpi_assettypes");
   }

   $assettypes = ['Computer' => '1'];
   foreach ($assettypes as $assettype => $rack) {
      $migration->addPostQuery(
         "INSERT INTO `glpi_assettypes` (`name`, `is_rackable`) VALUES ('$assettype', '$rack')
            ON DUPLICATE KEY UPDATE `name`='$assettype'"
      );
   }

   if (!$DB->tableExists('glpi_assets')) {
      $migration->renameTable('glpi_computers', 'glpi_assets');
      $migration->migrationOneTable('glpi_computers');
   } else if ($DB->tableExists('glpi_computers')) {
      die('Your database is partially migrated. Please drop it, restore a backup, and migrate again.²');
   }

   if (!$DB->fieldExists('glpi_assets', 'assettypes_id')) {
      $migration->addField("glpi_assets", "assettypes_id", "integer");
      $migration->migrationOneTable('glpi_assets');
      $migration->addKey("glpi_assets", "assettypes_id");

      $migration->addPostQuery(
         "UPDATE `glpi_assets` SET `assettypes_id`=
            (SELECT `id` FROM `glpi_assettypes` WHERE `name`='Computer')"
      );
   }

   // ************ Keep it at the end **************
   $migration->executeMigration();

   return $updateresult;
}
