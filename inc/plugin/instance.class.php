<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2015-2017 Teclib'.

 http://glpi-project.org

 based on GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2014 by the INDEPNET Development Team.

 -------------------------------------------------------------------------

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
 along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 --------------------------------------------------------------------------
 */

namespace Glpi\Plugin;

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

abstract class Instance {
   /**
    * @var string
    * Plugin name (and directory)
    */
   protected $plugin;

   /**
    * @var string
    * Minimal GLPI version
    */
   protected $min_glpi;

   /**
    * @var string
    * Maximal GLPI version
    */
   protected $max_glpi;

   /**
    * @var string[]
    * List of required extensions
    */
   protected $php_extensions = [];

   /**
    * @var string[]
    * List of required functions
    */
   protected $required_functions = [];

   /**
    * @var string[]
    * List of GLPI needed GLPI parameters
    */
   protected $glpi_params = [];

   /**
    * @var string
    * Minimal PHP version (defaults to GLPI minimal PHP version)
    */
   protected $min_php = GLPI_MIN_PHP;

   /**
    * @var string
    * Current installed version (from db)
    */
   protected $version;

   /**
    * @var integer
    * Current state (from db)
    */
   protected $state;

   /**
    * @var string[]
    * Errors
    */
   private $errors;

   /**
    * Main constructor
    *
    * @param string $name Plugin name (and directory)
    */
   final public function __construct($name) {
      $this->plugin = $name;

      $this
         ->setMinGlpi()
         ->setMaxGlpi()
         ->setMinPhp()
         ->setExtensions()
         ->setRequiredFunctions()
         ->setGlpiParameters();

      //check
      if (!$min_glpi) {
         throw new \RuntimeEception('Minimal GLPI version is required!');
      }

      if (!is_array($this->extentions)) {
         throw new \RuntimeEception('Extensions list must be an array!');
      }

      if (!is_array($this->glpi_params)) {
         throw new \RuntimeEception('GLPI parameters list must be an array!');
      }

      if (!is_array($this->required_functions)) {
         throw new \RuntimeEception('Required functions list must be an array!');
      }

   }

   /**
    * Initializes the plugin
    *
    * @return void
    */
   public function init() {
      global $PLUGIN_HOOKS, $LOADED_PLUGINS;

      $PLUGIN_HOOKS['csrf_compliant'][$this->plugin] = $this->isCSRFCompliant();
      $this->registerAutoloader();
      $this->load();

      $LOADED_PLUGINS[$this->plugin] = $this->plugin;
   }

   /**
    * Set minimal GLPI version
    * Designed to populate $this->min_glpi
    *
    * @return Instance
    */
   abstract protected function setMinGlpi();

   /**
    * Set maximal glpi version
    * Designed to populate $this->max_glpi
    *
    * @return Instance
    */
   protected function setMaxGlpi() {
      return $this;
   }

   /**
    * Set minimal PHP version
    * Designed to populate $this->min_php
    *
    * @return Instance
    */
   protected function setMinPhp() {
      return $this;
   }

   /**
    * Set extentions
    * Designed to populate $this->php_extensions
    *
    * @return Instance
    */
   protected function setExtensions() {
      return $this;
   }

   /**
    * Set requird functions
    * Designed to populate $this->required_functions
    *
    * @return Instance
    */
   protected function setRequiredFunctions() {
      return $this;
   }

   /**
    * Set required GLPI parameters
    * Designed to populate $this->glpi_params
    *
    * @return Instance
    */
   protected function setGlpiParameters() {
      return $this;
   }

   /**
    * Plugin's installation
    *
    * @return boolean
    */
   abstract public function install();

   /**
    * Plugin's uninstallation
    *
    * @return boolean
    */
   abstract public function uninstall();

