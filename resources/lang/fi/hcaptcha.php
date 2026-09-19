<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Captcha-tarkistus epäonnistui. Yritä uudelleen.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Täytä captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Captcha-palvelu ei ole saatavilla. Yritä uudelleen hetken kuluttua.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Captcha on vanhentunut. Täytä se uudelleen.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'Captchaa ei suoritettu loppuun. Yritä uudelleen.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Tarkistetaan, odota…',

];
