<?php

declare(strict_types=1);

return [

    // Shown when the token was rejected by hCaptcha.
    'failed' => 'Ověření captcha selhalo. Zkuste to prosím znovu.',

    // Shown when the form was submitted with no token at all.
    'missing' => 'Prosím, vyplňte captcha.',

    // Shown when hCaptcha could not be reached and the package failed closed.
    'unavailable' => 'Služba captcha je nedostupná. Zkuste to prosím znovu za chvíli.',

    // Shown when the token was already spent (hCaptcha tokens are single-use).
    'expired' => 'Platnost captcha vypršela. Prosím, vyplňte ji znovu.',

    // Accessible label rendered on the widget wrapper.
    'label' => 'Captcha',

    // Shown by the widget when the challenge failed, was closed, or could not load.
    'widget_error' => 'Captcha nebyla dokončena. Zkuste to znovu.',

    // Shown by the widget while an invisible challenge is being executed.
    'widget_pending' => 'Probíhá ověření, čekejte prosím…',

];
