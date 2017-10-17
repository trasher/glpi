<?php

if (!defined('GLPI_ROOT')) {
   die("Sorry. You can't access directly to this file");
}

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
**/
class Item_Rack extends CommonDBRelation {

   static public $itemtype_1 = 'Rack';
   static public $items_id_1 = 'racks_id';
   static public $itemtype_2 = 'itemtype';
   static public $items_id_2 = 'items_id';
   static public $checkItem_1_Rights = self::DONT_CHECK_ITEM_RIGHTS;
   static public $mustBeAttached_1      = false;
   static public $mustBeAttached_2      = false;

   static function getTypeName($nb = 0) {
      return _n('Item', 'Item', $nb);
   }

   /**
    * Count connection for an operating system
    *
    * @param Rack $rack Rack object instance
    *
    * @return integer
   **/
   static function countForRack(Rack $rack) {
      return countElementsInTable(self::getTable(),
                                  ['racks_id' => $rack->getID()]);
   }

   function getTabNameForItem(CommonGLPI $item, $withtemplate = 0) {
      $nb = 0;
      switch ($item->getType()) {
         default:
            if ($_SESSION['glpishow_count_on_tabs']) {
               $nb = countElementsInTable(
                  self::getTable(),
                  [
                     'itemtype'  => $item->getType(),
                     'items_id'  => $item->getID()
                  ]);
            }
            return self::createTabEntry(self::getTypeName(Session::getPluralNumber()), $nb);
      }
      return '';
   }

