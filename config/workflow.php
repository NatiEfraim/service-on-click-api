<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Booking types with a route of their own (App\Domain\Workflow\Routes)
    |--------------------------------------------------------------------------
    */

    'clearance' => [
        // Close a request (104 -> 105) on its own once the clearance registry shows the requested level. Off until a
        // registry (ותיקן) implementation of App\Domain\Clearance\ClearanceRegistry is bound; במ closes by hand meanwhile.
        'auto_close' => filter_var(env('CLEARANCE_AUTO_CLOSE', false), FILTER_VALIDATE_BOOL),

        // The interview date and time are entered in this timezone; the due moment is stored in UTC (bookings.stage_due_at).
        'timezone' => env('CLEARANCE_TIMEZONE', 'Asia/Jerusalem'),

        // The registry is asked at most this often (minutes); the due transitions run every time the command runs.
        'registry_every_minutes' => (int) env('CLEARANCE_REGISTRY_EVERY_MINUTES', 60),
    ],

    // The ICT service desk: a booking type id => an App\Domain\Workflow\Automation\IctAutomation class. Empty = no automation runs.
    'ict' => [
        'automation' => [],
    ],

];
