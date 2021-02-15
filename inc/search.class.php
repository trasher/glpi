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

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access this file directly");
}

/**
 * Search Class
 *
 * Generic class for Search Engine
**/
class Search {

   // Default number of items displayed in global search
   const GLOBAL_DISPLAY_COUNT = 10;
   // EXPORT TYPE
   const GLOBAL_SEARCH        = -1;
   const HTML_OUTPUT          = 0;
   const SYLK_OUTPUT          = 1;
   const PDF_OUTPUT_LANDSCAPE = 2;
   const CSV_OUTPUT           = 3;
   const PDF_OUTPUT_PORTRAIT  = 4;

   const LBBR = '#LBBR#';
   const LBHR = '#LBHR#';

   const SHORTSEP = '$#$';
   const LONGSEP  = '$$##$$';

   const NULLVALUE = '__NULL__';

   private $output_type = self::HTML_OUTPUT;
   static $search = [];

   private $db;
   private $item;
   private $itemtype;
   private $raw_params;
   private $sub_item = false;

   /**
    * Constructor
    *
    * @param CommonDBTM|null $item   Item instance
    * @param array           $params Search parameters
    */
   public function __construct($item, array $params) {
      global $DB;
      $this->db = $DB;
      if ($item instanceof CommonDBTM) {
         $this->item = $item;
         $this->itemtype = $item->getType();
      } else {
         $this->itemtype = $item;
         if ($item !== 'AllAssets') {
            $this->item = new $item;
         }
      }
      $this->raw_params = $params;
   }

   /**
    * Display search engine for an type
    *
    * @param string  $itemtype Item type to manage
    *
    * @return void
   **/
   public static function show($itemtype) {
      $params = self::manageParams($itemtype, $_GET);
      $item = $itemtype == 'AllAssets' ? $itemtype : new $itemtype;
      $inst = new self($item, $params);
      echo "<div class='search_page'>";
      $inst->showGenericSearch($itemtype, $params);
      if ($params['as_map'] == 1) {
         $inst->showMap($itemtype, $params);
      } else {
         $inst->showList($itemtype, $params);
      }
      echo "</div>";
   }

   /**
    * Display result table for search engine for an type
    *
    * @param string $itemtype Item type to manage
    * @param array  $params   Search params passed to prepareDatasForSearch function
    * @param array  $data     data if already processed
    *
    * @return void
    *
    * @since 10.0.0 Method is no longer static
    * @since 10.0.0 Added $data parameter
   **/
   public function showList($itemtype, $params, $data = []) {
      if (empty($data)) {
         $data = $this->prepareDataForSearch($itemtype, $params);
         $this->constructSQL($data);
         $this->constructData($data);
      }
      $this->displayData($data);
   }

   /**
    * Display result table for search engine for an type as a map
    *
    * @param string $itemtype Item type to manage
    * @param array  $params   Search params passed to prepareDatasForSearch function
    * @param array  $data     data if already processed
    *
    * @return void
    *
    * @since 10.0.0 Method is no longer static
    * @since 10.0.0 Added $data parameter
   **/
   public function showMap($itemtype, $params, $data = []) {
      global $CFG_GLPI;

      if ($itemtype == 'Location') {
         $latitude = 21;
         $longitude = 20;
      } else if ($itemtype == 'Entity') {
         $latitude = 67;
         $longitude = 68;
      } else {
         $latitude = 998;
         $longitude = 999;
      }

      if (empty($data)) {
         $params['criteria'][] = [
            'link'         => 'AND NOT',
            'field'        => $latitude,
            'searchtype'   => 'contains',
            'value'        => 'NULL'
         ];
         $params['criteria'][] = [
            'link'         => 'AND NOT',
            'field'        => $longitude,
            'searchtype'   => 'contains',
            'value'        => 'NULL'
         ];
         $data = $this->prepareDataForSearch($itemtype, $params);
         $this->constructSQL($data);
         $this->constructData($data);
      }
      $this->displayData($data);

      if ($data['data']['totalcount'] > 0) {
         $target = $data['search']['target'];
         $criteria = $data['search']['criteria'];
         array_pop($criteria);
         array_pop($criteria);
         $criteria[] = [
            'link'         => 'AND',
            'field'        => ($itemtype == 'Location' || $itemtype == 'Entity') ? 1 : (($itemtype == 'Ticket') ? 83 : 3),
            'searchtype'   => 'equals',
            'value'        => 'CURLOCATION'
         ];
         $globallinkto = Toolbox::append_params(
            [
               'criteria'     => Toolbox::stripslashes_deep($criteria),
               'metacriteria' => Toolbox::stripslashes_deep($data['search']['metacriteria'])
            ],
            '&amp;'
         );
         $parameters = "as_map=0&amp;sort=".$data['search']['sort']."&amp;order=".$data['search']['order'].'&amp;'.
                        $globallinkto;

         if (strpos($target, '?') == false) {
            $fulltarget = $target."?".$parameters;
         } else {
            $fulltarget = $target."&".$parameters;
         }
         $typename = class_exists($itemtype) ? $itemtype::getTypeName($data['data']['totalcount']) :
                        ($itemtype == 'AllAssets' ? __('assets') : $itemtype);

         echo "<div class='center'><p>".__('Search results for localized items only')."</p>";
         $js = "$(function() {
               var map = initMap($('#page'), 'map', 'full');
               _loadMap(map, '$itemtype');
            });

