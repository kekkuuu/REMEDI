<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Train on the till's own sales as well as the imported record?
    |--------------------------------------------------------------------------
    |
    | OFF as of 2026-09-27, at the user's decision. The imported record
    | (database/data/sales_history_daily_red_pharmacy_2022-2026.csv, the 56 "Sales by
    | Product" monthly files, RED Pharmacy) runs at ~300-500 units a day; the terminal, live
    | from 2026-08-16, at ~150. Mixing the two made the last complete month
    | (August) read as a 42% crash, and every forecast was dragged down by a
    | change of SOURCE rather than of demand. Off, both pipelines train on the
    | imported record alone -- through July 2026, its last complete month --
    | and both forecast CHARTS draw the same series, so the chart still shows
    | what the model saw.
    |
    | Turn it on once the terminal's own history is the record worth
    | forecasting from. One switch, read by: both Python scripts (passed as
    | --include-pos by GenerateDemandForecast / GenerateSalesForecast,
    | including the nightly schedule), DemandForecastService and
    | SalesForecastService.
    |
    */

    'include_pos' => (bool) env('FORECAST_INCLUDE_POS', false),

];
