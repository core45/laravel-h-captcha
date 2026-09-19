<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Preverjanje captche je spodletelo. Poskusite znova.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Prosimo, izpolnite captcho.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Storitev captche ni na voljo. Prosimo, poskusite znova čez trenutek.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Captcha je potekla. Prosimo, jo izpolnite znova.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'Captcha ni bila dokončana. Poskusite znova.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Preverjanje poteka, počakajte…',

];