   /**
    * Process checks (PHP and GLPI versions, ...)
    *
    * @return boolean
    */
   public function doChecks() {
      if ($this->min_glpi && $this->max_glpi) {
         if (version_compare(GLPI_VERSION, $this->min_glpi, 'lt')
            || $result = version_compare(GLPI_VERSION, $this->max_glpi, 'ge')
         ) {
            $this->addError(Plugin::messageIncompatible('core', $this->min_glpi, $this->max_glpi));
         }
      } else if ($this->min_glpi) {
         if (version_compare(GLPI_VERSION, $this->min_glpi, 'lt')) {
            $this->addError(Plugin::messageIncompatible('core', $this->min_glpi));
         }
      } else {
         if (version_compare(GLPI_VERSION, $this->max_glpi, 'ge')) {
            $this->addError(Plugin::messageIncompatible('core', $this->max_glpi));
         }
      }

      if ($this->min_php) {
         if (version_compare(PHP_VERSION, $this->min_php, 'lt')) {
            $this->addError(Plugin::messageIncompatible('php', $this->min_glpi));
         }
      }

      if (count($this->php_extensions) > 0) {
         foreach ($this->php_extensions as $ext) {
            if (!extension_loaded($ext)) {
               $this->addError(Plugin::messageMissingRequirement('ext', $ext));
            }
         }
      }

      if (count($this->glpi_params) > 0) {
         global $CFG_GLPI;
         foreach ($this->glpi_params as $param) {
            if (!isset($CFG_GLPI[$param])) {
               $this->addError(Plugin::messageMissingRequirement('param', $param));
            }
         }
      }

      $this->doMoreChecks();

      return $this->hasError();
   }

   /**
    * More checks from plugin itself.
    * Uses Instance::addError() when error are spotted,
    *
    * @return void
    */
   protected function doMoreChecks() {
      return;
   }

   /**
    * Adds an error in the stack
    *
    * @param string $error Error message
    *
    * @return void
    */
   protected function addError($error) {
      $this->errors[] = $error;
   }

   /**
    * Do we had errors?
    *
    * @return boolean
    */
   public function hasError() {
      return count($this->errors) > 0;
   }

   /**
    * Is plugin CSRF compliant
    *
    * @return boolean
    */
   public function isCSRFCompliant() {
      return true;
   }

   /**
    * Register plugin autoloade,r if any
    *
    * @return void
    */
   protected function registerAutoloader() {
      $path = GLPI_ROOT . "/plugins/{$this->plugin}/inc/autoloader.php";
      if (file_exists($path)) {
         include_once($path);
         $class = "GlpiPlugin\\" . ucwords($this->plugin) . "Autoloader";
         $autoloader = new $class($this->getAutoloaderOptions());
         $autoloader->register();
      }
   }

   /**
    * Optionnal autoloader options to set while registering.
    *
    * @return mixed
    */
   protected function getAutoloaderOptions() {
      return null;
   }

   /**
    * Load plugin from database
    *
    * @return void
    */
   protected function load() {
      $plugin = new \Plugin();
      $plugin->getFromDBbyDir($this->plugin);
      $this->version = $plugin->getField('version');
      $this->state   = $plugin->getField('state');
   }

   /**
    * Is plugin currenty installed?
    *
    * @return boolean
    */
   public function isInstalled() {
      return (($this->state == \Plugin::ACTIVATED)
         || ($this->state == \Plugin::TOBECONFIGURED)
         || ($this->state == \Plugin::NOTACTIVATED));
   }

   /**
    * Is plugin currenty active?
    *
    * @return boolean
    */
   public function isActive() {
      return $this->state == \Plugin::ACTIVATED;
   }

   /**
    * Register aditional asset
    *
    * @param string $type Asset type. Either "css" or "js"
    * @param string $path Asset path
    *
    * @return void
    */
   protected function addAsset($type, $path) {
      global $PLUGIN_HOOKS;

      $hook = null;
      switch ($type) {
         case 'css':
            $hook = 'add_css';
            break;
         case 'js':
            $hook = 'add_javascript';
            break;
         default:
            throw new \RuntimeEception("Unknown asset type $type!");
            break;
      }
      $PLUGIN_HOOKS[$hook][$this->plugin][] = $path;
   }

   /**
    * Register multiple aditional assets at once
    *
    * @param string $type  Asset type. Either "css" or "js"
    * @param array  $paths Assets paths
    *
    * @return void
    */
   protected function addAssets($type, array $paths) {
      foreach ($paths as $path) {
         $this->addAsset($type, $path);
      }
   }
}
