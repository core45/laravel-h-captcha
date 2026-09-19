<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Verifiering av captcha misslyckades. Försök igen.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Vänligen slutför captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Captcha-tjänsten är inte tillgänglig. Försök igen om en stund.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Captcha har gått ut. Vänligen slutför den igen.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'Captchan slutfördes inte. Försök igen.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Kontrollerar, vänta…',

];
