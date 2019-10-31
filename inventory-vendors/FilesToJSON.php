<?php
//Script to convert file data to sql

filePCItoJson();
fileUSBtoJson();
fileOUItoJson();

function filePCItoJson() {
   $pciFile = fopen("pci.ids", "r");
   $pci_ids = [];

   while (!feof($pciFile)) {
      $buffer = fgets($pciFile, 4096);

      $stack = [];
      if (preg_match("/^(\w+)\s*(.+)/i", $buffer, $stack)) {
         $vendorId = $stack[1];
         $pci_ids[$vendorId] = $stack[2];
      }

      $stack = [];
      if (preg_match("/^\t(\w+)\s*(.+)/i", $buffer, $stack)) {
         $deviceId = $stack[1];
         $pci_ids[$vendorId.'::'.$deviceId] = $stack[2];
      }
   }

   file_put_contents(__DIR__ . '/pciid.json', json_encode($pci_ids,  JSON_PRETTY_PRINT));
}


function fileUSBtoJson() {
    $usbFile = fopen("usb.ids", "r");
    $usb_ids = [];

   while (!feof($usbFile)) {
      $buffer = fgets($usbFile, 4096);

      $stack = [];
      if (preg_match("/^(\w+)\s*(.+)/i", $buffer, $stack)) {
         $vendorId = $stack[1];
         $usb_ids[$vendorId] = $stack[2];
      }

      $stack = [];
      if (preg_match("/^\t(\w+)\s*(.+)/i", $buffer, $stack)) {
         $deviceId = $stack[1];
         $usb_ids[$vendorId.'::'.$deviceId] = $stack[2];
      }
   }

   file_put_contents(__DIR__ . '/usbid.json', json_encode($usb_ids,  JSON_PRETTY_PRINT));
}


function fileOUItoJson() {
   $ouiFile = fopen("oui.txt", "r");
   $ouis = [];

   while (!feof($ouiFile)) {
      $buffer = fgets($ouiFile, 4096);

      $stack = [];
      if (preg_match("/^(\S+)\s*\(hex\)\t{2}(.+)/i", $buffer, $stack)) {
         $mac = strtr($stack[1], '-', ':');
         $ouis[$mac] = $stack[2];
      }
   }
   file_put_contents(__DIR__ . '/ouis.json', json_encode($ouis,  JSON_PRETTY_PRINT));
}
