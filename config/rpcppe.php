<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PPE Capitalization Threshold
    |--------------------------------------------------------------------------
    |
    | The unit acquisition cost (in PHP) at or above which an item is
    | classified as Property, Plant and Equipment (PPE) and therefore
    | belongs in the RPCPPE. Items below this threshold that are expected
    | to last more than one year are "Semi-Expendable Property" and must
    | NOT appear in the RPCPPE (COA Circular No. 2022-004).
    |
    | COA has changed this value before (it was previously PHP 15,000), so
    | it is kept configurable rather than hardcoded.
    |
    */
    'capitalization_threshold' => (float) env('RPCPPE_CAPITALIZATION_THRESHOLD', 50000),
];
