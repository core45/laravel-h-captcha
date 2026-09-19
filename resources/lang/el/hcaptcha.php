<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Η επαλήθευση captcha απέτυχε. Παρακαλώ δοκιμάστε ξανά.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Παρακαλώ ολοκληρώστε το captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Η υπηρεσία captcha δεν είναι διαθέσιμη. Παρακαλώ δοκιμάστε ξανά σε λίγο.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Το captcha έληξε. Παρακαλώ ολοκληρώστε το ξανά.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'Το captcha δεν ολοκληρώθηκε. Δοκιμάστε ξανά.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Γίνεται έλεγχος, περιμένετε…',

];
