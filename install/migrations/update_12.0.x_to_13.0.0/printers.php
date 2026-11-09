<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2026 Teclib' and contributors.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

/**
 * @var Migration $migration
 * @var DBmysql $DB
 */

// Printer memory is now stored in a `ram` field, like for other assets.
if ($DB->fieldExists('glpi_printers', 'memory_size')) {
    $iterator = $DB->request([
        'SELECT' => ['id', 'name', 'memory_size'],
        'FROM'   => 'glpi_printers',
        'WHERE'  => [
            'NOT' => ['memory_size' => null],
        ],
    ]);
    foreach ($iterator as $data) {
        $memory = trim((string) $data['memory_size']);
        if ($memory !== '' && !ctype_digit($memory)) {
            // Value cannot be safely converted (e.g. "512 Mo", "1GB"), as the unit is not known.
            $migration->addWarningMessage(
                sprintf(
                    __('The memory value "%1$s" of the printer "%2$s" (ID %3$d) is not a number of Mio. It has been removed.'),
                    $data['memory_size'],
                    $data['name'],
                    $data['id']
                )
            );
        }

        $DB->update(
            'glpi_printers',
            ['memory_size' => ctype_digit($memory) ? $memory : null],
            ['id' => $data['id']]
        );
    }

    $migration->changeField('glpi_printers', 'memory_size', 'ram', 'int unsigned DEFAULT NULL');
    $migration->changeSearchOption(Printer::class, 13, 15);
}
