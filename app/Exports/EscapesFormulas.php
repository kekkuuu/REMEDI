<?php

namespace App\Exports;

/**
 * Prevents a product/category/SKU name that happens to start with a formula
 * trigger from being interpreted as a live formula the moment the exported
 * file is opened in Excel or Sheets. These names are admin-editable text
 * (the product edit form, a SKU rename, a category rename), not fixed
 * catalogue data, so the export can't assume they never start with one.
 *
 * Same guard as AuditTrailController::csvCell(), duplicated rather than
 * shared because that method is private on a different controller -- same
 * reasoning DemandForecastService gives for its own copy of likeTerm(). A
 * trait rather than a second private copy per class here only because three
 * separate Export classes need the identical logic.
 */
trait EscapesFormulas
{
    private function escapeCell($value): string
    {
        $value = (string) $value;
        $first = substr($value, 0, 1);

        // chr(9)/chr(13) (tab/CR) join the four formula characters because a
        // leading whitespace character lets the trigger hide behind
        // something a reader strips before evaluating the cell.
        return in_array($first, ['=', '+', '-', '@', chr(9), chr(13)], true)
            ? "'".$value
            : $value;
    }
}