         var _loadMap = function(map_elt, itemtype) {
            L.AwesomeMarkers.Icon.prototype.options.prefix = 'far';
            var _micon = 'circle';

            var stdMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'blue'
            });

            var aMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'cadetblue'
            });

            var bMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'purple'
            });

            var cMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'darkpurple'
            });

            var dMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'red'
            });

            var eMarker = L.AwesomeMarkers.icon({
               icon: _micon,
               markerColor: 'darkred'
            });


            //retrieve geojson data
            map_elt.spin(true);
            $.ajax({
               dataType: 'json',
               method: 'POST',
               url: '{$CFG_GLPI['root_doc']}/ajax/map.php',
               data: {
                  itemtype: itemtype,
                  params: ".json_encode($params)."
               }
            }).done(function(data) {
               var _points = data.points;
               var _markers = L.markerClusterGroup({
                  iconCreateFunction: function(cluster) {
                     var childCount = cluster.getChildCount();

                     var markers = cluster.getAllChildMarkers();
                     var n = 0;
                     for (var i = 0; i < markers.length; i++) {
                        n += markers[i].count;
                     }

                     var c = ' marker-cluster-';
                     if (n < 10) {
                        c += 'small';
                     } else if (n < 100) {
                        c += 'medium';
                     } else {
                        c += 'large';
                     }

                     return new L.DivIcon({ html: '<div><span>' + n + '</span></div>', className: 'marker-cluster' + c, iconSize: new L.Point(40, 40) });
                  }
               });

               $.each(_points, function(index, point) {
                  var _title = '<strong>' + point.title + '</strong><br/><a href=\''+'$fulltarget'.replace(/CURLOCATION/, point.loc_id)+'\'>".sprintf(__('%1$s %2$s'), 'COUNT', $typename)."'.replace(/COUNT/, point.count)+'</a>';
                  if (point.types) {
                     $.each(point.types, function(tindex, type) {
                        _title += '<br/>".sprintf(__('%1$s %2$s'), 'COUNT', 'TYPE')."'.replace(/COUNT/, type.count).replace(/TYPE/, type.name);
                     });
                  }
                  var _icon = stdMarker;
                  if (point.count < 10) {
                     _icon = stdMarker;
                  } else if (point.count < 100) {
                     _icon = aMarker;
                  } else if (point.count < 1000) {
                     _icon = bMarker;
                  } else if (point.count < 5000) {
                     _icon = cMarker;
                  } else if (point.count < 10000) {
                     _icon = dMarker;
                  } else {
                     _icon = eMarker;
                  }
                  var _marker = L.marker([point.lat, point.lng], { icon: _icon, title: point.title });
                  _marker.count = point.count;
                  _marker.bindPopup(_title);
                  _markers.addLayer(_marker);
               });

               map_elt.addLayer(_markers);
               map_elt.fitBounds(
                  _markers.getBounds(), {
                     padding: [50, 50],
                     maxZoom: 12
                  }
               );
            }).fail(function (response) {
               var _data = response.responseJSON;
               var _message = '".__s('An error occured loading data :(')."';
               if (_data.message) {
                  _message = _data.message;
               }
               var fail_info = L.control();
               fail_info.onAdd = function (map) {
                  this._div = L.DomUtil.create('div', 'fail_info');
                  this._div.innerHTML = _message + '<br/><span id=\'reload_data\'><i class=\'fa fa-sync\'></i> ".__s('Reload')."</span>';
                  return this._div;
               };
               fail_info.addTo(map_elt);
               $('#reload_data').on('click', function() {
                  $('.fail_info').remove();
                  _loadMap(map_elt);
               });
            }).always(function() {
               //hide spinner
               map_elt.spin(false);
            });
         }

         ";
         echo Html::scriptBlock($js);
         echo "</div>";
      }
   }

   /**
    * Get data
    *
    * @param array|false $sub_item Are we looking for a sub item?
    *
    * @return array
    */
   public function getData($sub_item = false) {
      $this->sub_item = $sub_item;
      $itemtype = ($this->item instanceof CommonDBTM ? $this->item->getType() : 'AllAssets');
      $params = self::manageParams($itemtype, $this->raw_params);
      if ($params['as_map'] == 1) {
         $params['criteria'][] = [
            'link'         => 'AND NOT',
            'field'        => ($itemtype == 'Location') ? 21 : 998,
            'searchtype'   => 'contains',
            'value'        => 'NULL'
         ];
         $params['criteria'][] = [
            'link'         => 'AND NOT',
            'field'        => ($itemtype == 'Location') ? 20 : 999,
            'searchtype'   => 'contains',
            'value'        => 'NULL'
         ];
      }

      $data = $this->prepareDataForSearch($itemtype, $params, $this->sub_item);
      $this->constructSQL($data, $this->sub_item);
      $this->constructData($data);

      if (!isset($data['data']['totalcount'])) {
         Toolbox::logError("Something went wrong during $itemtype search");
         return;
      }

      return $data;
   }

   /**
    * Prepare search criteria to be used for a search
    *
    * @since 0.85
    *
    * @param string  $itemtype      Item type
    * @param array   $params        Array of parameters
    *                               may include sort, order, start, list_limit, deleted, criteria, metacriteria
    * @param array   $forcedisplay  Array of columns to display (default empty = empty use display pref and search criterias)
    * @param boolean $sub_item      Are we looking for a sub item?
    *
    * @return array prepare to be used for a search (include criteria and others needed information)
    *
    * @since 10.0.0 Method has been renamed is no longer static, $forcedisplay has been removed
   **/
   public function prepareDataForSearch($itemtype, array $params, $sub_item = false) {
      global $CFG_GLPI;

      // Default values of parameters
      $p['criteria']            = [];
      $p['metacriteria']        = [];
      $p['sort']                = '1'; //
      $p['order']               = 'ASC';//
      $p['start']               = 0;//
      $p['is_deleted']          = 0;
      $p['export_all']          = 0;
      if (class_exists($itemtype)) {
         $p['target']       = $itemtype::getSearchURL();
      } else {
         $p['target']       = Toolbox::getItemTypeSearchURL($itemtype);
      }
      $p['display_type']        = self::HTML_OUTPUT;
      $p['showmassiveactions']  = true;
      $p['dont_flush']          = false;
      $p['show_pager']          = true;
      $p['show_footer']         = true;
      $p['no_sort']             = false;
      $p['list_limit']          = $_SESSION['glpilist_limit'];
      $p['massiveactionparams'] = [];
      $p['forcedisplay']        = [];

      foreach ($params as $key => $val) {
         switch ($key) {
            case 'order':
               if (in_array($val, ['ASC', 'DESC'])) {
                  $p[$key] = $val;
               }
               break;
            case 'sort':
               $p[$key] = intval($val);
               if ($p[$key] < 0) {
                  $p[$key] = 1;
               }
               break;
            case 'is_deleted':
               if ($val == 1) {
                  $p[$key] = '1';
               }
               break;
            default:
               $p[$key] = $val;
               break;
         }
      }

      // Set display type for export if define
      if (isset($p['display_type'])) {
         // Limit to 10 element
         if ($p['display_type'] == self::GLOBAL_SEARCH) {
            $p['list_limit'] = self::GLOBAL_DISPLAY_COUNT;
         }
      }

      if ($p['export_all']) {
         $p['start'] = 0;
      }

      $data             = [];
      $data['search']   = $p;
      $data['real_itemtype'] = $itemtype;
      if ($sub_item && $sub_item['sub_item'] instanceof CommonDBRelation && $sub_item['sub_item']->getType() == $itemtype) {
         $itemtype = 'AllAssets';
      }
      $data['itemtype'] = $itemtype;

      // Instanciate an object to access method
      $data['item'] = null;

      if ($itemtype != 'AllAssets') {
         $data['item'] = getItemForItemtype($itemtype);
      }

      $data['display_type'] = $data['search']['display_type'];

      if (!$CFG_GLPI['allow_search_all']) {
         foreach ($p['criteria'] as $val) {
            if (isset($val['field']) && $val['field'] == 'all') {
               Html::displayRightError();
            }
         }
      }
      if (!$CFG_GLPI['allow_search_view']) {
         foreach ($p['criteria'] as $val) {
            if (isset($val['field']) && $val['field'] == 'view') {
               Html::displayRightError();
            }
         }
      }

      /// Get the items to display
      // Add searched items

      $forcetoview = false;
      if (is_array($p['forcedisplay']) && count($p['forcedisplay'])) {
         $forcetoview = true;
      }
      $data['search']['all_search']  = false;
      $data['search']['view_search'] = false;
      // If no research limit research to display item and compute number of item using simple request
      $data['search']['no_search']   = true;

      $data['toview'] = $this->addDefaultToView($itemtype, $params);
      $data['meta_toview'] = [];
      if (!$forcetoview) {
         // Add items to display depending of personal prefs
         $pref_itemtype = $itemtype;
         if ($sub_item && $sub_item['sub_item'] instanceof CommonDBRelation && $sub_item['sub_item']->getType() == $itemtype) {
            $pref_itemtype = 'AllAssets';
         }
         $displaypref = DisplayPreference::getForTypeUser(
            $pref_itemtype,
            Session::getLoginUserID(),
            ($sub_item !== false && count($sub_item))
         );
         if (count($displaypref)) {
            foreach ($displaypref as $val) {
               array_push($data['toview'], $val);
            }
         }
      } else {
         $data['toview'] = array_merge($data['toview'], $p['forcedisplay']);
      }

      if (count($p['criteria']) > 0) {
         // use a recursive closure to push searchoption when using nested criteria
         $parse_criteria = function($criteria) use (&$parse_criteria, &$data) {
            foreach ($criteria as $criterion) {
               // recursive call
               if (isset($criterion['criteria'])) {
                  $parse_criteria($criterion['criteria']);
               } else {
                  // normal behavior
                  if (isset($criterion['field'])
                     && !in_array($criterion['field'], $data['toview'])) {
                     if ($criterion['field'] != 'all'
                        && $criterion['field'] != 'view'
                        && (!isset($criterion['meta'])
                           || !$criterion['meta'])) {
                        array_push($data['toview'], $criterion['field']);
                     } else if ($criterion['field'] == 'all') {
                        $data['search']['all_search'] = true;
                     } else if ($criterion['field'] == 'view') {
                        $data['search']['view_search'] = true;
                     }
                  }

                  if (isset($criterion['value'])
                     && (strlen($criterion['value']) > 0)) {
                     $data['search']['no_search'] = false;
                  }
               }
            }
         };

         // call the closure
         $parse_criteria($p['criteria']);
      }

      if (count($p['metacriteria'])) {
         $data['search']['no_search'] = false;
      }

      // Add order item
      if (!in_array($p['sort'], $data['toview'])) {
         array_push($data['toview'], $p['sort']);
      }

      // Special case for Ticket : put ID in front
      if ($itemtype == 'Ticket') {
         array_unshift($data['toview'], 2);
      }

      $limitsearchopt   = $this->getCleanedOptions($this->itemtype);
      // Clean and reorder toview
      $tmpview = [];
      foreach ($data['toview'] as $val) {
         if (isset($limitsearchopt[$val]) && !in_array($val, $tmpview)) {
            $tmpview[] = $val;
         }
      }
      $data['toview']    = $tmpview;
      $data['tocompute'] = $data['toview'];

      // Force item to display
      if ($forcetoview) {
         foreach ($data['toview'] as $val) {
            if (!in_array($val, $data['tocompute'])) {
                array_push($data['tocompute'], $val);
            }
         }
      }

      if (!count($data['toview'])) {
         Toolbox::logWarning(
            'No columns found to display ' . $itemtype
         );
      }

      return $data;
   }


   /**
    * Construct SQL request depending of search parameters
    *
    * Add to data array a field sql containing an array of requests :
    *      search : request to get items limited to wanted ones
    *      count : to count all items based on search criterias
    *                    may be an array a request : need to add counts
    *                    maybe empty : use search one to count
    *
    * @since 0.85
    *
    * @param array $data  Array of search datas prepared to generate SQL
    * @param CommonDBTM|false $sub_item Are we calling from a sub item?
    *
    * @return void
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function constructSQL(array &$data, $sub_item = false) {
      global $CFG_GLPI;

      if (!isset($data['itemtype'])) {
         return false;
      }

      $data['sql']['count']  = [];
      $data['sql']['search'] = '';

      $searchopt        = &$this->getOptions($data['itemtype']);

      $blacklist_tables = [];
      $orig_table = self::getOrigTableName($data['itemtype']);
      if (isset($CFG_GLPI['union_search_type'][$data['itemtype']])) {
         $itemtable          = $CFG_GLPI['union_search_type'][$data['itemtype']];
         $blacklist_tables[] = $orig_table;
      } else {
         $itemtable = $orig_table;
      }

      // hack for AllAssets
      if (isset($CFG_GLPI['union_search_type'][$data['itemtype']])) {
         $entity_restrict = true;
      } else {
         $entity_restrict = $data['item']->isEntityAssign() && $data['item']->isField('entities_id');
      }

      // Construct the request

      //// 1 - SELECT
      // request currentuser for SQL supervision, not displayed
      $SELECT = "SELECT DISTINCT ".$this->db->quoteName("$itemtable.id")." AS id, ".$this->db->quote($_SESSION['glpiname'])." AS currentuser,
                        ".$this->addDefaultSelect($data['itemtype']);

      // Add select for all toview item
      foreach ($data['toview'] as $val) {
         $SELECT .= $this->addSelect($data['itemtype'], $val);
      }

      if (isset($data['search']['as_map']) && $data['search']['as_map'] == 1 && $data['itemtype'] != 'Entity') {
         $SELECT .= ' '.$this->db->quoteName('glpi_locations.id').' AS loc_id, ';
      }

      //// 2 - FROM AND LEFT JOIN
      // Set reference table
      $FROM = " FROM " . $this->db->quoteName($itemtable);

      // Init already linked tables array in order not to link a table several times
      $already_link_tables = [];
      // Put reference table
      array_push($already_link_tables, $itemtable);

      // Add default join
      $COMMONLEFTJOIN = $this->addDefaultJoin($data['itemtype'], $itemtable, $already_link_tables);

      $itemtype = $data['itemtype'];
      $COMMONSUBLEFTJOIN = '';
      if ($sub_item !== false && method_exists($sub_item['sub_item'], 'addSubDefaultJoin') && $sub_item['sub_item']->getType() !== $itemtype) {
         $sub = $sub_item['sub_item'];
         $COMMONSUBLEFTJOIN = $sub::addSubDefaultJoin($sub_item['item'], $itemtype);
      }

      $FROM          .= $COMMONLEFTJOIN;

      // Add all table for toview items
      foreach ($data['tocompute'] as $val) {
         if (!in_array($searchopt[$val]["table"], $blacklist_tables)) {
            $FROM .= $this->addLeftJoin($data['itemtype'], $itemtable, $already_link_tables,
                                       $searchopt[$val]["table"],
                                       $searchopt[$val]["linkfield"], 0, 0,
                                       $searchopt[$val]["joinparams"],
                                       $searchopt[$val]["field"]);
         }
      }

      // Search all case :
      if ($data['search']['all_search']) {
         foreach ($searchopt as $key => $val) {
            // Do not search on Group Name
            if (is_array($val) && isset($val['table'])) {
               if (!in_array($searchopt[$key]["table"], $blacklist_tables)) {
                  $FROM .= $this->addLeftJoin($data['itemtype'], $itemtable, $already_link_tables,
                                             $searchopt[$key]["table"],
                                             $searchopt[$key]["linkfield"], 0, 0,
                                             $searchopt[$key]["joinparams"],
                                             $searchopt[$key]["field"]);
               }
            }
         }
      }

      //// 3 - WHERE

      // default string
      $COMMONWHERE = '';
      if ($sub_item !== false && method_exists($sub_item['sub_item'], 'addSubDefaultWhere')) {
         $sub = $sub_item['sub_item'];
         $COMMONSUBWHERE = $sub::addSubDefaultWhere(
            $sub_item['item'],
            $sub_item['sub_item']->getType() == $data['real_itemtype']
         );
      } else {
         $COMMONWHERE = self::addDefaultWhere($data['itemtype']);
      }
      $first       = empty($COMMONWHERE);

      // Add deleted if item have it
      if ($data['item'] && $data['item']->maybeDeleted()) {
         $LINK = " AND ";
         if ($first) {
            $LINK  = " ";
            $first = false;
         }
         $COMMONWHERE .= $LINK. $this->db->quoteName("$itemtable.is_deleted") . " = " . (int)$data['search']['is_deleted'] . " ";
      }

      // Remove template items
      if ($data['item'] && $data['item']->maybeTemplate()) {
         $LINK = " AND ";
         if ($first) {
            $LINK  = " ";
            $first = false;
         }
         $COMMONWHERE .= $LINK . $this->db->quoteName("$itemtable.is_template") . " = 0 ";
      }

      // Add Restrict to current entities
      if ($entity_restrict) {
         $LINK = " AND ";
         if ($first) {
            $LINK  = " ";
            $first = false;
         }

         if ($data['itemtype'] == 'Entity') {
            $COMMONWHERE .= getEntitiesRestrictRequest($LINK, $itemtable, 'id', '', true);

         } else if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
            // Will be replace below in Union/Recursivity Hack
            $COMMONWHERE .= $LINK." ENTITYRESTRICT ";
         } else {
            $COMMONWHERE .= getEntitiesRestrictRequest($LINK, $itemtable, '', '',
                                                       $data['item']->maybeRecursive() && $data['item']->isField('is_recursive'));
         }
      }
      $WHERE  = "";
      $HAVING = "";

      // Add search conditions
      // If there is search items
      if (count($data['search']['criteria'])) {
         $WHERE  = $this->constructCriteriaSQL($data['search']['criteria'], $data, $searchopt);
         $HAVING = $this->constructCriteriaSQL($data['search']['criteria'], $data, $searchopt, true);

         // if criteria (with meta flag) need additional join/from sql
         $this->constructAdditionalSqlForMetacriteria($data['search']['criteria'], $SELECT, $FROM, $already_link_tables, $data);
      }

      //// 4 - ORDER
      $ORDER = " ORDER BY ".$this->db->quoteName('id'). ' ';
      foreach ($data['tocompute'] as $val) {
         if ($data['search']['sort'] == $val) {
            $ORDER = $this->addOrderBy(
               $data['itemtype'],
               $data['search']['sort'],
               $data['search']['order']
            );
         }
      }

      $SELECT = rtrim(trim($SELECT), ',');

      //// 7 - Manage GROUP BY
      $GROUPBY = "";
      // Meta Search / Search All / Count tickets
      $criteria_with_meta = array_filter($data['search']['criteria'], function($criterion) {
         return isset($criterion['meta'])
                && $criterion['meta'];
      });
      if ((count($data['search']['metacriteria']))
          || count($criteria_with_meta)
          || !empty($HAVING)
          || $data['search']['all_search']) {
         $GROUPBY = " GROUP BY " . $this->db->quoteName("$itemtable.id");
      }

      if (empty($GROUPBY)) {
         foreach ($data['toview'] as $val2) {
            if (!empty($GROUPBY)) {
               break;
            }
            if (isset($searchopt[$val2]["forcegroupby"])) {
               $GROUPBY = " GROUP BY " . $this->db->quoteName("$itemtable.id");;
            }
         }
      }

      $LIMIT   = "";
      $numrows = 0;
      //No search : count number of items using a simple count(ID) request and LIMIT search
      if ($data['search']['no_search']) {
         $LIMIT = " LIMIT ".(int)$data['search']['start'].", ".(int)$data['search']['list_limit'];

         // Force group by for all the type -> need to count only on table ID
         if (!isset($searchopt[1]['forcegroupby'])) {
            $count = "count(*)";
         } else {
            $count = "count(DISTINCT ".$this->db->quoteName("$itemtable.id").")";
         }
         // request currentuser for SQL supervision, not displayed
         $query_num_select = "SELECT $count,
                              ". $this->db->quote($_SESSION['glpiname'])." AS currentuser
                       FROM ".$this->db->quoteName($itemtable).
                       $COMMONLEFTJOIN;
         $query_num = '';

         $first     = true;

         if (!empty($COMMONWHERE)) {
            $LINK = " AND ";
            if ($first) {
               $LINK  = " WHERE ";
               $first = false;
            }
            $query_num .= $LINK.$COMMONWHERE;
         }
         // Union Search :
         if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
            $tmpquery = $query_num_select . $query_num;
            $numrows  = 0;

            foreach ($CFG_GLPI[$CFG_GLPI["union_search_type"][$data['itemtype']]] as $ctype) {
               $ctable = $ctype::getTable();
               if (($citem = getItemForItemtype($ctype))
                   && $citem->canView()) {
                  // State case
                  if ($data['itemtype'] == 'AllAssets') {
                     $query_num  = str_replace($CFG_GLPI["union_search_type"][$data['itemtype']],
                                               $ctable, $tmpquery);
                     $query_num  = str_replace($data['itemtype'], $ctype, $query_num);

                     if ($sub_item !== false && method_exists($sub_item['sub_item'], 'addSubSelect')) {
                        $sub = $sub_item['sub_item'];
                        $sub_select = $sub::addSubSelect(
                           $sub_item['item'],
                           $ctype
                        );
                        $query_num .= "AND $ctable.id IN ($sub_select)";
                     }

                     $query_num .= " AND ".$this->db->quoteName("$ctable.id")." IS NOT NULL ";

                     // Add deleted if item have it
                     if ($citem && $citem->maybeDeleted()) {
                        $query_num .= " AND ".$this->db->quoteName("$ctable.is_deleted")." = 0 ";
                     }

                     // Remove template items
                     if ($citem && $citem->maybeTemplate()) {
                        $query_num .= " AND ".$this->db->quoteName("$ctable.is_template")." = 0 ";
                     }

                  } else {// Ref table case
                     $reftable = $data['itemtype']::getTable();
                     if ($data['item'] && $data['item']->maybeDeleted()) {
                        $tmpquery = str_replace(
                           $this->db->quoteName($CFG_GLPI["union_search_type"][$data['itemtype']]).".".$this->db->quoteName('is_deleted'),
                           $this->db->quoteName("$reftable.is_deleted"),
                           $tmpquery
                        );
                     }
                     $replace  = "FROM ".$this->db->quoteName($reftable)."
                                  INNER JOIN ".$this->db->quoteName($ctable)."
                                       ON (".$this->db->quoteName("$reftable.items_id")." = ".$this->db->quoteName("$ctable.id")."
                                           AND ".$this->db->quoteName("$reftable.itemtype")." = ".$this->db->quote($ctype).")";

                     $query_num = str_replace("FROM ".
                                                $this->db->quoteName($CFG_GLPI["union_search_type"][$data['itemtype']]),
                                              $replace, $tmpquery);
                     $query_num = str_replace($CFG_GLPI["union_search_type"][$data['itemtype']],
                                              $ctable, $query_num);
                  }
                  $query_num = str_replace("ENTITYRESTRICT",
                                           getEntitiesRestrictRequest('', $ctable, '', '',
                                                                      $citem->maybeRecursive()),
                                           $query_num);
                  $data['sql']['count'][] = $query_num;
               }
            }

         } else {
            $query_num_select .= $COMMONSUBLEFTJOIN;
            if (!empty($COMMONSUBWHERE)) {
               $query_num .= (!empty($query_num) ? " AND ($COMMONSUBWHERE)": " WHERE $COMMONSUBWHERE");
            }

            $data['sql']['count'][] = $query_num_select . $query_num;
         }
      }

      // If export_all reset LIMIT condition
      if ($data['search']['export_all']) {
         $LIMIT = "";
      }

      if (!empty($WHERE) || !empty($COMMONWHERE)) {
         if (!empty($COMMONWHERE)) {
            $WHERE = ' WHERE '.$COMMONWHERE.(!empty($WHERE)?' AND ( '.$WHERE.' )':'');
         } else {
            $WHERE = ' WHERE '.$WHERE.' ';
         }
         $first = false;
      }

      $NOSUBWHERE = $WHERE;
      if (!empty($COMMONSUBWHERE)) {
         $WHERE .= (!empty($WHERE) ? " AND ($COMMONSUBWHERE)": " WHERE $COMMONSUBWHERE");
      }

      if (!empty($HAVING)) {
         $HAVING = ' HAVING '.$HAVING;
      }

      // Create QUERY
      if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
         $first = true;
         $QUERY = "";
         foreach ($CFG_GLPI[$CFG_GLPI["union_search_type"][$data['itemtype']]] as $ctype) {
            $ctable = $ctype::getTable();
            if (($citem = getItemForItemtype($ctype))
                && $citem->canView()) {
               if ($first) {
                  $first = false;
               } else {
                  $QUERY .= " UNION ";
               }
               $tmpquery = "";
               // AllAssets case
               if ($data['itemtype'] == 'AllAssets') {
                  $tmpquery = "$SELECT, '$ctype' AS TYPE $FROM $NOSUBWHERE";

                  if ($sub_item !== false && method_exists($sub_item['sub_item'], 'addSubSelect')) {
                     $sub = $sub_item['sub_item'];
                     $sub_select = $sub::addSubSelect(
                        $sub_item['item'],
                        $ctype
                     );
                     $tmpquery .= "AND $ctable.id IN ($sub_select)";
                  }

                  $tmpquery .= " AND ".$this->db->quoteName("$ctable.id")." IS NOT NULL ";

                  // Add deleted if item have it
                  if ($citem && $citem->maybeDeleted()) {
                     $tmpquery .= " AND ".$this->db->quoteName("$ctable.is_deleted")." = 0 ";
                  }

                  // Remove template items
                  if ($citem && $citem->maybeTemplate()) {
                     $tmpquery .= " AND ".$this->db->quoteName("$ctable.is_template")." = 0 ";
                  }

                  $tmpquery.= $GROUPBY.
                              $HAVING;

                  // Replace 'asset_types' by itemtype table name
                  $tmpquery = str_replace(
                     $CFG_GLPI["union_search_type"][$data['itemtype']],
                     $ctable,
                     $tmpquery
                  );
                  // Replace 'AllAssets' by itemtype
                  // Use quoted value to prevent replacement of AllAssets in column identifiers
                  $tmpquery = str_replace(
                     $this->db->quoteValue('AllAssets'),
                     $this->db->quoteValue($ctype),
                     $tmpquery
                  );
               } else {// Ref table case
                  $reftable = $data['itemtype']::getTable();

                  $tmpquery = $SELECT.", ".$this->db->quote($ctype)." AS TYPE,
                                      ".$this->db->quoteName("$reftable.id")." AS refID, "."
                                      ".$this->db->quoteName("$ctable.entities_id")." AS ENTITY ".
                              $FROM.
                              (strpos($QUERY, 'UNION') === false ? $WHERE : $NOSUBWHERE);
                  if ($data['item']->maybeDeleted()) {
                     $tmpquery = str_replace($this->db->quoteName($CFG_GLPI["union_search_type"][$data['itemtype']].".
                                                is_deleted"),
                                             $this->db->quoteName("$reftable.is_deleted"), $tmpquery);
                  }

                  $replace = "FROM ".$this->db->quoteName($reftable)."
                              INNER JOIN ".$this->db->quoteName($ctable)."
                                 ON (".$this->db->quoteName("$reftable.items_id")." = ".$this->db->quoteName("$ctable.id")."
                                     AND ".$this->db->quoteName("$reftable.itemtype")." = ".$this->db->quote($ctype).")";
                  $tmpquery = str_replace("FROM ".
                                             $this->db->quoteName($CFG_GLPI["union_search_type"][$data['itemtype']]),
                                          $replace, $tmpquery);
                  $tmpquery = str_replace($CFG_GLPI["union_search_type"][$data['itemtype']],
                                          $ctable, $tmpquery);
                  $name_field = $ctype::getNameField();
                  $tmpquery = str_replace("`$ctable`.`name`", "`$ctable`.`$name_field`", $tmpquery);
               }
               $tmpquery = str_replace("ENTITYRESTRICT",
                                       getEntitiesRestrictRequest('', $ctable, '', '',
                                                                  $citem->maybeRecursive()),
                                       $tmpquery);

               // SOFTWARE HACK
               if ($ctype == 'Software') {
                  $tmpquery = str_replace($this->db->quoteName("glpi_softwares.serial"), "''", $tmpquery);
                  $tmpquery = str_replace($this->db->quoteName("glpi_softwares.otherserial"), "''", $tmpquery);
               }
               $QUERY .= $tmpquery;
            }
         }
         if (empty($QUERY)) {
            echo $this->showError($data['display_type']);
            return;
         }
         $QUERY .= str_replace($CFG_GLPI["union_search_type"][$data['itemtype']].".", "", $ORDER) .
                   $LIMIT;
      } else {
         $QUERY = $SELECT.
                  $FROM.
                  $COMMONSUBLEFTJOIN.
                  $WHERE.
                  $GROUPBY.
                  $HAVING.
                  $ORDER.
                  $LIMIT;
      }
      $data['sql']['search'] = $QUERY;
   }

   /**
    * Construct WHERE (or HAVING) part of the sql based on passed criteria
    *
    * @since 9.4
    *
    * @param  array   $criteria  list of search criterion, we should have these keys:
    *                               - link (optionnal): AND, OR, NOT AND, NOT OR
    *                               - field: id of the searchoption
    *                               - searchtype: how to match value (contains, equals, etc)
    *                               - value
    * @param  array   $data      common array used by search engine,
    *                            contains all the search part (sql, criteria, params, itemtype etc)
    *                            TODO: should be a property of the class
    * @param  array   $searchopt Search options for the current itemtype
    * @param  boolean $is_having Do we construct sql WHERE or HAVING part
    *
    * @return string             the sql sub string
    *
    * @since 10.0.0 Method is no longer static
    */
   public function constructCriteriaSQL($criteria = [], $data = [], $searchopt = [], $is_having = false) {
      $sql = "";

      foreach ($criteria as $criterion) {
         if (!isset($criterion['criteria'])
             && (!isset($criterion['value'])
                 || strlen($criterion['value']) <= 0)) {
            continue;
         }

         $itemtype = $data['itemtype'];
         $meta = false;
         if (isset($criterion['meta'])
             && $criterion['meta']
             && isset($criterion['itemtype'])) {
            $itemtype = $criterion['itemtype'];
            $meta = true;
         }

         // common search
         if (!isset($criterion['field'])
             || ($criterion['field'] != "all"
                 && $criterion['field'] != "view")) {
            $LINK    = " ";
            $NOT     = 0;
            $tmplink = "";

            if (isset($criterion['link'])
                  && in_array($criterion['link'], array_keys(self::getLogicalOperators()))) {
               $tmplink = " ".$criterion['link'];
            } else {
               $tmplink = " AND ";
            }

            // Manage Link if not first item
            if (!empty($sql)) {
               $LINK = $tmplink;
            } else if (strstr($tmplink, "NOT")) {
               $NOT = 1;
            }

            if (isset($criterion['criteria']) && count($criterion['criteria'])) {
               $sub_sql = $this->constructCriteriaSQL($criterion['criteria'], $data, $searchopt, $is_having);
               if (strlen($sub_sql)) {
                  if ($NOT) {
                     $sql .= "$LINK NOT($sub_sql)";
                  } else {
                     $sql .= "$LINK ($sub_sql)";
                  }
               }
            } else if (isset($searchopt[$criterion['field']]["usehaving"])
                       || ($meta && "AND NOT" === $criterion['link'])) {
               if (!$is_having) {
                  // the having part will be managed in a second pass
                  continue;
               }

               $new_having = $this->addHaving($LINK, $NOT, $itemtype,
                                             $criterion['field'], $criterion['searchtype'],
                                             $criterion['value']);
               if ($new_having !== false) {
                  $sql .= $new_having;
               }
            } else {
               if ($is_having) {
                  // the having part has been already managed in the first pass
                  continue;
               }

               $new_where = $this->addWhere($LINK, $NOT, $itemtype, $criterion['field'],
                                           $criterion['searchtype'], $criterion['value'], $meta);
               if ($new_where !== false) {
                  $sql .= $new_where;
               }
            }
         } else if (isset($criterion['value'])
                    && strlen($criterion['value']) > 0) { // view and all search
            $LINK       = " OR ";
            $NOT        = 0;
            $globallink = " AND ";
            if (isset($criterion['link'])) {
               switch ($criterion['link']) {
                  case "AND" :
                     $LINK       = " OR ";
                     $globallink = " AND ";
                     break;
                  case "AND NOT" :
                     $LINK       = " AND ";
                     $NOT        = 1;
                     $globallink = " AND ";
                     break;
                  case "OR" :
                     $LINK       = " OR ";
                     $globallink = " OR ";
                     break;
                  case "OR NOT" :
                     $LINK       = " AND ";
                     $NOT        = 1;
                     $globallink = " OR ";
                     break;
               }
            } else {
               $tmplink =" AND ";
            }
            // Manage Link if not first item
            if (!empty($sql)) {
               $sql .= $globallink;
            }
            $first2 = true;
            $items = [];
            if (isset($criterion['field']) && $criterion['field'] == "all") {
               $items = $searchopt;
            } else { // toview case : populate toview
               foreach ($data['toview'] as $key2 => $val2) {
                  $items[$val2] = $searchopt[$val2];
               }
            }
            $view_sql = "";
            foreach ($items as $key2 => $val2) {
               if (isset($val2['nosearch']) && $val2['nosearch']) {
                  continue;
               }
               if (is_array($val2)) {
                  // Add Where clause if not to be done in HAVING CLAUSE
                  if (!$is_having && !isset($val2["usehaving"])) {
                     $tmplink = $LINK;
                     if ($first2) {
                        $tmplink = " ";
                     }

                     $new_where = $this->addWhere($tmplink, $NOT, $itemtype, $key2,
                                                 $criterion['searchtype'], $criterion['value'], $meta);
                     if ($new_where !== false) {
                        $first2  = false;
                        $view_sql .=  $new_where;
                     }
                  }
               }
            }
            if (strlen($view_sql)) {
               $sql.= " ($view_sql) ";
            }
         }
      }
      return $sql;
   }

   /**
    * Construct aditionnal SQL (select, joins, etc) for meta-criteria
    *
    * @since 9.4
    *
    * @param  array  $criteria             list of search criterion
    * @param  string &$SELECT              TODO: should be a class property (output parameter)
    * @param  string &$FROM                TODO: should be a class property (output parameter)
    * @param  array  &$already_link_tables TODO: should be a class property (output parameter)
    * @param  array  &$data                TODO: should be a class property (output parameter)
    *
    * @return void
    *
    * @since 10.0.0 Method is no longer static
    */
   public function constructAdditionalSqlForMetacriteria($criteria = [],
                                                         &$SELECT = "",
                                                         &$FROM = "",
                                                         &$already_link_tables = [],
                                                         &$data = []) {
      $data['meta_toview'] = [];
      foreach ($criteria as $criterion) {
         // manage sub criteria
         if (isset($criterion['criteria'])) {
            $this->constructAdditionalSqlForMetacriteria(
               $criterion['criteria'],
               $SELECT,
               $FROM,
               $already_link_tables,
               $data
            );
            continue;
         }

         // parse only criterion with meta flag
         if (!isset($criterion['itemtype'])
             || empty($criterion['itemtype'])
             || !isset($criterion['meta'])
             || !$criterion['meta']
             || !isset($criterion['value'])
             || strlen($criterion['value']) <= 0) {
            continue;
         }

         $m_itemtype = $criterion['itemtype'];
         $metaopt = &$this->getOptions($m_itemtype);
         $sopt    = $metaopt[$criterion['field']];

         //add toview for meta criterion
         $data['meta_toview'][$m_itemtype][] = $criterion['field'];

         $SELECT .= $this->addSelect(
            $m_itemtype,
            $criterion['field'],
            true, // meta-criterion
            $m_itemtype
         );

         if (!in_array($m_itemtype::getTable(), $already_link_tables)) {
            $FROM .= $this->addMetaLeftJoin($data['itemtype'], $m_itemtype,
                                           $already_link_tables,
                                           $sopt["joinparams"]);
         }

         $FROM .= $this->addLeftJoin($m_itemtype,
                                    $m_itemtype::getTable(),
                                    $already_link_tables, $sopt["table"],
                                    $sopt["linkfield"], 1, $m_itemtype,
                                    $sopt["joinparams"], $sopt["field"]);
      }
   }


   /**
    * Retrieve datas from DB : construct data array containing columns definitions and rows datas
    *
    * add to data array a field data containing :
    *      cols : columns definition
    *      rows : rows data
    *
    * @since 0.85
    *
    * @param array   $data      array of search data prepared to get data
    * @param boolean $onlycount If we just want to count results
    *
    * @return void
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function constructData(array &$data, $onlycount = false) {
      if (!isset($data['sql']) || !isset($data['sql']['search'])) {
         return false;
      }
      $data['data'] = [];

      // Use a ReadOnly connection if available and configured to be used
      $DBread = DBConnection::getReadConnection();
      $DBread->query("SET SESSION group_concat_max_len = 16384;");

      // directly increase group_concat_max_len to avoid double query
      if (count($data['search']['metacriteria'])) {
         foreach ($data['search']['metacriteria'] as $metacriterion) {
            if ($metacriterion['link'] == 'AND NOT'
                || $metacriterion['link'] == 'OR NOT') {
               $DBread->query("SET SESSION group_concat_max_len = 4194304;");
               break;
            }
         }
      }

      $DBread->execution_time = true;
      $result = $DBread->query($data['sql']['search']);
      /// Check group concat limit : if warning : increase limit
      if ($result2 = $DBread->query('SHOW WARNINGS')) {
         if ($DBread->numrows($result2) > 0) {
            $res = $DBread->fetchAssoc($result2);
            if ($res['Code'] == 1260) {
               $DBread->query("SET SESSION group_concat_max_len = 8194304;");
               $DBread->execution_time = true;
               $result = $DBread->query($data['sql']['search']);
            }

            if ($res['Code'] == 1116) { // too many tables
               echo $this->showError($data['search']['display_type'],
                                    __("'All' criterion is not usable with this object list, ".
                                       "sql query fails (too many tables). ".
                                       "Please use 'Items seen' criterion instead"));
               return false;
            }
         }
      }

      if ($result) {
         $data['data']['execution_time'] = $DBread->execution_time;
         if (isset($data['search']['savedsearches_id'])) {
            SavedSearch::updateExecutionTime(
               (int)$data['search']['savedsearches_id'],
               $DBread->execution_time
            );
         }

         $data['data']['totalcount'] = 0;
         // if real search or complete export : get numrows from request
         if (!$data['search']['no_search']
             || $data['search']['export_all']) {
            $data['data']['totalcount'] = $DBread->numrows($result);
         } else {
            if (!isset($data['sql']['count'])
               || (count($data['sql']['count']) == 0)) {
               $data['data']['totalcount'] = $DBread->numrows($result);
            } else {
               foreach ($data['sql']['count'] as $sqlcount) {
                  $result_num = $DBread->query($sqlcount);
                  $data['data']['totalcount'] += $DBread->result($result_num, 0, 0);
               }
            }
         }

         if ($onlycount) {
            //we just want to coutn results; no need to continue process
            return;
         }

         // Search case
         $data['data']['begin'] = $data['search']['start'];
         $data['data']['end']   = min($data['data']['totalcount'],
                                      $data['search']['start']+$data['search']['list_limit'])-1;
         //map case
         if (isset($data['search']['as_map'])  && $data['search']['as_map'] == 1) {
            $data['data']['end'] = $data['data']['totalcount']-1;
         }

         // No search Case
         if ($data['search']['no_search']) {
            $data['data']['begin'] = 0;
            $data['data']['end']   = min($data['data']['totalcount']-$data['search']['start'],
                                         $data['search']['list_limit'])-1;
         }
         // Export All case
         if ($data['search']['export_all']) {
            $data['data']['begin'] = 0;
            $data['data']['end']   = $data['data']['totalcount']-1;
         }

         // Get columns
         $data['data']['cols'] = [];

         $searchopt = &$this->getOptions($data['itemtype']);

         foreach ($data['toview'] as $opt_id) {
            $data['data']['cols'][] = [
               'itemtype'  => $data['itemtype'],
               'id'        => $opt_id,
               'name'      => $searchopt[$opt_id]["name"],
               'meta'      => 0,
               'searchopt' => $searchopt[$opt_id],
            ];
         }

         // manage toview column for criteria with meta flag
         if (isset($data['meta_toview'])) {
            foreach ($data['meta_toview'] as $m_itemtype => $toview) {
               $searchopt = &$this->getOptions($m_itemtype);
               foreach ($toview as $opt_id) {
                  $data['data']['cols'][] = [
                     'itemtype'  => $m_itemtype,
                     'id'        => $opt_id,
                     'name'      => $searchopt[$opt_id]["name"],
                     'meta'      => 1,
                     'searchopt' => $searchopt[$opt_id],
                  ];
               }
            }
         }

         // Display columns Headers for meta items
         $already_printed = [];

         if (count($data['search']['metacriteria'])) {
            foreach ($data['search']['metacriteria'] as $metacriteria) {
               if (isset($metacriteria['itemtype']) && !empty($metacriteria['itemtype'])
                     && isset($metacriteria['value']) && (strlen($metacriteria['value']) > 0)) {

                  if (!isset($already_printed[$metacriteria['itemtype'].$metacriteria['field']])) {
                     $searchopt = &$this->getOptions($metacriteria['itemtype']);

                     $data['data']['cols'][] = [
                        'itemtype'  => $metacriteria['itemtype'],
                        'id'        => $metacriteria['field'],
                        'name'      => $searchopt[$metacriteria['field']]["name"],
                        'meta'      => 1,
                        'searchopt' =>$searchopt[$metacriteria['field']]
                     ];

                     $already_printed[$metacriteria['itemtype'].$metacriteria['field']] = 1;
                  }
               }
            }
         }

         // search group (corresponding of dropdown optgroup) of current col
         foreach ($data['data']['cols'] as $num => $col) {
            // search current col in searchoptions ()
            while (key($searchopt) !== null
                   && key($searchopt) != $col['id']) {
               next($searchopt);
            }
            if (key($searchopt) !== null) {
               //search optgroup (non array option)
               while (key($searchopt) !== null
                      && is_numeric(key($searchopt))
                      && is_array(current($searchopt))) {
                  prev($searchopt);
               }
               if (key($searchopt) !== null
                   && key($searchopt) !== "common") {
                  $data['data']['cols'][$num]['groupname'] = current($searchopt);
               }

            }
            //reset
            reset($searchopt);
         }

         // Get rows

         // if real search seek to begin of items to display (because of complete search)
         if (!$data['search']['no_search']) {
            $DBread->dataSeek($result, $data['search']['start']);
         }

         $i = $data['data']['begin'];
         $data['data']['warning']
            = "For compatibility keep raw data  (ITEM_X, META_X) at the top for the moment. Will be drop in next version";

         $data['data']['rows']  = [];
         $data['data']['items'] = [];

         $this->output_type = $data['display_type'];

         while (($i < $data['data']['totalcount']) && ($i <= $data['data']['end'])) {
            $row = $DBread->fetchAssoc($result);
            $newrow        = [];
            $newrow['raw'] = $row;

            // Parse datas
            foreach ($newrow['raw'] as $key => $val) {
               if (preg_match('/ITEM(_(\w[^\d]+))?_(\d+)(_(.+))?/', $key, $matches)) {
                  $j = $matches[3];
                  if (isset($matches[2]) && !empty($matches[2])) {
                     $j = $matches[2] . '_' . $matches[3];
                  }
                  $fieldname = 'name';
                  if (isset($matches[5])) {
                     $fieldname = $matches[5];
                  }

                  // No Group_concat case
                  if ($fieldname == 'content' || strpos($val, self::LONGSEP) === false) {
                     $newrow[$j]['count'] = 1;

                     $handled = false;
                     if ($fieldname != 'content' && strpos($val, self::SHORTSEP) !== false) {
                        $split2                    = self::explodeWithID(self::SHORTSEP, $val);
                        if (is_numeric($split2[1])) {
                           $newrow[$j][0][$fieldname] = $split2[0];
                           $newrow[$j][0]['id']       = $split2[1];
                           $handled = true;
                        }
                     }

                     if (!$handled) {
                        if ($val === self::NULLVALUE) {
                           $newrow[$j][0][$fieldname] = null;
                        } else {
                           $newrow[$j][0][$fieldname] = $val;
                        }
                     }
                  } else {
                     if (!isset($newrow[$j])) {
                        $newrow[$j] = [];
                     }
                     $split               = explode(self::LONGSEP, $val);
                     $newrow[$j]['count'] = count($split);
                     foreach ($split as $key2 => $val2) {
                        $handled = false;
                        if (strpos($val2, self::SHORTSEP) !== false) {
                           $split2                  = self::explodeWithID(self::SHORTSEP, $val2);
                           if (is_numeric($split2[1])) {
                              $newrow[$j][$key2]['id'] = $split2[1];
                              if ($split2[0] == self::NULLVALUE) {
                                 $newrow[$j][$key2][$fieldname] = null;
                              } else {
                                 $newrow[$j][$key2][$fieldname] = $split2[0];
                              }
                              $handled = true;
                           }
                        }

                        if (!$handled) {
                           $newrow[$j][$key2][$fieldname] = $val2;
                        }
                     }
                  }
               } else {
                  if ($key == 'currentuser') {
                     if (!isset($data['data']['currentuser'])) {
                        $data['data']['currentuser'] = $val;
                     }
                  } else {
                     $newrow[$key] = $val;
                     // Add id to items list
                     if ($key == 'id') {
                        $data['data']['items'][$val] = $i;
                     }
                  }
               }
            }
            foreach ($data['data']['cols'] as $val) {
               $newrow[$val['itemtype'] . '_' . $val['id']]['displayname'] = $this->giveItem(
                  $val['itemtype'],
                  $val['id'],
                  $newrow
               );
            }

            if ($val['itemtype'] == 'AllAssets') {
               $newrow[$val['itemtype'] . '_TYPE'] = ['displayname' => $newrow['TYPE']];
            }

            $data['data']['rows'][$i] = $newrow;
            $i++;
         }

         $data['data']['count'] = count($data['data']['rows']);
         if ($data['itemtype'] == 'AllAssets') {
            $data['data']['cols'][] = [
               'itemtype'  => 'AllAssets',
               'id'        => 'TYPE',
               'name'      => __('Item type'),
               'meta'      => 0
            ];
         }
      } else {
         echo $DBread->error();
      }
   }


   /**
    * Display datas extracted from DB
    *
    * @param array $data Array of search datas prepared to get datas
    *
    * @return void
   **/
   static function displayData(array $data) {
      global $CFG_GLPI;

      $item = null;
      if (class_exists($data['itemtype'])) {
         $item = new $data['itemtype']();
      }

      if (!isset($data['data']) || !isset($data['data']['totalcount'])) {
         return false;
      }
      // Contruct Pager parameters
      $globallinkto
         = Toolbox::append_params(['criteria'
                                          => Toolbox::stripslashes_deep($data['search']['criteria']),
                                        'metacriteria'
                                          => Toolbox::stripslashes_deep($data['search']['metacriteria'])],
                                  '&amp;');
      $parameters = "sort=".$data['search']['sort']."&amp;order=".$data['search']['order'].'&amp;'.
                     $globallinkto;

      if (isset($_GET['_in_modal'])) {
         $parameters .= "&amp;_in_modal=1";
      }

      // Global search header
      if ($data['display_type'] == self::GLOBAL_SEARCH) {
         if ($data['item']) {
            echo "<div class='center'><h2>".$data['item']->getTypeName();
            // More items
            if ($data['data']['totalcount'] > ($data['search']['start'] + self::GLOBAL_DISPLAY_COUNT)) {
               echo " <a href='".$data['search']['target']."?$parameters'>".__('All')."</a>";
            }
            echo "</h2></div>\n";
         } else {
            return false;
         }
      }

      // If the begin of the view is before the number of items
      if ($data['data']['count'] > 0) {
         // Display pager only for HTML
         if ($data['display_type'] == self::HTML_OUTPUT) {
            // For plugin add new parameter if available
            if ($plug = isPluginItemType($data['itemtype'])) {
               $out = Plugin::doOneHook($plug['plugin'], 'addParamFordynamicReport', $data['itemtype']);
               if (is_array($out) && count($out)) {
                  $parameters .= Toolbox::append_params($out, '&amp;');
               }
            }
            $search_config_top    = "";
            $search_config_bottom = "";
            if (!isset($_GET['_in_modal'])) {

               $search_config_top = $search_config_bottom
                  = "<div class='pager_controls'>";

               $map_link = '';
               if (null == $item || $item->maybeLocated()) {
                  $map_link = "<input type='checkbox' name='as_map' id='as_map' value='1'";
                  if ($data['search']['as_map'] == 1) {
                     $map_link .= " checked='checked'";
                  }
                  $map_link .= "/>";
                  $map_link .= "<label for='as_map'><span title='".__s('Show as map')."' class='pointer fa fa-globe-americas'
                     onClick=\"toogle('as_map','','','');
                                 document.forms['searchform".$data["itemtype"]."'].submit();\"></span></label>";
               }
               $search_config_top .= $map_link;

               if (Session::haveRightsOr('search_config', [
                  DisplayPreference::PERSONAL,
                  DisplayPreference::GENERAL
               ])) {
                  $options_link = "<span class='fa fa-wrench pointer' title='".
                     __s('Select default items to show')."' onClick=\"$('#%id').dialog('open');\">
                     <span class='sr-only'>" .  __s('Select default items to show') . "</span></span>";

                  $search_config_top .= str_replace('%id', 'search_config_top', $options_link);
                  $search_config_bottom .= str_replace('%id', 'search_config_bottom', $options_link);

                  $pref_url = $CFG_GLPI["root_doc"]."/front/displaypreference.form.php?itemtype=".
                              $data['itemtype'];
                  $search_config_top .= Ajax::createIframeModalWindow(
                     'search_config_top',
                     $pref_url,
                     [
                        'title'         => __('Select default items to show'),
                        'reloadonclose' => true,
                        'display'       => false
                     ]
                  );
                  $search_config_bottom .= Ajax::createIframeModalWindow(
                     'search_config_bottom',
                     $pref_url,
                     [
                        'title'         => __('Select default items to show'),
                        'reloadonclose' => true,
                        'display'       => false
                     ]
                  );
               }
            }

            if ($item !== null && $item->maybeDeleted()) {
               $delete_ctrl        = self::isDeletedSwitch($data['search']['is_deleted'], $data['itemtype']);
               $search_config_top .= $delete_ctrl;
            }

            if ($data['search']['show_pager']) {
               Html::printPager($data['search']['start'], $data['data']['totalcount'],
                              $data['search']['target'], $parameters, $data['itemtype'], 0,
                                 $search_config_top);
            }

            $search_config_top    .= "</div>";
            $search_config_bottom .= "</div>";
         }

         // Define begin and end var for loop
         // Search case
         $begin_display = $data['data']['begin'];
         $end_display   = $data['data']['end'];

         // Form to massive actions
         $isadmin = ($data['item'] && $data['item']->canUpdate());
         if (!$isadmin
               && Infocom::canApplyOn($data['itemtype'])) {
            $isadmin = (Infocom::canUpdate() || Infocom::canCreate());
         }
         if ($data['itemtype'] != 'AllAssets') {
            $showmassiveactions = ($data['search']['showmassiveactions'] ?? true)
               && count(MassiveAction::getAllMassiveActions($data['item'],
                                                            $data['search']['is_deleted']));
         } else {
            $showmassiveactions = $data['search']['showmassiveactions'] ?? true;
         }

         if ($data['search']['as_map'] == 0) {
            $massformid = 'massform'.$data['itemtype'];
            if ($showmassiveactions
               && ($data['display_type'] == self::HTML_OUTPUT)) {

               Html::openMassiveActionsForm($massformid);
               $massiveactionparams                  = $data['search']['massiveactionparams'];
               $massiveactionparams['num_displayed'] = $end_display-$begin_display;
               $massiveactionparams['fixed']         = false;
               $massiveactionparams['is_deleted']    = $data['search']['is_deleted'];
               $massiveactionparams['container']     = $massformid;

               Html::showMassiveActions($massiveactionparams);
            }

            // Compute number of columns to display
            // Add toview elements
            $nbcols          = count($data['data']['cols']);

            if (($data['display_type'] == self::HTML_OUTPUT)
               && $showmassiveactions) { // HTML display - massive modif
               $nbcols++;
            }

            // Display List Header
            echo self::showHeader($data['display_type'], $end_display-$begin_display+1, $nbcols);

            // New Line for Header Items Line
            $headers_line        = '';
            $headers_line_top    = '';
            $headers_line_bottom = '';

            $headers_line_top .= self::showBeginHeader($data['display_type']);
            $headers_line_top .= self::showNewLine($data['display_type']);

            if ($data['display_type'] == self::HTML_OUTPUT) {
               // $headers_line_bottom .= self::showBeginHeader($data['display_type']);
               $headers_line_bottom .= self::showNewLine($data['display_type']);
            }

            $header_num = 1;

            if (($data['display_type'] == self::HTML_OUTPUT)
                  && $showmassiveactions) { // HTML display - massive modif
               $headers_line_top
                  .= self::showHeaderItem($data['display_type'],
                                          Html::getCheckAllAsCheckbox($massformid),
                                          $header_num, "", 0, $data['search']['order']);
               if ($data['display_type'] == self::HTML_OUTPUT) {
                  $headers_line_bottom
                     .= self::showHeaderItem($data['display_type'],
                                             Html::getCheckAllAsCheckbox($massformid),
                                             $header_num, "", 0, $data['search']['order']);
               }
            }

            // Display column Headers for toview items
            $metanames = [];
            foreach ($data['data']['cols'] as $val) {
               $linkto = '';
               if (!$val['meta']
                   && !$data['search']['no_sort']
                   && (!isset($val['searchopt']['nosort'])
                     || !$val['searchopt']['nosort'])) {

                  $linkto = $data['search']['target'].(strpos($data['search']['target'], '?') ? '&amp;' : '?').
                              "itemtype=".$data['itemtype']."&amp;sort=".
                              $val['id']."&amp;order=".
                              (($data['search']['order'] == "ASC") ?"DESC":"ASC").
                              "&amp;start=".$data['search']['start']."&amp;".$globallinkto;
               }

               $name = $val["name"];

               // prefix by group name (corresponding to optgroup in dropdown) if exists
               if (isset($val['groupname'])) {
                  $groupname = $val['groupname'];
                  if (is_array($groupname)) {
                     //since 9.2, getSearchOptions has been changed
                     $groupname = $groupname['name'];
                  }
                  $name  = "$groupname - $name";
               }

               // Not main itemtype add itemtype to display
               if ($data['itemtype'] != $val['itemtype']) {
                  if (!isset($metanames[$val['itemtype']])) {
                     if ($metaitem = getItemForItemtype($val['itemtype'])) {
                        $metanames[$val['itemtype']] = $metaitem->getTypeName();
                     }
                  }
                  $name = sprintf(__('%1$s - %2$s'), $metanames[$val['itemtype']],
                                 $val["name"]);
               }

               $headers_line .= self::showHeaderItem($data['display_type'],
                                                      $name,
                                                      $header_num, $linkto,
                                                      (!$val['meta']
                                                      && ($data['search']['sort'] == $val['id'])),
                                                      $data['search']['order']);
            }

            // Add specific column Header
            if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
               $headers_line .= self::showHeaderItem($data['display_type'], __('Item type'),
                                                      $header_num);
            }
            // End Line for column headers
            $headers_line        .= self::showEndLine($data['display_type']);

            $headers_line_top    .= $headers_line;
            if ($data['display_type'] == self::HTML_OUTPUT) {
               $headers_line_bottom .= $headers_line;
            }

            $headers_line_top    .= self::showEndHeader($data['display_type']);
            // $headers_line_bottom .= self::showEndHeader($data['display_type']);

            echo $headers_line_top;

            // Init list of items displayed
            if ($data['display_type'] == self::HTML_OUTPUT) {
               Session::initNavigateListItems($data['itemtype']);
            }

            // Num of the row (1=header_line)
            $row_num = 1;

            $massiveaction_field = 'id';
            if (($data['itemtype'] != 'AllAssets')
                  && isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
               $massiveaction_field = 'refID';
            }

            $typenames = [];
            // Display Loop
            foreach ($data['data']['rows'] as $rowkey => $row) {
               // Column num
               $item_num = 1;
               $row_num++;
               // New line
               echo self::showNewLine($data['display_type'], ($row_num%2),
                                    $data['search']['is_deleted']);

               $current_type       = (isset($row['TYPE']) ? $row['TYPE'] : $data['itemtype']);
               $massiveaction_type = $current_type;

               if (($data['itemtype'] != 'AllAssets')
                  && isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
                  $massiveaction_type = $data['itemtype'];
               }

               // Add item in item list
               Session::addToNavigateListItems($current_type, $row["id"]);

               if (($data['display_type'] == self::HTML_OUTPUT)
                     && $showmassiveactions) { // HTML display - massive modif
                  $tmpcheck = "";

                  if (($data['itemtype'] == 'Entity')
                        && !in_array($row["id"], $_SESSION["glpiactiveentities"])) {
                     $tmpcheck = "&nbsp;";

                  } else if ($data['itemtype'] == 'User'
                           && !Session::canViewAllEntities()
                           && !Session::haveAccessToOneOfEntities(Profile_User::getUserEntities($row["id"], false))) {
                     $tmpcheck = "&nbsp;";

                  } else if (($data['item'] instanceof CommonDBTM)
                              && $data['item']->maybeRecursive()
                              && !in_array($row["entities_id"], $_SESSION["glpiactiveentities"])) {
                     $tmpcheck = "&nbsp;";

                  } else {
                     $tmpcheck = Html::getMassiveActionCheckBox($massiveaction_type,
                                                               $row[$massiveaction_field]);
                  }
                  echo self::showItem($data['display_type'], $tmpcheck, $item_num, $row_num,
                                       "width='10'");
               }

               // Print other toview items
               foreach ($data['data']['cols'] as $col) {
                  $colkey = "{$col['itemtype']}_{$col['id']}";
                  if (!$col['meta']) {
                     echo self::showItem($data['display_type'], $row[$colkey]['displayname'],
                                          $item_num, $row_num,
                                          self::displayConfigItem($data['itemtype'], $col['id'],
                                                                  $row, $colkey));
                  } else { // META case
                     echo self::showItem($data['display_type'], $row[$colkey]['displayname'],
                                       $item_num, $row_num);
                  }
               }

               if (isset($CFG_GLPI["union_search_type"][$data['itemtype']])) {
                  if (!isset($typenames[$row["TYPE"]])) {
                     if ($itemtmp = getItemForItemtype($row["TYPE"])) {
                        $typenames[$row["TYPE"]] = $itemtmp->getTypeName();
                     }
                  }
                  echo self::showItem($data['display_type'], $typenames[$row["TYPE"]],
                                    $item_num, $row_num);
               }
               // End Line
               echo self::showEndLine($data['display_type']);
               // Flush ONLY for an HTML display (issue #3348)
               if ($data['display_type'] == self::HTML_OUTPUT
                   && !$data['search']['dont_flush']) {
                  Html::glpi_flush();
               }
            }

            // Create title
            $title = '';
            if (($data['display_type'] == self::PDF_OUTPUT_LANDSCAPE)
                  || ($data['display_type'] == self::PDF_OUTPUT_PORTRAIT)) {
               $title = self::computeTitle($data);
            }

            if ($data['search']['show_footer']) {
               if ($data['display_type'] == self::HTML_OUTPUT) {
                  echo $headers_line_bottom;
               }
            }

            // Display footer (close table)
            echo self::showFooter($data['display_type'], $title, $data['data']['count']);

            if ($data['search']['show_footer']) {
               // Delete selected item
               if ($data['display_type'] == self::HTML_OUTPUT) {
                  if ($showmassiveactions) {
                     $massiveactionparams['ontop'] = false;
                     Html::showMassiveActions($massiveactionparams);
                     // End form for delete item
                     Html::closeForm();
                  } else {
                     echo "<br>";
                  }
               }
               if ($data['display_type'] == self::HTML_OUTPUT
                  && $data['search']['show_pager']) { // In case of HTML display
                  Html::printPager($data['search']['start'], $data['data']['totalcount'],
                                 $data['search']['target'], $parameters, '', 0,
                                    $search_config_bottom);

               }
            }
         }
      } else {
         if (!isset($_GET['_in_modal'])) {
            echo "<div class='center pager_controls'>";
            if (null == $item || $item->maybeLocated()) {
               $map_link = "<input type='checkbox' name='as_map' id='as_map' value='1'";
               if ($data['search']['as_map'] == 1) {
                  $map_link .= " checked='checked'";
               }
               $map_link .= "/>";
               $map_link .= "<label for='as_map'><span title='".__s('Show as map')."' class='pointer fa fa-globe-americas'
                  onClick=\"toogle('as_map','','','');
                              document.forms['searchform".$data["itemtype"]."'].submit();\"></span></label>";
               echo $map_link;
            }

            if ($item !== null && $item->maybeDeleted()) {
               echo self::isDeletedSwitch($data['search']['is_deleted'], $data['itemtype']);
            }
            echo "</div>";
         }
         echo self::showError($data['display_type']);
      }
   }


   /**
    * @since 0.90
    *
    * @param boolean $is_deleted
    * @param string  $itemtype
    *
    * @return string
   */
   static function isDeletedSwitch($is_deleted, $itemtype = "") {
      $rand = mt_rand();
      return "<div class='switch grey_border pager_controls'>".
             "<label for='is_deletedswitch$rand' title='".__s('Show the trashbin')."' >".
                "<span class='sr-only'>" . __s('Show the trashbin') . "</span>" .
                "<input type='hidden' name='is_deleted' value='0' /> ".
                "<input type='checkbox' id='is_deletedswitch$rand' name='is_deleted' value='1' ".
                  ($is_deleted?"checked='checked'":"").
                  " onClick = \"toogle('is_deleted','','','');
                              document.forms['searchform$itemtype'].submit();\" />".
                "<span class='fa fa-trash-alt pointer'></span>".
                "<span class='lever'></span>" .
                "</label>".
             "</div>";
   }


   /**
    * Compute title (use case of PDF OUTPUT)
    *
    * @param array $data Array data of search
    *
    * @return string Title
   **/
   static function computeTitle($data) {
      $title = "";

      if (count($data['search']['criteria'])) {
         //Drop the first link as it is not needed, or convert to clean link (AND NOT -> NOT)
         if (isset($data['search']['criteria']['0']['link'])) {
            $notpos = strpos($data['search']['criteria']['0']['link'], 'NOT');
            //If link was like '%NOT%' just use NOT. Otherwise remove the link
            if ($notpos > 0) {
               $data['search']['criteria']['0']['link'] = 'NOT';
            } else if (!$notpos) {
               unset($data['search']['criteria']['0']['link']);
            }
         }

         foreach ($data['search']['criteria'] as $criteria) {
            if (isset($criteria['itemtype'])) {
               $searchopt = &self::getOptions($criteria['itemtype']);
            } else {
               $searchopt = &self::getOptions($data['itemtype']);
            }
            $titlecontain = '';

            if (isset($criteria['criteria'])) {
               //This is a group criteria, call computeTitle again and concat
               $newdata = $data;
               $oldlink = $criteria['link'];
               $newdata['search'] = $criteria;
               $titlecontain = sprintf(__('%1$s %2$s (%3$s)'), $titlecontain, $oldlink,
                  Search::computeTitle($newdata));
            } else {
               if (strlen($criteria['value']) > 0) {
                  if (isset($criteria['link'])) {
                     $titlecontain = " ".$criteria['link']." ";
                  }
                  $gdname    = '';
                  $valuename = '';

                  switch ($criteria['field']) {
                     case "all" :
                        $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain, __('All'));
                        break;

                     case "view" :
                        $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain, __('Items seen'));
                        break;

                     default :
                        if (isset($criteria['meta']) && $criteria['meta']) {
                           $searchoptname = sprintf(__('%1$s / %2$s'),
                                          $criteria['itemtype'],
                                          $searchopt[$criteria['field']]["name"]);
                        } else {
                           $searchoptname = $searchopt[$criteria['field']]["name"];
                        }

                        $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain, $searchoptname);
                        $itemtype     = getItemTypeForTable($searchopt[$criteria['field']]["table"]);
                        $valuename    = '';
                        if ($item = getItemForItemtype($itemtype)) {
                           $valuename = $item->getValueToDisplay($searchopt[$criteria['field']],
                                                                 $criteria['value']);
                        }

                        $gdname = Dropdown::getDropdownName($searchopt[$criteria['field']]["table"],
                                                            $criteria['value']);
                  }

                  if (empty($valuename)) {
                     $valuename = $criteria['value'];
                  }
                  switch ($criteria['searchtype']) {
                     case "equals" :
                        if (in_array($searchopt[$criteria['field']]["field"],
                                     ['name', 'completename'])) {
                           $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain, $gdname);
                        } else {
                           $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain, $valuename);
                        }
                        break;

                     case "notequals" :
                        if (in_array($searchopt[$criteria['field']]["field"],
                                       ['name', 'completename'])) {
                           $titlecontain = sprintf(__('%1$s <> %2$s'), $titlecontain, $gdname);
                        } else {
                           $titlecontain = sprintf(__('%1$s <> %2$s'), $titlecontain, $valuename);
                        }
                        break;

                     case "lessthan" :
                        $titlecontain = sprintf(__('%1$s < %2$s'), $titlecontain, $valuename);
                        break;

                     case "morethan" :
                        $titlecontain = sprintf(__('%1$s > %2$s'), $titlecontain, $valuename);
                        break;

                     case "contains" :
                        $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain,
                                                '%'.$valuename.'%');
                        break;

                     case "notcontains" :
                        $titlecontain = sprintf(__('%1$s <> %2$s'), $titlecontain,
                                                '%'.$valuename.'%');
                        break;

                     case "under" :
                        $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain,
                                                sprintf(__('%1$s %2$s'), __('under'), $gdname));
                        break;

                     case "notunder" :
                        $titlecontain = sprintf(__('%1$s %2$s'), $titlecontain,
                                                sprintf(__('%1$s %2$s'), __('not under'), $gdname));
                        break;

                     default :
                        $titlecontain = sprintf(__('%1$s = %2$s'), $titlecontain, $valuename);
                        break;
                  }
               }
            }
            $title .= $titlecontain;
         }
      }
      if (isset($data['search']['metacriteria']) &&
         count($data['search']['metacriteria'])) {
         $metanames = [];
         foreach ($data['search']['metacriteria'] as $metacriteria) {
            $searchopt = &self::getOptions($metacriteria['itemtype']);
            if (!isset($metanames[$metacriteria['itemtype']])) {
               if ($metaitem = getItemForItemtype($metacriteria['itemtype'])) {
                  $metanames[$metacriteria['itemtype']] = $metaitem->getTypeName();
               }
            }

            $titlecontain2 = '';
            if (strlen($metacriteria['value']) > 0) {
               if (isset($metacriteria['link'])) {
                  $titlecontain2 = sprintf(__('%1$s %2$s'), $titlecontain2,
                                             $metacriteria['link']);
               }
               $titlecontain2
                  = sprintf(__('%1$s %2$s'), $titlecontain2,
                              sprintf(__('%1$s / %2$s'),
                                    $metanames[$metacriteria['itemtype']],
                                    $searchopt[$metacriteria['field']]["name"]));

               $gdname2 = Dropdown::getDropdownName($searchopt[$metacriteria['field']]["table"],
                                                      $metacriteria['value']);
               switch ($metacriteria['searchtype']) {
                  case "equals" :
                     if (in_array($searchopt[$metacriteria['link']]
                                             ["field"],
                                    ['name', 'completename'])) {
                        $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2,
                                                   $gdname2);
                     } else {
                        $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2,
                                                   $metacriteria['value']);
                     }
                     break;

                  case "notequals" :
                     if (in_array($searchopt[$metacriteria['link']]["field"],
                                    ['name', 'completename'])) {
                        $titlecontain2 = sprintf(__('%1$s <> %2$s'), $titlecontain2,
                                                   $gdname2);
                     } else {
                        $titlecontain2 = sprintf(__('%1$s <> %2$s'), $titlecontain2,
                                                   $metacriteria['value']);
                     }
                     break;

                  case "lessthan" :
                     $titlecontain2 = sprintf(__('%1$s < %2$s'), $titlecontain2,
                                                $metacriteria['value']);
                     break;

                  case "morethan" :
                     $titlecontain2 = sprintf(__('%1$s > %2$s'), $titlecontain2,
                                                $metacriteria['value']);
                     break;

                  case "contains" :
                     $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2,
                                                '%'.$metacriteria['value'].'%');
                     break;

                  case "notcontains" :
                     $titlecontain2 = sprintf(__('%1$s <> %2$s'), $titlecontain2,
                                                '%'.$metacriteria['value'].'%');
                     break;

                  case "under" :
                     $titlecontain2 = sprintf(__('%1$s %2$s'), $titlecontain2,
                                                sprintf(__('%1$s %2$s'), __('under'),
                                                      $gdname2));
                     break;

                  case "notunder" :
                     $titlecontain2 = sprintf(__('%1$s %2$s'), $titlecontain2,
                                                sprintf(__('%1$s %2$s'), __('not under'),
                                                      $gdname2));
                     break;

                  default :
                     $titlecontain2 = sprintf(__('%1$s = %2$s'), $titlecontain2,
                                                $metacriteria['value']);
                     break;
               }
            }
            $title .= $titlecontain2;
         }
      }
      return $title;
   }

   /**
    * Get meta types available for search engine
    *
    * @param string $itemtype Type to display the form
    *
    * @return array Array of available itemtype
   **/
   static function getMetaItemtypeAvailable($itemtype) {
      global $CFG_GLPI;

      // Display meta search items
      $linked = [];
      // Define meta search items to linked

      $meta_itemtype = static::getMetaReferenceItemtype($itemtype);
      switch ($meta_itemtype) {
         case 'Computer' :
            $linked = ['Monitor', 'Peripheral', 'Phone', 'Printer',
                            'Software', 'User', 'Group', 'Budget'];
            break;

         case 'Ticket' :
            if (Session::haveRight("ticket", Ticket::READALL)) {
               $linked = array_keys(Ticket::getAllTypesForHelpdesk());
            }
            break;

         case 'Problem' :
            if (Session::haveRight("problem", Problem::READALL)) {
               $linked = array_keys(Problem::getAllTypesForHelpdesk());
            }
            break;

         case 'Change' :
            if (Session::haveRight("change", Change::READALL)) {
               $linked = array_keys(Change::getAllTypesForHelpdesk());
            }
            break;

         case 'Printer' :
         case 'Monitor' :
         case "Peripheral" :
         case "Software" :
         case "Phone" :
            $linked = ['Computer', 'User', 'Group', 'Budget'];
            break;
      }

      if (in_array($meta_itemtype, $CFG_GLPI['appliance_types'])) {
         $linked[] = 'Appliance';
      }

      return $linked;
   }


   /**
    * @since 0.85
    *
    * @param $itemtype
   **/
   static function getMetaReferenceItemtype ($itemtype) {

      $types = [
         'Computer',
         'Problem',
         'Change',
         'Ticket',
         'Printer',
         'Monitor',
         'Peripheral',
         'Software',
         'Phone'
      ];
      foreach ($types as $type) {
         if (is_a($itemtype, $type, true)) {
            return $type;
         }
      }
      return false;
   }


   /**
    * @since 0.85
   **/
   static function getLogicalOperators($only_not = false) {
      if ($only_not) {
         return [
            'AND'     => Dropdown::EMPTY_VALUE,
            'AND NOT' => __("NOT")
         ];
      }

      return [
         'AND'     => __('AND'),
         'OR'      => __('OR'),
         'AND NOT' => __('AND NOT'),
         'OR NOT'  => __('OR NOT')
      ];
   }


   /**
    * Print generic search form
    *
    * Params need to parsed before using Search::manageParams function
    *
    * @param string $itemtype  Type to display the form
    * @param array  $params    Array of parameters may include sort, is_deleted, criteria, metacriteria
    *
    * @return void
   **/
   static function showGenericSearch($itemtype, array $params) {
      global $CFG_GLPI;

      // Default values of parameters
      $p['sort']         = '';
      $p['is_deleted']   = 0;
      $p['as_map']       = 0;
      $p['criteria']     = [];
      $p['metacriteria'] = [];
      if (class_exists($itemtype)) {
         $p['target']       = $itemtype::getSearchURL();
      } else {
         $p['target']       = Toolbox::getItemTypeSearchURL($itemtype);
      }
      $p['showreset']    = true;
      $p['showbookmark'] = true;
      $p['showfolding']  = true;
      $p['mainform']     = true;
      $p['prefix_crit']  = '';
      $p['addhidden']    = [];
      $p['actionname']   = 'search';
      $p['actionvalue']  = _sx('button', 'Search');

      foreach ($params as $key => $val) {
         $p[$key] = $val;
      }

      $main_block_class = '';
      if ($p['mainform']) {
         echo "<form name='searchform$itemtype' method='get' action='".$p['target']."'>";
      } else {
         $main_block_class = "sub_criteria";
      }
      echo "<div id='searchcriteria' class='$main_block_class'>";
      $nbsearchcountvar      = 'nbcriteria'.strtolower($itemtype).mt_rand();
      $searchcriteriatableid = 'criteriatable'.strtolower($itemtype).mt_rand();
      // init criteria count
      echo Html::scriptBlock("
         var $nbsearchcountvar = ".count($p['criteria']).";
      ");

      echo "<ul id='$searchcriteriatableid'>";

      // Display normal search parameters
      $i = 0;
      foreach (array_keys($p['criteria']) as $i) {
         self::displayCriteria([
            'itemtype' => $itemtype,
            'num'      => $i,
            'p'        => $p
         ]);
      }

      $rand_criteria = mt_rand();
      echo "<li id='more-criteria$rand_criteria'
            class='normalcriteria headerRow'
            style='display: none;'>...</li>";

      echo "</ul>";
      echo "<div class='search_actions'>";

      // Keep track of the current savedsearches on reload
      if (isset($_GET['savedsearches_id'])) {
         echo Html::input("savedsearches_id", [
            'type' => "hidden",
            'value' => $_GET['savedsearches_id'],
         ]);
      }

      $linked = self::getMetaItemtypeAvailable($itemtype);
      echo "<span id='addsearchcriteria$rand_criteria' class='secondary'>
               <i class='fas fa-plus-square'></i>
               ".__s('rule')."
            </span>";
      if (count($linked)) {
         echo "<span id='addmetasearchcriteria$rand_criteria' class='secondary'>
                  <i class='far fa-plus-square'></i>
                  ".__s('global rule')."
               </span>";
      }
      echo "<span id='addcriteriagroup$rand_criteria' class='secondary'>
               <i class='fas fa-plus-circle'></i>
               ".__s('group')."
            </span>";
      $json_p = json_encode($p);

      if ($p['mainform']) {
         // Display submit button
         echo "<input type='submit' name='".$p['actionname']."' value=\"".$p['actionvalue']."\" class='submit' >";
         if ($p['showbookmark'] || $p['showreset']) {
            if ($p['showbookmark']) {
               //TODO: change that!
               Ajax::createIframeModalWindow('loadbookmark',
                                       SavedSearch::getSearchURL() . "?action=load&type=" . SavedSearch::SEARCH,
                                       ['title'         => __('Load a saved search')]);
               SavedSearch::showSaveButton(
                  SavedSearch::SEARCH,
                  $itemtype,
                  isset($_GET['savedsearches_id'])
               );
            }

            if ($p['showreset']) {
               echo "<a class='fa fa-undo reset-search' href='"
                  .$p['target']
                  .(strpos($p['target'], '?') ? '&amp;' : '?')
                  ."reset=reset' title=\"".__s('Blank')."\"
                  ><span class='sr-only'>" . __s('Blank')  ."</span></a>";
            }

            if ($p['showfolding']) {
               echo "<a class='fa fa-angle-double-up fa-fw fold-search'
                        href='#'
                        title=\"".__("Fold search")."\"></a>";
            }
         }
      }
      echo "</div>"; //.search_actions

      // idor checks
      $idor_display_criteria       = Session::getNewIDORToken($itemtype);
      $idor_display_meta_criteria  = Session::getNewIDORToken($itemtype);
      $idor_display_criteria_group = Session::getNewIDORToken($itemtype);

      $JS = <<<JAVASCRIPT
         $('#addsearchcriteria$rand_criteria').on('click', function(event) {
            event.preventDefault();
            $.post('{$CFG_GLPI['root_doc']}/ajax/search.php', {
               'action': 'display_criteria',
               'itemtype': '$itemtype',
               'num': $nbsearchcountvar,
               'p': $json_p,
               '_idor_token': '$idor_display_criteria'
            })
            .done(function(data) {
               $(data).insertBefore('#more-criteria$rand_criteria');
               $nbsearchcountvar++;
            });
         });

         $('#addmetasearchcriteria$rand_criteria').on('click', function(event) {
            event.preventDefault();
            $.post('{$CFG_GLPI['root_doc']}/ajax/search.php', {
               'action': 'display_meta_criteria',
               'itemtype': '$itemtype',
               'meta': true,
               'num': $nbsearchcountvar,
               'p': $json_p,
               '_idor_token': '$idor_display_meta_criteria'
            })
            .done(function(data) {
               $(data).insertBefore('#more-criteria$rand_criteria');
               $nbsearchcountvar++;
            });
         });

         $('#addcriteriagroup$rand_criteria').on('click', function(event) {
            event.preventDefault();
            $.post('{$CFG_GLPI['root_doc']}/ajax/search.php', {
               'action': 'display_criteria_group',
               'itemtype': '$itemtype',
               'meta': true,
               'num': $nbsearchcountvar,
               'p': $json_p,
               '_idor_token': '$idor_display_criteria_group'
            })
            .done(function(data) {
               $(data).insertBefore('#more-criteria$rand_criteria');
               $nbsearchcountvar++;
            });
         });
JAVASCRIPT;

      if ($p['mainform']) {
         $JS .= <<<JAVASCRIPT
         $('.fold-search').on('click', function(event) {
            event.preventDefault();
            $(this)
               .toggleClass('fa-angle-double-up')
               .toggleClass('fa-angle-double-down');
            $('#searchcriteria ul li:not(:first-child)').toggle();
         });

         $(document).on("click", ".remove-search-criteria", function() {
            var rowID = $(this).data('rowid');
            $('#' + rowID).remove();
            $('#searchcriteria ul li:first-child').addClass('headerRow').show();
         });
JAVASCRIPT;
      }
      echo Html::scriptBlock($JS);

      if (count($p['addhidden'])) {
         foreach ($p['addhidden'] as $key => $val) {
            echo Html::hidden($key, ['value' => $val]);
         }
      }

      if ($p['mainform']) {
         // For dropdown
         echo Html::hidden('itemtype', ['value' => $itemtype]);
         // Reset to start when submit new search
         echo Html::hidden('start', ['value'    => 0]);
      }

      echo "</div>";
      if ($p['mainform']) {
         Html::closeForm();
      }
   }

   /**
    * Display a criteria field set, this function should be called by ajax/search.php
    *
    * @since 9.4
    *
    * @param  array  $request we should have these keys of parameters:
    *                            - itemtype: main itemtype for criteria, sub one for metacriteria
    *                            - num: index of the criteria
    *                            - p: params of showGenericSearch method
    *
    * @return void
    */
   static function displayCriteria($request = []) {
      global $CFG_GLPI;

      if (!isset($request["itemtype"])
          || !isset($request["num"])) {
         return "";
      }

      $num         = (int) $request['num'];
      $p           = $request['p'];
      $options     = self::getCleanedOptions($request["itemtype"]);
      $randrow     = mt_rand();
      $rowid       = 'searchrow'.$request['itemtype'].$randrow;
      $addclass    = $num == 0 ? ' headerRow' : '';
      $prefix      = isset($p['prefix_crit']) ? $p['prefix_crit'] :'';
      $parents_num = isset($p['parents_num']) ? $p['parents_num'] : [];
      $criteria    = [];

      $sess_itemtype = $request["itemtype"];
      if (isset($request['from_meta'])
          && $request['from_meta']) {
         $sess_itemtype = $request["parent_itemtype"];
      }

      if (!$criteria = self::findCriteriaInSession($sess_itemtype, $num, $parents_num)) {
         $criteria = self::getDefaultCriteria($request["itemtype"]);
      }

      if (isset($criteria['meta'])
          && $criteria['meta']
          && (!isset($request['from_meta'])
              || !$request['from_meta'])) {
         return self::displayMetaCriteria($request);
      }

      if (isset($criteria['criteria'])
          && is_array($criteria['criteria'])) {
         return self::displayCriteriaGroup($request);
      }

      echo "<li class='normalcriteria$addclass' id='$rowid'>";

      if (!isset($request['from_meta'])
          || !$request['from_meta']) {
         // First line display add / delete images for normal and meta search items
         if ($num == 0
             && isset($p['mainform'])
             && $p['mainform']) {
            // Instanciate an object to access method
            $item = null;
            if ($request["itemtype"] != 'AllAssets') {
               $item = getItemForItemtype($request["itemtype"]);
            }
            if ($item && $item->maybeDeleted()) {
               echo Html::hidden('is_deleted', [
                  'value' => $p['is_deleted'],
                  'id'    => 'is_deleted'
               ]);
            }
            echo Html::hidden('as_map', [
               'value' => $p['as_map'],
               'id'    => 'as_map'
            ]);
         }
         echo "<i class='far fa-minus-square remove-search-criteria' alt='-' title=\"".
                  __s('Delete a rule')."\" data-rowid='$rowid'></i>&nbsp;";

      }

      // Display link item
      $value = '';
      if (!isset($request['from_meta'])
          || !$request['from_meta']) {
         if (isset($criteria["link"])) {
            $value = $criteria["link"];
         }
         $operators = Search::getLogicalOperators(($num == 0));
         Dropdown::showFromArray("criteria{$prefix}[$num][link]", $operators, [
            'value' => $value,
            'width' => '80px'
         ]);
      }

      $values   = [];
      // display select box to define search item
      if ($CFG_GLPI['allow_search_view'] == 2 && !isset($request['from_meta'])) {
         $values['view'] = __('Items seen');
      }

      reset($options);
      $group = '';

      foreach ($options as $key => $val) {
         // print groups
         if (!is_array($val)) {
            $group = $val;
         } else if (count($val) == 1) {
            $group = $val['name'];
         } else {
            if ((!isset($val['nosearch']) || ($val['nosearch'] == false))
                && (!array_key_exists('nometa', $val) || $val['nometa'] !== true)) {
               $values[$group][$key] = $val["name"];
            }
         }
      }
      if ($CFG_GLPI['allow_search_view'] == 1 && !isset($request['from_meta'])) {
         $values['view'] = __('Items seen');
      }
      if ($CFG_GLPI['allow_search_all'] && !isset($request['from_meta'])) {
         $values['all'] = __('All');
      }
      $value = '';

      if (isset($criteria['field'])) {
         $value = $criteria['field'];
      }

      $rand = Dropdown::showFromArray("criteria{$prefix}[$num][field]", $values, [
         'value' => $value,
         'width' => '170px'
      ]);
      $field_id = Html::cleanId("dropdown_criteria{$prefix}[$num][field]$rand");
      $spanid   = Html::cleanId('SearchSpan'.$request["itemtype"].$prefix.$num);
      echo "<span id='$spanid'>";

      $used_itemtype = $request["itemtype"];
      // Force Computer itemtype for AllAssets to permit to show specific items
      if ($request["itemtype"] == 'AllAssets') {
         $used_itemtype = 'Computer';
      }

      $searchtype = isset($criteria['searchtype'])
                     ? $criteria['searchtype']
                     : "";
      $p_value    = isset($criteria['value'])
                     ? stripslashes($criteria['value'])
                     : "";

      $params = [
         'itemtype'    => $used_itemtype,
         '_idor_token' => Session::getNewIDORToken($used_itemtype),
         'field'       => $value,
         'searchtype'  => $searchtype,
         'value'       => $p_value,
         'num'         => $num,
         'p'           => $p,
      ];
      Search::displaySearchoption($params);
      echo "</span>";

      Ajax::updateItemOnSelectEvent(
         $field_id,
         $spanid,
         $CFG_GLPI["root_doc"]."/ajax/search.php",
         [
            'action'     => 'display_searchoption',
            'field'      => '__VALUE__',
         ] + $params
      );

      echo "</li>";
   }

   /**
    * Display a meta-criteria field set, this function should be called by ajax/search.php
    * Call displayCriteria method after displaying its itemtype field
    *
    * @since 9.4
    *
    * @param  array  $request @see displayCriteria method
    *
    * @return void
    */
   static function displayMetaCriteria($request = []) {
      global $CFG_GLPI;

      if (!isset($request["itemtype"])
          || !isset($request["num"])) {
         return "";
      }

      $p            = $request['p'];
      $num          = (int) $request['num'];
      $prefix       = isset($p['prefix_crit']) ? $p['prefix_crit'] : '';
      $parents_num  = isset($p['parents_num']) ? $p['parents_num'] : [];
      $itemtype     = $request["itemtype"];
      $metacriteria = [];

      if (!$metacriteria = self::findCriteriaInSession($itemtype, $num, $parents_num)) {
         // Set default field
         $options  = Search::getCleanedOptions($itemtype);

         foreach ($options as $key => $val) {
            if (is_array($val) && isset($val['table'])) {
               $metacriteria['field'] = $key;
               break;
            }
         }
      }

      $linked =  Search::getMetaItemtypeAvailable($itemtype);
      $rand   = mt_rand();

      $rowid  = 'metasearchrow'.$request['itemtype'].$rand;

      echo "<li class='metacriteria' id='$rowid'>";
      echo "<i class='far fa-minus-square remove-search-criteria' alt='-' title=\"".
               __s('Delete a global rule')."\" data-rowid='$rowid'></i>&nbsp;";

      // Display link item (not for the first item)
      Dropdown::showFromArray(
         "criteria{$prefix}[$num][link]",
         Search::getLogicalOperators(),
         [
            'value' => isset($metacriteria["link"])
               ? $metacriteria["link"]
               : "",
            'width' => '80px'
         ]
      );

      // Display select of the linked item type available
      $rand = Dropdown::showItemTypes("criteria{$prefix}[$num][itemtype]", $linked, [
         'value' => isset($metacriteria['itemtype'])
                    && !empty($metacriteria['itemtype'])
                     ? $metacriteria['itemtype']
                     : "",
         'width' => '170px'
      ]);
      echo Html::hidden("criteria{$prefix}[$num][meta]", [
         'value' => true
      ]);
      $field_id = Html::cleanId("dropdown_criteria{$prefix}[$num][itemtype]$rand");
      $spanid   = Html::cleanId("show_".$request["itemtype"]."_".$prefix.$num."_$rand");
      // Ajax script for display search met& item
      echo "<blockquote>";

      $params = [
         'action'          => 'display_criteria',
         'itemtype'        => '__VALUE__',
         'parent_itemtype' => $request['itemtype'],
         'from_meta'       => true,
         'num'             => $num,
         'p'               => $request["p"],
         '_idor_token'     => Session::getNewIDORToken("", [
            'parent_itemtype' => $request['itemtype']
         ])
      ];
      Ajax::updateItemOnSelectEvent(
         $field_id,
         $spanid,
         $CFG_GLPI["root_doc"]."/ajax/search.php",
         $params
      );

      echo "<span id='$spanid'>";
      if (isset($metacriteria['itemtype'])
          && !empty($metacriteria['itemtype'])) {
         $params['itemtype'] = $metacriteria['itemtype'];
         self::displayCriteria($params);
      }
      echo "</span>";
      echo "</blockquote>";
      echo "</li>";
   }

   /**
    * Display a group of nested criteria.
    * A group (parent) criteria  can contains children criteria (who also cantains children, etc)
    *
    * @since 9.4
    *
    * @param  array  $request @see displayCriteria method
    *
    * @return void
    */
   static function displayCriteriaGroup($request = []) {
      $num         = (int) $request['num'];
      $p           = $request['p'];
      $randrow     = mt_rand();
      $rowid       = 'searchrow'.$request['itemtype'].$randrow;
      $addclass    = $num == 0 ? ' headerRow' : '';
      $prefix      = isset($p['prefix_crit']) ? $p['prefix_crit'] : '';
      $parents_num = isset($p['parents_num']) ? $p['parents_num'] : [];

      if (!$criteria = self::findCriteriaInSession($request['itemtype'], $num, $parents_num)) {
         $criteria = [
            'criteria' => self::getDefaultCriteria($request['itemtype']),
         ];
      }

      echo "<li class='normalcriteria$addclass' id='$rowid'>";
      echo "<i class='far fa-minus-square remove-search-criteria' alt='-' title=\"".
               __s('Delete a rule')."\" data-rowid='$rowid'></i>&nbsp;";
      Dropdown::showFromArray("criteria{$prefix}[$num][link]", Search::getLogicalOperators(), [
         'value' => isset($criteria["link"]) ? $criteria["link"] : '',
         'width' => '80px'
      ]);

      $parents_num = isset($p['parents_num']) ? $p['parents_num'] : [];
      array_push($parents_num, $num);
      $params = [
         'mainform'    => false,
         'prefix_crit' => "{$prefix}[$num][criteria]",
         'parents_num' => $parents_num,
         'criteria'    => $criteria['criteria'],
      ];

      echo self::showGenericSearch($request['itemtype'], $params);
      echo "</li>";
   }

   /**
    * Retrieve a single criteria in Session by its index
    *
    * @since 9.4
    *
    * @param  string  $itemtype    which glpi type we must search in session
    * @param  integer $num         index of the criteria
    * @param  array   $parents_num node indexes of the parents (@see displayCriteriaGroup)
    *
    * @return mixed   the found criteria array of false of nothing found
    */
   static function findCriteriaInSession($itemtype = '', $num = 0, $parents_num = []) {
      if (!isset($_SESSION['glpisearch'][$itemtype]['criteria'])) {
         return false;
      }
      $criteria = &$_SESSION['glpisearch'][$itemtype]['criteria'];

      if (count($parents_num)) {
         foreach ($parents_num as $parent) {
            if (!isset($criteria[$parent]['criteria'])) {
               return false;
            }
            $criteria = &$criteria[$parent]['criteria'];
         }
      }

      if (isset($criteria[$num])
          && is_array($criteria[$num])) {
         return $criteria[$num];
      }

      return false;
   }

   /**
    * construct the default criteria for an itemtype
    *
    * @since 9.4
    *
    * @param  string $itemtype
    *
    * @return array  criteria
    */
   static function getDefaultCriteria($itemtype = '') {
      global $CFG_GLPI;

      $field = '';

      if ($CFG_GLPI['allow_search_view'] == 2) {
         $field = 'view';
      } else {
         $options = self::getCleanedOptions($itemtype);
         foreach ($options as $key => $val) {
            if (is_array($val)
                && isset($val['table'])) {
               $field = $key;
               break;
            }
         }
      }

      return [
         [
            'field' => $field,
            'link'  => 'contains',
            'value' => ''
         ]
      ];
   }

   /**
    * Display first part of criteria (field + searchtype, just after link)
    * will call displaySearchoptionValue for the next part (value)
    *
    * @since 9.4
    *
    * @param  array  $request we should have these keys of parameters:
    *                            - itemtype: main itemtype for criteria, sub one for metacriteria
    *                            - num: index of the criteria
    *                            - field: field key of the criteria
    *                            - p: params of showGenericSearch method
    *
    * @return void
    */
   static function displaySearchoption($request = []) {
      global $CFG_GLPI;
      if (!isset($request["itemtype"])
          || !isset($request["field"])
          || !isset($request["num"])) {
         return "";
      }

      $p      = $request['p'];
      $num    = (int) $request['num'];
      $prefix = isset($p['prefix_crit']) ? $p['prefix_crit'] : '';

      if (!is_subclass_of($request['itemtype'], 'CommonDBTM')) {
         throw new \RuntimeException('Invalid itemtype provided!');
      }

      if (isset($request['meta']) && $request['meta']) {
         $fieldname = 'metacriteria';
      } else {
         $fieldname = 'criteria';
         $request['meta'] = 0;
      }

      $actions = Search::getActionsFor($request["itemtype"], $request["field"]);

      // is it a valid action for type ?
      if (count($actions)
          && (empty($request['searchtype']) || !isset($actions[$request['searchtype']]))) {
         $tmp = $actions;
         unset($tmp['searchopt']);
         $request['searchtype'] = key($tmp);
         unset($tmp);
      }

      $rands = -1;
      $dropdownname = Html::cleanId("spansearchtype$fieldname".
                                    $request["itemtype"].
                                    $prefix.
                                    $num);
      $searchopt = [];
      if (count($actions)>0) {
         // get already get search options
         if (isset($actions['searchopt'])) {
            $searchopt = $actions['searchopt'];
            // No name for clean array with quotes
            unset($searchopt['name']);
            unset($actions['searchopt']);
         }
         $searchtype_name = "{$fieldname}{$prefix}[$num][searchtype]";
         $rands = Dropdown::showFromArray($searchtype_name, $actions, [
            'value' => $request["searchtype"],
            'width' => '105px'
         ]);
         $fieldsearch_id = Html::cleanId("dropdown_$searchtype_name$rands");
      }

      echo "<span id='$dropdownname'>";
      $params = [
         'value'       => rawurlencode(stripslashes($request['value'])),
         'searchopt'   => $searchopt,
         'searchtype'  => $request["searchtype"],
         'num'         => $num,
         'itemtype'    => $request["itemtype"],
         '_idor_token' => Session::getNewIDORToken($request["itemtype"]),
         'from_meta'   => isset($request['from_meta'])
                           ? $request['from_meta']
                           : false,
         'field'       => $request["field"],
         'p'           => $p,
      ];
      self::displaySearchoptionValue($params);
      echo "</span>";

      Ajax::updateItemOnSelectEvent(
         $fieldsearch_id,
         $dropdownname,
         $CFG_GLPI["root_doc"]."/ajax/search.php",
         [
            'action'     => 'display_searchoption_value',
            'searchtype' => '__VALUE__',
         ] + $params
      );
   }

   /**
    * Display last part of criteria (value, just after searchtype)
    * called by displaySearchoptionValue
    *
    * @since 9.4
    *
    * @param  array  $request we should have these keys of parameters:
    *                            - searchtype: (contains, equals) passed by displaySearchoption
    *
    * @return void
    */
   static function displaySearchoptionValue($request = []) {
      if (!isset($request['searchtype'])) {
         return "";
      }

      $p                 = $request['p'];
      $prefix            = isset($p['prefix_crit']) ? $p['prefix_crit'] : '';
      $searchopt         = isset($request['searchopt']) ? $request['searchopt'] : [];
      $request['value']  = rawurldecode($request['value']);
      $fieldname         = isset($request['meta']) && $request['meta']
                              ? 'metacriteria'
                              : 'criteria';
      $inputname         = $fieldname.$prefix.'['.$request['num'].'][value]';
      $display           = false;
      $item              = getItemForItemtype($request['itemtype']);
      $options2          = [];
      $options2['value'] = $request['value'];
      $options2['width'] = '100%';
      // For tree dropdpowns
      $options2['permit_select_parent'] = true;

      switch ($request['searchtype']) {
         case "equals" :
         case "notequals" :
         case "morethan" :
         case "lessthan" :
         case "under" :
         case "notunder" :
            if (!$display && isset($searchopt['field'])) {
               // Specific cases
               switch ($searchopt['table'].".".$searchopt['field']) {
                  // Add mygroups choice to searchopt
                  case "glpi_groups.completename" :
                     $searchopt['toadd'] = ['mygroups' => __('My groups')];
                     break;

                  case "glpi_changes.status" :
                  case "glpi_changes.impact" :
                  case "glpi_changes.urgency" :
                  case "glpi_problems.status" :
                  case "glpi_problems.impact" :
                  case "glpi_problems.urgency" :
                  case "glpi_tickets.status" :
                  case "glpi_tickets.impact" :
                  case "glpi_tickets.urgency" :
                     $options2['showtype'] = 'search';
                     break;

                  case "glpi_changes.priority" :
                  case "glpi_problems.priority" :
                  case "glpi_tickets.priority" :
                     $options2['showtype']  = 'search';
                     $options2['withmajor'] = true;
                     break;

                  case "glpi_tickets.global_validation" :
                     $options2['all'] = true;
                     break;

                  case "glpi_ticketvalidations.status" :
                     $options2['all'] = true;
                     break;

                  case "glpi_users.name" :
                     $options2['right']            = (isset($searchopt['right']) ? $searchopt['right'] : 'all');
                     $options2['inactive_deleted'] = 1;
                     break;
               }

               // Standard datatype usage
               if (!$display && isset($searchopt['datatype'])) {
                  switch ($searchopt['datatype']) {

                     case "date" :
                     case "date_delay" :
                     case "datetime" :
                        $options2['relative_dates'] = true;
                        break;
                  }
               }

               $out = $item->getValueToSelect($searchopt, $inputname, $request['value'], $options2);
               if (strlen($out)) {
                  echo $out;
                  $display = true;
               }

               //Could display be handled by a plugin ?
               if (!$display
                   && $plug = isPluginItemType(getItemTypeForTable($searchopt['table']))) {
                  $display = Plugin::doOneHook(
                     $plug['plugin'],
                     'searchOptionsValues',
                     [
                        'name'           => $inputname,
                        'searchtype'     => $request['searchtype'],
                        'searchoption'   => $searchopt,
                        'value'          => $request['value']
                     ]
                  );
               }

            }
           break;
      }

      // Default case : text field
      if (!$display) {
           echo "<input type='text' size='13' name='$inputname' value=\"".
                  Html::cleanInputText($request['value'])."\">";
      }
   }


   /**
    * Generic Function to add GROUP BY to a request
    *
    * @since 9.4: $num param has been dropped
    *
    * @param string  $LINK           link to use
    * @param string  $NOT            is is a negative search ?
    * @param string  $itemtype       item type
    * @param integer $ID             ID of the item to search
    * @param string  $searchtype     search type ('contains' or 'equals')
    * @param string  $val            value search
    *
    * @return select string
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function addHaving($LINK, $NOT, $itemtype, $ID, $searchtype, $val) {

      global $DB;

      $searchopt  = &self::getOptions($itemtype);
      if (!isset($searchopt[$ID]['table'])) {
         return false;
      }
      $table = $searchopt[$ID]["table"];
      $NAME = $this->db->quoteName("ITEM_{$itemtype}_{$ID}");

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $out = Plugin::doOneHook(
            $plug['plugin'],
            'addHaving',
            $LINK, $NOT, $itemtype, $ID, $val, "{$itemtype}_{$ID}"
         );
         if (!empty($out)) {
            return $out;
         }
      }

      //// Default cases
      // Link with plugin tables
      if (preg_match("/^glpi_plugin_([a-z0-9]+)/", $table, $matches)) {
         if (count($matches) == 2) {
            $plug     = $matches[1];
            $out = Plugin::doOneHook(
               $plug,
               'addHaving',
               $LINK, $NOT, $itemtype, $ID, $val, "{$itemtype}_{$ID}"
            );
            if (!empty($out)) {
               return $out;
            }
         }
      }

      if (in_array($searchtype, ["notequals", "notcontains"])) {
         $NOT = !$NOT;
      }

      // Preformat items
      if (isset($searchopt[$ID]["datatype"])) {
         switch ($searchopt[$ID]["datatype"]) {
            case "datetime" :
               if (in_array($searchtype, ['contains', 'notcontains'])) {
                  break;
               }

               $force_day = false;
               if (strstr($val, 'BEGIN') || strstr($val, 'LAST')) {
                  $force_day = true;
               }

               $val = Html::computeGenericDateTimeSearch($val, $force_day);

               $operator = '';
               switch ($searchtype) {
                  case 'equals':
                     $operator = !$NOT ? '=' : '!=';
                     break;
                  case 'notequals':
                     $operator = !$NOT ? '!=' : '=';
                     break;
                  case 'lessthan':
                     $operator = !$NOT ? '<' : '>';
                     break;
                  case 'morethan':
                     $operator = !$NOT ? '>' : '<';
                     break;
               }

               return " {$LINK} ({$this->db->quoteName($NAME)} $operator {$this->db->quoteValue($val)}) ";
               break;
            case "count" :
            case "number" :
            case "decimal" :
            case "timestamp" :
               $search  = ["/\&lt;/","/\&gt;/"];
               $replace = ["<",">"];
               $val     = preg_replace($search, $replace, $val);
               if (preg_match("/([<>])([=]*)[[:space:]]*([0-9]+)/", $val, $regs)) {
                  if ($NOT) {
                     if ($regs[1] == '<') {
                        $regs[1] = '>';
                     } else {
                        $regs[1] = '<';
                     }
                  }
                  $regs[1] .= $regs[2];
                  return " $LINK ($NAME ".$regs[1]." ".$this->db->quote($regs[3])." ) ";
               }

               if (is_numeric($val)) {
                  $val = (int)$val;
                  if (isset($searchopt[$ID]["width"])) {
                     if (!$NOT) {
                        return " $LINK ($NAME < ".($val + $searchopt[$ID]['width'])." AND $NAME > ".
                           ($val - $searchopt[$ID]['width']).") ";
                     }
                     return " $LINK ($NAME > ".($val + $searchopt[$ID]['width'])." OR $NAME < ".
                        ($val - $searchopt[$ID]['width']).") ";
                  }
                  // Exact search
                  if (!$NOT) {
                     return " $LINK ($NAME = $val) ";
                  }
                  return " $LINK ($NAME <> $val) ";
               }
               break;
         }
      }

      return $this->makeTextCriteria($NAME, $val, $NOT, $LINK);
   }


   /**
    * Generic Function to add ORDER BY to a request
    *
    * @since 9.4: $key param has been dropped
    *
    * @param string  $itemtype  ID of the device type
    * @param integer $ID        field to add
    * @param string  $order     order define
    *
    * @return select string
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function addOrderBy($itemtype, $ID, $order) {
      global $CFG_GLPI;

      // Security test for order
      if ($order != "ASC") {
         $order = "DESC";
      }
      $searchopt = &self::getOptions($itemtype);

      $table     = $searchopt[$ID]["table"];
      $field     = $searchopt[$ID]["field"];

      $addtable = '';

      $is_fkey_composite_on_self = getTableNameForForeignKeyField($searchopt[$ID]["linkfield"]) == $table
         && $searchopt[$ID]["linkfield"] != getForeignKeyFieldForTable($table);
      $orig_table = self::getOrigTableName($itemtype);
      if (($is_fkey_composite_on_self || $table != $orig_table)
          && ($searchopt[$ID]["linkfield"] != getForeignKeyFieldForTable($table))) {
         $addtable .= "_".$searchopt[$ID]["linkfield"];
      }

      if (isset($searchopt[$ID]['joinparams'])) {
         $complexjoin = self::computeComplexJoinID($searchopt[$ID]['joinparams']);

         if (!empty($complexjoin)) {
            $addtable .= "_".$complexjoin;
         }
      }

      if (isset($CFG_GLPI["union_search_type"][$itemtype])) {
         return " ORDER BY `ITEM_{$itemtype}_{$ID}` $order ";
      }

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $out = Plugin::doOneHook(
            $plug['plugin'],
            'addOrderBy',
            $itemtype, $ID, $order, "{$itemtype}_{$ID}"
         );
         if (!empty($out)) {
            return $out;
         }
      }

      switch ($table.".".$field) {
         case "glpi_auth_tables.name" :
            $user_searchopt = self::getOptions('User');
            return " ORDER BY ".$this->db->quoteName("glpi_users.authtype")." $order,
                              ".$this->db->quoteName("glpi_authldaps{$addtable}_".
                                 self::computeComplexJoinID($user_searchopt[30]['joinparams']))."
                                 ".$this->db->quoteName('name')." $order,
                                 ".$this->db->quoteName("glpi_authmails{$addtable}_".
                                 self::computeComplexJoinID($user_searchopt[31]['joinparams'])).".
                                 ".$this->db->quoteName('name')." $order ";

         case "glpi_users.name" :
            if ($itemtype!='User') {
               if ($_SESSION["glpinames_format"] == User::FIRSTNAME_BEFORE) {
                  $name1 = 'firstname';
                  $name2 = 'realname';
               } else {
                  $name1 = 'realname';
                  $name2 = 'firstname';
               }
               return " ORDER BY ".$this->db->quoteName("$table$addtable.$name1")." $order,
                                 ".$this->db->quoteName("$table$addtable.$name2")." $order,
                                 ".$this->db->quoteName("$table$addtable.name")." $order";
            }
            return " ORDER BY ".$this->db->quoteName("$table.name")." $order";

         case "glpi_networkequipments.ip" :
         case "glpi_ipaddresses.name" :
            return " ORDER BY INET_ATON(".$this->db->quoteName("$table$addtable.$field").") $order ";
      }

      //// Default cases

      // Link with plugin tables
      if (preg_match("/^glpi_plugin_([a-z0-9]+)/", $table, $matches)) {
         if (count($matches) == 2) {
            $plug     = $matches[1];
            $out = Plugin::doOneHook(
               $plug,
               'addOrderBy',
               $itemtype, $ID, $order, "{$itemtype}_{$ID}"
            );
            if (!empty($out)) {
               return $out;
            }
         }
      }

      // Preformat items
      if (isset($searchopt[$ID]["datatype"])) {
         switch ($searchopt[$ID]["datatype"]) {
            case "date_delay" :
               $interval = "MONTH";
               if (isset($searchopt[$ID]['delayunit'])) {
                  $interval = $searchopt[$ID]['delayunit'];
               }

               $add_minus = '';
               if (isset($searchopt[$ID]["datafields"][3])) {
                  $add_minus = "- ".$this->db->quoteName("$table$addtable.".$searchopt[$ID]["datafields"][3]);
               }
               return " ORDER BY ADDDATE(".$this->db->quoteName("$table$addtable.".$searchopt[$ID]["datafields"][1]).",
                                         INTERVAL (".$this->db->quoteName("$table$addtable.".
                                                   $searchopt[$ID]["datafields"][2])." $add_minus)
                                         $interval) $order ";
         }
      }

      return " ORDER BY `ITEM_{$itemtype}_{$ID}` $order ";
   }


   /**
    * Generic Function to add default columns to view
    *
    * @param string $itemtype device type
    * @param array  $params   array of parameters
    *
    * @return array
   **/
   static function addDefaultToView($itemtype, $params) {
      global $CFG_GLPI;

      $toview = [];
      $item   = null;
      $entity_check = true;

      if ($itemtype != 'AllAssets') {
         $item = getItemForItemtype($itemtype);
         $entity_check = $item->isEntityAssign();
      }
      // Add first element (name)
      array_push($toview, 1);

      if (isset($params['as_map']) && $params['as_map'] == 1) {
         // Add location name when map mode
         array_push($toview, ($itemtype == 'Location' ? 1 : ($itemtype == 'Ticket' ? 83 : 3)));
      }

      // Add entity view :
      if (Session::isMultiEntitiesMode()
          && $entity_check
          && (isset($CFG_GLPI["union_search_type"][$itemtype])
              || ($item && $item->maybeRecursive())
              || isset($_SESSION['glpiactiveentities']) && (count($_SESSION["glpiactiveentities"]) > 1))) {
         array_push($toview, 80);
      }
      return $toview;
   }


   /**
    * Generic Function to add default select to a request
    *
    * @param string $itemtype device type
    *
    * @return string Select SQL string
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function addDefaultSelect($itemtype) {
      global $DB;

      $itemtable = self::getOrigTableName($itemtype);
      $item      = null;
      $mayberecursive = false;
      if ($itemtype != 'AllAssets') {
         $item           = getItemForItemtype($itemtype);
         $mayberecursive = $item->maybeRecursive();
      }
      $ret = "";
      switch ($itemtype) {

         case 'FieldUnicity' :
            $ret = $this->db->quoteName("glpi_fieldunicities.itemtype")." AS ITEMTYPE,";
            break;

         default :
            // Plugin can override core definition for its type
            if ($plug = isPluginItemType($itemtype)) {
               $ret = Plugin::doOneHook(
                  $plug['plugin'],
                  'addDefaultSelect',
                  $itemtype
               );
            }
      }
      if ($itemtable == 'glpi_entities') {
         $ret .= $this->db->quoteName("$itemtable.id")." AS entities_id, '1' AS is_recursive, ";
      } else if ($mayberecursive) {
         if ($item->isField('entities_id')) {
            $ret .= $DB->quoteName("$itemtable.entities_id").", ";
         }
         if ($item->isField('is_recursive')) {
            $ret .= $DB->quoteName("$itemtable.is_recursive").", ";
         }
      }
      return $ret;
   }


   /**
    * Generic Function to add select to a request
    *
    * @since 9.4: $num param has been dropped
    *
    * @param string  $itemtype     item type
    * @param integer $ID           ID of the item to add
    * @param boolean $meta         boolean is a meta
    * @param integer $meta_type    meta type table ID (default 0)
    *
    * @return string Select string
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function addSelect($itemtype, $ID, $meta = 0, $meta_type = 0) {
      global $CFG_GLPI;

      $searchopt   = &self::getOptions($itemtype);
      $table       = $searchopt[$ID]["table"];
      $field       = $searchopt[$ID]["field"];
      $addtable    = "";
      $addtable2   = "";
      $NAME        = "ITEM_{$itemtype}_{$ID}";
      $complexjoin = '';

      if (isset($searchopt[$ID]['joinparams'])) {
         $complexjoin = self::computeComplexJoinID($searchopt[$ID]['joinparams']);
      }

      $is_fkey_composite_on_self = getTableNameForForeignKeyField($searchopt[$ID]["linkfield"]) == $table
         && $searchopt[$ID]["linkfield"] != getForeignKeyFieldForTable($table);

      $orig_table = self::getOrigTableName($itemtype);
      if (((($is_fkey_composite_on_self || $table != $orig_table)
            && (!isset($CFG_GLPI["union_search_type"][$itemtype])
                || ($CFG_GLPI["union_search_type"][$itemtype] != $table)))
           || !empty($complexjoin))
          && ($searchopt[$ID]["linkfield"] != getForeignKeyFieldForTable($table))) {
         $addtable .= "_".$searchopt[$ID]["linkfield"];
      }

      if (!empty($complexjoin)) {
         $addtable .= "_".$complexjoin;
         $addtable2 .= "_".$complexjoin;
      }

      if ($meta) {
         // $NAME = "META";
         if ($meta_type::getTable() != $table) {
            $addtable  .= "_".$meta_type;
            $addtable2 .= "_".$meta_type;
         }
      }

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $out = Plugin::doOneHook(
            $plug['plugin'],
            'addSelect',
            $itemtype, $ID, "{$itemtype}_{$ID}"
         );
         if (!empty($out)) {
            return $out;
         }
      }

      $tocompute      = $this->db->quoteName("$table$addtable.$field");
      $tocomputeid    = $this->db->quoteName("$table$addtable.id");

      $tocomputetrans = "IFNULL(".$this->db->quoteName("$table{$addtable}_trans.value").", '".$this->db->escape(self::NULLVALUE).")' ";

      $ADDITONALFIELDS = '';
      if (isset($searchopt[$ID]["additionalfields"])
          && count($searchopt[$ID]["additionalfields"])) {
         foreach ($searchopt[$ID]["additionalfields"] as $key) {
            if ($meta
                || (isset($searchopt[$ID]["forcegroupby"]) && $searchopt[$ID]["forcegroupby"])) {
               $ADDITONALFIELDS .= " IFNULL(GROUP_CONCAT(DISTINCT CONCAT(IFNULL(".$this->db->quoteName("$table$addtable.$key").",
                                                                         ".$this->db->quote(self::NULLVALUE)."),
                                                   ".$this->db->quote(self::SHORTSEP).", $tocomputeid) SEPARATOR '".self::LONGSEP."'), ".$this->db->quote(self::NULLVALUE.self::SHORTSEP).")
                                    AS ".$this->db->quoteName($NAME . "_$key").", ";
            } else {
               $ADDITONALFIELDS .= $this->db->quoteName("$table$addtable.$key"). " AS ".$this->db->quoteName($NAME . "_$key").", ";
            }
         }
      }

      // Virtual display no select : only get additional fields
      if (strpos($field, '_virtual') === 0) {
         return $ADDITONALFIELDS;
      }

      switch ($table.".".$field) {

         case "glpi_users.name" :
            if ($itemtype != 'User') {
               if ((isset($searchopt[$ID]["forcegroupby"]) && $searchopt[$ID]["forcegroupby"])) {
                  $addaltemail = "";
                  if ((($itemtype == 'Ticket') || ($itemtype == 'Problem'))
                      && isset($searchopt[$ID]['joinparams']['beforejoin']['table'])
                      && (($searchopt[$ID]['joinparams']['beforejoin']['table']
                            == 'glpi_tickets_users')
                          || ($searchopt[$ID]['joinparams']['beforejoin']['table']
                                == 'glpi_problems_users')
                          || ($searchopt[$ID]['joinparams']['beforejoin']['table']
                                == 'glpi_changes_users'))) { // For tickets_users

                     $ticket_user_table
                        = $searchopt[$ID]['joinparams']['beforejoin']['table'].
                          "_".self::computeComplexJoinID($searchopt[$ID]['joinparams']['beforejoin']
                                                                   ['joinparams']);
                     $addaltemail
                        = "GROUP_CONCAT(DISTINCT CONCAT(".$this->db->quoteName("$ticket_user_table.users_id").", ' ',
                                                        ".$this->db->quoteName("$ticket_user_table.alternative_email").")
                                                        SEPARATOR '".self::LONGSEP."') AS ".$this->db->quoteName($NAME."_2").", ";
                  }
                  return " GROUP_CONCAT(DISTINCT ".$this->db->quoteName("$table$addtable.id")." SEPARATOR '".self::LONGSEP."')
                                       AS ".$this->db->quoteName($NAME).",
                           $addaltemail
                           $ADDITONALFIELDS";

               }
               return " ".$this->db->quoteName("$table$addtable.$field")." AS ".$this->db->quoteName($NAME).",
                        ".$this->db->quoteName("$table$addtable.realname")." AS ".$this->db->quoteName($NAME."_realname").",
                        ".$this->db->quoteName("$table$addtable.id")."  AS ".$this->db->quoteName($NAME."_id").",
                        ".$this->db->quoteName("$table$addtable.firstname")." AS ".$this->db->quoteName($NAME."_firstname").",
                        $ADDITONALFIELDS";
            }
            break;

         case "glpi_softwarelicenses.number" :
            if ($meta) {
               return " FLOOR(SUM(".$this->db->quoteName("$table$addtable2.$field").")
                              * COUNT(DISTINCT ".$this->db->quoteName("$table$addtable2.id").")
                              / COUNT(".$this->db->quoteName("$table$addtable2.id").")) AS ".$this->db->quoteName($NAME).",
                        MIN(".$this->db->quoteName("$table$addtable2.$field").") AS ".$this->db->quoteName($NAME."_min").",
                         $ADDITONALFIELDS";
            } else {
               return " FLOOR(SUM(".$this->db->quoteName("$table$addtable.$field").")
                              * COUNT(DISTINCT ".$this->db->quoteName("$table$addtable.id").")
                              / COUNT(".$this->db->quoteName("$table$addtable.id").")) AS ".$this->db->quoteName($NAME).",
                        MIN(".$this->db->quoteName("$table$addtable.$field").") AS".$this->db->quoteName($NAME."_min").",
                         $ADDITONALFIELDS";
            }

         case "glpi_profiles.name" :
            if (($itemtype == 'User')
                && ($ID == 20)) {

               $addtable2 = '';
               if ($meta) {
                  $addtable2 = "_".$meta_type;
               }
               return " GROUP_CONCAT(".$this->db->quoteName("$table$addtable.$field")." SEPARATOR '".self::LONGSEP."') AS ".$this->db->quoteName($NAME).",
                        GROUP_CONCAT(".$this->db->quoteName("glpi_profiles_users$addtable2.entities_id")." SEPARATOR '".self::LONGSEP."')
                                    AS ".$this->db->quoteName($NAME."_entities_id").",
                        GROUP_CONCAT(".$this->db->quoteName("glpi_profiles_users$addtable2.is_recursive")." SEPARATOR '".self::LONGSEP."')
                                    AS ".$this->db->quoteName($NAME."_is_recursive").",
                        GROUP_CONCAT(".$this->db->quoteName("glpi_profiles_users$addtable2.is_dynamic")." SEPARATOR '".self::LONGSEP."')
                                    AS ".$this->db->quoteName($NAME."_is_dynamic").",
                        $ADDITONALFIELDS";
            }
            break;

         case "glpi_entities.completename" :
            if (($itemtype == 'User')
                && ($ID == 80)) {

               $addtable2 = '';
               if ($meta) {
                  $addtable2 = "_".$meta_type;
               }
               return " GROUP_CONCAT(".$this->db->quoteName("$table$addtable.completename")." SEPARATOR '".self::LONGSEP."')
                                    AS ".$this->db->quoteName($NAME).",
                        GROUP_CONCAT(".$this->db->quoteName("glpi_profiles_users$addtable2.profiles_id")." SEPARATOR '".self::LONGSEP."')
                                    AS ".$this->db->quoteName($NAME."_profiles_id").",
                        GROUP_CONCAT(".$this->db->quoteName("glpi_profiles_users$addtable2.is_recursive")." SEPARATOR '".self::LONGSEP."')
                                    AS ".$this->db->quoteName($NAME."_is_recursive").",
                        GROUP_CONCAT(".$this->db->quoteName("glpi_profiles_users$addtable2.is_dynamic")." SEPARATOR '".self::LONGSEP."')
                                    AS ".$this->db->quoteName($NAME."_is_dynamic").",
                        $ADDITONALFIELDS";
            }
            break;

         case "glpi_auth_tables.name":
            $user_searchopt = self::getOptions('User');
            return " ".$this->db->quoteName("glpi_users.authtype")." AS ".$this->db->quoteName($NAME).",
                     ".$this->db->quoteName("glpi_users.auths_id")." AS ".$this->db->quoteName($NAME."_auths_id").",
                     ".$this->db->quoteName("glpi_authldaps".$addtable."_".
                           self::computeComplexJoinID($user_searchopt[30]['joinparams']).".$field")."
                              AS ".$this->db->quoteName($NAME."_".$ID."_ldapname").",
                     ".$this->db->quoteName("glpi_authmails".$addtable."_".
                           self::computeComplexJoinID($user_searchopt[31]['joinparams']).".$field")."
                              AS ".$this->db->quoteName($NAME."_mailname").",
                     $ADDITONALFIELDS";

         case "glpi_softwareversions.name" :
            if ($meta) {
               return " GROUP_CONCAT(DISTINCT CONCAT(".$this->db->quoteName("glpi_softwares.name").", ' - ',
                                                     ".$this->db->quoteName("$table$addtable2.$field").", '".self::SHORTSEP."',
                                                     ".$this->db->quoteName("$table$addtable2.id").") SEPARATOR '".self::LONGSEP."')
                                    AS ".$this->db->quoteName($NAME).",
                        $ADDITONALFIELDS";
            }
            break;

         case "glpi_softwareversions.comment" :
            if ($meta) {
               return " GROUP_CONCAT(DISTINCT CONCAT(".$this->db->quoteName("glpi_softwares.name").", ' - ',
                                                     ".$this->db->quoteName("$table$addtable2.$field").",'".self::SHORTSEP."',
                                                     ".$this->db->quoteName("$table$addtable2.id").") SEPARATOR '".self::LONGSEP."')
                                    AS ".$this->db->quoteName($NAME).",
                        $ADDITONALFIELDS";
            }
            return " GROUP_CONCAT(DISTINCT CONCAT(".$this->db->quoteName("$table$addtable.name").", ' - ',
                                                  ".$this->db->quoteName("$table$addtable.$field").", '".self::SHORTSEP."',
                                                  ".$this->db->quoteName("$table$addtable.id").") SEPARATOR '".self::LONGSEP."')
                                 AS ".$this->db->quoteName($NAME).",
                     $ADDITONALFIELDS";

         case "glpi_states.name" :
            if ($meta && ($meta_type == 'Software')) {
               return " GROUP_CONCAT(DISTINCT CONCAT(".$this->db->quoteName("glpi_softwares.name").", ' - ',
                                                     ".$this->db->quoteName("glpi_softwareversions$addtable.name").", ' - ',
                                                     ".$this->db->quoteName("$table$addtable2.$field").", '".self::SHORTSEP."',
                                                     ".$this->db->quoteName("$table$addtable2.id").") SEPARATOR '".self::LONGSEP."')
                                     AS ".$this->db->quoteName($NAME).",
                        $ADDITONALFIELDS";
            } else if ($itemtype == 'Software') {
               return " GROUP_CONCAT(DISTINCT CONCAT(".$this->db->quoteName("glpi_softwareversions.name").", ' - ',
                                                     ".$this->db->quoteName("$table$addtable.$field").",'".self::SHORTSEP."',
                                                     ".$this->db->quoteName("$table$addtable.id").") SEPARATOR '".self::LONGSEP."')
                                    AS ".$this->db->quoteName($NAME).",
                        $ADDITONALFIELDS";
            }
            break;

         case "glpi_itilfollowups.content":
         case "glpi_tickettasks.content":
         case "glpi_changetasks.content":
            if (is_subclass_of($itemtype, "CommonITILObject")) {
               // force ordering by date desc
               return " GROUP_CONCAT(
                  DISTINCT CONCAT(
                     IFNULL($tocompute, '".self::NULLVALUE."'),
                     '".self::SHORTSEP."',
                     $tocomputeid
                  )
                  ORDER BY `$table$addtable`.`date` DESC
                  SEPARATOR '".self::LONGSEP."'
               ) AS `".$NAME."`, $ADDITONALFIELDS";
            }
            break;

         default:
            break;
      }

      //// Default cases
      // Link with plugin tables
      if (preg_match("/^glpi_plugin_([a-z0-9]+)/", $table, $matches)) {
         if (count($matches) == 2) {
            $plug     = $matches[1];
            $out = Plugin::doOneHook(
               $plug,
               'addSelect',
               $itemtype, $ID, "{$itemtype}_{$ID}"
            );
            if (!empty($out)) {
               return $out;
            }
         }
      }

      if (isset($searchopt[$ID]["computation"])) {
         $tocompute = $searchopt[$ID]["computation"];
         $tocompute = str_replace($this->db->quoteName('TABLE'), 'TABLE', $tocompute);
         $tocompute = str_replace("TABLE", $this->db->quoteName("$table$addtable"), $tocompute);
      }
      // Preformat items
      if (isset($searchopt[$ID]["datatype"])) {
         switch ($searchopt[$ID]["datatype"]) {
            case "count" :
               return " COUNT(DISTINCT ".$this->db->quoteName("$table$addtable.$field").") AS ".$this->db->quoteName($NAME).",
                     $ADDITONALFIELDS";

            case "date_delay" :
               $interval = "MONTH";
               if (isset($searchopt[$ID]['delayunit'])) {
                  $interval = $searchopt[$ID]['delayunit'];
               }

               $add_minus = '';
               if (isset($searchopt[$ID]["datafields"][3])) {
                  $add_minus = "-".$this->db->quoteName("$table$addtable.".$searchopt[$ID]["datafields"][3]);
               }
               if ($meta
                   || (isset($searchopt[$ID]["forcegroupby"]) && $searchopt[$ID]["forcegroupby"])) {
                  return " GROUP_CONCAT(DISTINCT ADDDATE(".$this->db->quoteName("$table$addtable.".
                                                            $searchopt[$ID]["datafields"][1]).",
                                                         INTERVAL (".$this->db->quoteName("$table$addtable.".
                                                                    $searchopt[$ID]["datafields"][2]).
                                                                    " $add_minus) $interval)
                                         SEPARATOR '".self::LONGSEP."') AS ".$this->db->quoteName($NAME).",
                           $ADDITONALFIELDS";
               }
               return "ADDDATE(".$this->db->quoteName("$table$addtable.".$searchopt[$ID]["datafields"][1]).",
                               INTERVAL (".$this->db->quoteName("$table$addtable.".$searchopt[$ID]["datafields"][2]).
                                          " $add_minus) $interval) AS ".$this->db->quoteName($NAME).",
                       $ADDITONALFIELDS";

            case "itemlink" :
               if ($meta
                  || (isset($searchopt[$ID]["forcegroupby"]) && $searchopt[$ID]["forcegroupby"])) {

                  $TRANS = '';
                  if (Session::haveTranslations(getItemTypeForTable($table), $field)) {
                      $TRANS = "GROUP_CONCAT(DISTINCT CONCAT(IFNULL($tocomputetrans, '".self::NULLVALUE."'),
                                                             '".self::SHORTSEP."',$tocomputeid) ORDER BY $tocomputeid
                                             SEPARATOR '".self::LONGSEP."')
                                     AS ".$this->db->quoteName($NAME."_trans").", ";
                  }

                  return " GROUP_CONCAT(DISTINCT CONCAT($tocompute, '".self::SHORTSEP."' ,
                                                        ".$this->db->quoteName("$table$addtable.id").") ORDER BY ".$this->db->quoteName("$table$addtable.id")."
                                        SEPARATOR '".self::LONGSEP."') AS ".$this->db->quoteName($NAME).",
                           $TRANS
                           $ADDITONALFIELDS";
               }
               return " $tocompute AS ".$this->db->quoteName($NAME).",
                        ".$this->db->quoteName("$table$addtable.id")." AS ".$this->db->quoteName($NAME."_id").",
                        $ADDITONALFIELDS";
         }
      }

      // Default case
      if ($meta
          || (isset($searchopt[$ID]["forcegroupby"]) && $searchopt[$ID]["forcegroupby"]
              && (!isset($searchopt[$ID]["computation"])
                  || isset($searchopt[$ID]["computationgroupby"])
                     && $searchopt[$ID]["computationgroupby"]))) { // Not specific computation
         $TRANS = '';
         if (Session::haveTranslations(getItemTypeForTable($table), $field)) {
            $TRANS = "GROUP_CONCAT(DISTINCT CONCAT(IFNULL($tocomputetrans, '".self::NULLVALUE."'),
                                                   '".self::SHORTSEP."',$tocomputeid) ORDER BY $tocomputeid SEPARATOR '".self::LONGSEP."')
                                  AS ".$this->db->quoteName($NAME."_trans").", ";

         }
         return " GROUP_CONCAT(DISTINCT CONCAT(IFNULL($tocompute, '".self::NULLVALUE."'),
                                               '".self::SHORTSEP."',$tocomputeid) ORDER BY $tocomputeid SEPARATOR '".self::LONGSEP."')
                              AS ".$this->db->quoteName($NAME).",
                  $TRANS
                  $ADDITONALFIELDS";
      }
      $TRANS = '';
      if (Session::haveTranslations(getItemTypeForTable($table), $field)) {
         $TRANS = $tocomputetrans." AS ".$this->db->quoteName($NAME."_trans").", ";

      }
      return "$tocompute AS ".$this->db->quoteName($NAME).", $TRANS $ADDITONALFIELDS";
   }


   /**
    * Generic Function to add default where to a request
    *
    * @param string $itemtype device type
    *
    * @return string
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function addDefaultWhere($itemtype) {
      $condition = '';

      switch ($itemtype) {
         case 'Reminder' :
            $condition = Reminder::addVisibilityRestrict();
            break;

         case 'RSSFeed' :
            $condition = RSSFeed::addVisibilityRestrict();
            break;

         case 'Notification' :
            if (!Config::canView()) {
               $condition = " ".$this->db->quoteName("glpi_notifications.itemtype")." NOT IN ('Crontask', 'DBConnection') ";
            }
            break;

         // No link
         case 'User' :
            // View all entities
            if (!Session::canViewAllEntities()) {
               $condition = getEntitiesRestrictRequest("", "glpi_profiles_users", '', '', true);
            }
            break;

         case 'ProjectTask' :
            $condition  = '';
            $teamtable  = 'glpi_projecttaskteams';
            $condition .= $this->db->quoteName("glpi_projects.is_template")." = 0";
            $condition .= " AND ((".$this->db->quoteName("$teamtable.itemtype")." = 'User'
                             AND ".$this->db->quoteName("$teamtable.items_id")." = '".Session::getLoginUserID()."')";
            if (count($_SESSION['glpigroups'])) {
               $condition .= " OR (".$this->db->quoteName("$teamtable.itemtype")." = 'Group'
                                    AND ".$this->db->quoteName("$teamtable.items_id")."
                                       IN (".implode(",", $_SESSION['glpigroups'])."))";
            }
            $condition .= ") ";
            break;

         case 'Project' :
            $condition = '';
            if (!Session::haveRight("project", Project::READALL)) {
               $teamtable  = 'glpi_projectteams';
               $condition .= "(".$this->db->quoteName("glpi_projects.users_id")." = '".Session::getLoginUserID()."'
                               OR (".$this->db->quoteName("$teamtable.itemtype")." = 'User'
                                   AND ".$this->db->quoteName("$teamtable.items_id")." = '".Session::getLoginUserID()."')";
               if (count($_SESSION['glpigroups'])) {
                  $condition .= " OR (".$this->db->quoteName("glpi_projects.groups_id")."
                                       IN (".implode(",", $_SESSION['glpigroups'])."))";
                  $condition .= " OR (".$this->db->quoteName("$teamtable.itemtype")." = 'Group'
                                      AND ".$this->db->quoteName("$teamtable.items_id")."
                                          IN (".implode(",", $_SESSION['glpigroups'])."))";
               }
               $condition .= ") ";
            }
            break;

         case 'Ticket' :
            // Same structure in addDefaultJoin
            $condition = '';
            if (!Session::haveRight("ticket", Ticket::READALL)) {

               $searchopt
                  = &self::getOptions($itemtype);
               $requester_table
                  = $this->db->quoteName('glpi_tickets_users_'.
                     self::computeComplexJoinID($searchopt[4]['joinparams']['beforejoin']
                                                          ['joinparams']));
               $requestergroup_table
                  = $this->db->quoteName('glpi_groups_tickets_'.
                     self::computeComplexJoinID($searchopt[71]['joinparams']['beforejoin']
                                                          ['joinparams']));

               $assign_table
                  = $this->db->quoteName('glpi_tickets_users_'.
                     self::computeComplexJoinID($searchopt[5]['joinparams']['beforejoin']
                                                          ['joinparams']));
               $assigngroup_table
                  = $this->db->quoteName('glpi_groups_tickets_'.
                     self::computeComplexJoinID($searchopt[8]['joinparams']['beforejoin']
                                                          ['joinparams']));

               $observer_table
                  = $this->db->quoteName('glpi_tickets_users_'.
                     self::computeComplexJoinID($searchopt[66]['joinparams']['beforejoin']
                                                          ['joinparams']));
               $observergroup_table
                  = $this->db->quoteName('glpi_groups_tickets_'.
                     self::computeComplexJoinID($searchopt[65]['joinparams']['beforejoin']
                                                          ['joinparams']));

               $condition = "(";

               if (Session::haveRight("ticket", Ticket::READMY)) {
                    $condition .= " ".$this->db->quoteName("$requester_table.users_id")." = '".Session::getLoginUserID()."'
                                    OR ".$this->db->quoteName("$observer_table.users_id")." = '".Session::getLoginUserID()."'
                                    OR ".$this->db->quoteName("glpi_tickets.users_id_recipient")." = '".Session::getLoginUserID()."'";
               } else {
                    $condition .= "0=1";
               }

               if (Session::haveRight("ticket", Ticket::READGROUP)) {
                  if (count($_SESSION['glpigroups'])) {
                     $condition .= " OR ".$this->db->quoteName("$requestergroup_table.groups_id")."
                                             IN (".implode(",", $_SESSION['glpigroups']).")";
                     $condition .= " OR ".$this->db->quoteName("$observergroup_table.groups_id")."
                                             IN (".implode(",", $_SESSION['glpigroups']).")";
                  }
               }

               if (Session::haveRight("ticket", Ticket::OWN)) {// Can own ticket : show assign to me
                  $condition .= " OR $assign_table.users_id = '".Session::getLoginUserID()."' ";
               }

               if (Session::haveRight("ticket", Ticket::READASSIGN)) { // assign to me

                  $condition .=" OR ".$this->db->quoteName("$assign_table.users_id")." = '".Session::getLoginUserID()."'";
                  if (count($_SESSION['glpigroups'])) {
                     $condition .= " OR ".$this->db->quoteName("$assigngroup_table.groups_id")."
                                             IN (".implode(",", $_SESSION['glpigroups']).")";
                  }
                  if (Session::haveRight('ticket', Ticket::ASSIGN)) {
                     $condition .= " OR ".$this->db->quoteName("glpi_tickets.status")."='".CommonITILObject::INCOMING."'";
                  }
               }

               if (Session::haveRightsOr('ticketvalidation',
                                         [TicketValidation::VALIDATEINCIDENT,
                                               TicketValidation::VALIDATEREQUEST])) {
                  $condition .= " OR ".$this->db->quoteName("glpi_ticketvalidations.users_id_validate")."
                                          = '".Session::getLoginUserID()."'";
               }
               $condition .= ") ";
            }
            break;

         case 'Change' :
         case 'Problem':
            if ($itemtype == 'Change') {
               $right       = 'change';
               $table       = 'changes';
               $groupetable = "glpi_changes_groups_";
            } else if ($itemtype == 'Problem') {
               $right       = 'problem';
               $table       = 'problems';
               $groupetable = "glpi_groups_problems_";
            }
            // Same structure in addDefaultJoin
            $condition = '';
            if (!Session::haveRight("$right", $itemtype::READALL)) {
               $searchopt       = &self::getOptions($itemtype);
               if (Session::haveRight("$right", $itemtype::READMY)) {
                  $requester_table      = $this->db->quoteName('glpi_'.$table.'_users_'.
                                          self::computeComplexJoinID($searchopt[4]['joinparams']
                                                                     ['beforejoin']['joinparams']));
                  $requestergroup_table = $this->db->quoteName($groupetable.
                                          self::computeComplexJoinID($searchopt[71]['joinparams']
                                                                     ['beforejoin']['joinparams']));

                  $observer_table       = $this->db->quoteName('glpi_'.$table.'_users_'.
                                          self::computeComplexJoinID($searchopt[66]['joinparams']
                                                                     ['beforejoin']['joinparams']));
                  $observergroup_table  = $this->db->quoteName($groupetable.
                                          self::computeComplexJoinID($searchopt[65]['joinparams']
                                                                    ['beforejoin']['joinparams']));

                  $assign_table         = $this->db->quoteName('glpi_'.$table.'_users_'.
                                          self::computeComplexJoinID($searchopt[5]['joinparams']
                                                                     ['beforejoin']['joinparams']));
                  $assigngroup_table    = $this->db->quoteName($groupetable.
                                          self::computeComplexJoinID($searchopt[8]['joinparams']
                                                                     ['beforejoin']['joinparams']));
               }
               $condition = "(";

               if (Session::haveRight("$right", $itemtype::READMY)) {
                  $condition .= " $requester_table.users_id = '".Session::getLoginUserID()."'
                                 OR $observer_table.users_id = '".Session::getLoginUserID()."'
                                 OR $assign_table.users_id = '".Session::getLoginUserID()."'
                                 OR ".$this->db->quoteName("glpi_".$table.".users_id_recipient")." = '".Session::getLoginUserID()."'";
                  if (count($_SESSION['glpigroups'])) {
                     $my_groups_keys = "'" . implode("','", $_SESSION['glpigroups']) . "'";
                     $condition .= " OR ".$this->db->quoteName("$requestergroup_table.groups_id")." IN ($my_groups_keys)
                                 OR ".$this->db->quoteName("$observergroup_table.groups_id")." IN ($my_groups_keys)
                                 OR ".$this->db->quoteName("$assigngroup_table.groups_id")." IN ($my_groups_keys)";
                  }
               } else {
                  $condition .= "0=1";
               }

               $condition .= ") ";
            }
            break;

         case 'Config':
            $availableContexts = ['core'] + Plugin::getPlugins();
            $availableContexts = implode("', '", $availableContexts);
            $condition = $this->db->quoteName('context') . " IN ('$availableContexts')";
            break;

         case 'SavedSearch':
            $restrict = SavedSearch::addVisibilityRestrict();
            break;

         case 'TicketTask':
            // Filter on is_private
            $allowed_is_private = [];
            if (Session::haveRight(TicketTask::$rightname, CommonITILTask::SEEPRIVATE)) {
               $allowed_is_private[] = 1;
            }
            if (Session::haveRight(TicketTask::$rightname, CommonITILTask::SEEPUBLIC)) {
               $allowed_is_private[] = 0;
            }

            // If the user can't see public and private
            if (!count($allowed_is_private)) {
               $condition = "0 = 1";
               break;
            }

            $in = "IN ('" . implode("','", $allowed_is_private) . "')";
            $condition = "(`glpi_tickettasks`.`is_private` $in ";

            // Check for assigned or created tasks
            $condition .= "OR `glpi_tickettasks`.`users_id` = " . Session::getLoginUserID() . " ";
            $condition .= "OR `glpi_tickettasks`.`users_id_tech` = " . Session::getLoginUserID() . " ";

            // Check for parent item visibility unless the user can see all the
            // possible parents
            if (!Session::haveRight('ticket', Ticket::READALL)) {
               $condition .= "AND " . TicketTask::buildParentCondition();
            }

            $condition .= ")";

            break;

         case 'ITILFollowup':
            // Filter on is_private
            $allowed_is_private = [];
            if (Session::haveRight(ITILFollowup::$rightname, ITILFollowup::SEEPRIVATE)) {
               $allowed_is_private[] = 1;
            }
            if (Session::haveRight(ITILFollowup::$rightname, ITILFollowup::SEEPUBLIC)) {
               $allowed_is_private[] = 0;
            }

            // If the user can't see public and private
            if (!count($allowed_is_private)) {
               $condition = "0 = 1";
               break;
            }

            $in = "IN ('" . implode("','", $allowed_is_private) . "')";
            $condition = "(`glpi_itilfollowups`.`is_private` $in ";

            // Now filter on parent item visiblity
            $condition .= "AND (";

            // Filter for "ticket" parents
            $condition .= ITILFollowup::buildParentCondition(\Ticket::getType());
            $condition .= "OR ";

            // Filter for "change" parents
            $condition .= ITILFollowup::buildParentCondition(
               \Change::getType(),
               'changes_id',
               "glpi_changes_users",
               "glpi_changes_groups"
            );
            $condition .= "OR ";

            // Fitler for "problem" parents
            $condition .= ITILFollowup::buildParentCondition(
               \Problem::getType(),
               'problems_id',
               "glpi_problems_users",
               "glpi_groups_problems"
            );
            $condition .= "))";

            break;

         default :
            // Plugin can override core definition for its type
            if ($plug = isPluginItemType($itemtype)) {
               $condition = Plugin::doOneHook($plug['plugin'], 'addDefaultWhere', $itemtype);
            }
            break;
      }

      /* Hook to restrict user right on current itemtype */
      list($itemtype, $condition) = Plugin::doHookFunction('add_default_where', [$itemtype, $condition]);
      return $condition;
   }

   /**
    * Get search clause for type
    *
    * @param string  $searchtype Type
    * @param mixed   $val        Value
    * @param boolean $nott       ?
    * @param string  $inittable  ?
    *
    * @return string
    */
   private function getSearchFromType($searchtype, $val, $nott, $inittable, $wcontains = false) {
      $SEARCH = null;
      switch ($searchtype) {
         case "notcontains" :
            $nott = !$nott;
         case "contains" :
            if ($wcontains === true) {
               $SEARCH = $this->makeTextSearch($val, $nott);
            }
            break;

         case "equals" :
            if ($nott) {
               $SEARCH = " <> $val";
            } else {
               $SEARCH = " = $val";
            }
            break;

         case "notequals" :
            if ($nott) {
               $SEARCH = " = $val";
            } else {
               $SEARCH = " <> $val";
            }
            break;

         case "under" :
            $values = getSonsOf($inittable, $val);
            $expr = implode(', ', $values);
            if ($nott) {
               $SEARCH = " NOT IN ($expr)";
            } else {
               $SEARCH = " IN ($expr)";
            }
            break;

         case "notunder" :
            $values = getSonsOf($inittable, $val);
            $expr = implode(', ', $values);
            if ($nott) {
               $SEARCH = " IN ($expr)";
            } else {
               $SEARCH = " NOT IN ($expr)";
            }
            break;

      }

      return $SEARCH;
   }
   /**
    * Generic Function to add where to a request
    *
    * @param string  $link         Link string
    * @param boolean $nott         Is it a negative search ?
    * @param string  $itemtype     Item type
    * @param integer $ID           ID of the item to search
    * @param string  $searchtype   Searchtype used (equals or contains)
    * @param string  $val          Item num in the request
    * @param integer $meta         Is a meta search (meta=2 in search.class.php) (default 0)
    *
    * @return string Where string
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function addWhere($link, $nott, $itemtype, $ID, $searchtype, $val, $meta = 0) {

      global $DB;

      $searchopt = &self::getOptions($itemtype);
      if (!isset($searchopt[$ID]['table'])) {
         return false;
      }
      $table     = $searchopt[$ID]["table"];
      $field     = $searchopt[$ID]["field"];

      $inittable = $table;
      $addtable  = '';
      $is_fkey_composite_on_self = getTableNameForForeignKeyField($searchopt[$ID]["linkfield"]) == $table
         && $searchopt[$ID]["linkfield"] != getForeignKeyFieldForTable($table);
      $orig_table = self::getOrigTableName($itemtype);
      if (($table != 'asset_types')
          && ($is_fkey_composite_on_self || $table != $orig_table)
          && ($searchopt[$ID]["linkfield"] != getForeignKeyFieldForTable($table))) {
         $addtable = "_".$searchopt[$ID]["linkfield"];
         $table   .= $addtable;
      }

      if (isset($searchopt[$ID]['joinparams'])) {
         $complexjoin = self::computeComplexJoinID($searchopt[$ID]['joinparams']);

         if (!empty($complexjoin)) {
            $table .= "_".$complexjoin;
         }
      }

      if ($meta
          && ($itemtype::getTable() != $inittable)) {
         $table .= "_".$itemtype;
      }

      // Hack to allow search by ID on every sub-table
      if (preg_match('/^\$\$\$\$([0-9]+)$/', $val, $regs)) {
         return $link." (".$this->db->quoteName("$table.id")." ".($nott?"<>":"=").$regs[1]." ".
                         (($regs[1] == 0)?" OR ".$this->db->quoteName("$table.id")." IS NULL":'').") ";
      }

      // Preparse value
      if (isset($searchopt[$ID]["datatype"])) {
         switch ($searchopt[$ID]["datatype"]) {
            case "datetime" :
            case "date" :
            case "date_delay" :
               $force_day = true;
               if ($searchopt[$ID]["datatype"] == 'datetime'
                  && !(strstr($val, 'BEGIN') || strstr($val, 'LAST') || strstr($val, 'DAY'))
               ) {
                  $force_day = false;
               }

               $val = Html::computeGenericDateTimeSearch($val, $force_day);

               break;
         }
      }

      //Check in current item if a specific where is defined
      if (method_exists($itemtype, 'addWhere')) {
         $qry_params = [];
         $out = $itemtype::addWhere($link, $nott, $itemtype, $ID, $searchtype, $val, $qry_params);
         if (!empty($out)) {
            return $out;
         }
      }

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $out = Plugin::doOneHook(
            $plug['plugin'],
            'addWhere',
            $link, $nott, $itemtype, $ID, $val, $searchtype
         );
         if (!empty($out)) {
            return $out;
         }
      }

      switch ($inittable.".".$field) {
         // case "glpi_users_validation.name" :

         case "glpi_users.name" :
            if ($itemtype == 'User') { // glpi_users case / not link table
               if (in_array($searchtype, ['equals', 'notequals'])) {
                  $search_str = $this->db->quoteName("$table.id") . $this->getSearchFromType($searchtype, $val, $nott, $inittable);

                  if ($searchtype == 'notequals') {
                     $nott = !$nott;
                  }

                  // Add NULL if $val = 0 and not negative search
                  // Or negative search on real value
                  if ((!$nott && ($val == 0)) || ($nott && ($val != 0))) {
                     $search_str .= " OR " . $this->db->quoteName("$table.id") . " IS NULL";
                  }

                  return " $link ($search_str)";
               }
               return $this->makeTextCriteria($this->db->quoteName("$table.$field"), $val, $nott, $link);
            }
            if ($_SESSION["glpinames_format"] == User::FIRSTNAME_BEFORE) {
               $name1 = 'firstname';
               $name2 = 'realname';
            } else {
               $name1 = 'realname';
               $name2 = 'firstname';
            }

            if (in_array($searchtype, ['equals', 'notequals'])) {
               return " $link (".$this->db->quoteName("$table.id").$this->getSearchFromType($searchtype, $val, $nott, $inittable).
                               (($val == 0)?" OR ".$this->db->quoteName("$table.id")." IS".
                                   (($searchtype == "notequals")?" NOT":"")." NULL":'').') ';
            }
            $toadd   = '';

            $tmplink = 'OR';
            if ($nott) {
               $tmplink = 'AND';
            }

            if (($itemtype == 'Ticket') || ($itemtype == 'Problem')) {
               if (isset($searchopt[$ID]["joinparams"]["beforejoin"]["table"])
                   && isset($searchopt[$ID]["joinparams"]["beforejoin"]["joinparams"])
                   && (($searchopt[$ID]["joinparams"]["beforejoin"]["table"]
                         == 'glpi_tickets_users')
                       || ($searchopt[$ID]["joinparams"]["beforejoin"]["table"]
                             == 'glpi_problems_users')
                       || ($searchopt[$ID]["joinparams"]["beforejoin"]["table"]
                             == 'glpi_changes_users'))) {

                  $bj        = $searchopt[$ID]["joinparams"]["beforejoin"];
                  $linktable = $bj['table'].'_'.self::computeComplexJoinID($bj['joinparams']);
                  $toadd     = $this->makeTextCriteria($this->db->quoteName("$linktable.alternative_email"), $val,
                                                      $nott, $tmplink);
                  if ($val == '^$') {
                     return $link." ((".$this->db->quoteName("$linktable.users_id")." IS NULL)
                            OR ".$this->db->quoteName("$linktable.alternative_email")." IS NULL)";
                  }
               }
            }
            $toadd2 = '';
            if ($nott
                && ($val != 'NULL') && ($val != 'null')) {
               $toadd2 = " OR ".$this->db->quoteName("$table.$field")." IS NULL";
            }
            return $link." (((".$this->db->quoteName("$table.$name1")." ".$this->getSearchFromType($searchtype, $val, $nott, $inittable, true)."
                            $tmplink ".$this->db->quoteName("$table.$name2")." ".$this->getSearchFromType($searchtype, $val, $nott, $inittable, true)."
                            $tmplink ".$this->db->quoteName("$table.$field")." ".$this->getSearchFromType($searchtype, $val, $nott, $inittable, true)."
                            $tmplink CONCAT(".$this->db->quoteName("$table.$name1").", ' ', ".$this->db->quoteName("$table.$name2").") ".$this->getSearchFromType($searchtype, $val, $nott, $inittable, true)." )
                            $toadd2) $toadd)";

         case "glpi_groups.completename" :
            if ($val == 'mygroups') {
               $field_name = $this->db->quoteName("$table.id");
               switch ($searchtype) {
                  case 'equals' :
                     $expr = implode(', ', $_SESSION['glpigroups']);
                     return " $link ($field_name IN ($expr)) ";

                  case 'notequals' :
                     $expr = implode(', ', $_SESSION['glpigroups']);
                     return " $link ($field_name NOT IN ($expr)) ";

                  case 'under' :
                     $groups = $_SESSION['glpigroups'];
                     foreach ($_SESSION['glpigroups'] as $g) {
                        $groups += getSonsOf($inittable, $g);
                     }
                     $groups = array_unique($groups);
                     return " $link ($field_name IN ('".
                        implode(', ', $groups)."')) ";

                  case 'notunder' :
                     $groups = $_SESSION['glpigroups'];
                     foreach ($_SESSION['glpigroups'] as $g) {
                        $groups += getSonsOf($inittable, $g);
                     }
                     $groups = array_unique($groups);
                     return " $link ($field_name NOT IN ('".
                        implode(',', $groups)."')) ";
               }
            }
            break;

         case "glpi_auth_tables.name" :
            $user_searchopt = self::getOptions('User');
            $tmplink        = 'OR';
            if ($nott) {
               $tmplink = 'AND';
            }

            return $link." (".$this->db->quoteName(
               "glpi_authmails".$addtable."_".
               $this->computeComplexJoinID($user_searchopt[31]['joinparams']).".name"
            )." ".$this->getSearchFromType($searchtype, $val, $nott, $inittable)."
               $tmplink ".$this->db->quoteName(
               "glpi_authldaps".$addtable."_".
               $this->computeComplexJoinID($user_searchopt[30]['joinparams']).".name"
            )."
               ".$this->getSearchFromType($searchtype, $val, $nott, $inittable)." ) ";

         case "glpi_ipaddresses.name" :
            $search  = ["/\&lt;/","/\&gt;/"];
            $replace = ["<",">"];
            $val     = preg_replace($search, $replace, $val);
            if (preg_match("/^\s*([<>])([=]*)[[:space:]]*([0-9\.]+)/", $val, $regs)) {
               if ($nott) {
                  if ($regs[1] == '<') {
                     $regs[1] = '>';
                  } else {
                     $regs[1] = '<';
                  }
               }
               $regs[1] .= $regs[2];
               return $link." (INET_ATON(".$this->db->quoteName("$table.$field").") ".$regs[1]." INET_ATON(".$this->db->quote($regs[3]).") ";
            }
            break;

         case "glpi_tickets.status" :
         case "glpi_problems.status" :
         case "glpi_changes.status" :
            if ($val == 'all') {
               return "";
            }
            $tocheck = [];
            if ($item = getItemForItemtype($itemtype)) {
               switch ($val) {
                  case 'process' :
                     $tocheck = $item->getProcessStatusArray();
                     break;

                  case 'notclosed' :
                     $tocheck = $item->getAllStatusArray();
                     foreach ($item->getClosedStatusArray() as $status) {
                        if (isset($tocheck[$status])) {
                           unset($tocheck[$status]);
                        }
                     }
                     $tocheck = array_keys($tocheck);
                     break;

                  case 'old' :
                     $tocheck = array_merge($item->getSolvedStatusArray(),
                                            $item->getClosedStatusArray());
                     break;

                  case 'notold' :
                     $tocheck = $item::getNotSolvedStatusArray();
                     break;
               }
            }

            if (count($tocheck) == 0) {
               $statuses = $item->getAllStatusArray();
               if (isset($statuses[$val])) {
                  $tocheck = [$val];
               }
            }

            if (count($tocheck)) {
               $field_name = $this->db->quoteName("$table.$field");
               if ($nott) {
                  return $link." $field_name NOT IN (".implode(', ', $tocheck).")";
               }
               return $link." $field_name IN (".implode(', ', $tocheck).")";
            }
            break;

         case "glpi_tickets_tickets.tickets_id_1" :
            $tmplink = 'OR';
            $compare = '=';
            if ($nott) {
               $tmplink = 'AND';
               $compare = '<>';
            }
            $toadd2 = '';
            if ($nott
                && ($val != 'NULL') && ($val != 'null')) {
               $toadd2 = " OR ".$this->db->quoteName("$table.$field")." IS NULL";
            }

            return $link." (((".$this->db->quoteName("$table.tickets_id_1")." $compare $val
                              $tmplink ".$this->db->quoteName("$table.tickets_id_2")." $compare $val)
                             AND ".$this->db->quoteName("glpi_tickets.id")." <> $val)
                            $toadd2)";

         case "glpi_tickets.priority" :
         case "glpi_tickets.impact" :
         case "glpi_tickets.urgency" :
         case "glpi_problems.priority" :
         case "glpi_problems.impact" :
         case "glpi_problems.urgency" :
         case "glpi_changes.priority" :
         case "glpi_changes.impact" :
         case "glpi_changes.urgency" :
         case "glpi_projects.priority" :
            if (is_numeric($val)) {
               $field_name = $this->db->quoteName("$table.$field");
               if ($val > 0) {
                  $compare = ($nott ? '<>' : '=');
                  return "$link $field_name $compare $val";
               }
               if ($val < 0) {
                  $compare = ($nott ? '<' : '>=');
                  $val = abs($val);
                  return "$link $field_name $compare $val";
               }
               // Show all
               $compare = ($nott ? '<' : '>=');
               return "$link $field_name $compare 0 ";
            }
            return "";

         case "glpi_tickets.global_validation" :
         case "glpi_ticketvalidations.status" :
            if ($val == 'all') {
               return "";
            }
            $tocheck = [];
            switch ($val) {
               case 'can' :
                  $tocheck = CommonITILValidation::getCanValidationStatusArray();
                  break;

               case 'all' :
                  $tocheck = CommonITILValidation::getAllValidationStatusArray();
                  break;

            }
            if (count($tocheck) == 0) {
               $tocheck = [$val];
            }
            if (count($tocheck)) {
               $field_name = $this->db->quoteName("$table.$field");
               if ($nott) {
                  return "$link $field_name NOT IN ('".implode(',', array_fill(0, count($tocheck), '?'))."')";
               }
               return "$link $field_name IN ('".implode(',', array_fill(0, count($tocheck), '?'))."')";
            }
            break;

         case "glpi_notifications.event" :
            if (in_array($searchtype, ['equals', 'notequals']) && strpos($val, self::SHORTSEP)) {
               $not = 'notequals' === $searchtype ? 'NOT' : '';
               list($itemtype_val, $event_val) = explode(self::SHORTSEP, $val);
               return " $link $not(`$table`.`event` = '$event_val'
                               AND `$table`.`itemtype` = '$itemtype_val')";
            }
            break;

      }

      //// Default cases

      // Link with plugin tables
      if (preg_match("/^glpi_plugin_([a-z0-9]+)/", $inittable, $matches)) {
         if (count($matches) == 2) {
            $plug     = $matches[1];
            $out = Plugin::doOneHook(
               $plug,
               'addWhere',
               $link, $nott, $itemtype, $ID, $val, $searchtype
            );
            if (!empty($out)) {
               return $out;
            }
         }
      }

      $tocompute      = $this->db->quoteName("$table.$field");
      $tocomputetrans = $this->db->quoteName("{$table}_trans.value");
      if (isset($searchopt[$ID]["computation"])) {
         $tocompute = $searchopt[$ID]["computation"];
         $tocompute = str_replace($this->db->quoteName('TABLE'), 'TABLE', $tocompute);
         $tocompute = str_replace("TABLE", $this->db->quoteName("$table"), $tocompute);
      }

      // Preformat items
      if (isset($searchopt[$ID]["datatype"])) {
         switch ($searchopt[$ID]["datatype"]) {
            case "itemtypename" :
               if (in_array($searchtype, ['equals', 'notequals'])) {
                  return " $link (".$this->db->quoteName("$table.$field").$this->getSearchFromType($searchtype, $val, $nott, $inittable).') ';
               }
               break;

            case "itemlink" :
               if (in_array($searchtype, ['equals', 'notequals', 'under', 'notunder'])) {
                  return " $link (".$this->db->quoteName("$table.id").$this->getSearchFromType($searchtype, $val, $nott, $inittable).') ';
               }
               break;

            case "datetime" :
            case "date" :
            case "date_delay" :
               if ($searchopt[$ID]["datatype"] == 'datetime') {
                  // Specific search for datetime
                  if (in_array($searchtype, ['equals', 'notequals'])) {
                     $val = preg_replace("/:00$/", '', $val);
                     $val = '^'.$val;
                     if ($searchtype == 'notequals') {
                        $nott = !$nott;
                     }
                     return $this->makeTextCriteria($this->db->quoteName("$table.$field"), $val, $nott, $link);
                  }
               }
               if ($searchtype == 'lessthan') {
                  $val = '< ' . $val;
               }
               if ($searchtype == 'morethan') {
                  $val = '> ' . $val;
               }
               if ($searchtype) {
                  $date_computation = $tocompute;
               }
               if (in_array($searchtype, ["contains", "notcontains"])) {
                  $default_charset = DBConnection::getDefaultCharset();
                  $date_computation = "CONVERT($date_computation USING {$default_charset})";
               }
               $search_unit = ' MONTH ';
               if (isset($searchopt[$ID]['searchunit'])) {
                  $search_unit = $searchopt[$ID]['searchunit'];
               }
               if ($searchopt[$ID]["datatype"]=="date_delay") {
                  $delay_unit = ' MONTH ';
                  if (isset($searchopt[$ID]['delayunit'])) {
                     $delay_unit = $searchopt[$ID]['delayunit'];
                  }
                  $add_minus = '';
                  if (isset($searchopt[$ID]["datafields"][3])) {
                     $add_minus = "-".$this->db->quoteName("$table.".$searchopt[$ID]["datafields"][3]);
                  }
                  $date_computation = "ADDDATE(".$this->db->quoteName("$table.".$searchopt[$ID]["datafields"][1]).",
                                               INTERVAL (".$this->db->quoteName("$table.".$searchopt[$ID]["datafields"][2])."
                                                         $add_minus)
                                               $delay_unit)";
               }
               if (in_array($searchtype, ['equals', 'notequals'])) {
                  return " $link ($date_computation ".$this->getSearchFromType($searchtype, $val, $nott, $inittable).') ';
               }
               $search  = ["/\&lt;/","/\&gt;/"];
               $replace = ["<",">"];
               $val     = preg_replace($search, $replace, $val);
               if (preg_match("/^\s*([<>=]+)\s*(.*)/", $val, $regs)) {
                  if (is_numeric($regs[2])) {
                     return $link." $date_computation ".$regs[1]."
                            ADDDATE(NOW(), INTERVAL ".$regs[2]." $search_unit) ";
                  }
                  // ELSE Reformat date if needed
                  $regs[2] = preg_replace('@(\d{1,2})(-|/)(\d{1,2})(-|/)(\d{4})@', '\5-\3-\1',
                                          $regs[2]);
                  if (preg_match('/[0-9]{2,4}-[0-9]{1,2}-[0-9]{1,2}/', $regs[2])) {
                     $ret = $link;
                     if ($nott) {
                        $ret .= " NOT(";
                     }
                     $ret .= " $date_computation {$regs[1]} " . $this->db->quote($regs[2]);
                     if ($nott) {
                        $ret .= ")";
                     }
                     return $ret;
                  }
                  return "";
               }
               // ELSE standard search
               // Date format modification if needed
               $val = preg_replace('@(\d{1,2})(-|/)(\d{1,2})(-|/)(\d{4})@', '\5-\3-\1', $val);
               if ($date_computation) {
                  return $this->makeTextCriteria($date_computation, $val, $nott, $link);
               }
               return '';

            case "right" :
               if ($searchtype == 'notequals') {
                  $nott = !$nott;
               }
               return $link. ($nott?' NOT':'')." ($tocompute & $val) ";

            case "bool" :
               if (!is_numeric($val)) {
                  if (strcasecmp($val, __('No')) == 0) {
                     $val = 0;
                  } else if (strcasecmp($val, __('Yes')) == 0) {
                     $val = 1;
                  }
               }
               // No break here : use number comparaison case

            case "count" :
            case "number" :
            case "decimal" :
            case "timestamp" :
            case "progressbar" :
               $search  = ["/\&lt;/", "/\&gt;/"];
               $replace = ["<", ">"];
               $val     = preg_replace($search, $replace, $val);

               if (preg_match("/([<>])([=]*)[[:space:]]*([0-9]+)/", $val, $regs)) {
                  if (in_array($searchtype, ["notequals", "notcontains"])) {
                     $nott = !$nott;
                  }
                  if ($nott) {
                     if ($regs[1] == '<') {
                        $regs[1] = '>';
                     } else {
                        $regs[1] = '<';
                     }
                  }
                  $regs[1] .= $regs[2];
                  return $link." ($tocompute ".$regs[1]." ".$regs[3].") ";
               }

               if (is_numeric($val)) {
                  $numeric_val = floatval($val);

                  if (in_array($searchtype, ["notequals", "notcontains"])) {
                     $nott = !$nott;
                  }

                  if (isset($searchopt[$ID]["width"])) {
                     $ADD = "";
                     if ($nott
                         && ($val != 'NULL') && ($val != 'null')) {
                        $ADD = " OR $tocompute IS NULL";
                     }
                     if ($nott) {
                        return $link." ($tocompute < ".($val - $searchopt[$ID]['width'])." OR $tocompute > ".
                            ($val + $searchopt[$ID]['width'])." $ADD) ";
                     }
                     return $link." (($tocompute >= ".($val - $searchopt[$ID]['width'])." AND $tocompute <= ".
                        ($val + $searchopt[$ID]['width']).") $ADD) ";
                  }
                  if (!$nott) {
                     return " $link ($tocompute = $numeric_val) ";
                  }
                  return " $link ($tocompute <> $numeric_val) ";
               }
               break;
         }
      }

      // Default case
      if (in_array($searchtype, ['equals', 'notequals','under', 'notunder'])) {

         if ((!isset($searchopt[$ID]['searchequalsonfield'])
              || !$searchopt[$ID]['searchequalsonfield'])
            && ($itemtype == 'AllAssets'
                || $table != $itemtype::getTable())) {
            $out = " $link (".$this->db->quoteName("$table.id").$this->getSearchFromType($searchtype, $val, $nott, $inittable);
         } else {
            $out = " $link (".$this->db->quoteName("$table.$field").$this->getSearchFromType($searchtype, $val, $nott, $inittable);
         }
         if ($searchtype == 'notequals') {
            $nott = !$nott;
         }
         // Add NULL if $val = 0 and not negative search
         // Or negative search on real value
         if ((!$nott && ($val == 0))
             || ($nott && ($val != 0))) {
            $out .= " OR ".$this->db->quoteName("$table.id")." IS NULL";
         }
         $out .= ')';
         return $out;
      }
      $transitemtype = getItemTypeForTable($inittable);
      if (Session::haveTranslations($transitemtype, $field)) {
         return " $link (".$this->makeTextCriteria($tocompute, $val, $nott, '')."
                          OR ".$this->makeTextCriteria($tocomputetrans, $val, $nott, '').")";
      }

      return $this->makeTextCriteria($tocompute, $val, $nott, $link);
   }


   /**
    * Generic Function to add Default left join to a request
    *
    * @param string $itemtype             Reference item type
    * @param string $ref_table            Reference table
    * @param array &$already_link_tables  Array of tables already joined
    *
    * @return string Left join SQL string
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function addDefaultJoin($itemtype, $ref_table, array &$already_link_tables) {
      $out = '';

      switch ($itemtype) {
         // No link
         case 'User' :
            $out = $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                     "glpi_profiles_users", "profiles_users_id", 0, 0,
                                     ['jointype' => 'child']);
            break;

         case 'Reminder' :
            $out = Reminder::addVisibilityJoins();
            break;

         case 'RSSFeed' :
            $out = RSSFeed::addVisibilityJoins();
            break;

         case 'ProjectTask' :
            // Same structure in addDefaultWhere
            $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                      "glpi_projects", "projects_id");
            $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                      "glpi_projecttaskteams", "projecttaskteams_id", 0, 0,
                                      ['jointype' => 'child']);
            break;

         case 'Project' :
            // Same structure in addDefaultWhere
            if (!Session::haveRight("project", Project::READALL)) {
               $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                          "glpi_projectteams", "projectteams_id", 0, 0,
                                          ['jointype' => 'child']);
            }
            break;

         case 'Ticket' :
            // Same structure in addDefaultWhere
            if (!Session::haveRight("ticket", Ticket::READALL)) {
               $searchopt = &$this->getOptions($itemtype);

               // show mine : requester
               $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                         "glpi_tickets_users", "tickets_users_id", 0, 0,
                                         $searchopt[4]['joinparams']['beforejoin']['joinparams']);

               if (Session::haveRight("ticket", Ticket::READGROUP)) {
                  if (count($_SESSION['glpigroups'])) {
                     $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                               "glpi_groups_tickets", "groups_tickets_id", 0, 0,
                                               $searchopt[71]['joinparams']['beforejoin']
                                                         ['joinparams']);
                  }
               }

               // show mine : observer
               $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                         "glpi_tickets_users", "tickets_users_id", 0, 0,
                                         $searchopt[66]['joinparams']['beforejoin']['joinparams']);

               if (count($_SESSION['glpigroups'])) {
                  $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                            "glpi_groups_tickets", "groups_tickets_id", 0, 0,
                                            $searchopt[65]['joinparams']['beforejoin']['joinparams']);
               }

               if (Session::haveRight("ticket", Ticket::OWN)) { // Can own ticket : show assign to me
                  $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                            "glpi_tickets_users", "tickets_users_id", 0, 0,
                                            $searchopt[5]['joinparams']['beforejoin']['joinparams']);
               }

               if (Session::haveRightsOr("ticket", [Ticket::READMY, Ticket::READASSIGN])) { // show mine + assign to me
                  $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                            "glpi_tickets_users", "tickets_users_id", 0, 0,
                                            $searchopt[5]['joinparams']['beforejoin']['joinparams']);

                  if (count($_SESSION['glpigroups'])) {
                     $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                               "glpi_groups_tickets", "groups_tickets_id", 0, 0,
                                               $searchopt[8]['joinparams']['beforejoin']
                                                         ['joinparams']);
                  }
               }

               if (Session::haveRightsOr('ticketvalidation',
                                         [TicketValidation::VALIDATEINCIDENT,
                                               TicketValidation::VALIDATEREQUEST])) {
                  $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                            "glpi_ticketvalidations", "ticketvalidations_id", 0, 0,
                                            $searchopt[58]['joinparams']['beforejoin']['joinparams']);
               }
            }
            break;

         case 'Change' :
         case 'Problem' :
            if ($itemtype == 'Change') {
               $right       = 'change';
               $table       = 'changes';
               $groupetable = "glpi_changes_groups";
               $linkfield   = "changes_groups_id";
            } else if ($itemtype == 'Problem') {
               $right       = 'problem';
               $table       = 'problems';
               $groupetable = "glpi_groups_problems";
               $linkfield   = "groups_problems_id";
            }

            // Same structure in addDefaultWhere
            $out = '';
            if (!Session::haveRight("$right", $itemtype::READALL)) {
               $searchopt = &self::getOptions($itemtype);

               if (Session::haveRight("$right", $itemtype::READMY)) {
                  // show mine : requester
                  $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                            "glpi_".$table."_users", $table."_users_id", 0, 0,
                                            $searchopt[4]['joinparams']['beforejoin']['joinparams']);
                  if (count($_SESSION['glpigroups'])) {
                     $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                               $groupetable, $linkfield, 0, 0,
                                               $searchopt[71]['joinparams']['beforejoin']['joinparams']);
                  }

                  // show mine : observer
                  $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                            "glpi_".$table."_users", $table."_users_id", 0, 0,
                                            $searchopt[66]['joinparams']['beforejoin']['joinparams']);
                  if (count($_SESSION['glpigroups'])) {
                     $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                               $groupetable, $linkfield, 0, 0,
                                               $searchopt[65]['joinparams']['beforejoin']['joinparams']);
                  }

                  // show mine : assign
                  $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                            "glpi_".$table."_users", $table."_users_id", 0, 0,
                                            $searchopt[5]['joinparams']['beforejoin']['joinparams']);
                  if (count($_SESSION['glpigroups'])) {
                     $out .= $this->addLeftJoin($itemtype, $ref_table, $already_link_tables,
                                               $groupetable, $linkfield, 0, 0,
                                               $searchopt[8]['joinparams']['beforejoin']['joinparams']);
                  }
               }
            }
            break;

         default :
            // Plugin can override core definition for its type
            if ($plug = isPluginItemType($itemtype)) {
               $plugin_name   = $plug['plugin'];
               $hook_function = 'plugin_' . $plugin_name . '_addDefaultJoin';
               $hook_closure  = function () use ($hook_function, $itemtype, $ref_table, &$already_link_tables) {
                  if (is_callable($hook_function)) {
                     return $hook_function($itemtype, $ref_table, $already_link_tables);
                  }
               };
               $out = Plugin::doOneHook($plug['plugin'], $hook_closure);
            }
            break;
      }

      list($itemtype, $out) = Plugin::doHookFunction('add_default_join', [$itemtype, $out]);
      return $out;
   }


   /**
    * Generic Function to add left join to a request
    *
    * @param string  $itemtype             Item type
    * @param string  $ref_table            Reference table
    * @param array   $already_link_tables  Array of tables already joined
    * @param string  $new_table            New table to join
    * @param string  $linkfield            Linkfield for LeftJoin
    * @param boolean $meta                 Is it a meta item ? (default 0)
    * @param integer $meta_type            Meta type table (default 0)
    * @param array   $joinparams           Array join parameters (condition / joinbefore...)
    * @param string  $field                Field to display (needed for translation join) (default '')
    *
    * @return string Left join string
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function addLeftJoin($itemtype, $ref_table, array &$already_link_tables, $new_table,
                               $linkfield, $meta = 0, $meta_type = 0, $joinparams = [], $field = '') {

      // Rename table for meta left join
      $AS = "";
      $nt = $new_table;
      $cleannt    = $nt;

      // Virtual field no link
      if (strpos($linkfield, '_virtual') === 0) {
         return false;
      }

      $complexjoin = self::computeComplexJoinID($joinparams);

      $is_fkey_composite_on_self = getTableNameForForeignKeyField($linkfield) == $ref_table
         && $linkfield != getForeignKeyFieldForTable($ref_table);

      // Auto link
      if (($ref_table == $new_table)
          && empty($complexjoin)
          && !$is_fkey_composite_on_self) {
         $transitemtype = getItemTypeForTable($new_table);
         if (Session::haveTranslations($transitemtype, $field)) {
            $transAS            = $nt.'_trans';
            return $this->joinDropdownTranslations(
               $transAS,
               $nt,
               $transitemtype,
               $field
            );
         }
         return "";
      }

      // Multiple link possibilies case
      if (!empty($linkfield) && ($linkfield != getForeignKeyFieldForTable($new_table))) {
         $nt .= "_".$linkfield;
         $AS  = " AS ".$this->db->quoteName($nt);
      }

      if (!empty($complexjoin)) {
         $nt .= "_".$complexjoin;
         $AS  = " AS ". $this->db->quoteName($nt);
      }

      $addmetanum = "";
      $rt         = $ref_table;
      $cleanrt    = $rt;
      if ($meta && $meta_type::getTable() != $new_table) {
         $addmetanum = "_".$meta_type;
         $AS         = " AS ".$this->db->quoteName($nt.$addmetanum);
         $nt         = $nt.$addmetanum;
      }

      // Do not take into account standard linkfield
      $tocheck = $nt.".".$linkfield;
      if ($linkfield == getForeignKeyFieldForTable($new_table)) {
         $tocheck = $nt;
      }

      if (in_array($tocheck, $already_link_tables)) {
         return "";
      }
      array_push($already_link_tables, $tocheck);

      $specific_leftjoin = '';

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $plugin_name   = $plug['plugin'];
         $hook_function = 'plugin_' . $plugin_name . '_addLeftJoin';
         $hook_closure  = function () use ($hook_function, $itemtype, $ref_table, $new_table, $linkfield, &$already_link_tables) {
            if (is_callable($hook_function)) {
               return $hook_function($itemtype, $ref_table, $new_table, $linkfield, $already_link_tables);
            }
         };
         $specific_leftjoin = Plugin::doOneHook($plugin_name, $hook_closure);
      }

      // Link with plugin tables : need to know left join structure
      if (empty($specific_leftjoin)
          && preg_match("/^glpi_plugin_([a-z0-9]+)/", $new_table, $matches)) {
         if (count($matches) == 2) {
            $plugin_name   = $matches[1];
            $hook_function = 'plugin_' . $plugin_name . '_addLeftJoin';
            $hook_closure  = function () use ($hook_function, $itemtype, $ref_table, $new_table, $linkfield, &$already_link_tables) {
               if (is_callable($hook_function)) {
                  return $hook_function($itemtype, $ref_table, $new_table, $linkfield, $already_link_tables);
               }
            };
            $specific_leftjoin = Plugin::doOneHook($plugin_name, $hook_closure);
         }
      }
      if (!empty($linkfield)) {
         $before = '';

         if (isset($joinparams['beforejoin']) && is_array($joinparams['beforejoin'])) {

            if (isset($joinparams['beforejoin']['table'])) {
               $joinparams['beforejoin'] = [$joinparams['beforejoin']];
            }

            foreach ($joinparams['beforejoin'] as $tab) {
               if (isset($tab['table'])) {
                  $intertable = $tab['table'];
                  if (isset($tab['linkfield'])) {
                     $interlinkfield = $tab['linkfield'];
                  } else {
                     $interlinkfield = getForeignKeyFieldForTable($intertable);
                  }

                  $interjoinparams = [];
                  if (isset($tab['joinparams'])) {
                     $interjoinparams = $tab['joinparams'];
                  }
                  $before .= $this->addLeftJoin($itemtype, $rt, $already_link_tables, $intertable,
                                               $interlinkfield, $meta, $meta_type, $interjoinparams);
               }

               // No direct link with the previous joins
               if (!isset($tab['joinparams']['nolink']) || !$tab['joinparams']['nolink']) {
                  $cleanrt     = $intertable;
                  $complexjoin = self::computeComplexJoinID($interjoinparams);
                  if (!empty($complexjoin)) {
                     $intertable .= "_".$complexjoin;
                  }
                  $rt = $intertable.$addmetanum;
               }
            }
         }

         $addcondition = '';
         if (isset($joinparams['condition'])) {
            $condition = $joinparams['condition'];
            if (is_array($condition)) {
               $it = new DBmysqlIterator($this->db);
               $condition = ' AND ' . $it->analyseCrit($condition);
            }
            $from         = [
               $this->db->quoteName("REFTABLE"),
               "REFTABLE",
               $this->db->quoteName("NEWTABLE"),
               "NEWTABLE"
            ];
            $to           = [
               $this->db->quoteName($rt),
               $this->db->quoteName($rt),
               $this->db->quoteName($nt),
               $this->db->quoteName($nt)
            ];
            $addcondition = str_replace($from, $to, $condition);
            $addcondition = $addcondition." ";
         }

         if (!isset($joinparams['jointype'])) {
            $joinparams['jointype'] = 'standard';
         }

         if (empty($specific_leftjoin)) {
            switch ($new_table) {
               // No link
               case "glpi_auth_tables" :
                     $user_searchopt     = self::getOptions('User');

                     $specific_leftjoin  = $this->addLeftJoin($itemtype, $rt, $already_link_tables,
                                                             "glpi_authldaps", 'auths_id', 0, 0,
                                                             $user_searchopt[30]['joinparams']);
                     $specific_leftjoin .= $this->addLeftJoin($itemtype, $rt, $already_link_tables,
                                                             "glpi_authmails", 'auths_id', 0, 0,
                                                             $user_searchopt[31]['joinparams']);
                     break;
            }
         }

         if (empty($specific_leftjoin)) {
            switch ($joinparams['jointype']) {
               case 'child' :
                  $linkfield = getForeignKeyFieldForTable($cleanrt);
                  if (isset($joinparams['linkfield'])) {
                     $linkfield = $joinparams['linkfield'];
                  }

                  // Child join
                  $specific_leftjoin = " LEFT JOIN ".$this->db->quoteName($new_table)." $AS
                                             ON (".$this->db->quoteName("$rt.id")." = ".$this->db->quoteName("$nt.$linkfield")."
                                                 $addcondition)";
                  break;

               case 'item_item' :
                  // Item_Item join
                  $specific_leftjoin = " LEFT JOIN ".$this->db->quoteName($new_table)." $AS
                                          ON ((".$this->db->quoteName("$rt.id")."
                                                = ".$this->db->quoteName("$nt.".getForeignKeyFieldForTable($cleanrt)."_1")."
                                               OR ".$this->db->quoteName("$rt.id")."
                                                 = ".$this->db->quoteName("$nt.".getForeignKeyFieldForTable($cleanrt)."_2").")
                                              $addcondition)";
                  break;

               case 'item_item_revert' :
                  // Item_Item join reverting previous item_item
                  $specific_leftjoin = " LEFT JOIN ".$this->db->quoteName($new_table)." $AS
                                          ON ((".$this->db->quoteName("$nt.id")."
                                                = ".$this->db->quoteName("$rt.".getForeignKeyFieldForTable($cleannt)."_1")."
                                               OR ".$this->db->quoteName("$nt.id")."
                                                 = ".$this->db->quoteName("$rt.".getForeignKeyFieldForTable($cleannt)."_2").")
                                              $addcondition)";
                  break;

               case "mainitemtype_mainitem" :
                  $addmain = 'main';

               case "itemtype_item" :
                  if (!isset($addmain)) {
                     $addmain = '';
                  }
                  $used_itemtype = $itemtype;
                  if (isset($joinparams['specific_itemtype'])
                      && !empty($joinparams['specific_itemtype'])) {
                     $used_itemtype = $joinparams['specific_itemtype'];
                  }
                  // Itemtype join
                  $specific_leftjoin = " LEFT JOIN ".$this->db->quoteName($new_table)." $AS
                                          ON (".$this->db->quoteName("$rt.id")." = ".$this->db->quoteName("$nt.{$addmain}items_id")."
                                              AND ".$this->db->quoteName("$nt.{$addmain}itemtype")." = ".$this->db->quote($used_itemtype)."
                                              $addcondition) ";
                  break;

               case "itemtype_item_revert" :
                  if (!isset($addmain)) {
                     $addmain = '';
                  }
                  $used_itemtype = $itemtype;
                  if (isset($joinparams['specific_itemtype'])
                      && !empty($joinparams['specific_itemtype'])) {
                     $used_itemtype = $joinparams['specific_itemtype'];
                  }
                  // Itemtype join
                  $specific_leftjoin = " LEFT JOIN ".$this->db->quoteName($new_table)." $AS
                                          ON (".$this->db->quoteName("$nt.id")." = ".$this->db->quoteName("$rt.{$addmain}items_id")."
                                              AND ".$this->db->quoteName("$rt.{$addmain}itemtype")." = ".$this->db->quote($used_itemtype)."
                                              $addcondition) ";
                  break;

               case "itemtypeonly" :
                  $used_itemtype = $itemtype;
                  if (isset($joinparams['specific_itemtype'])
                      && !empty($joinparams['specific_itemtype'])) {
                     $used_itemtype = $joinparams['specific_itemtype'];
                  }
                  // Itemtype join
                  $specific_leftjoin = " LEFT JOIN ".$this->db->quoteName($new_table)." $AS
                                          ON (".$this->db->quoteName("$nt.itemtype")." = ".$this->db->quote($used_itemtype)."
                                              $addcondition) ";
                  break;

               default :
                  // Standard join
                  $specific_leftjoin = " LEFT JOIN ".$this->db->quoteName($new_table)." $AS
                                          ON (".$this->db->quoteName("$rt.$linkfield")." = ".$this->db->quoteName("$nt.id")."
                                              $addcondition)";
                  $transitemtype = getItemTypeForTable($new_table);
                  if (Session::haveTranslations($transitemtype, $field)) {
                     $transAS            = $nt.'_trans';
                     $specific_leftjoin .= $this->joinDropdownTranslations(
                        $transAS,
                        $nt,
                        $transitemtype,
                        $field
                     );
                  }
                  break;
            }
         }
         return $before.$specific_leftjoin;
      }
   }


   /**
    * Generic Function to add left join for meta items
    *
    * @param string $from_type             Reference item type ID
    * @param string $to_type               Item type to add
    * @param array  $already_link_tables2  Array of tables already joined
    *
    * @return string Meta Left join string
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function addMetaLeftJoin($from_type, $to_type, array &$already_link_tables2,
                                   $joinparams = []) {
      $LINK = " LEFT JOIN ";

      $from_table = $from_type::getTable();
      $from_fk    = getForeignKeyFieldForTable($from_table);
      $to_table   = $to_type::getTable();
      $to_fk      = getForeignKeyFieldForTable($to_table);

      $complexjoin = self::computeComplexJoinID($joinparams);
      if ($complexjoin != '') {
         $complexjoin .= '_';
      }

      // Generic metacriteria
      switch ($to_type) {
         case 'User' :
         case 'Group' :
            array_push($already_link_tables2, $to_table);
            return "$LINK ".$this->db->quoteName("$to_table")."
                        ON (".$this->db->quoteName("$from_table.$to_fk")." = ".$this->db->quoteName("$to_table.id").") ";
         case 'Budget' :
            array_push($already_link_tables2, $to_table);
            return "$LINK ".$this->db->quoteName("glpi_infocoms")."
                        ON (".$this->db->quoteName("$from_table.id")." = ".$this->db->quoteName("glpi_infocoms.items_id")."
                            AND ".$this->db->quoteName("glpi_infocoms.itemtype")." = ".$this->db->quote($from_type).")
                    $LINK ".$this->db->quoteName($to_table)."
                        ON (".$this->db->quoteName("glpi_infocoms.$to_fk")." = ".$this->db->quoteName("$to_table.id").") ";
         case 'Appliance' :
            array_push($already_link_tables2, $to_table);
            return "$LINK `glpi_appliances_items`
                        ON (`$from_table`.`id` = `glpi_appliances_items`.`items_id`
                            AND `glpi_appliances_items`.`itemtype` = '$from_type')
                    $LINK `$to_table`
                        ON (`glpi_appliances_items`.`$to_fk` = `$to_table`.`id`) ";
      }

      // specific metacriteria
      switch (static::getMetaReferenceItemtype($from_type)) {
         case 'Ticket' :
         case 'Problem' :
         case 'Change' :
            switch ($from_type) {
               case 'Ticket':
                  $link_table = "glpi_items_tickets";
                  break;
               case 'Problem':
                  $link_table = "glpi_items_problems";
                  break;
               case 'Change':
                  $link_table = "glpi_changes_items";
                  break;
            }
            array_push($already_link_tables2, $to_table);
            return " $LINK ".$this->db->quoteName($link_table)." AS {$link_table}_to_$to_type
                        ON (".$this->db->quoteName("$from_table.id")." = ".$this->db->quoteName("{$link_table}_to_$to_type.$from_fk").")
                     $LINK ".$this->db->quoteName($to_table)."
                        ON (".$this->db->quoteName("$to_table.id")." = ".$this->db->quoteName("{$link_table}_to_$to_type.items_id")."
                     AND ".$this->db->quoteName("{$link_table}_to_$to_type.itemtype")." = ".$this->db->quote($to_type).")";

         case 'Computer' :
            switch ($to_type) {
               case 'Printer' :
                  array_push($already_link_tables2, $to_table);
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK ".$this->db->quoteName("glpi_computers_items")." AS ".$this->db->quoteName("glpi_computers_items_$to_type")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.computers_id")."
                                       = ".$this->db->quoteName("glpi_computers.id")."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.itemtype")." = ".$this->db->quote($to_type)."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.is_deleted")." = 0)
                           $LINK ".$this->db->quoteName("glpi_printers")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.items_id")." = ".$this->db->quoteName("glpi_printers.id").") ";

               case 'Monitor' :
                  array_push($already_link_tables2, $to_table);
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK ".$this->db->quoteName("glpi_computers_items")." AS ".$this->db->quoteName("glpi_computers_items_$to_type")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.computers_id")."
                                       = ".$this->db->quoteName("glpi_computers.id")."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.itemtype")." = ".$this->db->quote($to_type)."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.is_deleted")." = 0)
                           $LINK ".$this->db->quoteName("glpi_monitors")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.items_id")." = ".$this->db->quoteName("glpi_monitors.id").") ";

               case 'Peripheral' :
                  array_push($already_link_tables2, $to_table);
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK ".$this->db->quoteName("glpi_computers_items")." AS ".$this->db->quoteName("glpi_computers_items_$to_type")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.computers_id")."
                                       = ".$this->db->quoteName("glpi_computers.id")."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.itemtype")." = ".$this->db->quote($to_type)."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.is_deleted")." = 0)
                           $LINK ".$this->db->quoteName("glpi_peripherals")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.items_id")."
                                       = ".$this->db->quoteName("glpi_peripherals.id").") ";

               case 'Phone' :
                  array_push($already_link_tables2, $to_table);
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK ".$this->db->quoteName("glpi_computers_items")." AS ".$this->db->quoteName("glpi_computers_items_$to_type")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.computers_id")."
                                       = ".$this->db->quoteName("glpi_computers.id")."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.itemtype")." = ".$this->db->quote($to_type)."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.is_deleted")." = 0)
                           $LINK ".$this->db->quoteName("glpi_phones")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.items_id")." = ".$this->db->quoteName("glpi_phones.id").") ";

               case 'Software' :
                  array_push($already_link_tables2, $to_table);
                  array_push($already_link_tables2, "glpi_softwareversions_$complexjoin$to_type");
                  array_push($already_link_tables2, "glpi_softwarelicenses_$complexjoin$to_type");
                  array_push($already_link_tables2, "glpi_items_softwareversions_$complexjoin$to_type");
                  return " $LINK ".$this->db->quoteName("glpi_items_softwareversions")."
                                    AS ".$this->db->quoteName("glpi_items_softwareversions_$complexjoin$to_type")."
                              ON (".$this->db->quoteName("glpi_items_softwareversions_$complexjoin$to_type.items_id")."
                                       = ".$this->db->quoteName("glpi_computers.id")." AND ".$this->db->quoteName("glpi_items_softwareversions_$complexjoin$to_type.itemtype")."='Computer'
                                  AND ".$this->db->quoteName("glpi_items_softwareversions_$complexjoin$to_type.is_deleted")." = 0)
                           $LINK ".$this->db->quoteName("glpi_softwareversions")." AS ".$this->db->quoteName("glpi_softwareversions_$complexjoin$to_type")."
                              ON (".$this->db->quoteName("glpi_items_softwareversions_$complexjoin$to_type.softwareversions_id")."
                                       = ".$this->db->quoteName("glpi_softwareversions_$complexjoin$to_type.id").")
                           $LINK ".$this->db->quoteName("glpi_softwares")."
                              ON (".$this->db->quoteName("glpi_softwareversions_$complexjoin$to_type.softwares_id")."
                                       = ".$this->db->quoteName("glpi_softwares.id").")
                           LEFT JOIN ".$this->db->quoteName("glpi_softwarelicenses")." AS ".$this->db->quoteName("glpi_softwarelicenses_$complexjoin$to_type")."
                              ON (".$this->db->quoteName("glpi_softwares.id")."
                                       = ".$this->db->quoteName("glpi_softwarelicenses_$complexjoin$to_type.softwares_id").
                                  getEntitiesRestrictRequest(' AND',
                                                             "glpi_softwarelicenses_$complexjoin$to_type",
                                                             '', '', true).") ";
            }
            break;

         case 'Monitor' :
            switch ($to_type) {
               case 'Computer' :
                  array_push($already_link_tables2, $to_table);
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK ".$this->db->quoteName("glpi_computers_items")." AS ".$this->db->quoteName("glpi_computers_items_$to_type")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.items_id")." = ".$this->db->quoteName("glpi_monitors.id")."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.itemtype")." = ".$this->db->quote($from_type)."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.is_deleted")." = 0)
                           $LINK ".$this->db->quoteName("glpi_computers")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.computers_id")."
                                       = ".$this->db->quoteName("glpi_computers.id").") ";

               case 'Software' :
                  array_push($already_link_tables2, getTableForItemType($to_type));
                  array_push($already_link_tables2, "glpi_softwareversions_$to_type");
                  array_push($already_link_tables2, "glpi_softwarelicenses_$to_type");
                  return " $LINK `glpi_items_softwareversions`
                                    AS `glpi_items_softwareversions_$complexjoin$to_type`
                              ON (`glpi_items_softwareversions_$complexjoin$to_type`.`items_id`
                                       = `$from_table`.`id`
                                  AND `glpi_items_softwareversions_$complexjoin$to_type`.`itemtype` = '$from_type'
                                  AND `glpi_items_softwareversions_$complexjoin$to_type`.`is_deleted` = 0)
                           $LINK `glpi_softwareversions` AS `glpi_softwareversions_$complexjoin$to_type`
                              ON (`glpi_items_softwareversions_$complexjoin$to_type`.`softwareversions_id`
                                       = `glpi_softwareversions_$complexjoin$to_type`.`id`)
                           $LINK `glpi_softwares`
                              ON (`glpi_softwareversions_$complexjoin$to_type`.`softwares_id`
                                       = `glpi_softwares`.`id`)
                           LEFT JOIN `glpi_softwarelicenses` AS `glpi_softwarelicenses_$complexjoin$to_type`
                              ON (`glpi_softwares`.`id`
                                       = `glpi_softwarelicenses_$complexjoin$to_type`.`softwares_id`".
                     getEntitiesRestrictRequest(' AND',
                        "glpi_softwarelicenses_$complexjoin$to_type",
                        '', '', true).") ";
            }
            break;

         case 'Printer' :
            switch ($to_type) {
               case 'Computer' :
                  array_push($already_link_tables2, $to_table);
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK ".$this->db->quoteName("glpi_computers_items")." AS ".$this->db->quoteName("glpi_computers_items_$to_type")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.items_id")." = ".$this->db->quoteName("glpi_printers.id")."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.itemtype")." = ".$this->db->quote($from_type)."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.is_deleted")." = 0)
                           $LINK ".$this->db->quoteName("glpi_computers")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.computers_id")."
                                       = ".$this->db->quoteName("glpi_computers.id")." ".
                                  getEntitiesRestrictRequest("AND", 'glpi_computers').") ";
            }
            break;

         case 'Peripheral' :
            switch ($to_type) {
               case 'Computer' :
                  array_push($already_link_tables2, $to_table);
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK ".$this->db->quoteName("glpi_computers_items")." AS ".$this->db->quoteName("glpi_computers_items_$to_type")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.items_id")."
                                       = ".$this->db->quoteName("glpi_peripherals.id")."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.itemtype")." = ".$this->db->quote($from_type)."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.is_deleted")." = 0)
                           $LINK ".$this->db->quoteName("glpi_computers")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.computers_id")."
                                       = ".$this->db->quoteName("glpi_computers.id").") ";
            }
            break;

         case 'Phone' :
            switch ($to_type) {
               case 'Computer' :
                  array_push($already_link_tables2, $to_table);
                  array_push($already_link_tables2, "glpi_computers_items_$to_type");
                  return " $LINK ".$this->db->quoteName("glpi_computers_items")." AS ".$this->db->quoteName("glpi_computers_items_$to_type")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.items_id")." = ".$this->db->quoteName("glpi_phones.id")."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.itemtype")." = ".$this->db->quote($from_type)."
                                  AND ".$this->db->quoteName("glpi_computers_items_$to_type.is_deleted")." = 0)
                           $LINK ".$this->db->quoteName("glpi_computers")."
                              ON (".$this->db->quoteName("glpi_computers_items_$to_type.computers_id")."
                                       = ".$this->db->quoteName("glpi_computers.id").") ";
            }
            break;

         case 'Software' :
            switch ($to_type) {
               case 'Computer' :
                  array_push($already_link_tables2, $to_table);
                  array_push($already_link_tables2, "glpi_softwareversions_$to_type");
                  array_push($already_link_tables2, "glpi_softwareversions_$to_type");
                  return " $LINK ".$this->db->quoteName("glpi_softwareversions")." AS ".$this->db->quoteName("glpi_softwareversions_$to_type")."
                              ON (".$this->db->quoteName("glpi_softwareversions_$to_type.softwares_id")."
                                       = ".$this->db->quoteName("glpi_softwares.id").")
                           $LINK ".$this->db->quoteName("glpi_items_softwareversions")."
                                    AS ".$this->db->quoteName("glpi_items_softwareversions_$to_type")."
                              ON (".$this->db->quoteName("glpi_items_softwareversions_$to_type.softwareversions_id")."
                                       = ".$this->db->quoteName("glpi_softwareversions_$to_type.id")."
                                  AND ".$this->db->quoteName("glpi_items_softwareversions_$to_type.itemtype")." = '$to_type'
                                  AND ".$this->db->quoteName("glpi_items_softwareversions_$to_type.is_deleted")." = 0)
                           $LINK ".$this->db->quoteName("glpi_computers")."
                              ON (".$this->db->quoteName("glpi_items_softwareversions_$to_type.items_id")."
                                       = ".$this->db->quoteName("glpi_computers.id")." AND ".$this->db->quoteName("glpi_items_softwareversions_$to_type.items_id")."='Computer' ".
                                  getEntitiesRestrictRequest("AND", 'glpi_computers').") ";
            }
            break;
      }
   }


   /**
    * Generic Function to display Items
    *
    * @since 9.4: $num param has been dropped
    *
    * @param string  $itemtype item type
    * @param integer $ID       ID of the SEARCH_OPTION item
    * @param array   $data     array retrieved data array
    *
    * @return string String to print
   **/
   static function displayConfigItem($itemtype, $ID, $data = []) {

      $searchopt  = &self::getOptions($itemtype);

      $table      = $searchopt[$ID]["table"];
      $field      = $searchopt[$ID]["field"];

      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $out = Plugin::doOneHook(
            $plug['plugin'],
            'displayConfigItem',
            $itemtype, $ID, $data, "{$itemtype}_{$ID}"
         );
         if (!empty($out)) {
            return $out;
         }
      }

      $out = "";
      $NAME = "{$itemtype}_{$ID}";

      switch ($table.".".$field) {
         case "glpi_tickets.time_to_resolve" :
         case "glpi_tickets.internal_time_to_resolve" :
         case "glpi_problems.time_to_resolve" :
         case "glpi_changes.time_to_resolve" :
         case "glpi_tickets.time_to_own" :
         case "glpi_tickets.internal_time_to_own" :
            if (!in_array($ID, [151, 158, 181, 186])
                && !empty($data[$NAME][0]['name'])
                && ($data[$NAME][0]['status'] != CommonITILObject::WAITING)
                && ($data[$NAME][0]['name'] < $_SESSION['glpi_currenttime'])) {
               $out = " style=\"background-color: #cf9b9b\" ";
            }
            break;

         case "glpi_projectstates.color" :
            $out = " style=\"background-color:".$data[$NAME][0]['name'].";\" ";
            break;

         case "glpi_projectstates.name" :
            if (array_key_exists('color', $data[$NAME][0])) {
               $out = " style=\"background-color:".$data[$NAME][0]['color'].";\" ";
            }
            break;
      }

      return $out;
   }


   /**
    * Generic Function to display Items
    *
    * @since 9.4: $num param has been dropped
    *
    * @param string  $itemtype        item type
    * @param integer $ID              ID of the SEARCH_OPTION item
    * @param array   $data            array containing data results
    * @param boolean $meta            is a meta item ? (default 0)
    * @param array   $addobjectparams array added parameters for union search
    * @param string  $orig_itemtype   Original itemtype, used for union_search_type
    *
    * @return string String to print
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function giveItem($itemtype, $ID, array $data, $meta = 0,
                            array $addobjectparams = [], $orig_itemtype = null) {
      global $CFG_GLPI;

      $searchopt = &self::getOptions($itemtype);
      if ($itemtype == 'AllAssets' || isset($CFG_GLPI["union_search_type"][$itemtype])
          && ($CFG_GLPI["union_search_type"][$itemtype] == $searchopt[$ID]["table"])) {

         $oparams = [];
         if (isset($searchopt[$ID]['addobjectparams'])
             && $searchopt[$ID]['addobjectparams']) {
            $oparams = $searchopt[$ID]['addobjectparams'];
         }

         // Search option may not exists in subtype
         // This is the case for "Inventory number" for a Software listed from ReservationItem search
         $subtype_so = &self::getOptions($data["TYPE"]);
         if (!array_key_exists($ID, $subtype_so)) {
            return '';
         }

         return $this->giveItem($data["TYPE"], $ID, $data, $meta, $oparams, $itemtype);
      }
      $so = $searchopt[$ID];
      $orig_id = $ID;
      $ID = ($orig_itemtype !== null ? $orig_itemtype : $itemtype) . '_' . $ID;

      if (count($addobjectparams)) {
         $so = array_merge($so, $addobjectparams);
      }
      // Plugin can override core definition for its type
      if ($plug = isPluginItemType($itemtype)) {
         $out = Plugin::doOneHook(
            $plug['plugin'],
            'giveItem',
            $itemtype, $orig_id, $data, $ID
         );
         if (!empty($out)) {
            return $out;
         }
      }

      if (isset($so["table"])) {
         $table     = $so["table"];
         $field     = $so["field"];
         $linkfield = $so["linkfield"];

         /// TODO try to clean all specific cases using SpecificToDisplay

         switch ($table.'.'.$field) {
            case "glpi_users.name" :
               if ($itemtype == 'Ticket'
                  && Session::getCurrentInterface() == 'helpdesk'
                  && $orig_id == 5
                  && Entity::getUsedConfig(
                     'anonymize_support_agents',
                     $itemtype::getById($data['id'])->getEntityId()
                  )
               ) {
                  return __("Helpdesk");
               }

               // USER search case
               if (($itemtype != 'User')
                   && isset($so["forcegroupby"]) && $so["forcegroupby"]) {
                  $out           = "";
                  $count_display = 0;
                  $added         = [];

                  $showuserlink = 0;
                  if (Session::haveRight('user', READ)) {
                     $showuserlink = 1;
                  }

                  for ($k=0; $k<$data[$ID]['count']; $k++) {

                     if ((isset($data[$ID][$k]['name']) && ($data[$ID][$k]['name'] > 0))
                         || (isset($data[$ID][$k][2]) && ($data[$ID][$k][2] != ''))) {
                        if ($count_display) {
                           $out .= self::LBBR;
                        }

                        if ($itemtype == 'Ticket') {
                           if (isset($data[$ID][$k]['name'])
                                 && $data[$ID][$k]['name'] > 0) {
                              $userdata = getUserName($data[$ID][$k]['name'], 2);
                              $tooltip  = "";
                              if (Session::haveRight('user', READ)) {
                                 $tooltip = Html::showToolTip($userdata["comment"],
                                                              ['link'    => $userdata["link"],
                                                                    'display' => false]);
                              }
                              $out .= sprintf(__('%1$s %2$s'), $userdata['name'], $tooltip);
                              $count_display++;
                           }
                        } else {
                           $out .= getUserName($data[$ID][$k]['name'], $showuserlink);
                           $count_display++;
                        }

                        // Manage alternative_email for tickets_users
                        if (($itemtype == 'Ticket')
                            && isset($data[$ID][$k][2])) {

                           $split = explode(self::LONGSEP, $data[$ID][$k][2]);
                           for ($l=0; $l<count($split); $l++) {
                              $split2 = explode(" ", $split[$l]);
                              if ((count($split2) == 2) && ($split2[0] == 0) && !empty($split2[1])) {
                                 if ($count_display) {
                                    $out .= self::LBBR;
                                 }
                                 $count_display++;
                                 $out .= "<a href='mailto:".$split2[1]."'>".$split2[1]."</a>";
                              }
                           }
                        }
                     }
                  }
                  return $out;
               }
               if ($itemtype != 'User') {
                  $toadd = '';
                  if (($itemtype == 'Ticket')
                      && ($data[$ID][0]['id'] > 0)) {
                     $userdata = getUserName($data[$ID][0]['id'], 2);
                     $toadd    = Html::showToolTip($userdata["comment"],
                                                   ['link'    => $userdata["link"],
                                                         'display' => false]);
                  }
                  $usernameformat = formatUserName($data[$ID][0]['id'], $data[$ID][0]['name'],
                                                   $data[$ID][0]['realname'],
                                                   $data[$ID][0]['firstname'], 1);
                  return sprintf(__('%1$s %2$s'), $usernameformat, $toadd);
               }
               break;

            case "glpi_profiles.name" :
               if (($itemtype == 'User')
                   && ($orig_id == 20)) {
                  $out           = "";

                  $count_display = 0;
                  $added         = [];
                  for ($k=0; $k<$data[$ID]['count']; $k++) {
                     if (strlen(trim($data[$ID][$k]['name'])) > 0
                         && !in_array($data[$ID][$k]['name']."-".$data[$ID][$k]['entities_id'],
                                      $added)) {
                        $text = sprintf(__('%1$s - %2$s'), $data[$ID][$k]['name'],
                                        Dropdown::getDropdownName('glpi_entities',
                                                                  $data[$ID][$k]['entities_id']));
                        $comp = '';
                        if ($data[$ID][$k]['is_recursive']) {
                           $comp = __('R');
                           if ($data[$ID][$k]['is_dynamic']) {
                              $comp = sprintf(__('%1$s%2$s'), $comp, ", ");
                           }
                        }
                        if ($data[$ID][$k]['is_dynamic']) {
                           $comp = sprintf(__('%1$s%2$s'), $comp, __('D'));
                        }
                        if (!empty($comp)) {
                           $text = sprintf(__('%1$s %2$s'), $text, "(".$comp.")");
                        }
                        if ($count_display) {
                           $out .= self::LBBR;
                        }
                        $count_display++;
                        $out     .= $text;
                        $added[]  = $data[$ID][$k]['name']."-".$data[$ID][$k]['entities_id'];
                     }
                  }
                  return $out;
               }
               break;

            case "glpi_entities.completename" :
               if ($itemtype == 'User') {

                  $out           = "";
                  $added         = [];
                  $count_display = 0;
                  for ($k=0; $k<$data[$ID]['count']; $k++) {
                     if (isset($data[$ID][$k]['name'])
                         && (strlen(trim($data[$ID][$k]['name'])) > 0)
                         && !in_array($data[$ID][$k]['name']."-".$data[$ID][$k]['profiles_id'],
                                      $added)) {
                        $text = sprintf(__('%1$s - %2$s'), $data[$ID][$k]['name'],
                                        Dropdown::getDropdownName('glpi_profiles',
                                                                  $data[$ID][$k]['profiles_id']));
                        $comp = '';
                        if ($data[$ID][$k]['is_recursive']) {
                           $comp = __('R');
                           if ($data[$ID][$k]['is_dynamic']) {
                              $comp = sprintf(__('%1$s%2$s'), $comp, ", ");
                           }
                        }
                        if ($data[$ID][$k]['is_dynamic']) {
                           $comp = sprintf(__('%1$s%2$s'), $comp, __('D'));
                        }
                        if (!empty($comp)) {
                           $text = sprintf(__('%1$s %2$s'), $text, "(".$comp.")");
                        }
                        if ($count_display) {
                           $out .= self::LBBR;
                        }
                        $count_display++;
                        $out    .= $text;
                        $added[] = $data[$ID][$k]['name']."-".$data[$ID][$k]['profiles_id'];
                     }
                  }
                  return $out;
               }
               break;

            case "glpi_documenttypes.icon" :
               if (!empty($data[$ID][0]['name'])) {
                  return "<img class='middle' alt='' src='".$CFG_GLPI["typedoc_icon_dir"]."/".
                           $data[$ID][0]['name']."'>";
               }
               return "&nbsp;";

            case "glpi_documents.filename" :
               $doc = new Document();
               if ($doc->getFromDB($data['id'])) {
                  return $doc->getDownloadLink();
               }
               return NOT_AVAILABLE;

            case "glpi_tickets_tickets.tickets_id_1" :
               $out        = "";
               $displayed  = [];
               for ($k=0; $k<$data[$ID]['count']; $k++) {

                  $linkid = ($data[$ID][$k]['tickets_id_2'] == $data['id'])
                                 ? $data[$ID][$k]['name']
                                 : $data[$ID][$k]['tickets_id_2'];
                  if (($linkid > 0) && !isset($displayed[$linkid])) {
                     $text  = "<a ";
                     $text .= "href=\"".Ticket::getFormURLWithID($linkid)."\">";
                     $text .= Dropdown::getDropdownName('glpi_tickets', $linkid)."</a>";
                     if (count($displayed)) {
                        $out .= self::LBBR;
                     }
                     $displayed[$linkid] = $linkid;
                     $out               .= $text;
                  }
               }
               return $out;

            case "glpi_problems.id" :
               if ($so["datatype"] == 'count') {
                  if (($data[$ID][0]['name'] > 0)
                      && Session::haveRight("problem", Problem::READALL)) {
                     if ($itemtype == 'ITILCategory') {
                        $options['criteria'][0]['field']      = 7;
                        $options['criteria'][0]['searchtype'] = 'equals';
                        $options['criteria'][0]['value']      = $data['id'];
                        $options['criteria'][0]['link']       = 'AND';
                     } else {
                        $options['criteria'][0]['field']       = 12;
                        $options['criteria'][0]['searchtype']  = 'equals';
                        $options['criteria'][0]['value']       = 'all';
                        $options['criteria'][0]['link']        = 'AND';

                        $options['metacriteria'][0]['itemtype']   = $itemtype;
                        $options['metacriteria'][0]['field']      = self::getOptionNumber($itemtype,
                              'name');
                        $options['metacriteria'][0]['searchtype'] = 'equals';
                        $options['metacriteria'][0]['value']      = $data['id'];
                        $options['metacriteria'][0]['link']       = 'AND';
                     }

                     $options['reset'] = 'reset';

                     $out  = "<a id='problem$itemtype".$data['id']."' ";
                     $out .= "href=\"".$CFG_GLPI["root_doc"]."/front/problem.php?".
                              Toolbox::append_params($options, '&amp;')."\">";
                     $out .= $data[$ID][0]['name']."</a>";
                     return $out;
                  }
               }
               break;

            case "glpi_tickets.id" :
               if ($so["datatype"] == 'count') {
                  if (($data[$ID][0]['name'] > 0)
                      && Session::haveRight("ticket", Ticket::READALL)) {

                     if ($itemtype == 'User') {
                        // Requester
                        if ($ID == 'User_60') {
                           $options['criteria'][0]['field']      = 4;
                           $options['criteria'][0]['searchtype']= 'equals';
                           $options['criteria'][0]['value']      = $data['id'];
                           $options['criteria'][0]['link']       = 'AND';
                        }

                        // Writer
                        if ($ID == 'User_61') {
                           $options['criteria'][0]['field']      = 22;
                           $options['criteria'][0]['searchtype']= 'equals';
                           $options['criteria'][0]['value']      = $data['id'];
                           $options['criteria'][0]['link']       = 'AND';
                        }
                        // Assign
                        if ($ID == 'User_64') {
                           $options['criteria'][0]['field']      = 5;
                           $options['criteria'][0]['searchtype']= 'equals';
                           $options['criteria'][0]['value']      = $data['id'];
                           $options['criteria'][0]['link']       = 'AND';
                        }
                     } else if ($itemtype == 'ITILCategory') {
                        $options['criteria'][0]['field']      = 7;
                        $options['criteria'][0]['searchtype'] = 'equals';
                        $options['criteria'][0]['value']      = $data['id'];
                        $options['criteria'][0]['link']       = 'AND';

                     } else {
                        $options['criteria'][0]['field']       = 12;
                        $options['criteria'][0]['searchtype']  = 'equals';
                        $options['criteria'][0]['value']       = 'all';
                        $options['criteria'][0]['link']        = 'AND';

                        $options['metacriteria'][0]['itemtype']   = $itemtype;
                        $options['metacriteria'][0]['field']      = self::getOptionNumber($itemtype,
                                                                                          'name');
                        $options['metacriteria'][0]['searchtype'] = 'equals';
                        $options['metacriteria'][0]['value']      = $data['id'];
                        $options['metacriteria'][0]['link']       = 'AND';
                     }

                     $options['reset'] = 'reset';

                     $out  = "<a id='ticket$itemtype".$data['id']."' ";
                     $out .= "href=\"".$CFG_GLPI["root_doc"]."/front/ticket.php?".
                              Toolbox::append_params($options, '&amp;')."\">";
                     $out .= $data[$ID][0]['name']."</a>";
                     return $out;
                  }
               }
               break;

            case "glpi_tickets.time_to_resolve" :
            case "glpi_problems.time_to_resolve" :
            case "glpi_changes.time_to_resolve" :
            case "glpi_tickets.time_to_own" :
            case "glpi_tickets.internal_time_to_own" :
            case "glpi_tickets.internal_time_to_resolve" :
               // Due date + progress
               if (in_array($orig_id, [151, 158, 181, 186])) {
                  $out = Html::convDateTime($data[$ID][0]['name']);

                  // No due date in waiting status
                  if ($data[$ID][0]['status'] == CommonITILObject::WAITING) {
                     return '';
                  }
                  if (empty($data[$ID][0]['name'])) {
                     return '';
                  }
                  if (($data[$ID][0]['status'] == Ticket::SOLVED)
                      || ($data[$ID][0]['status'] == Ticket::CLOSED)) {
                     return $out;
                  }

                  $itemtype = getItemTypeForTable($table);
                  $item = new $itemtype();
                  $item->getFromDB($data['id']);
                  $percentage  = 0;
                  $totaltime   = 0;
                  $currenttime = 0;
                  $slaField    = 'slas_id';

                  // define correct sla field
                  switch ($table.'.'.$field) {
                     case "glpi_tickets.time_to_resolve" :
                        $slaField = 'slas_id_ttr';
                        break;
                     case "glpi_tickets.time_to_own" :
                        $slaField = 'slas_id_tto';
                        break;
                     case "glpi_tickets.internal_time_to_own" :
                        $slaField = 'olas_id_tto';
                        break;
                     case "glpi_tickets.internal_time_to_resolve" :
                        $slaField = 'olas_id_ttr';
                        break;
                  }

                  switch ($table.'.'.$field) {
                     // If ticket has been taken into account : no progression display
                     case "glpi_tickets.time_to_own" :
                     case "glpi_tickets.internal_time_to_own" :
                        if (($item->fields['takeintoaccount_delay_stat'] > 0)) {
                           return $out;
                        }
                        break;
                  }

                  if ($item->isField($slaField) && $item->fields[$slaField] != 0) { // Have SLA
                     $sla = new SLA();
                     $sla->getFromDB($item->fields[$slaField]);
                     $currenttime = $sla->getActiveTimeBetween($item->fields['date'],
                                                               date('Y-m-d H:i:s'));
                     $totaltime   = $sla->getActiveTimeBetween($item->fields['date'],
                                                               $data[$ID][0]['name']);
                  } else {
                     $calendars_id = Entity::getUsedConfig('calendars_id',
                                                           $item->fields['entities_id']);
                     if ($calendars_id != 0) { // Ticket entity have calendar
                        $calendar = new Calendar();
                        $calendar->getFromDB($calendars_id);
                        $currenttime = $calendar->getActiveTimeBetween($item->fields['date'],
                                                                       date('Y-m-d H:i:s'));
                        $totaltime   = $calendar->getActiveTimeBetween($item->fields['date'],
                                                                       $data[$ID][0]['name']);
                     } else { // No calendar
                        $currenttime = strtotime(date('Y-m-d H:i:s'))
                                                 - strtotime($item->fields['date']);
                        $totaltime   = strtotime($data[$ID][0]['name'])
                                                 - strtotime($item->fields['date']);
                     }
                  }
                  if ($totaltime != 0) {
                     $percentage  = round((100 * $currenttime) / $totaltime);
                  } else {
                     // Total time is null : no active time
                     $percentage = 100;
                  }
                  if ($percentage > 100) {
                     $percentage = 100;
                  }
                  $percentage_text = $percentage;

                  if ($_SESSION['glpiduedatewarning_unit'] == '%') {
                     $less_warn_limit = $_SESSION['glpiduedatewarning_less'];
                     $less_warn       = (100 - $percentage);
                  } else if ($_SESSION['glpiduedatewarning_unit'] == 'hour') {
                     $less_warn_limit = $_SESSION['glpiduedatewarning_less'] * HOUR_TIMESTAMP;
                     $less_warn       = ($totaltime - $currenttime);
                  } else if ($_SESSION['glpiduedatewarning_unit'] == 'day') {
                     $less_warn_limit = $_SESSION['glpiduedatewarning_less'] * DAY_TIMESTAMP;
                     $less_warn       = ($totaltime - $currenttime);
                  }

                  if ($_SESSION['glpiduedatecritical_unit'] == '%') {
                     $less_crit_limit = $_SESSION['glpiduedatecritical_less'];
                     $less_crit       = (100 - $percentage);
                  } else if ($_SESSION['glpiduedatecritical_unit'] == 'hour') {
                     $less_crit_limit = $_SESSION['glpiduedatecritical_less'] * HOUR_TIMESTAMP;
                     $less_crit       = ($totaltime - $currenttime);
                  } else if ($_SESSION['glpiduedatecritical_unit'] == 'day') {
                     $less_crit_limit = $_SESSION['glpiduedatecritical_less'] * DAY_TIMESTAMP;
                     $less_crit       = ($totaltime - $currenttime);
                  }

                  $color = $_SESSION['glpiduedateok_color'];
                  if ($less_crit < $less_crit_limit) {
                     $color = $_SESSION['glpiduedatecritical_color'];
                  } else if ($less_warn < $less_warn_limit) {
                     $color = $_SESSION['glpiduedatewarning_color'];
                  }

                  if (!isset($so['datatype'])) {
                     $so['datatype'] = 'progressbar';
                  }

                  $progressbar_pre_data = [
                     'text'         => Html::convDateTime($data[$ID][0]['name']),
                     'percent'      => $percentage,
                     'percent_text' => $percentage_text,
                     'color'        => $color
                  ];
               }
               break;

            case "glpi_softwarelicenses.number" :
               if ($data[$ID][0]['min'] == -1) {
                  return __('Unlimited');
               }
               if (empty($data[$ID][0]['name'])) {
                  return 0;
               }
               return $data[$ID][0]['name'];

            case "glpi_auth_tables.name" :
               return Auth::getMethodName($data[$ID][0]['name'], $data[$ID][0]['auths_id'], 1,
                                          $data[$ID][0]['ldapname'].$data[$ID][0]['mailname']);

            case "glpi_reservationitems.comment" :
               if (empty($data[$ID][0]['name'])) {
                  $text = __('None');
               } else {
                  $text = Html::resume_text($data[$ID][0]['name']);
               }
               if (Session::haveRight('reservation', UPDATE)) {
                  return "<a title=\"".__s('Modify the comment')."\"
                           href='".ReservationItem::getFormURLWithID($data['refID'])."' >".$text."</a>";
               }
               return $text;

            case 'glpi_crontasks.description' :
               $tmp = new CronTask();
               return $tmp->getDescription($data[$ID][0]['name']);

            case 'glpi_changes.status':
               $status = Change::getStatus($data[$ID][0]['name']);
               return "<span class='no-wrap'>".
                      Change::getStatusIcon($data[$ID][0]['name']) . "&nbsp;$status".
                      "</span>";

            case 'glpi_problems.status':
               $status = Problem::getStatus($data[$ID][0]['name']);
               return "<span class='no-wrap'>".
                      Problem::getStatusIcon($data[$ID][0]['name']) . "&nbsp;$status".
                      "</span>";

            case 'glpi_tickets.status':
               $status = Ticket::getStatus($data[$ID][0]['name']);
               return "<span class='no-wrap'>".
                      Ticket::getStatusIcon($data[$ID][0]['name']) . "&nbsp;$status".
                      "</span>";

            case 'glpi_projectstates.name':
               $out = '';
               $name = $data[$ID][0]['name'];
               if (isset($data[$ID][0]['trans'])) {
                  $name = $data[$ID][0]['trans'];
               }
               if ($itemtype == 'ProjectState') {
                  $out =   "<a href='".ProjectState::getFormURLWithID($data[$ID][0]["id"])."'>". $name."</a></div>";
               } else {
                  $out = $name;
               }
               return $out;

            case 'glpi_items_tickets.items_id' :
            case 'glpi_items_problems.items_id' :
            case 'glpi_changes_items.items_id' :
            case 'glpi_certificates_items.items_id' :
            case 'glpi_appliances_items.items_id' :
               if (!empty($data[$ID])) {
                  $items = [];
                  foreach ($data[$ID] as $key => $val) {
                     if (is_numeric($key)) {
                        if (!empty($val['itemtype'])
                                && ($item = getItemForItemtype($val['itemtype']))) {
                           if ($item->getFromDB($val['name'])) {
                              $items[] = $item->getLink(['comments' => true]);
                           }
                        }
                     }
                  }
                  if (!empty($items)) {
                     return implode("<br>", $items);
                  }
               }
               return '&nbsp;';

            case 'glpi_items_tickets.itemtype' :
            case 'glpi_items_problems.itemtype' :
               if (!empty($data[$ID])) {
                  $itemtypes = [];
                  foreach ($data[$ID] as $key => $val) {
                     if (is_numeric($key)) {
                        if (!empty($val['name'])
                              && ($item = getItemForItemtype($val['name']))) {
                           $item = new $val['name']();
                           $name = $item->getTypeName();
                           $itemtypes[] = __($name);
                        }
                     }
                  }
                  if (!empty($itemtypes)) {
                     return implode("<br>", $itemtypes);
                  }
               }

               return '&nbsp;';

            case 'glpi_tickets.name' :
            case 'glpi_problems.name' :
            case 'glpi_changes.name' :

               if (isset($data[$ID][0]['content'])
                   && isset($data[$ID][0]['id'])
                   && isset($data[$ID][0]['status'])) {
                  $link = $itemtype::getFormURLWithID($data[$ID][0]['id']);

                  $out  = "<a id='$itemtype".$data[$ID][0]['id']."' href=\"".$link;
                  // Force solution tab if solved
                  if ($item = getItemForItemtype($itemtype)) {
                     if (in_array($data[$ID][0]['status'], $item->getSolvedStatusArray())) {
                        $out .= "&amp;forcetab=$itemtype$2";
                     }
                  }
                  $out .= "\">";
                  $name = $data[$ID][0]['name'];
                  if ($_SESSION["glpiis_ids_visible"]
                      || empty($data[$ID][0]['name'])) {
                     $name = sprintf(__('%1$s (%2$s)'), $name, $data[$ID][0]['id']);
                  }
                  $out    .= $name."</a>";
                  $hdecode = Html::entity_decode_deep($data[$ID][0]['content']);
                  $content = Toolbox::unclean_cross_side_scripting_deep($hdecode);
                  $out     = sprintf(__('%1$s %2$s'), $out,
                                     Html::showToolTip(nl2br(Html::Clean($content)),
                                                             ['applyto' => $itemtype.
                                                                                $data[$ID][0]['id'],
                                                                   'display' => false]));
                  return $out;
               }

            case 'glpi_ticketvalidations.status' :
               $out   = '';
               for ($k=0; $k<$data[$ID]['count']; $k++) {
                  if ($data[$ID][$k]['name']) {
                     $status  = TicketValidation::getStatus($data[$ID][$k]['name']);
                     $bgcolor = TicketValidation::getStatusColor($data[$ID][$k]['name']);
                     $out    .= (empty($out)?'':self::LBBR).
                                 "<div style=\"background-color:".$bgcolor.";\">".$status.'</div>';
                  }
               }
               return $out;

            case 'glpi_ticketsatisfactions.satisfaction' :
               if ($this->output_type == self::HTML_OUTPUT) {
                  return TicketSatisfaction::displaySatisfaction($data[$ID][0]['name']);
               }
               break;

            case 'glpi_projects._virtual_planned_duration' :
               return Html::timestampToString(ProjectTask::getTotalPlannedDurationForProject($data["id"]),
                                              false);

            case 'glpi_projects._virtual_effective_duration' :
               return Html::timestampToString(ProjectTask::getTotalEffectiveDurationForProject($data["id"]),
                                              false);

            case 'glpi_cartridgeitems._virtual' :
               return Cartridge::getCount($data["id"], $data[$ID][0]['alarm_threshold'],
                                          $this->output_type != self::HTML_OUTPUT);

            case 'glpi_printers._virtual' :
               return Cartridge::getCountForPrinter($data["id"],
                                                    $this->output_type != self::HTML_OUTPUT);

            case 'glpi_consumableitems._virtual' :
               return Consumable::getCount($data["id"], $data[$ID][0]['alarm_threshold'],
                                           $this->output_type != self::HTML_OUTPUT);

            case 'glpi_links._virtual' :
               $out = '';
               $link = new Link();
               if (($item = getItemForItemtype($itemtype))
                   && $item->getFromDB($data['id'])
                   && $link->getfromDB($data[$ID][0]['id'])
                   && ($item->fields['entities_id'] == $link->fields['entities_id'])) {
                  if (count($data[$ID])) {
                     $count_display = 0;
                     foreach ($data[$ID] as$val) {

                        if (is_array($val)) {
                           $links = Link::getAllLinksFor($item, $val);
                           foreach ($links as $link) {
                              if ($count_display) {
                                 $out .=  self::LBBR;
                              }
                              $out .= $link;
                              $count_display++;
                           }
                        }
                     }
                  }
               }
               return $out;

            case 'glpi_reservationitems._virtual' :
               if ($data[$ID][0]['is_active']) {
                  return "<a href='reservation.php?reservationitems_id=".
                                          $data["refID"]."' title=\"".__s('See planning')."\">".
                                          "<i class='far fa-calendar-alt'></i><span class='sr-only'>".__('See planning')."</span></a>";
               } else {
                  return "&nbsp;";
               }

            case "glpi_tickets.priority" :
            case "glpi_problems.priority" :
            case "glpi_changes.priority" :
            case "glpi_projects.priority" :
               $index = $data[$ID][0]['name'];
               $color = $_SESSION["glpipriority_$index"];
               $name  = CommonITILObject::getPriorityName($index);
               return "<div class='priority_block' style='border-color: $color'>
                        <span style='background: $color'></span>&nbsp;$name
                       </div>";
         }
      }

      //// Default case

      if ($itemtype == 'Ticket'
         && Session::getCurrentInterface() == 'helpdesk'
         && $orig_id == 8
         && Entity::getUsedConfig(
            'anonymize_support_agents',
            $itemtype::getById($data['id'])->getEntityId()
         )
      ) {
         // Assigned groups
         return __("Helpdesk group");
      }

      // Link with plugin tables : need to know left join structure
      if (isset($table)) {
         if (preg_match("/^glpi_plugin_([a-z0-9]+)/", $table.'.'.$field, $matches)) {
            if (count($matches) == 2) {
               $plug     = $matches[1];
               $out = Plugin::doOneHook(
                  $plug,
                  'giveItem',
                  $itemtype, $orig_id, $data, $ID
               );
               if (!empty($out)) {
                  return $out;
               }
            }
         }
      }
      $unit = '';
      if (isset($so['unit'])) {
         $unit = $so['unit'];
      }

      // Preformat items
      if (isset($so["datatype"])) {
         switch ($so["datatype"]) {
            case "itemlink" :
               $linkitemtype  = getItemTypeForTable($so["table"]);

               $out           = "";
               $count_display = 0;
               $separate      = self::LBBR;
               if (isset($so['splititems']) && $so['splititems']) {
                  $separate = self::LBHR;
               }

               for ($k=0; $k<$data[$ID]['count']; $k++) {
                  if (isset($data[$ID][$k]['id'])) {
                     if ($count_display) {
                        $out .= $separate;
                     }
                     $count_display++;
                     $page  = $linkitemtype::getFormURLWithID($data[$ID][$k]['id']);
                     $name  = Dropdown::getValueWithUnit($data[$ID][$k]['name'], $unit);
                     if ($_SESSION["glpiis_ids_visible"] || empty($data[$ID][$k]['name'])) {
                        $name = sprintf(__('%1$s (%2$s)'), $name, $data[$ID][$k]['id']);
                     }
                     $out  .= "<a id='".$linkitemtype."_".$data['id']."_".
                                $data[$ID][$k]['id']."' href='$page'>".
                               $name."</a>";
                  }
               }
               return $out;

            case "text" :
               $separate = self::LBBR;
               if (isset($so['splititems']) && $so['splititems']) {
                  $separate = self::LBHR;
               }

               $out           = '';
               $count_display = 0;
               for ($k=0; $k<$data[$ID]['count']; $k++) {
                  if (strlen(trim($data[$ID][$k]['name'])) > 0) {
                     if ($count_display) {
                        $out .= $separate;
                     }
                     $count_display++;
                     $text = "";
                     if (isset($so['htmltext']) && $so['htmltext']) {
                        $text = Html::clean(Toolbox::unclean_cross_side_scripting_deep(nl2br($data[$ID][$k]['name'])));
                     } else {
                        $text = nl2br($data[$ID][$k]['name']);
                     }

                     if ($this->output_type == self::HTML_OUTPUT
                         && (Toolbox::strlen($text) > $CFG_GLPI['cut'])) {
                        $rand = mt_rand();
                        $popup_params = [
                           'display'   => false
                        ];
                        if (Toolbox::strlen($text) > $CFG_GLPI['cut']) {
                           $popup_params += [
                              'awesome-class'   => 'fa-comments',
                              'autoclose'       => false,
                              'onclick'         => true
                           ];
                        } else {
                           $popup_params += [
                              'applyto'   => "text$rand",
                           ];
                        }
                        $out .= sprintf(
                           __('%1$s %2$s'),
                           "<span id='text$rand'>". Html::resume_text($text, $CFG_GLPI['cut']).'</span>',
                           Html::showToolTip(
                              '<div class="fup-popup">'.$text.'</div>', $popup_params
                              )
                        );
                     } else {
                        $out .= $text;
                     }
                  }
               }
               return $out;

            case "date" :
            case "date_delay" :
               $out   = '';
               for ($k=0; $k<$data[$ID]['count']; $k++) {
                  if (is_null($data[$ID][$k]['name'])
                      && isset($so['emptylabel']) && $so['emptylabel']) {
                     $out .= (empty($out)?'':self::LBBR).$so['emptylabel'];
                  } else {
                     $out .= (empty($out)?'':self::LBBR).Html::convDate($data[$ID][$k]['name']);
                  }
               }
               return $out;

            case "datetime" :
               $out   = '';
               for ($k=0; $k<$data[$ID]['count']; $k++) {
                  if (is_null($data[$ID][$k]['name'])
                      && isset($so['emptylabel']) && $so['emptylabel']) {
                     $out .= (empty($out)?'':self::LBBR).$so['emptylabel'];
                  } else {
                     $out .= (empty($out)?'':self::LBBR).Html::convDateTime($data[$ID][$k]['name']);
                  }
               }
               return $out;

            case "timestamp" :
               $withseconds = false;
               if (isset($so['withseconds'])) {
                  $withseconds = $so['withseconds'];
               }
               $withdays = true;
               if (isset($so['withdays'])) {
                  $withdays = $so['withdays'];
               }

               $out   = '';
               for ($k=0; $k<$data[$ID]['count']; $k++) {
                   $out .= (empty($out)?'':'<br>').Html::timestampToString($data[$ID][$k]['name'],
                                                                           $withseconds,
                                                                           $withdays);
               }
               return $out;

            case "email" :
               $out           = '';
               $count_display = 0;
               for ($k=0; $k<$data[$ID]['count']; $k++) {
                  if ($count_display) {
                     $out .= self::LBBR;
                  }
                  $count_display++;
                  if (!empty($data[$ID][$k]['name'])) {
                     $out .= (empty($out)?'':self::LBBR);
                     $out .= "<a href='mailto:".Html::entities_deep($data[$ID][$k]['name'])."'>".$data[$ID][$k]['name'];
                     $out .= "</a>";
                  }
               }
               return (empty($out) ? "&nbsp;" : $out);

            case "weblink" :
               $orig_link = trim($data[$ID][0]['name']);
               if (!empty($orig_link)) {
                  // strip begin of link
                  $link = preg_replace('/https?:\/\/(www[^\.]*\.)?/', '', $orig_link);
                  $link = preg_replace('/\/$/', '', $link);
                  if (Toolbox::strlen($link)>$CFG_GLPI["url_maxlength"]) {
                     $link = Toolbox::substr($link, 0, $CFG_GLPI["url_maxlength"])."...";
                  }
                  return "<a href=\"".Toolbox::formatOutputWebLink($orig_link)."\" target='_blank'>$link</a>";
               }
               return "&nbsp;";

            case "count" :
            case "number" :
               $out           = "";
               $count_display = 0;
               for ($k=0; $k<$data[$ID]['count']; $k++) {
                  if (strlen(trim($data[$ID][$k]['name'])) > 0) {
                     if ($count_display) {
                        $out .= self::LBBR;
                     }
                     $count_display++;
                     if (isset($so['toadd'])
                           && isset($so['toadd'][$data[$ID][$k]['name']])) {
                        $out .= $so['toadd'][$data[$ID][$k]['name']];
                     } else {
                        $number = str_replace(' ', '&nbsp;',
                                              Html::formatNumber($data[$ID][$k]['name'], false, 0));
                        $out .= Dropdown::getValueWithUnit($number, $unit);
                     }
                  }
               }
               return $out;

            case "decimal" :
               $out           = "";
               $count_display = 0;
               for ($k=0; $k<$data[$ID]['count']; $k++) {
                  if (strlen(trim($data[$ID][$k]['name'])) > 0) {

                     if ($count_display) {
                        $out .= self::LBBR;
                     }
                     $count_display++;
                     if (isset($so['toadd'])
                           && isset($so['toadd'][$data[$ID][$k]['name']])) {
                        $out .= $so['toadd'][$data[$ID][$k]['name']];
                     } else {
                        $number = str_replace(' ', '&nbsp;',
                                              Html::formatNumber($data[$ID][$k]['name']));
                        $out   .= Dropdown::getValueWithUnit($number, $unit);
                     }
                  }
               }
               return $out;

            case "bool" :
               $out           = "";
               $count_display = 0;
               for ($k=0; $k<$data[$ID]['count']; $k++) {
                  if (strlen(trim($data[$ID][$k]['name'])) > 0) {
                     if ($count_display) {
                        $out .= self::LBBR;
                     }
                     $count_display++;
                     $out .= Dropdown::getValueWithUnit(Dropdown::getYesNo($data[$ID][$k]['name']),
                                                        $unit);
                  }
               }
               return $out;

            case "itemtypename":
               if ($obj = getItemForItemtype($data[$ID][0]['name'])) {
                  return $obj->getTypeName();
               }
               return "";

            case "language":
               if (isset($CFG_GLPI['languages'][$data[$ID][0]['name']])) {
                  return $CFG_GLPI['languages'][$data[$ID][0]['name']][0];
               }
               return __('Default value');
            case 'progressbar':
               $out = '';
               for ($k=0; $k < $data[$ID]['count']; $k++) {
                  if ($data[$ID][$k]['name'] == null) {
                     continue;
                  }
                  if (isset($progressbar_pre_data)) {
                     $progressbar_data = $progressbar_pre_data;
                     unset($progressbar_pre_data);
                  } else {
                     $bar_color = 'green';
                     if ($data[$ID][$k]['name'] >= 90) {
                        $bar_color = 'red';
                     } else if ($data[$ID][$k]['name'] >= 75) {
                        $bar_color = 'yellow';
                     }
                     $progressbar_data = [
                        'percent'      => $data[$ID][$k]['name'],
                        'percent_text' => $data[$ID][$k]['name'],
                        'color'        => $bar_color,
                        'text'         => ''
                     ];
                  }
                  $out .= "{$progressbar_data['text']}<div class='center' style='background-color: #ffffff; width: 100%;
                           border: 1px solid #9BA563; position: relative;' >";
                  $out .= "<div style='position:absolute;'>&nbsp;{$progressbar_data['percent_text']}%</div>";
                  $out .= "<div class='center' style='background-color: {$progressbar_data['color']};
                           width: {$progressbar_data['percent']}%; height: 12px' ></div>";
                  $out .= "</div>";
               }
               return $out;
               break;
         }
      }
      // Manage items with need group by / group_concat
      $out           = "";
      $count_display = 0;
      $separate      = self::LBBR;
      if (isset($so['splititems']) && $so['splititems']) {
         $separate = self::LBHR;
      }
      for ($k=0; $k<$data[$ID]['count']; $k++) {
         if (strlen(trim($data[$ID][$k]['name'])) > 0) {
            if ($count_display) {
               $out .= $separate;
            }
            $count_display++;
            // Get specific display if available
            if (isset($table)) {
               $itemtype = getItemTypeForTable($table);
               if ($item = getItemForItemtype($itemtype)) {
                  $tmpdata  = $data[$ID][$k];
                  // Copy name to real field
                  $tmpdata[$field] = $data[$ID][$k]['name'];

                  $specific = $item->getSpecificValueToDisplay(
                     $field,
                     $tmpdata, [
                        'html'      => true,
                        'searchopt' => $so,
                        'raw_data'  => $data
                     ]
                  );
               }
            }
            if (!empty($specific)) {
               $out .= $specific;
            } else {
               if (isset($so['toadd'])
                   && isset($so['toadd'][$data[$ID][$k]['name']])) {
                  $out .= $so['toadd'][$data[$ID][$k]['name']];
               } else {
                  // Empty is 0 or empty
                  if (empty($split[0])&& isset($so['emptylabel'])) {
                     $out .= $so['emptylabel'];
                  } else {
                     // Trans field exists
                     if (isset($data[$ID][$k]['trans']) && !empty($data[$ID][$k]['trans'])) {
                        $out .=  Dropdown::getValueWithUnit($data[$ID][$k]['trans'], $unit);
                     } else {
                        $out .= Dropdown::getValueWithUnit($data[$ID][$k]['name'], $unit);
                     }
                  }
               }
            }
         }
      }
      return $out;
   }


   /**
    * Reset save searches
    *
    * @return void
   **/
   static function resetSaveSearch() {

      unset($_SESSION['glpisearch']);
      $_SESSION['glpisearch']       = [];
   }


   /**
    * Completion of the URL $_GET values with the $_SESSION values or define default values
    *
    * @param string  $itemtype        Item type to manage
    * @param array   $params          Params to parse
    * @param boolean $usesession      Use datas save in session (true by default)
    * @param boolean $forcebookmark   Force trying to load parameters from default bookmark:
    *                                  used for global search (false by default)
    *
    * @return array parsed params
   **/
   static function manageParams($itemtype, $params = [], $usesession = true,
                                $forcebookmark = false) {
      $default_values = [];

      $default_values["start"]       = 0;
      $default_values["order"]       = "ASC";
      $default_values["sort"]        = 1;
      $default_values["is_deleted"]  = 0;
      $default_values["as_map"]      = 0;

      if (isset($params['start'])) {
         $params['start'] = (int)$params['start'];
      }

      $default_values["criteria"]     = self::getDefaultCriteria($itemtype);
      $default_values["metacriteria"] = [];

      // Reorg search array
      // start
      // order
      // sort
      // is_deleted
      // itemtype
      // criteria : array (0 => array (link =>
      //                               field =>
      //                               searchtype =>
      //                               value =>   (contains)
      // metacriteria : array (0 => array (itemtype =>
      //                                  link =>
      //                                  field =>
      //                                  searchtype =>
      //                                  value =>   (contains)

      if (($itemtype != 'AllAssets')
          && class_exists($itemtype)) {

         // retrieve default values for current itemtype
         $itemtype_default_values = [];
         if (method_exists($itemtype, 'getDefaultSearchRequest')) {
            $itemtype_default_values = call_user_func([$itemtype, 'getDefaultSearchRequest']);
         }

         // retrieve default values for the current user
         $user_default_values = SavedSearch_User::getDefault(Session::getLoginUserID(), $itemtype);
         if ($user_default_values === false) {
            $user_default_values = [];
         }

         // we construct default values in this order:
         // - general default
         // - itemtype default
         // - user default
         //
         // The last ones erase values or previous
         // So, we can combine each part (order from itemtype, criteria from user, etc)
         $default_values = array_merge($default_values,
                                       $itemtype_default_values,
                                       $user_default_values);
      }

      // First view of the page or force bookmark : try to load a bookmark
      if ($forcebookmark
          || ($usesession
              && !isset($params["reset"])
              && !isset($_SESSION['glpisearch'][$itemtype]))) {

         $user_default_values = SavedSearch_User::getDefault(Session::getLoginUserID(), $itemtype);
         if ($user_default_values) {
            $_SESSION['glpisearch'][$itemtype] = [];
            // Only get datas for bookmarks
            if ($forcebookmark) {
               $params = $user_default_values;
            } else {
               $bookmark = new SavedSearch();
               $bookmark->load($user_default_values['savedsearches_id'], false);
            }
         }
      }
      // Force reorder criterias
      if (isset($params["criteria"])
          && is_array($params["criteria"])
          && count($params["criteria"])) {

         $tmp                = $params["criteria"];
         $params["criteria"] = [];
         foreach ($tmp as $val) {
            $params["criteria"][] = $val;
         }
      }

      // transform legacy meta-criteria in criteria (with flag meta=true)
      // at the end of the array, as before there was only at the end of the query
      if (isset($params["metacriteria"])
          && is_array($params["metacriteria"])) {
         // as we will append meta to criteria, check the key exists
         if (!isset($params["criteria"])) {
            $params["criteria"] = [];
         }
         foreach ($params["metacriteria"] as $val) {
            $params["criteria"][] = $val + ['meta' => 1];
         }
         $params["metacriteria"] = [];
      }

      if ($usesession
          && isset($params["reset"])) {
         if (isset($_SESSION['glpisearch'][$itemtype])) {
            unset($_SESSION['glpisearch'][$itemtype]);
         }
      }

      if (isset($params) && is_array($params)
          && $usesession) {
         foreach ($params as $key => $val) {
            $_SESSION['glpisearch'][$itemtype][$key] = $val;
         }
      }

      $saved_params = $params;
      foreach ($default_values as $key => $val) {
         if (!isset($params[$key])) {
            if ($usesession
                && ($key == 'is_deleted' || $key == 'as_map' || !isset($saved_params['criteria'])) // retrieve session only if not a new request
                && isset($_SESSION['glpisearch'][$itemtype][$key])) {
               $params[$key] = $_SESSION['glpisearch'][$itemtype][$key];
            } else {
               $params[$key]                    = $val;
               $_SESSION['glpisearch'][$itemtype][$key] = $val;
            }
         }
      }

      return $params;
   }


   /**
    * Clean search options depending of user active profile
    *
    * @param string  $itemtype     Item type to manage
    * @param integer $action       Action which is used to manupulate searchoption
    *                               (default READ)
    * @param boolean $withplugins  Get plugins options (true by default)
    *
    * @return array Clean $SEARCH_OPTION array
   **/
   static function getCleanedOptions($itemtype, $action = READ, $withplugins = true) {
      global $CFG_GLPI;

      $options = &self::getOptions($itemtype, $withplugins);
      $todel   = [];

      if (!Session::haveRight('infocom', $action)
          && Infocom::canApplyOn($itemtype)) {
         $itemstodel = Infocom::getSearchOptionsToAdd($itemtype);
         $todel      = array_merge($todel, array_keys($itemstodel));
      }

      if (!Session::haveRight('contract', $action)
          && in_array($itemtype, $CFG_GLPI["contract_types"])) {
         $itemstodel = Contract::getSearchOptionsToAdd();
         $todel      = array_merge($todel, array_keys($itemstodel));
      }

      if (!Session::haveRight('document', $action)
          && Document::canApplyOn($itemtype)) {
         $itemstodel = Document::getSearchOptionsToAdd();
         $todel      = array_merge($todel, array_keys($itemstodel));
      }

      // do not show priority if you don't have right in profile
      if (($itemtype == 'Ticket')
          && ($action == UPDATE)
          && !Session::haveRight('ticket', Ticket::CHANGEPRIORITY)) {
         $todel[] = 3;
      }

      if ($itemtype == 'Computer') {
         if (!Session::haveRight('networking', $action)) {
            $itemstodel = NetworkPort::getSearchOptionsToAdd($itemtype);
            $todel      = array_merge($todel, array_keys($itemstodel));
         }
      }
      if (!Session::haveRight(strtolower($itemtype), READNOTE)) {
         $todel[] = 90;
      }

      if (count($todel)) {
         foreach ($todel as $ID) {
            if (isset($options[$ID])) {
               unset($options[$ID]);
            }
         }
      }

      return $options;
   }


   /**
    *
    * Get an option number in the SEARCH_OPTION array
    *
    * @param string $itemtype  Item type
    * @param string $field     Name
    *
    * @return integer
   **/
   static function getOptionNumber($itemtype, $field) {

      $table = $itemtype::getTable();
      $opts  = &self::getOptions($itemtype);

      foreach ($opts as $num => $opt) {
         if (is_array($opt) && isset($opt['table'])
             && ($opt['table'] == $table)
             && ($opt['field'] == $field)) {
            return $num;
         }
      }
      return 0;
   }


   /**
    * Get the SEARCH_OPTION array
    *
    * @param string  $itemtype     Item type
    * @param boolean $withplugins  Get search options from plugins (true by default)
    *
    * @return array The reference to the array of search options for the given item type
   **/
   static function &getOptions($itemtype, $withplugins = true) {
      global $CFG_GLPI;

      $item = null;

      if (!isset(self::$search[$itemtype])) {
         // standard type first
         switch ($itemtype) {
            case 'Internet' :
               self::$search[$itemtype]['common']            = __('Characteristics');

               self::$search[$itemtype][1]['table']          = 'networkport_types';
               self::$search[$itemtype][1]['field']          = 'name';
               self::$search[$itemtype][1]['name']           = __('Name');
               self::$search[$itemtype][1]['datatype']       = 'itemlink';
               self::$search[$itemtype][1]['searchtype']     = 'contains';

               self::$search[$itemtype][2]['table']          = 'networkport_types';
               self::$search[$itemtype][2]['field']          = 'id';
               self::$search[$itemtype][2]['name']           = __('ID');
               self::$search[$itemtype][2]['searchtype']     = 'contains';

               self::$search[$itemtype][31]['table']         = 'glpi_states';
               self::$search[$itemtype][31]['field']         = 'completename';
               self::$search[$itemtype][31]['name']          = __('Status');

               self::$search[$itemtype] += NetworkPort::getSearchOptionsToAdd('networkport_types');
               break;

            case 'AllAssets' :
               self::$search[$itemtype]['common']            = __('Characteristics');

               self::$search[$itemtype][1]['table']          = 'asset_types';
               self::$search[$itemtype][1]['field']          = 'name';
               self::$search[$itemtype][1]['name']           = __('Name');
               self::$search[$itemtype][1]['datatype']       = 'itemlink';
               self::$search[$itemtype][1]['searchtype']     = 'contains';

               self::$search[$itemtype][2]['table']          = 'asset_types';
               self::$search[$itemtype][2]['field']          = 'id';
               self::$search[$itemtype][2]['name']           = __('ID');
               self::$search[$itemtype][2]['searchtype']     = 'contains';

               self::$search[$itemtype][31]['table']         = 'glpi_states';
               self::$search[$itemtype][31]['field']         = 'completename';
               self::$search[$itemtype][31]['name']          = __('Status');

               self::$search[$itemtype] += Location::getSearchOptionsToAdd();

               self::$search[$itemtype][5]['table']          = 'asset_types';
               self::$search[$itemtype][5]['field']          = 'serial';
               self::$search[$itemtype][5]['name']           = __('Serial number');

               self::$search[$itemtype][6]['table']          = 'asset_types';
               self::$search[$itemtype][6]['field']          = 'otherserial';
               self::$search[$itemtype][6]['name']           = __('Inventory number');

               self::$search[$itemtype][16]['table']         = 'asset_types';
               self::$search[$itemtype][16]['field']         = 'comment';
               self::$search[$itemtype][16]['name']          = __('Comments');
               self::$search[$itemtype][16]['datatype']      = 'text';

               self::$search[$itemtype][70]['table']         = 'glpi_users';
               self::$search[$itemtype][70]['field']         = 'name';
               self::$search[$itemtype][70]['name']          = User::getTypeName(1);

               self::$search[$itemtype][7]['table']          = 'asset_types';
               self::$search[$itemtype][7]['field']          = 'contact';
               self::$search[$itemtype][7]['name']           = __('Alternate username');
               self::$search[$itemtype][7]['datatype']       = 'string';

               self::$search[$itemtype][8]['table']          = 'asset_types';
               self::$search[$itemtype][8]['field']          = 'contact_num';
               self::$search[$itemtype][8]['name']           = __('Alternate username number');
               self::$search[$itemtype][8]['datatype']       = 'string';

               self::$search[$itemtype][71]['table']         = 'glpi_groups';
               self::$search[$itemtype][71]['field']         = 'completename';
               self::$search[$itemtype][71]['name']          = Group::getTypeName(1);

               self::$search[$itemtype][19]['table']         = 'asset_types';
               self::$search[$itemtype][19]['field']         = 'date_mod';
               self::$search[$itemtype][19]['name']          = __('Last update');
               self::$search[$itemtype][19]['datatype']      = 'datetime';
               self::$search[$itemtype][19]['massiveaction'] = false;

               self::$search[$itemtype][23]['table']         = 'glpi_manufacturers';
               self::$search[$itemtype][23]['field']         = 'name';
               self::$search[$itemtype][23]['name']          = Manufacturer::getTypeName(1);

               self::$search[$itemtype][24]['table']         = 'glpi_users';
               self::$search[$itemtype][24]['field']         = 'name';
               self::$search[$itemtype][24]['linkfield']     = 'users_id_tech';
               self::$search[$itemtype][24]['name']          = __('Technician in charge of the hardware');
               self::$search[$itemtype][24]['condition']     = ['is_assign' => 1];

               self::$search[$itemtype][49]['table']          = 'glpi_groups';
               self::$search[$itemtype][49]['field']          = 'completename';
               self::$search[$itemtype][49]['linkfield']      = 'groups_id_tech';
               self::$search[$itemtype][49]['name']           = __('Group in charge of the hardware');
               self::$search[$itemtype][49]['condition']      = ['is_assign' => 1];
               self::$search[$itemtype][49]['datatype']       = 'dropdown';

               self::$search[$itemtype][80]['table']         = 'glpi_entities';
               self::$search[$itemtype][80]['field']         = 'completename';
               self::$search[$itemtype][80]['name']          = Entity::getTypeName(1);
               break;

            default :
               if ($item = getItemForItemtype($itemtype)) {
                  self::$search[$itemtype] = $item->searchOptions();
               }
               break;
         }

         if (Session::getLoginUserID()
             && in_array($itemtype, $CFG_GLPI["ticket_types"])) {
            self::$search[$itemtype]['tracking']          = __('Assistance');

            self::$search[$itemtype][60]['table']         = 'glpi_tickets';
            self::$search[$itemtype][60]['field']         = 'id';
            self::$search[$itemtype][60]['datatype']      = 'count';
            self::$search[$itemtype][60]['name']          = _x('quantity', 'Number of tickets');
            self::$search[$itemtype][60]['forcegroupby']  = true;
            self::$search[$itemtype][60]['usehaving']     = true;
            self::$search[$itemtype][60]['massiveaction'] = false;
            self::$search[$itemtype][60]['joinparams']    = ['beforejoin'
                                                              => ['table'
                                                                        => 'glpi_items_tickets',
                                                                       'joinparams'
                                                                        => ['jointype'
                                                                                  => 'itemtype_item']],
                                                              'condition'
                                                              => getEntitiesRestrictRequest('AND',
                                                                                            'NEWTABLE')];

            self::$search[$itemtype][140]['table']         = 'glpi_problems';
            self::$search[$itemtype][140]['field']         = 'id';
            self::$search[$itemtype][140]['datatype']      = 'count';
            self::$search[$itemtype][140]['name']          = _x('quantity', 'Number of problems');
            self::$search[$itemtype][140]['forcegroupby']  = true;
            self::$search[$itemtype][140]['usehaving']     = true;
            self::$search[$itemtype][140]['massiveaction'] = false;
            self::$search[$itemtype][140]['joinparams']    = ['beforejoin'
                                                              => ['table'
                                                                        => 'glpi_items_problems',
                                                                       'joinparams'
                                                                        => ['jointype'
                                                                                  => 'itemtype_item']],
                                                              'condition'
                                                              => getEntitiesRestrictRequest('AND',
                                                                                            'NEWTABLE')];
         }

         if (in_array($itemtype, $CFG_GLPI["networkport_types"])
             || ($itemtype == 'AllAssets')) {
            self::$search[$itemtype] += NetworkPort::getSearchOptionsToAdd($itemtype);
         }

         if (in_array($itemtype, $CFG_GLPI["contract_types"])
             || ($itemtype == 'AllAssets')) {
            self::$search[$itemtype] += Contract::getSearchOptionsToAdd();
         }

         if (Document::canApplyOn($itemtype)
             || ($itemtype == 'AllAssets')) {
            self::$search[$itemtype] += Document::getSearchOptionsToAdd();
         }

         if (Infocom::canApplyOn($itemtype)
             || ($itemtype == 'AllAssets')) {
            self::$search[$itemtype] += Infocom::getSearchOptionsToAdd($itemtype);
         }

         if (in_array($itemtype, $CFG_GLPI["domain_types"])
             || ($itemtype == 'AllAssets')) {
            self::$search[$itemtype] += Domain::getSearchOptionsToAdd($itemtype);
         }

         if (in_array($itemtype, $CFG_GLPI["appliance_types"])
             || ($itemtype == 'AllAssets')) {
            self::$search[$itemtype] += Appliance::getSearchOptionsToAdd($itemtype);
         }

         if (in_array($itemtype, $CFG_GLPI["link_types"])) {
            self::$search[$itemtype]['link'] = _n('External link', 'External links', Session::getPluralNumber());
            self::$search[$itemtype] += Link::getSearchOptionsToAdd($itemtype);
         }

         if ($withplugins) {
            // Search options added by plugins
            $plugsearch = Plugin::getAddSearchOptions($itemtype);
            $plugsearch = $plugsearch + Plugin::getAddSearchOptionsNew($itemtype);
            if (count($plugsearch)) {
               self::$search[$itemtype] += ['plugins' => _n('Plugin', 'Plugins', Session::getPluralNumber())];
               self::$search[$itemtype] += $plugsearch;
            }
         }

         // Complete linkfield if not define
         if (is_null($item)) { // Special union type
            $itemtable = $CFG_GLPI['union_search_type'][$itemtype];
         } else {
            if ($item = getItemForItemtype($itemtype)) {
               $itemtable = $item->getTable();
            }
         }

         foreach (self::$search[$itemtype] as $key => $val) {
            if (!is_array($val) || count($val) == 1) {
               // skip sub-menu
               continue;
            }
            // Compatibility before 0.80 : Force massive action to false if linkfield is empty :
            if (isset($val['linkfield']) && empty($val['linkfield'])) {
               self::$search[$itemtype][$key]['massiveaction'] = false;
            }

            // Set default linkfield
            if (!isset($val['linkfield']) || empty($val['linkfield'])) {
               if ((strcmp($itemtable, $val['table']) == 0)
                   && (!isset($val['joinparams']) || (count($val['joinparams']) == 0))) {
                  self::$search[$itemtype][$key]['linkfield'] = $val['field'];
               } else {
                  self::$search[$itemtype][$key]['linkfield'] = getForeignKeyFieldForTable($val['table']);
               }
            }
            // Add default joinparams
            if (!isset($val['joinparams'])) {
               self::$search[$itemtype][$key]['joinparams'] = [];
            }
         }

      }

      return self::$search[$itemtype];
   }

   /**
    * Is the search item related to infocoms
    *
    * @param string  $itemtype  Item type
    * @param integer $searchID  ID of the element in $SEARCHOPTION
    *
    * @return boolean
   **/
   static function isInfocomOption($itemtype, $searchID) {
      if (!Infocom::canApplyOn($itemtype)) {
         return false;
      }

      $infocom_options = Infocom::rawSearchOptionsToAdd($itemtype);
      $found_infocoms  = array_filter($infocom_options, function($option) use ($searchID) {
         return isset($option['id']) && $searchID == $option['id'];
      });

      return (count($found_infocoms) > 0);
   }


   /**
    * @param string  $itemtype
    * @param integer $field_num
   **/
   static function getActionsFor($itemtype, $field_num) {

      $searchopt = &self::getOptions($itemtype);
      $actions   = [
         'contains'    => __('contains'),
         'notcontains' => __('not contains'),
         'searchopt'   => []
      ];

      if (isset($searchopt[$field_num]) && isset($searchopt[$field_num]['table'])) {
         $actions['searchopt'] = $searchopt[$field_num];

         // Force search type
         if (isset($actions['searchopt']['searchtype'])) {
            // Reset search option
            $actions              = [];
            $actions['searchopt'] = $searchopt[$field_num];
            if (!is_array($actions['searchopt']['searchtype'])) {
               $actions['searchopt']['searchtype'] = [$actions['searchopt']['searchtype']];
            }
            foreach ($actions['searchopt']['searchtype'] as $searchtype) {
               switch ($searchtype) {
                  case "equals" :
                     $actions['equals'] = __('is');
                     break;

                  case "notequals" :
                     $actions['notequals'] = __('is not');
                     break;

                  case "contains" :
                     $actions['contains']    = __('contains');
                     $actions['notcontains'] = __('not contains');
                     break;

                  case "notcontains" :
                     $actions['notcontains'] = __('not contains');
                     break;

                  case "under" :
                     $actions['under'] = __('under');
                     break;

                  case "notunder" :
                     $actions['notunder'] = __('not under');
                     break;

                  case "lessthan" :
                     $actions['lessthan'] = __('before');
                     break;

                  case "morethan" :
                     $actions['morethan'] = __('after');
                     break;
               }
            }
            return $actions;
         }

         if (isset($searchopt[$field_num]['datatype'])) {
            switch ($searchopt[$field_num]['datatype']) {
               case 'count' :
               case 'number' :
                  $opt = [
                     'contains'    => __('contains'),
                     'notcontains' => __('not contains'),
                     'equals'      => __('is'),
                     'notequals'   => __('is not'),
                     'searchopt'   => $searchopt[$field_num]
                  ];
                  // No is / isnot if no limits defined
                  if (!isset($searchopt[$field_num]['min'])
                      && !isset($searchopt[$field_num]['max'])) {
                     unset($opt['equals']);
                     unset($opt['notequals']);

                     // https://github.com/glpi-project/glpi/issues/6917
                     // change filter wording for numeric values to be more
                     // obvious if the number dropdown will not be used
                     $opt['contains']    = __('is');
                     $opt['notcontains'] = __('is not');
                  }
                  return $opt;

               case 'bool' :
                  return [
                     'equals'      => __('is'),
                     'notequals'   => __('is not'),
                     'contains'    => __('contains'),
                     'notcontains' => __('not contains'),
                     'searchopt'   => $searchopt[$field_num]
                  ];

               case 'right' :
                  return ['equals'    => __('is'),
                               'notequals' => __('is not'),
                               'searchopt' => $searchopt[$field_num]];

               case 'itemtypename' :
                  return ['equals'    => __('is'),
                               'notequals' => __('is not'),
                               'searchopt' => $searchopt[$field_num]];

               case 'date' :
               case 'datetime' :
               case 'date_delay' :
                  return [
                     'equals'      => __('is'),
                     'notequals'   => __('is not'),
                     'lessthan'    => __('before'),
                     'morethan'    => __('after'),
                     'contains'    => __('contains'),
                     'notcontains' => __('not contains'),
                     'searchopt'   => $searchopt[$field_num]
                  ];
            }
         }

         // switch ($searchopt[$field_num]['table']) {
         //    case 'glpi_users_validation' :
         //       return array('equals'    => __('is'),
         //                    'notequals' => __('is not'),
         //                    'searchopt' => $searchopt[$field_num]);
         // }

         switch ($searchopt[$field_num]['field']) {
            case 'id' :
               return ['equals'    => __('is'),
                            'notequals' => __('is not'),
                            'searchopt' => $searchopt[$field_num]];

            case 'name' :
            case 'completename' :
               $actions = [
                  'contains'    => __('contains'),
                  'notcontains' => __('not contains'),
                  'equals'      => __('is'),
                  'notequals'   => __('is not'),
                  'searchopt'   => $searchopt[$field_num]
               ];

               // Specific case of TreeDropdown : add under
               $itemtype_linked = getItemTypeForTable($searchopt[$field_num]['table']);
               if ($itemlinked = getItemForItemtype($itemtype_linked)) {
                  if ($itemlinked instanceof CommonTreeDropdown) {
                     $actions['under']    = __('under');
                     $actions['notunder'] = __('not under');
                  }
                  return $actions;
               }
         }
      }
      return $actions;
   }


   /**
    * Print generic Header Column
    *
    * @param integer          $type     Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
    * @param string           $value    Value to display
    * @param integer          &$num     Column number
    * @param string           $linkto   Link display element (HTML specific) (default '')
    * @param boolean|integer  $issort   Is the sort column ? (default 0)
    * @param string           $order    Order type ASC or DESC (defaut '')
    * @param string           $options  Options to add (default '')
    *
    * @return string HTML to display
   **/
   static function showHeaderItem($type, $value, &$num, $linkto = "", $issort = 0, $order = "",
                                  $options = "") {
      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf

         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $PDF_TABLE .= "<th $options>";
            $PDF_TABLE .= Html::clean($value);
            $PDF_TABLE .= "</th>\n";
            break;

         case self::SYLK_OUTPUT : //sylk
            global $SYLK_HEADER,$SYLK_SIZE;
            $SYLK_HEADER[$num] = self::sylk_clean($value);
            $SYLK_SIZE[$num]   = Toolbox::strlen($SYLK_HEADER[$num]);
            break;

         case self::CSV_OUTPUT : //CSV
            $out = "\"".self::csv_clean($value)."\"".$_SESSION["glpicsv_delimiter"];
            break;

         default :
            $class = "";
            if ($issort) {
               $class = "order_$order";
            }
            $out = "<th $options class='$class'>";
            if (!empty($linkto)) {
               $out .= "<a href=\"$linkto\">";
            }
            $out .= $value;
            if (!empty($linkto)) {
               $out .= "</a>";
            }
            $out .= "</th>\n";
      }
      $num++;
      return $out;
   }


   /**
    * Print generic normal Item Cell
    *
    * @param integer $type        Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
    * @param string  $value       Value to display
    * @param integer &$num        Column number
    * @param integer $row         Row number
    * @param string  $extraparam  Extra parameters for display (default '')
    *
    * @return string HTML to display
   **/
   static function showItem($type, $value, &$num, $row, $extraparam = '') {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $value = preg_replace('/'.self::LBBR.'/', '<br>', $value);
            $value = preg_replace('/'.self::LBHR.'/', '<hr>', $value);
            $PDF_TABLE .= "<td $extraparam valign='top'>";
            $PDF_TABLE .= Html::weblink_extract(Html::clean($value));
            $PDF_TABLE .= "</td>\n";

            break;

         case self::SYLK_OUTPUT : //sylk
            global $SYLK_ARRAY,$SYLK_SIZE;
            $value                  = Html::weblink_extract(Html::clean($value));
            $value = preg_replace('/'.self::LBBR.'/', '<br>', $value);
            $value = preg_replace('/'.self::LBHR.'/', '<hr>', $value);
            $SYLK_ARRAY[$row][$num] = self::sylk_clean($value);
            $SYLK_SIZE[$num]        = max($SYLK_SIZE[$num],
                                          Toolbox::strlen($SYLK_ARRAY[$row][$num]));
            break;

         case self::CSV_OUTPUT : //csv
            $value = preg_replace('/'.self::LBBR.'/', '<br>', $value);
            $value = preg_replace('/'.self::LBHR.'/', '<hr>', $value);
            $value = Html::weblink_extract(Html::clean($value));
            $out   = "\"".self::csv_clean($value)."\"".$_SESSION["glpicsv_delimiter"];
            break;

         default :
            global $CFG_GLPI;
            $out = "<td $extraparam valign='top'>";

            if (!preg_match('/'.self::LBHR.'/', $value)) {
               $values = preg_split('/'.self::LBBR.'/i', $value);
               $line_delimiter = '<br>';
            } else {
               $values = preg_split('/'.self::LBHR.'/i', $value);
               $line_delimiter = '<hr>';
            }

            if (count($values) > 1
                && Toolbox::strlen($value) > $CFG_GLPI['cut']) {
               $value = '';
               foreach ($values as $v) {
                  $value .= $v.$line_delimiter;
               }
               $value = preg_replace('/'.self::LBBR.'/', '<br>', $value);
               $value = preg_replace('/'.self::LBHR.'/', '<hr>', $value);
               $value = '<div class="fup-popup">'.$value.'</div>';
               $valTip = "&nbsp;".Html::showToolTip(
                  $value, [
                     'awesome-class'   => 'fa-comments',
                     'display'         => false,
                     'autoclose'       => false,
                     'onclick'         => true
                  ]
               );
               $out .= $values[0] . $valTip;
            } else {
               $value = preg_replace('/'.self::LBBR.'/', '<br>', $value);
               $value = preg_replace('/'.self::LBHR.'/', '<hr>', $value);
               $out .= $value;
            }
            $out .= "</td>\n";
      }
      $num++;
      return $out;
   }


   /**
    * Print generic error
    *
    * @param integer $type     Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
    * @param string  $message  Message to display, if empty "no item found" will be displayed
    *
    * @return string HTML to display
   **/
   static function showError($type, $message = "") {
      if (strlen($message) == 0) {
         $message = __('No item found');
      }

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
         case self::SYLK_OUTPUT : //sylk
         case self::CSV_OUTPUT : //csv
            break;

         default :
            $out = "<div class='center b'>$message</div>\n";
      }
      return $out;
   }


   /**
    * Print generic footer
    *
    * @param integer $type  Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
    * @param string  $title title of file : used for PDF (default '')
    * @param integer $count Total number of results
    *
    * @return string HTML to display
   **/
   static function showFooter($type, $title = "", $count = null) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            if ($type == self::PDF_OUTPUT_LANDSCAPE) {
               $pdf = new GLPIPDF('L', 'mm', 'A4', true, 'UTF-8', false);
            } else {
               $pdf = new GLPIPDF('P', 'mm', 'A4', true, 'UTF-8', false);
            }
            if ($count !== null) {
               $pdf->setTotalCount($count);
            }
            $pdf->SetCreator('GLPI');
            $pdf->SetAuthor('GLPI');
            $pdf->SetTitle($title);
            $pdf->SetHeaderData('', '', $title, '');
            $font       = 'helvetica';
            //$subsetting = true;
            $fontsize   = 8;
            if (isset($_SESSION['glpipdffont']) && $_SESSION['glpipdffont']) {
               $font       = $_SESSION['glpipdffont'];
               //$subsetting = false;
            }
            $pdf->setHeaderFont([$font, 'B', $fontsize]);
            $pdf->setFooterFont([$font, 'B', $fontsize]);

            //set margins
            $pdf->SetMargins(10, 15, 10);
            $pdf->SetHeaderMargin(10);
            $pdf->SetFooterMargin(10);

            //set auto page breaks
            $pdf->SetAutoPageBreak(true, 15);

            // For standard language
            //$pdf->setFontSubsetting($subsetting);
            // set font
            $pdf->SetFont($font, '', $fontsize);
            $pdf->AddPage();
            $PDF_TABLE .= '</table>';
            $pdf->writeHTML($PDF_TABLE, true, false, true, false, '');
            $pdf->Output('glpi.pdf', 'I');
            break;

         case self::SYLK_OUTPUT : //sylk
            global $SYLK_HEADER,$SYLK_ARRAY,$SYLK_SIZE;
            // largeurs des colonnes
            foreach ($SYLK_SIZE as $num => $val) {
               $out .= "F;W".$num." ".$num." ".min(50, $val)."\n";
            }
            $out .= "\n";
            // Header
            foreach ($SYLK_HEADER as $num => $val) {
               $out .= "F;SDM4;FG0C;".($num == 1 ? "Y1;" : "")."X$num\n";
               $out .= "C;N;K\"$val\"\n";
               $out .= "\n";
            }
            // Datas
            foreach ($SYLK_ARRAY as $row => $tab) {
               foreach ($tab as $num => $val) {
                  $out .= "F;P3;FG0L;".($num == 1 ? "Y".$row.";" : "")."X$num\n";
                  $out .= "C;N;K\"$val\"\n";
               }
            }
            $out.= "E\n";
            break;

         case self::CSV_OUTPUT : //csv
            break;

         default :
            $out = "</table></div>\n";
      }
      return $out;
   }


   /**
    * Print generic footer
    *
    * @param integer         $type   Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
    * @param integer         $rows   Number of rows
    * @param integer         $cols   Number of columns
    * @param boolean|integer $fixed  Used tab_cadre_fixe table for HTML export ? (default 0)
    *
    * @return string HTML to display
   **/
   static function showHeader($type, $rows, $cols, $fixed = 0) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $PDF_TABLE = "<table cellspacing=\"0\" cellpadding=\"1\" border=\"1\" >";
            break;

         case self::SYLK_OUTPUT : // Sylk
            global $SYLK_ARRAY, $SYLK_HEADER, $SYLK_SIZE;
            $SYLK_ARRAY  = [];
            $SYLK_HEADER = [];
            $SYLK_SIZE   = [];
            // entetes HTTP
            header("Expires: Mon, 26 Nov 1962 00:00:00 GMT");
            header('Pragma: private'); /// IE BUG + SSL
            header('Cache-control: private, must-revalidate'); /// IE BUG + SSL
            header("Content-disposition: filename=glpi.slk");
            header('Content-type: application/octetstream');
            // entete du fichier
            echo "ID;PGLPI_EXPORT\n"; // ID;Pappli
            echo "\n";
            // formats
            echo "P;PGeneral\n";
            echo "P;P#,##0.00\n";       // P;Pformat_1 (reels)
            echo "P;P#,##0\n";          // P;Pformat_2 (entiers)
            echo "P;P@\n";              // P;Pformat_3 (textes)
            echo "\n";
            // polices
            echo "P;EArial;M200\n";
            echo "P;EArial;M200\n";
            echo "P;EArial;M200\n";
            echo "P;FArial;M200;SB\n";
            echo "\n";
            // nb lignes * nb colonnes
            echo "B;Y".$rows;
            echo ";X".$cols."\n"; // B;Yligmax;Xcolmax
            echo "\n";
            break;

         case self::CSV_OUTPUT : // csv
            header("Expires: Mon, 26 Nov 1962 00:00:00 GMT");
            header('Pragma: private'); /// IE BUG + SSL
            header('Cache-control: private, must-revalidate'); /// IE BUG + SSL
            header("Content-disposition: filename=glpi.csv");
            header('Content-type: text/csv');
            // zero width no break space (for excel)
            echo"\xEF\xBB\xBF";
            break;

         default :
            if ($fixed) {
               $out = "<div class='center'><table border='0' class='tab_cadre_fixehov'>\n";
            } else {
               $out = "<div class='center'><table border='0' class='tab_cadrehov'>\n";
            }
      }
      return $out;
   }


   /**
    * Print begin of header part
    *
    * @param integer $type   Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
    *
    * @since 0.85
    *
    * @return string HTML to display
   **/
   static function showBeginHeader($type) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $PDF_TABLE .= "<thead>";
            break;

         case self::SYLK_OUTPUT : //sylk
         case self::CSV_OUTPUT : //csv
            break;

         default :
            $out = "<thead>";
      }
      return $out;
   }


   /**
    * Print end of header part
    *
    * @param integer $type   Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
    *
    * @since 0.85
    *
    * @return string to display
   **/
   static function showEndHeader($type) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $PDF_TABLE .= "</thead>";
            break;

         case self::SYLK_OUTPUT : //sylk
         case self::CSV_OUTPUT : //csv
            break;

         default :
            $out = "</thead>";
      }
      return $out;
   }


   /**
    * Print generic new line
    *
    * @param integer $type        Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
    * @param boolean $odd         Is it a new odd line ? (false by default)
    * @param boolean $is_deleted  Is it a deleted search ? (false by default)
    *
    * @return string HTML to display
   **/
   static function showNewLine($type, $odd = false, $is_deleted = false) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $style = "";
            if ($odd) {
               $style = " style=\"background-color:#DDDDDD;\" ";
            }
            $PDF_TABLE .= "<tr $style nobr=\"true\">";
            break;

         case self::SYLK_OUTPUT : //sylk
         case self::CSV_OUTPUT : //csv
            break;

         default :
            $class = " class='tab_bg_2".($is_deleted?'_2':'')."' ";
            if ($odd) {
               $class = " class='tab_bg_1".($is_deleted?'_2':'')."' ";
            }
            $out = "<tr $class>";
      }
      return $out;
   }


   /**
    * Print generic end line
    *
    * @param integer $type  Display type (0=HTML, 1=Sylk, 2=PDF, 3=CSV)
    *
    * @return string HTML to display
   **/
   static function showEndLine($type) {

      $out = "";
      switch ($type) {
         case self::PDF_OUTPUT_LANDSCAPE : //pdf
         case self::PDF_OUTPUT_PORTRAIT :
            global $PDF_TABLE;
            $PDF_TABLE.= '</tr>';
            break;

         case self::SYLK_OUTPUT : //sylk
            break;

         case self::CSV_OUTPUT : //csv
            $out = "\n";
            break;

         default :
            $out = "</tr>";
      }
      return $out;
   }


   /**
    * @param array $joinparams
    */
   static function computeComplexJoinID(array $joinparams) {

      $complexjoin = '';

      if (isset($joinparams['condition'])) {
         if (!is_array($joinparams['condition'])) {
            $complexjoin .= $joinparams['condition'];
         } else {
            global $DB;
            $dbi = new DBmysqlIterator($DB);
            $sql_clause = $dbi->analyseCrit($joinparams['condition']);
            $complexjoin .= ' AND ' . $sql_clause; //TODO: and should came from conf
         }
         }
      }

      // For jointype == child
      if (isset($joinparams['jointype']) && ($joinparams['jointype'] == 'child')
          && isset($joinparams['linkfield'])) {
         $complexjoin .= $joinparams['linkfield'];
      }

      if (isset($joinparams['beforejoin'])) {
         if (isset($joinparams['beforejoin']['table'])) {
            $joinparams['beforejoin'] = [$joinparams['beforejoin']];
         }
         foreach ($joinparams['beforejoin'] as $tab) {
            if (isset($tab['table'])) {
               $complexjoin .= $tab['table'];
            }
            if (isset($tab['joinparams']) && isset($tab['joinparams']['condition'])) {
               if (!is_array($tab['joinparams']['condition'])) {
                  $complexjoin .= $tab['joinparams']['condition'];
               } else {
                  global $DB;
                  $dbi = new DBmysqlIterator($DB);
                  $sql_clause = $dbi->analyseCrit($tab['joinparams']['condition']);
                  $complexjoin .= ' AND ' . $sql_clause; //TODO: and should came from conf
               }
               }
            }
         }
      }

      if (!empty($complexjoin)) {
         $complexjoin = md5($complexjoin);
      }
      return $complexjoin;
   }


   /**
    * Clean display value for csv export
    *
    * @param string $value value
    *
    * @return string Clean value
    **/
   static function csv_clean($value) {

      $value = str_replace("\"", "''", $value);
      $value = Html::clean($value, true, 2, false);
      $value = str_replace("&gt;", ">", $value);
      $value = str_replace("&lt;", "<", $value);

      return $value;
   }


   /**
    * Clean display value for sylk export
    *
    * @param string $value value
    *
    * @return string Clean value
    **/
   static function sylk_clean($value) {

      $value = preg_replace('/\x0A/', ' ', $value);
      $value = preg_replace('/\x0D/', null, $value);
      $value = str_replace("\"", "''", $value);
      $value = Html::clean($value);
      $value = str_replace("\n", " | ", $value);
      $value = str_replace("&gt;", ">", $value);
      $value = str_replace("&lt;", "<", $value);

      return $value;
   }


   /**
    * Create SQL search condition
    *
    * @param string  $field  Name (should be ` protected)
    * @param string  $val    Value to search
    * @param boolean $not    Is a negative search ? (false by default)
    * @param string  $link   With previous criteria (default 'AND')
    *
    * @return search SQL string
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function makeTextCriteria ($field, $val, $not = false, $link = 'AND') {

      $sql = $field . $this->makeTextSearch($val, $not);
      // mange empty field (string with length = 0)
      $sql_or = "";
      if (strtolower($val) == "null") {
         $sql_or = "OR $field = " . $this->db->quote('');
      }

      if (($not && ($val != 'NULL') && ($val != 'null') && ($val != '^$'))    // Not something
          ||(!$not && ($val == '^$'))) {   // Empty
         $sql = "($sql OR $field IS NULL)";
      }
      return " $link ($sql $sql_or)";
   }

   /**
    * Create SQL search value
    *
    * @since 9.4
    *
    * @param string  $val value to search
    *
    * @return string|null
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function makeTextSearchValue($val) {
      // Unclean to permit < and > search
      $val = Toolbox::unclean_cross_side_scripting_deep($val);

      // escape _ char used as wildcard in mysql likes
      $val = str_replace('_', '\\_', $val);

      if (($val == 'NULL') || ($val == 'null')) {
         return null;
      }

      $search = '';
      preg_match('/^(\^?)([^\^\$]*)(\$?)$/', $val, $matches);
      if (isset($matches[2]) && strlen(trim($matches[2])) > 0) {
         $search =
            ($matches[1] != '^' ? '%' : '') .
            trim($matches[2]) .
            ($matches[3] != '$' ? '%' : '');
      } else if (isset($matches[1])
            && strlen(trim($matches[1])) == 1
            && (!isset($matches[3]) || empty($matches[3]))) {
         // this case is for search with only ^, so mean the field is not empty / not null
         $search = '%';
      }
      return $search;
   }


   /**
    * Create SQL search condition
    *
    * @param string  $val  Value to search
    * @param boolean $not  Is a negative search ? (false by default)
    *
    * @return string Search string
    *
    * @since 10.0.0 Method is no longer static
   **/
   public function makeTextSearch($val, $not = false) {

      $NOT = "";
      if ($not) {
         $NOT = "NOT";
      }

      $val = $this->makeTextSearchValue($val);
      if ($val == null) {
         $SEARCH = " IS $NOT NULL ";
      } else {
         $SEARCH = " $NOT LIKE ".$this->db->quote($val)." ";
      }
      return $SEARCH;
   }


   /**
    * @since 0.84
    *
    * @param string $pattern
    * @param string $subject
   **/
   static function explodeWithID($pattern, $subject) {

      $tab = explode($pattern, $subject);

      if (isset($tab[1]) && !is_numeric($tab[1])) {
         // Report $ to tab[0]
         if (preg_match('/^(\\$*)(.*)/', $tab[1], $matchs)) {
            if (isset($matchs[2]) && is_numeric($matchs[2])) {
               $tab[1]  = $matchs[2];
               $tab[0] .= $matchs[1];
            }
         }
      }
      // Manage NULL value
      if ($tab[0] == self::NULLVALUE) {
         $tab[0] = null;
      }
      return $tab;
   }

   /**
    * Add join for dropdown translations
    *
    * @param string $alias    Alias for translation table
    * @param string $table    Table to join on
    * @param string $itemtype Item type
    * @param string $field    Field name
    *
    * @return string
    */
   public function joinDropdownTranslations($alias, $table, $itemtype, $field) {
      return " LEFT JOIN ".$this->db->quoteName("glpi_dropdowntranslations")." AS ".$this->db->quoteName($alias)."
                  ON (".$this->db->quoteName("$alias.itemtype")." = ".$this->db->quote($itemtype)."
                        AND ".$this->db->quoteName("$alias.items_id")." = ".$this->db->quoteName("$table.id")."
                        AND ".$this->db->quoteName("$alias.language")." = ".$this->db->quote($_SESSION['glpilanguage'])."
                        AND ".$this->db->quoteName("$alias.field")." = ".$this->db->quote($field).")";
   }

   /**
    * Get table name for item type
    *
    * @param string $itemtype
    *
    * @return string
    */
   public static function getOrigTableName(string $itemtype): string {
      return (is_a($itemtype, CommonDBTM::class, true)) ? $itemtype::getTable() : getTableForItemType($itemtype);
   }
}
