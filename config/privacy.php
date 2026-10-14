<?php

/*
| E-99: Data Privacy Act housekeeping. The retention schedule below is what the app does
| today (each period comes from the setting that enforces it). Review it with your Data
| Protection Officer and counsel; this is not legal advice.
*/
return [
    // Sign-in history and devices (IP address, browser) are deleted after this many days.
    'login_history_days' => (int) env('PRIVACY_LOGIN_HISTORY_DAYS', 180),
];
