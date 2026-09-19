<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Overenie captchy zlyhalo. Skúste to prosím znova.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Prosím, vyplňte captchu.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Služba captchy nie je dostupná. Skúste to prosím znova za chvíľu.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Platnosť captchy skončila. Prosím, vyplňte ju znova.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'Captcha nebola dokončená. Skúste to znova.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Prebieha overenie, čakajte prosím…',

];