   static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0) {
      self::showItems($item, $withtemplate);
   }

   /**
    * Print racks items
    *
    * @return void
   **/
   static function showItems(Rack $rack) {
      global $DB, $CFG_GLPI;

      $ID = $rack->getID();
      $rand = mt_rand();

      if (!$rack->getFromDB($ID)
          || !$rack->can($ID, READ)) {
         return false;
      }
      $canedit = $rack->canEdit($ID);

      $items = $DB->request([
         'FROM'   => self::getTable(),
         'WHERE'  => [
            'racks_id' => $rack->getID()
         ]
      ]);
      $link = new self();

      Session::initNavigateListItems(
         self::getType(),
         //TRANS : %1$s is the itemtype name,
         //        %2$s is the name of the item (used for headings of a list)
         sprintf(
            __('%1$s = %2$s'),
            $rack->getTypeName(1),
            $rack->getName()
         )
      );

      echo "<div id='switchview'>";
      echo "<i id='sviewlist' class='pointer fa fa-list-alt' title='".__('View as list')."'></i>";
      echo "<i id='sviewgraph' class='pointer fa fa-th-large selected' title='".__('View graphical representation')."'></i>";
      echo "</div>";

      $items = iterator_to_array($items);
      echo "<div id='viewlist'>";

      /*$rack = new self();*/
      if (!count($items)) {
         echo "<table class='tab_cadre_fixe'><tr><th>".__('No item found')."</th></tr>";
         echo "</table>";
      } else {
         if ($canedit) {
            $massiveactionparams = [
               'num_displayed'   => min($_SESSION['glpilist_limit'], count($items)),
               'container'       => 'mass'.__CLASS__.$rand
            ];
            Html::showMassiveActions($massiveactionparams);
         }

         echo "<table class='tab_cadre_fixehov'>";
         $header = "<tr>";
         if ($canedit) {
            $header .= "<th width='10'>";
            $header .= Html::getCheckAllAsCheckbox('mass'.__CLASS__.$rand);
            $header .= "</th>";
         }
         $header .= "<th>".__('Item')."</th>";
         $header .= "<th>".__('Position')."</th>";
         $header .= "<th>".__('Orientation')."</th>";
         $header .= "</tr>";

         echo $header;
         foreach ($items as $row) {
            $item = new $row['itemtype'];
            $item->getFromDB($row['items_id']);
            echo "<tr lass='tab_bg_1'>";
            if ($canedit) {
               echo "<td>";
               Html::showMassiveActionCheckBox(__CLASS__, $row["id"]);
               echo "</td>";
            }
            echo "<td>" . $item->getLink() . "</td>";
            echo "<td>{$row['position']}</td>";
            echo "<td>{$row['orientation']}</td>";
            echo "</tr>";
         }
         echo $header;
         echo "</table>";

         if ($canedit && count($items)) {
            $massiveactionparams['ontop'] = false;
            Html::showMassiveActions($massiveactionparams);
         }
         if ($canedit) {
            Html::closeForm();
         }
      }
      echo "</div>";
      echo "<div id='viewgraph'>";

      $data = [];
      //all rows; empty
      for ($i = (int)$rack->fields['number_units']; $i > 0; --$i) {
         foreach ([Rack::FRONT, Rack::REAR] as $y) {
            $obj[$y] = [];
         }
         $data[$i] = $obj;
      }

      //fill rows
      $outbound = [];
      foreach ($items as $row) {
         $item = new $row['itemtype'];
         $item->getFromDB($row['items_id']);
         $model_class = $item->getType() . 'Model';
         $modelsfield = strtolower($item->getType()) . 'models_id';
         $model = new $model_class;
         if ($model->getFromDB($item->fields[$modelsfield])) {
            $item->model = $model;
         } else {
            $item->model = null;
         }

         $in = false;
         if (isset($data[$row['position']])) {
            $in = true;
            $required_units = 1;
            if ($item->model != null && $item->model->fields['required_units'] > 1) {
               $required_units = $item->model->fields['required_units'];
            }
            $first = true;
            for ($i = 0; $i < $required_units; $i++) {
               $current_position = $row['position'] + $i;
               $hposis = [Rack::POS_LEFT, Rack::POS_RIGHT];
               if ($item->model != null && $item->model->fields['is_half_rack'] == 1) {
                  $hposis = [$row['hpos']];
               }
               if ($item->model != null && $item->model->fields['depth'] == 1) {
                  foreach ($hposis as $hpos) {
                     $data[$current_position][Rack::FRONT][$hpos] = self::getCell(
                        $row,
                        $item, [
                           'hpos'         => $hpos,
                           'orientation'  => Rack::FRONT,
                           'position'     => $current_position,
                        ]
                     );
                  }
                  $chpos = 0;
                  foreach ($hposis as $hpos) {
                     ++$chpos;
                     $data[$current_position][Rack::REAR][$hpos] = self::getCell(
                        $row,
                        $item, [
                           'hpos'         => $hpos,
                           'orientation'  => Rack::REAR,
                           'position'     => $current_position,
                        ],
                        $first && count($hposis) === $chpos
                     );
                  }
               } else {
                  $chpos = 0;
                  foreach ($hposis as $hpos) {
                     ++$chpos;
                     $data[$current_position][$row['orientation']][$hpos] = self::getCell(
                        $row,
                        $item, [
                           'hpos'         => $hpos,
                           'orientation'  => $row['orientation'],
                           'position'     => $current_position,
                        ],
                        $first && count($hposis) === $chpos
                     );
                  }
               }
               $first = false;
            }
         }

         if ($in === false) {
            $outbound[] = ['row' => $row, 'item' => $item];
         }

      }

      if (count($outbound)) {
         echo "<table><thead><th colspan='10' class='redips-mark'>";
         echo __('Following elements are out of rack bounds');
         echo "</th></thead><tbody>";
         echo "<tr>";
         $count = 0;
         foreach ($outbound as $out) {
            if ($count % 10 == 0) {
               echo "</tr><tr>";
            }
            echo "<td>".self::getCell($out['row'], $out['item'], true)."</td>";
            ++$count;
         }
         echo "</tr></tbody></table>";
      }

      echo "<table><thead><tr>";
      echo "<th class='redips-mark' colspan='2'>".__('Front')."</th>";
      echo "<th class='redips-mark' colspan='2'>".__('Rear')."</th>";
      echo "</tr></thead>";
      $position = count($data);
      foreach ($data as $row) {
         echo "<tr>";
         foreach ($row as $orientation => $items) {
            foreach ([Rack::POS_LEFT, Rack::POS_RIGHT] as $pos) {
               echo "<td data-hpos='$pos' data-orientation='$orientation' data-position='$position'>";
               if (isset($items[$pos])) {
                  echo $items[$pos];
               }
               echo "</td>";
            }
         }
         echo "</tr>";
         --$position;
      }
      echo "</table>";
      echo "</div>";

      $js = "$(function(){
         $('#sviewlist').on('click', function(){
            $('#viewlist').show();
            $('#viewgraph').hide();
            $(this).addClass('selected');
            $('#sviewgraph').removeClass('selected');
         });
         $('#sviewgraph').on('click', function(){
            $('#viewlist').hide();
            $('#viewgraph').show();
            $(this).addClass('selected');
            $('#sviewlist').removeClass('selected');
         });

         var rd = REDIPS.drag;
         var _relocate_ok = false;
         rd.event.dropped = function (cell) {
            var _cell = $(cell);
            var _div = _cell.find('div');
            var _content = _div.html();
            var _id = _div.data('id');
            var _hpos = _div.data('hpos')
            var _position = _div.data('position');
            var _orientation = _div.data('orientation');
            var pos = rd.getPosition();

            var _real_position = _cell.data('position');
            var _real_hpos = _cell.data('hpos');
            var _real_orientation = _cell.data('orientation');
            var _brothers = [];

            //find other divs, if any
            $('div[data-id='+_id+']').each(function() {
               var _bro = $(this);
               var _current = {
                  'position': _bro.data('position'),
                  'hpos': _bro.data('hpos'),
                  'orientation': _bro.data('orientation')
               };

               //adjust positionning, if required.
               if (_current.position < _position) {
                  _real_position = _cell.data('position') - (_position - _current.position);
               }
               if (_current.hpos < _real_hpos) {
                  _real_hpos = _current.hpos;
               }
               if (_current.orientation < _real_orientation) {
                  _real_orientation = _current.orientation;
               }

               //list real brothers
               if (_current.position != _position || _current.hpos != _hpos || _current.orientation != _orientation) {
                  _brothers.push(_bro);
               }

            });

            $.ajax({
               url: '{$CFG_GLPI['root_doc']}/ajax/moverackitem.php',
               method: 'POST',
               data: {
                  'id': _id,
                  'position': _real_position,
                  'hpos': _real_hpos,
                  'orientation': _real_orientation
               },
               success: function(res) {
                  if (res.success == false) {
                     alert('".__s('Item has not been moved')."');
                     rd.moveObject({
                        obj: _div.get(0),
                        target: [0, pos[4], pos[5]]
                     });
                     displayAjaxMessageAfterRedirect();
                  } else {
                     //move other divs, if any
                     _div.data('position', _cell.data('position'));
                     _div.data('hpos', _cell.data('hpos'));
                     _div.data('orientation', _cell.data('orientation'));
                     var _pos_diff = pos[1] - pos[4];
                     var _nblines = _cell.parents('tbody').find('tr').length;
                     $(_brothers).each(function() {
                        var _bro = $(this);
                        var _brocell = _bro.parent('td');
                        rd.moveObject({
                           obj: _bro.get(0),
                           target: [
                              0,
                              _nblines - _brocell.data('position') + _pos_diff + 1,
                              _bro.data('orientation') * 2 + _bro.data('hpos') - 1
                           ]
                        });
                        _bro.data({'position': _brocell.data('position') + _pos_diff * -1})
                     });
                  }
               },
               beforeSend: function() {
                  _div.html('<i class=\'fa fa-spinner\'></i>');
               },
               complete: function() {
                  _div.html(_content);
               },
               error: function() {
                  alert('".__s('Item has not been moved')."');
                  rd.moveObject({
                     obj: _div.get(0),
                     target: [0, pos[4], pos[5]]
                  });
               }
            });

         };

         /* Broken with Html::redefineConfirm() stuff :( */
         /*rd.trash.question = '". __s('Are you sure you want to dissociate this item?')."';
         rd.event.deleted = function (cell) {
            console.log('DELETED');
         }
         rd.event.undeleted = function (cell) {
            console.log('UNDELETED');
         }*/

         rd.dropMode = 'single'; //disable drop if cell is not empty
         rd.init('viewgraph');

         $('#viewgraph td').on('click', function(){
            var _this = $(this);
            if (_this.find('div').length == 0) {
               _this.append('<div class=\'redips-drag\' id=\'new_rack\'>".__("Add a new item")."</div>');
               rd.enableDrag(true, '#new_rack');
               var pos = rd.getPosition('new_rack');
               window.location = '{$link->getFormURL()}?rack={$rack->getID()}&orientation=' + pos[2] + '&unit='+pos[1];
            }
         });

         $('#viewgraph .redips-drag').each(function() {
            var _this = $(this);
            _this.qtip({
               position: { viewport: $(window) },
               content: {
                  text: _this.find('.tipcontent')
               },
               style: {
                  classes: 'qtip-shadow qtip-bootstrap'
               }
            });
         });
      });";
      echo Html::scriptBlock($js);
   }

   function showForm($ID, $options = []) {
      global $DB, $CFG_GLPI;

      $colspan = 4;

      echo "<div class='center'>";

      $this->initForm($ID, $this->fields);
      $this->showFormHeader();

      $rack = new Rack();
      $rack->getFromDB($this->fields['racks_id']);

      $rand = mt_rand();

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='dropdown_itemtype$rand'>".__('Item type')."</label></td>";
      echo "<td>";
      Dropdown::showFromArray(
         'itemtype',
         array_combine($CFG_GLPI['rackable_types'], $CFG_GLPI['rackable_types']), [
            'display_emptychoice'   => true,
            'value'                 => $this->fields["itemtype"],
            'rand'                  => $rand
         ]
      );

      //get all used items
      $used = [];
      $iterator = $DB->request([
         'FROM'   => $this->getTable()
      ]);
      while ($row = $iterator->next()) {
         $used [$row['itemtype']][] = $row['items_id'];
      }

      Ajax::updateItemOnSelectEvent(
         "dropdown_itemtype$rand",
         "items_id",
         $CFG_GLPI["root_doc"]."/ajax/dropdownAllItems.php", [
            'idtable'   => '__VALUE__',
            'name'      => 'items_id',
            'value'     => $this->fields['items_id'],
            'rand'      => $rand,
            'used'      => $used
         ]
      );

      //TODO: update possible positions according to selected item number of units
      //TODO: update positions on rack selection
      //TODO: update hpos from item model info is_half_rack
      //TODO: update orientation according to item model depth

      echo "</td>";
      echo "<td><label for='dropdown_items_id$rand'>".__('Item')."</label></td>";
      echo "<td id='items_id'>";
      if (isset($this->fields['itemtype']) && !empty($this->fields['itemtype'])) {
         $itemtype = $this->fields['itemtype'];
         $itemtype = new $itemtype();
         $itemtype::dropdown([
            'name'   => "items_id",
            'value'  => $this->fields['items_id'],
            'rand'   => $rand
         ]);
      } else {
         Dropdown::showFromArray(
            'items_id',
            [], [
               'display_emptychoice'   => true,
               'rand'                  => $rand
            ]
         );
      }

      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='dropdown_racks_id$rand'>".__('Rack')."</label></td>";
      echo "<td>";
      Rack::dropdown(['value' => $this->fields["racks_id"], 'rand' => $rand]);
      echo "</td>";
      echo "<td><label for='dropdown_position$rand'>".__('Position')."</label></td>";
      echo "<td >";
      Dropdown::showNumber(
         'position', [
            'value'  => $this->fields["position"],
            'min'    => 1,
            'max'    => $rack->fields['number_units'],
            'step'   => 1,
            'used'   => $rack->getFilled($this->fields['itemtype'], $this->fields['items_id']),
            'rand'   => $rand
         ]
      );
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='dropdown_orientation$rand'>".__('Orientation')."</label></td>";
      echo "<td >";
      Dropdown::showFromArray(
         'orientation', [
            Rack::FRONT => __('Front'),
            Rack::REAR  => __('Rear')
         ], [
            'value' => $this->fields["orientation"],
            'rand' => $rand
         ]
      );
      echo "</td>";
      echo "<td><label for='bgcolor$rand'>".__('Background color')."</label></td>";
      echo "<td>";
      Html::showColorField(
         'bgcolor', [
            'value'  => $this->fields['bgcolor'],
            'rand'   => $rand
         ]
      );
      echo "</td>";
      echo "</tr>";

      echo "<tr class='tab_bg_1'>";
      echo "<td><label for='dropdown_hpos$rand'>".__('Horizontal position')."</label></td>";
      echo "<td>";
      Dropdown::showFromArray(
         'hpos',
         [
            Rack::POS_NONE    => __('None'),
            Rack::POS_LEFT    => __('Left'),
            Rack::POS_RIGHT   => __('Right')
         ], [
            'value'  => $this->fields['hpos'],
            'rand'   =>$rand
         ]
      );
      echo "</td>";
      echo "</tr>";

      $this->showFormButtons($options);
   }

   /**
    * Get cell content
    *
    * @param array      $row       Item/Rack row
    * @param CommonDBTM $item      Item instance
    * @param array      $positions Array of positions to populate data-* attributes
    * @param boolean    $show_link Whether to show item link on cell
    *
    * @return string
    */
   private static function getCell(array $row, CommonDBTM $item, array $positions, $show_link = false) {
      $style = '';
      if ($row['bgcolor'] != '') {
         $style = " style='background-color: {$row['bgcolor']};" .
               "border-color: {$row['bgcolor']};'";
      }

      $typestable = 'glpi_' . strtolower($item->getType()).'types';
      $typesfield = strtolower($item->getType()) . 'types_id';
      $name = sprintf(
         '%1$s %2$s %3$s',
         Dropdown::getDropDownName($typestable, $item->fields[$typesfield]),
         $item->getTypeName(1),
         $item->getName()
      );

      $required_units = $item->model != null ? $item->model->fields['required_units'] : 1;

      $cell = "<div
         data-id='{$row['id']}'
         data-hpos='{$positions['hpos']}'
         data-position='{$positions['position']}'
         data-orientation='{$positions['orientation']}'
         data-u='$required_units'
         class='redips-drag'
         $style>$name";
      if ($show_link === true) {
         $cell .= "<a href='{$item->getLinkURL()}'><i class='fa fa-link'></i></a>";
      }
      $cell .= "<span class='tipcontent'>
               <strong>".__('Name:')."</strong> {$item->getName()}<br/>
               <strong>".__('Serial:')."</strong> {$item->getField('serial')}
            </span>
         </div>";
      return $cell;
   }

   function prepareInputForAdd($input) {
      return $this->prepareInput($input);
   }

   function prepareInputForUpdate($input) {
      return $this->prepareInput($input);
   }

   /**
    * Prepares input (for update and add)
    *
    * @param array $input Input data
    *
    * @return array
    */
   private function prepareInput($input) {
      $error_detected = [];

      $itemtype = $this->fields['itemtype'];
      $items_id = $this->fields['items_id'];
      $racks_id = $this->fields['racks_id'];
      $position = $this->fields['position'];
      $hpos = $this->fields['hpos'];
      $orientation = $this->fields['orientation'];

      //check for requirements
      if ($this->isNewItem()) {
         if (!isset($input['itemtype'])) {
            $error_detected[] = __('An item type is required');
         }

         if (!isset($input['items_id'])) {
            $error_detected[] = __('An item is required');
         }

         if (!isset($input['racks_id'])) {
            $error_detected[] = __('A rack is required');
         }

         if (!isset($input['position'])) {
            $error_detected[] = __('A position is required');
         }
      }

      if (isset($input['itemtype'])) {
         $itemtype = $input['itemtype'];
      }
      if (isset($input['items_id'])) {
         $items_id = $input['items_id'];
      }
      if (isset($input['racks_id'])) {
         $racks_id = $input['racks_id'];
      }
      if (isset($input['position'])) {
         $position = $input['position'];
      }
      if (isset($input['hpos'])) {
         $hpos = $input['hpos'];
      }
      if (isset($input['orientation'])) {
         $orientation = $input['orientation'];
      }

      if (!count($error_detected)) {
         //check if required U are available at position
         $rack = new Rack();
         $rack->getFromDB($racks_id);

         $filled = $rack->getFilled($itemtype, $items_id);

         $item = new $itemtype;
         $item->getFromDB($items_id);
         $model_class = $item->getType() . 'Model';
         $modelsfield = strtolower($item->getType()) . 'models_id';
         $model = new $model_class;
         if ($model->getFromDB($item->fields[$modelsfield])) {
            $item->model = $model;
         } else {
            $item->model = null;
         }

         $required_units = 1;
         $width          = 1;
         $depth          = 1;
         if ($item->model != null) {
            if ($item->model->fields['required_units'] > 1) {
               $required_units = $item->model->fields['required_units'];
            }
            if ($item->model->fields['is_half_rack'] == 1) {
               if ($this->isNewItem() && !isset($input['hpos']) || $input['hpos'] == 0) {
                  $error_detected[] = __('You must define an horizontal position for this item');
               }
               $width = 0.5;
            }
            if ($item->model->fields['depth'] != 1) {
               if ($this->isNewItem() && !isset($input['orientation'])) {
                  $error_detected[] = __('You must define an orientation for this item');
               }
               $depth = $item->model->fields['depth'];
            }
         }

         if ($position > $rack->fields['number_units'] ||
            $position + $required_units  > $rack->fields['number_units'] + 1
         ) {
            $error_detected[] = __('Item is out of rack bounds');
         } else if (!count($error_detected)) {
            $i = 0;
            while ($i < $required_units) {
               $current_position = $position + $i;
               if (isset($filled[$current_position])) {
                  $width_overflow = false;
                  $depth_overflow = false;
                  if ($filled[$current_position]['width'] + $width > 1) {
                     if ($depth > 0.5) {
                        $width_overflow = true;
                     }
                  } else if ($filled[$current_position]['width'] <= 0.5 && $hpos == $filled[$current_position]['hpos']) {
                     $error_detected[] = __('An item already exists at this horizontal position');
                  }
                  if ($filled[$current_position]['depth'] + $depth > 1) {
                     if ($width > 0.5) {
                        $depth_overflow = true;
                     }
                  } else if ($filled[$current_position]['depth'] <= 0.5 && $orientation == $filled[$current_position]['orientation']) {
                     $error_detected[] = __('An item already exists for this orientation');
                  }

                  if ($width_overflow || $depth_overflow) {
                     $error_detected[] = __('Not enougth space available to place item');
                  }
               }
               ++$i;
            }
         }
      }

      if (count($error_detected)) {
         foreach ($error_detected as $error) {
            Session::addMessageAfterRedirect(
               $error,
               true,
               ERROR
            );
         }
         return false;
      }

      return $input;
   }
}
