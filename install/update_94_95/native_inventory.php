<?php
/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2018 Teclib' and contributors.
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

$migration->addConfig(\Glpi\Inventory\Conf::$defaults, 'inventory');

if (!$DB->tableExists('glpi_agenttypes')) {
   $query = "CREATE TABLE `glpi_agenttypes` (
         `id` int(11) NOT NULL AUTO_INCREMENT,
         `name` varchar(255) DEFAULT NULL,
         PRIMARY KEY (`id`),
         KEY `name` (`name`)
      ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
   $DB->queryOrDie($query, "9.5 add table glpi_agenttypes");
   $migration->addPostQuery(
      $DB->buildInsert(
         "glpi_agenttypes",
         [
            'id'           => 1,
            'name'         => 'Core',
         ]
      )
   );
}
if (!$DB->tableExists('glpi_agents')) {
   $query = "CREATE TABLE `glpi_agents` (
         `id` int(11) NOT NULL AUTO_INCREMENT,
         `deviceid` VARCHAR(255) NOT NULL,
         `entities_id` int(11) NOT NULL DEFAULT '0',
         `is_recursive` tinyint(1) NOT NULL DEFAULT '0',
         `name` varchar(255) DEFAULT NULL,
         `agenttypes_id` int(11) NOT NULL,
         `last_contact` timestamp NULL DEFAULT NULL,
         `version` varchar(255) DEFAULT NULL,
         `locked` tinyint(1) NOT NULL DEFAULT '0',
         `itemtype` varchar(100) COLLATE utf8_unicode_ci NOT NULL,
         `items_id` int(11) NOT NULL,
         `useragent` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
         `tag` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
         `port` varchar(6) DEFAULT NULL,
         PRIMARY KEY (`id`),
         KEY `name` (`name`),
         KEY `items_id` (`items_id`),
         KEY `itemtype` (`itemtype`),
         UNIQUE KEY `deviceid` (`deviceid`),
         FOREIGN KEY (agenttypes_id) REFERENCES glpi_agenttypes (id) ON DELETE CASCADE ON UPDATE CASCADE
      ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
   $DB->queryOrDie($query, "9.5 add table glpi_agents");
}

if (!$DB->tableExists('glpi_rulematchedlogs')) {
   $migration->migrationOneTable('glpi_agents');
   $query = "CREATE TABLE `glpi_rulematchedlogs` (
         `id` int(11) NOT NULL AUTO_INCREMENT,
         `date` timestamp NULL DEFAULT NULL,
         `items_id` int(11) NOT NULL DEFAULT '0',
         `itemtype` varchar(100) DEFAULT NULL,
         `rules_id` int(11) NULL DEFAULT NULL,
         `agents_id` int(11) NOT NULL DEFAULT '0',
         `method` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
         PRIMARY KEY (`id`),
         KEY `item` (`itemtype`,`items_id`)
      ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
   $DB->queryOrDie($query, "9.5 add table glpi_rulematchedlogs");
}

//default rules.
RuleImportComputer::initRules(false, false, true);

//locked fields
if (!$DB->tableExists('glpi_lockedfields')) {
   $query = "CREATE TABLE `glpi_lockedfields` (
         `id` int(11) NOT NULL AUTO_INCREMENT,
         `itemtype` varchar(100) DEFAULT NULL,
         `items_id` int(11) NOT NULL DEFAULT '0',
         `field` varchar(50) NOT NULL,
         `value` varchar(255) COLLATE utf8_unicode_ci DEFAULT NULL,
         `date_creation` timestamp NULL DEFAULT NULL,
         PRIMARY KEY (`id`),
         KEY `item` (`itemtype`,`items_id`),
         UNIQUE KEY `unicity` (`itemtype`, `items_id`, `field`)
      ) ENGINE=InnoDB  DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci;";
   $DB->queryOrDie($query, "9.5 add table glpi_lockedfields");
}

if (!$DB->fieldExists('glpi_lockedfields', 'value')) {
   $migration->addField(
      'glpi_lockedfields',
      'value',
      'string'
   );
}

//transfer configuration per entity
if (!$DB->fieldExists('glpi_entities', 'transfers_id')) {
   $migration->addField(
      'glpi_entities',
      'transfers_id',
      'int', [
         'value' => -2,
      ]
   );
   $migration->addKey('glpi_entities', 'transfers_id');
}
$ADDTODISPLAYPREF['Lockedfield'] = [3, 13, 5];
